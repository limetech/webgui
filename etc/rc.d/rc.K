#!/bin/bash
#
# script: rc.K
#
# This file is executed by init when it goes into runlevel 1, which is the administrative state.
# It kills all daemons and then puts the system into single user mode.
# Note that the file systems are kept mounted.
#
# Version: 3.1415 Sat Jan 13 13:37:26 PST 2001
#
# Author:  Miquel van Smoorenburg <miquels@drinkel.nl.mugnet.org>
#          Modified by: Patrick J. Volkerding <volkerdi@slackware.com>
#
# LimeTech - modified for Unraid OS
# Bergware - modified for Unraid OS, October 2023

# Set the path.
PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin

# run & log functions
. /etc/rc.d/rc.runlog

# Load a custom screen font if the user has an rc.font script.
if [[ -x /etc/rc.d/rc.font ]]; then
  /etc/rc.d/rc.font
fi

# Load any needed keyboard mappings:
if [[ -x /etc/rc.d/rc.keymap ]]; then
  /etc/rc.d/rc.keymap
fi

# If there are SystemV init scripts for this runlevel, run them.
if [[ -x /etc/rc.d/rc.sysvinit ]]; then
  /etc/rc.d/rc.sysvinit
fi

# Try to turn off quota:
if grep -q quota /etc/fstab ; then
  if [[ -x /sbin/quotaoff ]]; then
    log "Turning off filesystem quotas."
    run /sbin/quotaoff -a
  fi
fi

# Try to turn off accounting:
if [[ -x /sbin/accton && -r /var/log/pacct ]]; then
  /sbin/accton off
fi

# Run any local shutdown scripts:
if [[ -x /etc/rc.d/rc.local_shutdown ]]; then
  /etc/rc.d/rc.local_shutdown stop
fi

# Stop the Apache web server:
if [[ -x /etc/rc.d/rc.httpd ]]; then
  /etc/rc.d/rc.httpd stop
fi

# Stop the Samba server:
if [[ -x /etc/rc.d/rc.samba ]]; then
  /etc/rc.d/rc.samba stop
fi

# Shut down the NFS server:
if [[ -x /etc/rc.d/rc.nfsd ]]; then
  /etc/rc.d/rc.nfsd stop
fi

# Kill any processes (typically gam) that would otherwise prevent
# unmounting NFS volumes:
unset FUSER_DELAY
for DIR in $(mount | grep -e 'type nfs ' -e 'type nfs4 ' | sed -e 's|.* on ||g' | cut -d ' ' -f 1); do
  log "Killing processes holding NFS mount $DIR open..."
  # Background this to prevent fuser from also blocking shutdown:
  run /usr/bin/fuser -k -M -m "$DIR" &
  FUSER_DELAY=5
done
# If fuser was run, let it have some delay:
if [[ -n "$FUSER_DELAY" ]]; then
  sleep $FUSER_DELAY
fi

# Unmount any NFS, SMB, or CIFS filesystems:
log "Unmounting remote filesystems:"
run umount -v -a -l -f -r -t nfs,nfs4,smbfs,cifs | tr -d ' ' | grep successfully | sed "s/:successfullyunmounted/ has been successfully unmounted./g"

# Shut down PCMCIA devices:
if [[ -x /etc/rc.d/rc.pcmcia ]]; then
  /etc/rc.d/rc.pcmcia stop
  # The cards might need a little extra time here to deactivate:
  sleep 5
fi

# Terminate acpid before syslog:
if [[ -x /etc/rc.d/rc.acpid && -r /var/run/acpid.pid ]]; then # quit
  /etc/rc.d/rc.acpid stop
fi

# Kill all processes.
OMITPIDS="$(for P in $(pgrep --ns $$  mdmon); do echo -o $P; done)" # Don't kill mdmon
log "Sending all processes the SIGHUP signal."
run killall5 -1 $OMITPIDS
log "Waiting for processes to hang up"
for LOOP in {1..5}; do sleep 1; done
log "Sending all processes the SIGTERM signal."
run killall5 -15 $OMITPIDS
log "Waiting for processes to terminate"
for LOOP in {1..5}; do sleep 1; done
echo "Sending all processes the SIGKILL signal."
run killall5 -9 $OMITPIDS
log "Waiting for processes to exit"
for LOOP in {1..5}; do sleep 1; done

# Now go to the single user level
log "Going to single user mode..."
run telinit -t 1 1
