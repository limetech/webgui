<?PHP
/* Copyright 2005-2023, Lime Technology
 * Copyright 2015-2021, Derek Macias, Eric Schultz, Jon Panozzo.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?
	class Libvirt {
		private $conn;
		private $last_error;
		private $allow_cached = true;
		private $dominfos = [];
		private $enabled = false;

		function Libvirt($uri = false, $login = false, $pwd = false, $debug=false) {
			if ($debug)
				$this->set_logfile($debug);
			if ($uri != false) {
				$this->enabled = $this->connect($uri, $login, $pwd);
			}
		}

		function __construct($uri = false, $login = false, $pwd = false, $debug=false) {
			if ($debug)
				$this->set_logfile($debug);
			if ($uri != false) {
				$this->enabled = $this->connect($uri, $login, $pwd);
			}
		}

		function _set_last_error() {
			$this->last_error = libvirt_get_last_error();
			return false;
		}

		function enabled() {
			return $this->enabled;
		}

		function set_logfile($filename) {
			if (!libvirt_logfile_set($filename,10000))
				return $this->_set_last_error();

			return true;
		}

		function get_capabilities() {
			$tmp = libvirt_connect_get_capabilities($this->conn);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_machine_types($arch = 'x86_64' /* or 'i686' */) {
			$tmp = libvirt_connect_get_machine_types($this->conn);

			if (!$tmp)
				return $this->_set_last_error();

			if (empty($tmp[$arch]))
				return [];

			return $tmp[$arch];
		}

		function get_default_emulator() {
			$tmp = libvirt_connect_get_capabilities($this->conn, '//capabilities/guest/arch/domain/emulator');
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function set_folder_nodatacow($folder) {
			if (!is_dir($folder)) {
				return false;
			}

			$folder = transpose_user_path($folder);

			#@shell_exec("chattr +C -R " . escapeshellarg($folder) . " &>/dev/null");

			return true;
		}

		function create_disk_image($disk, $vmname = '', $diskid = 1) {
			$arrReturn = [];

			if (!empty($disk['size'])) {
				$disk['size'] = str_replace(["KB","MB","GB","TB","PB"], ["K","M","G","T","P"], strtoupper($disk['size']));
			}
			if (empty($disk['driver'])) {
				$disk['driver'] = 'raw';
			}

			// if new is a folder then
			//   if existing then
			//     create folder 'new/vmname'
			//     create image file as new/vmname/vdisk[1-x].xxx
			//   if doesn't exist then
			//     create folder 'new'
			//     create image file as new/vdisk[1-x].xxx

			// if new is a file then
			//   if existing then
			//     nothing to do
			//   if doesn't exist then
			//     create folder dirname('new') if needed
			//     create image file as new --> if size is specified

			if (!empty($disk['new'])) {
				if (is_file($disk['new']) || is_block($disk['new'])) {
					$disk['image'] = $disk['new'];
				}
			}

			if (!empty($disk['image'])) {
				// Use existing disk image

				if (is_block($disk['image'])) {
					// Valid block device, return as-is
					return $disk;
				}

				if (is_file($disk['image'])) {
					$json_info = getDiskImageInfo($disk['image']);
					$disk['driver'] = $json_info['format'];

					if (!empty($disk['size'])) {
						//TODO: expand disk image if size param is larger
					}

					return $disk;
				}

				$disk['new'] = $disk['image'];
			}

			if (!empty($disk['new'])) {
				// Create new disk image
				$strImgFolder = $disk['new'];
				$strImgPath = '';

				if (strpos($strImgFolder, '/dev/') === 0) {
					// ERROR invalid block device
					$arrReturn = [
						'error' => "Not a valid block device location '" . $strImgFolder . "'"
					];

					return $arrReturn;
				}

				if (empty($disk['size'])) {
					// ERROR invalid disk size
					$arrReturn = [
						'error' => "Please specify a disk size for '" . $strImgFolder . "'"
					];

					return $arrReturn;
				}

				$path_parts = pathinfo($strImgFolder);
				if (empty($path_parts['extension'])) {
					// 'new' is a folder

					if (substr($strImgFolder, -1) != '/') {
						$strImgFolder .= '/';
					}

					if (is_dir($strImgFolder)) {
						// 'new' is a folder and already exists, append vmname folder
						$strImgFolder .= preg_replace('((^\.)|\/|(\.$))', '_', $vmname) . '/';
					}

					// create folder if needed
					if (!is_dir($strImgFolder)) {
						#mkdir($strImgFolder, 0777, true);
						my_mkdir($strImgFolder, 0777, true);
						#chown($strImgFolder, 'nobody');
						#chgrp($strImgFolder, 'users');
					}

					$this->set_folder_nodatacow($strImgFolder);

					$strExt = ($disk['driver'] == 'raw') ? 'img' : $disk['driver'];

					$strImgPath = $strImgFolder . 'vdisk' . $diskid . '.' . $strExt;

				} else {
					// 'new' is a file

					// create parent folder if needed
					if (!is_dir($path_parts['dirname'])) {
						#mkdir($path_parts['dirname'], 0777, true);
						my_mkdir($path_parts['dirname'], 0777, true);
						#chown($path_parts['dirname'], 'nobody');
						#chgrp($path_parts['dirname'], 'users');
					}

					$this->set_folder_nodatacow($path_parts['dirname']);

					$strExt = ($disk['driver'] == 'raw') ? 'img' : $disk['driver'];
					$strImgPath = $path_parts['dirname'] . '/vdisk' . $diskid . '.' . $strExt;
				}


				if (is_file($strImgPath)) {
					$json_info = getDiskImageInfo($strImgPath);
					$disk['driver'] = $json_info['format'];
					$return_value = 0;
				} else {
					$strImgRawLocationPath = $strImgPath;
					if (!empty($disk['storage']) && !empty($disk['select']) && $disk['select'] == 'auto' && $disk['storage'] != "default") $disk['select'] = $disk['storage'];
					if (!empty($disk['select']) && (!in_array($disk['select'], ['auto', 'manual'])) && (is_dir('/mnt/'.$disk['select']))) {
						// Force qemu disk creation to happen directly on either cache/disk1/disk2 ect based on dropdown selection
						$strImgRawLocationPath = str_replace('/mnt/user/', '/mnt/'.$disk['select'].'/', $strImgPath);

						// create folder if needed
						$strImgRawLocationParent = dirname($strImgRawLocationPath);
						if (!is_dir($strImgRawLocationParent)) {
							#mkdir($strImgRawLocationParent, 0777, true);
							my_mkdir($strImgRawLocationParent, 0777, true);
							#chown($strImgRawLocationParent, 'nobody');
							#chgrp($strImgRawLocationParent, 'users');
						}

						$this->set_folder_nodatacow($strImgRawLocationParent);
					}

					$strLastLine = exec("qemu-img create -q -f ".escapeshellarg($disk['driver'])." ".escapeshellarg($strImgRawLocationPath)." ".escapeshellarg($disk['size'])." 2>&1", $output, $return_value);

					if (is_file($strImgPath)) {
						chmod($strImgPath, 0777);
						chown($strImgPath, 'nobody');
						chgrp($strImgPath, 'users');
					}
				}

				if ($return_value != 0) {

					// ERROR during image creation, return message to user
					$arrReturn = [
						'error' => "Error creating disk image '" . $strImgPath . "': " . $strLastLine,
						'error_output' => $output
					];

				} else {

					// Success!
					$arrReturn = [
						'image' => $strImgPath,
						'driver' => $disk['driver']
					];
					if (!empty($disk['dev'])) {
						$arrReturn['dev'] = $disk['dev'];
					}
					if (!empty($disk['bus'])) {
						$arrReturn['bus'] = $disk['bus'];
					}
					if (!empty($disk['boot'])) {
						$arrReturn['boot'] = $disk['boot'];
					}
					if (!empty($disk['rotation'])) {
						$arrReturn['rotation'] = $disk['rotation'];
					}
					if (!empty($disk['serial'])) {
						$arrReturn['serial'] = $disk['serial'];
					}
					if (!empty($disk['discard'])) {
						$arrReturn['discard'] = $disk['discard'];
					}

				}
			}

			return $arrReturn;
		}


		function config_to_xml($config,$vmclone = false) {
			$domain = $config['domain'];
			$media = $config['media'];
			$nics = $config['nic'];
			$disks = $config['disk'];
			$usb = $config['usb'];
			$usbopt = $config['usbopt'];
			$usbboot = $config['usbboot'];
			$shares = $config['shares'];
			$gpus = $config['gpu'];
			$pcis = $config['pci'];
			$pciboot = $config['pciboot'];
			$audios = $config['audio'];
			$template = $config['template'];
			$clocks = $config['clock'];
			$evdevs = $config['evdev'];

			$type = $domain['type'];
			$name = $domain['name'];
			$mem = $domain['mem'];
			$maxmem = (!empty($domain['maxmem'])) ? $domain['maxmem'] : $mem;
			$uuid = (!empty($domain['uuid']) ? $domain['uuid'] : $this->domain_generate_uuid());
			$machine = $domain['machine'];
			$machine_type = (stripos($machine, 'q35') !== false ? 'q35' : 'pc');
			$os_type = ((empty($template['os']) || stripos($template['os'], 'windows') === false) ? 'other' : 'windows');
			//$emulator = $this->get_default_emulator();
			$emulator = '/usr/local/sbin/qemu';
			$arch = $domain['arch'];
			$pae = ($arch == 'i686') ? '<pae/>' : '';

			$loader = '';
			$swtpm = '';
			$osbootdev = '';
			if (!empty($domain['ovmf'])) {
			  if ($domain['ovmf'] == 1) {
			  	if (!is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd')) {
			  		// Create a new copy of OVMF VARS for this VM
			  		mkdir('/etc/libvirt/qemu/nvram/', 0777, true);
				  	copy('/usr/share/qemu/ovmf-x64/OVMF_VARS-pure-efi.fd', '/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd');
				  }
				if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd')) {
					// Delete OVMF-TPM VARS for this VM if found
					unlink('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd');
				  }

				  $loader = "<loader readonly='yes' type='pflash'>/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd</loader>
				  			<nvram>/etc/libvirt/qemu/nvram/".$uuid."_VARS-pure-efi.fd</nvram>";
				if ($domain['usbboot'] == 'Yes') $osbootdev = "<boot dev='fd'/>" ;
			  }
			  if ($domain['ovmf'] == 2) {
				  if (!is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd')) {
					  // Create a new copy of OVMF VARS for this VM
  					mkdir('/etc/libvirt/qemu/nvram/', 0777, true);
  					copy('/usr/share/qemu/ovmf-x64/OVMF_VARS-pure-efi-tpm.fd', '/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd');
  				}
				if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd')) {
					// Delete OVMF VARS for this VM if found
					unlink('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd');
				  }

  				$loader = "<loader readonly='yes' type='pflash'>/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi-tpm.fd</loader>
  							<nvram>/etc/libvirt/qemu/nvram/".$uuid."_VARS-pure-efi-tpm.fd</nvram>";

				$swtpm = "<tpm model='tpm-tis'>
  							<backend type='emulator' version='2.0' persistent_state='yes'/>
  						</tpm>";
				if ($domain['usbboot'] == 'Yes') $osbootdev = "<boot dev='fd'/>" ;
	  		}
	  	}


			$metadata = '';
			if (!empty($template)) {
				$metadata .= "<metadata>";
				$template_options = '';
				foreach ($template as $key => $value) {
					$template_options .= $key . "='" . htmlspecialchars($value, ENT_QUOTES | ENT_XML1) . "' ";
				}
				$metadata .= "<vmtemplate xmlns='unraid' " . $template_options . "/>";
				$metadata .= "</metadata>";
			}

			$vcpus = $domain['vcpus'];
			$vcpupinstr = '';

			if (!empty($domain['vcpu']) && is_array($domain['vcpu'])) {
				$vcpus = count($domain['vcpu']);
				foreach($domain['vcpu'] as $i => $vcpu) {
					$vcpupinstr .= "<vcpupin vcpu='$i' cpuset='$vcpu'/>";
				}
			} 
			
			$intCores = $vcpus;
			$intThreads = 1;
			$intCPUThreadsPerCore = 1;

			$cpumode = '';
			$cpucache = '';
			$cpufeatures = '';
			$cpumigrate = '';
			if (!empty($domain['cpumode']) && $domain['cpumode'] == 'host-passthrough') {
				$cpumode .= "mode='host-passthrough'";
				$cpucache = "<cache mode='passthrough'/>";

				// detect if the processor is hyperthreaded:
				$intCPUThreadsPerCore = max(intval(shell_exec('/usr/bin/lscpu | grep \'Thread(s) per core\' | awk \'{print $4}\'')), 1);

				// detect if the processor is AMD + multithreaded, and if so, enable topoext cpu feature
				if ($intCPUThreadsPerCore > 1) {
					$strCPUInfo = file_get_contents('/proc/cpuinfo');
					if (strpos($strCPUInfo, 'AuthenticAMD') !== false) {
						$cpufeatures .= "<feature policy='require' name='topoext'/>";
					}
				}

				// even amount of cores assigned and cpu is hyperthreaded: pass that info along to the cpu section below
				if ($intCPUThreadsPerCore > 1 && ($vcpus % $intCPUThreadsPerCore == 0)) {
					$intCores = $vcpus / $intCPUThreadsPerCore;
					$intThreads = $intCPUThreadsPerCore;
				}

				if (!empty($domain['cpumigrate'])) $cpumigrate = " migratable='".$domain['cpumigrate']."'" ;
			}

			$cpustr = "<cpu $cpumode $cpumigrate>
							<topology sockets='1' cores='{$intCores}' threads='{$intThreads}'/>
							$cpucache
							$cpufeatures
						</cpu>
						<vcpu placement='static'>{$vcpus}</vcpu>
						<cputune>
							$vcpupinstr
						</cputune>";

			$usbmode = 'usb3';
			if (!empty($domain['usbmode'])) {
				$usbmode = $domain['usbmode'];
			}

			$ctrl = '';
			switch ($usbmode) {
				case 'usb3':
					$ctrl = "<controller type='usb' index='0' model='nec-xhci' ports='15'>
								<address type='pci' domain='0x0000' bus='0x00' slot='0x07' function='0x0'/>
							</controller>";
					break;

				case 'usb3-qemu':
					$ctrl = "<controller type='usb' index='0' model='qemu-xhci' ports='15'>
								<address type='pci' domain='0x0000' bus='0x00' slot='0x07' function='0x0'/>
							</controller>";
					break;

				case 'usb2':
					$ctrl = "<controller type='usb' index='0' model='ich9-ehci1'>
								<address type='pci' domain='0x0000' bus='0x00' slot='0x07' function='0x7'/>
							</controller>
							<controller type='usb' index='0' model='ich9-uhci1'>
								<master startport='0'/>
								<address type='pci' domain='0x0000' bus='0x00' slot='0x07' function='0x0' multifunction='on'/>
							</controller>
							<controller type='usb' index='0' model='ich9-uhci2'>
								<master startport='2'/>
								<address type='pci' domain='0x0000' bus='0x00' slot='0x07' function='0x1'/>
							</controller>
							<controller type='usb' index='0' model='ich9-uhci3'>
								<master startport='4'/>
								<address type='pci' domain='0x0000' bus='0x00' slot='0x07' function='0x2'/>
							</controller>";
					break;
			}

			/*
			if ($os_type == "windows") $hypervclock = "<timer name='hypervclock' present='yes'/>" ; else $hypervclock = "" ;

			$clock = "<clock offset='" . $domain['clock'] . "'>
						<timer name='rtc' tickpolicy='catchup'/>
						<timer name='pit' tickpolicy='delay'/>
						<timer name='hpet' present='no'/>
						$hypervclock
					</clock>";

			$hyperv = '';
			if ($domain['hyperv'] == 1 && $os_type == "windows") {
				$hyperv = "<hyperv>
							<relaxed state='on'/>
							<vapic state='on'/>
							<spinlocks state='on' retries='8191'/>
							<vendor_id state='on' value='none'/>
						</hyperv>";

				$clock = "<clock offset='" . $domain['clock'] . "'>
							<timer name='hypervclock' present='yes'/>
							<timer name='hpet' present='no'/>
						</clock>";
			}
			*/

			$clock = "<clock offset='" . $domain['clock'] . "'>" ;
			foreach ($clocks as $clockname => $clockvalues) { 
				switch ($clockname){
					case "rtc":
						if ($clockvalues['present'] == "yes") $clock .= "<timer name='rtc' tickpolicy='{$clockvalues['tickpolicy']}'/>";
						break ;
					case "pit":
						if ($clockvalues['present'] == "yes") $clock .= "<timer name='pit' tickpolicy='{$clockvalues['tickpolicy']}'/>";
						break ;
					case "hpet":
						$clock .= "<timer name='hpet' present='{$clockvalues['present']}'/>" ;
						break ;
					case "hypervclock":
						$clock .= "<timer name='hypervclock' present='{$clockvalues['present']}'/>" ;
						break ;
				}
			}
			$hyperv = "" ;
			if ($domain['hyperv'] == 1 && $os_type == "windows") {
				$hyperv = "<hyperv>
							<relaxed state='on'/>
							<vapic state='on'/>
							<spinlocks state='on' retries='8191'/>
							<vendor_id state='on' value='none'/>
						";

						if ($clocks['hypervclock']['present'] == "yes") 
						$hyperv .= "<vpindex state='on'/><synic state='on'/><stimer state='on'/>" ;
						$hyperv .="</hyperv>";
				#$clock = "<clock offset='" . $domain['clock'] . "'>
				#			<timer name='hypervclock' present='yes'/>
				#			<timer name='hpet' present='no'/>";
			}
			$clock .= "</clock>" ;

			$usbstr = '';
			if (!empty($usb)) {
				foreach($usb as $i => $v){
					if ($vmclone) $usbx = explode(':', $v['id']); else $usbx = explode(':', $v);
					$startupPolicy = '' ;
					if (isset($usbopt[$v]) && !$vmclone ) {
						 if (strpos($usbopt[$v], "#remove") == false) $startupPolicy = 'startupPolicy="optional"' ; 	else  $startupPolicy = '' ;
					}  
					if (isset($v["startupPolicy"]) && $vmclone ) {
						if ($v["startupPolicy"] == "optional" ) $startupPolicy = 'startupPolicy="optional"' ; 	else  $startupPolicy = '' ;
				    } 

					$usbstr .= "<hostdev mode='subsystem' type='usb'>
									<source $startupPolicy>
										<vendor id='0x".$usbx[0]."'/>
										<product id='0x".$usbx[1]."'/>
									</source>" ;
					if (!empty($usbboot[$v]) && !$vmclone ) {
						$usbstr .= "<boot order='".$usbboot[$v]."'/>" ;
						} 	
					if (isset($v["usbboot"]) && $vmclone ) {
						if ($v["usbboot"] != NULL) $usbstr .= "<boot order='".$v["usbboot"]."'/>" ;
						} 			
					$usbstr .= "</hostdev>";
				}
			}

			$arrAvailableDevs = [];
			foreach (range('a', 'z') as $letter) {
				$arrAvailableDevs['hd' . $letter] = 'hd' . $letter;
			}

			$needSCSIController = false;

			//media settings
			$bus = "ide";
			if ($machine_type == 'q35'){
				$bus = "sata";
			}

			$mediastr = '';
			if (!empty($media['cdrom'])) {
				unset($arrAvailableDevs['hda']);
				$cdromboot = $media['cdromboot'] ;
				$media['cdrombus'] = $media['cdrombus'] ?: $bus;
				if ($media['cdrombus'] == 'scsi') {
					$needSCSIController = true;
				}
				if ($cdromboot > 0) {
					$mediaboot = "<boot order='$cdromboot'/>" ;
				}
				$mediastr = "<disk type='file' device='cdrom'>
								<driver name='qemu'/>
								<source file='" . htmlspecialchars($media['cdrom'], ENT_QUOTES | ENT_XML1) . "'/>
								<target dev='hda' bus='" . $media['cdrombus'] . "'/>
								<readonly/>
								$mediaboot
							</disk>";
			}

			$driverstr = '';
			if (!empty($media['drivers']) && $os_type == "windows") {
				unset($arrAvailableDevs['hdb']);
				$media['driversbus'] = $media['driversbus'] ?: $bus;
				if ($media['driversbus'] == 'scsi') {
					$needSCSIController = true;
				}
				$driverstr = "<disk type='file' device='cdrom'>
								<driver name='qemu'/>
								<source file='" . htmlspecialchars($media['drivers'], ENT_QUOTES | ENT_XML1) . "'/>
								<target dev='hdb' bus='" . $media['driversbus'] . "'/>
								<readonly/>
							</disk>";
			}

			//disk settings
			$diskstr = '';
			$diskcount = 0;
			if (!empty($disks)) {

				// force any hard drives to start with hdc, hdd, hde, etc
				unset($arrAvailableDevs['hda']);
				unset($arrAvailableDevs['hdb']);

				foreach ($disks as $i => $disk) {
					if (!empty($disk['image']) | !empty($disk['new']) ) {
						//TODO: check if image/new is a block device
						$diskcount++;

						if (!empty($disk['new'])) {
							if (is_file($disk['new']) || is_block($disk['new'])) {
								$disk['image'] = $disk['new'];
							}
						}

						if (!empty($disk['image'])) {
							if (empty($disk['driver'])) {
								$disk['driver'] = 'raw';

								if (is_file($disk['image'])) {
									$json_info = getDiskImageInfo($disk['image']);
									$disk['driver'] = $json_info['format'];
								}
							}
						} else {
							if (empty($disk['driver'])) {
								$disk['driver'] = 'raw';
							}

							$strImgFolder = $disk['new'];
							$strImgPath = '';

							$path_parts = pathinfo($strImgFolder);
							if (empty($path_parts['extension'])) {
								// 'new' is a folder

								if (substr($strImgFolder, -1) != '/') {
									$strImgFolder .= '/';
								}

								if (is_dir($strImgFolder)) {
									// 'new' is a folder and already exists, append domain name as child folder
									$strImgFolder .= preg_replace('((^\.)|\/|(\.$))', '_', $domain['name']) . '/';
								}

								$strExt = ($disk['driver'] == 'raw') ? 'img' : $disk['driver'];

								$strImgPath = $strImgFolder . 'vdisk' . $diskcount . '.' . $strExt;

							} else {
								// 'new' is a file
								$strImgPath = $strImgFolder;
							}

							if (is_file($strImgPath)) {
								$json_info = getDiskImageInfo($strImgPath);
								$disk['driver'] = $json_info['format'];
							}

							$arrReturn = [
								'image' => $strImgPath,
								'driver' => $disk['driver']
							];
							if (!empty($disk['dev'])) {
								$arrReturn['dev'] = $disk['dev'];
							}
							if (!empty($disk['bus'])) {
								$arrReturn['bus'] = $disk['bus'];
							}

							$disk = $arrReturn;
						}

						$disk['bus'] = $disk['bus'] ?: 'virtio';

						if ($disk['bus'] == 'scsi') {
							$needSCSIController = true;
						}

						if (empty($disk['dev']) || !in_array($disk['dev'], $arrAvailableDevs)) {
							$disk['dev'] = array_shift($arrAvailableDevs);
						}
						unset($arrAvailableDevs[$disk['dev']]);
						$boot = $disk['boot'] ;
						$bootorder = '';
						if ($boot > 0) {
							$bootorder = "<boot order='$boot'/>" ;
						}

						$readonly = '';
						if (!empty($disk['readonly'])) {
							$readonly = '<readonly/>';
						}

						$strDevType = @filetype(realpath($disk['image']));

						if ($disk["serial"] != "") $serial = "<serial>".$disk["serial"]."</serial>" ; else $serial = "" ;

						$rotation_rate = "";
						if ($disk['bus'] == "scsi" || $disk['bus'] == "sata" || $disk['bus'] == "ide" ) {
							if ($disk['rotation']) $rotation_rate = " rotation_rate='1' ";
						}

						if ($strDevType == 'file' || $strDevType == 'block') {
							$strSourceType = ($strDevType == 'file' ? 'file' : 'dev');

							if (isset($disk['discard'])) $strDevUnmap = " discard=\"{$disk['discard']}\" "; else $strDevUnmap = " discard=\"ignore\" ";

							$diskstr .= "<disk type='" . $strDevType . "' device='disk'>
											<driver name='qemu' type='" . $disk['driver'] . "' cache='writeback'".$strDevUnmap."/>
											<source " . $strSourceType . "='" . htmlspecialchars($disk['image'], ENT_QUOTES | ENT_XML1) . "'/>
											<target bus='" . $disk['bus'] . "' dev='" . $disk['dev'] . "' $rotation_rate />
											$bootorder
											$readonly
											$serial
										</disk>";
						}
					}
				}
			}

			$scsicontroller = '';
			if ($needSCSIController) {
				$scsicontroller = "<controller type='scsi' index='0' model='virtio-scsi'/>";
			}

			$netstr = '';
			if (!empty($nics)) {
				foreach ($nics as $i => $nic) {
					if (empty($nic['mac']) || empty($nic['network'])) {
						continue;
					}
					$netmodel = $nic['model'] ?: 'virtio-net';

					$net_res =$this->libvirt_get_net_res($this->conn, $nic['network']);
					exec("ls --indicator-style=none /sys/class/net|grep -Po '^((vir)?br|vhost)[0-9]+(\.[0-9]+)?'",$br);
					if ($nic["boot"] != NULL) $nicboot = "<boot order='".$nic["boot"]."'/>" ; else $nicboot = "" ;
					if ($net_res) {
							$netstr .= "<interface type='network'>
										<mac address='{$nic['mac']}'/>
										<source network='" . htmlspecialchars($nic['network'], ENT_QUOTES | ENT_XML1) . "'/>
										<model type='$netmodel'/>
										$nicboot
									</interface>";
					} elseif (in_array($nic['network'], $br)) {
						if (preg_match('/^(vir)?br/',$nic['network'])) {
							$netstr .= "<interface type='bridge'>
										<mac address='{$nic['mac']}'/>
										<source bridge='" . htmlspecialchars($nic['network'], ENT_QUOTES | ENT_XML1) . "'/>
										<model type='$netmodel'/>
										$nicboot
									</interface>";
						} else {
							$netstr .= "<interface type='direct' trustGuestRxFilters='yes'>
										<mac address='{$nic['mac']}'/>
										<source dev='" . htmlspecialchars($nic['network'], ENT_QUOTES | ENT_XML1) . "' mode='bridge'/>
										<model type='$netmodel'/>
										$nicboot
									</interface>";
						}
					} else {
						continue;
					}
				}
			}

			$sharestr = '';
			$memorybacking = json_decode($domain['memoryBacking'],true);

			if (!empty($shares)) {
				foreach ($shares as $i => $share) {
					if (empty($share['source']) || empty($share['target']) || ($os_type == "windows" && $share["mode"] == "9p")) {
						continue;
					}

					if ($share['mode'] == "virtiofs") {
					if (!isset($memorybacking['source'])) 	$memorybacking['source']["@attributes"]["type"] = "memfd" ;
					if (!isset($memorybacking['access'])) 	$memorybacking['access']["@attributes"]["mode"] = "shared" ;

						$sharestr .=	"<filesystem type='mount' accessmode='passthrough'>
											<driver type='virtiofs' queue='1024' />
											<source dir='" . htmlspecialchars($share['source'], ENT_QUOTES | ENT_XML1) . "'/>
											<target dir='" . htmlspecialchars($share['target'], ENT_QUOTES | ENT_XML1) . "'/>
											<binary path='/usr/libexec/virtiofsd'  xattr='on'>
												<sandbox mode='chroot'/>
												<cache mode='always'/>
											</binary>
										</filesystem>" ;
					} else {
							$sharestr .= "<filesystem type='mount' accessmode='passthrough'>
												<source dir='" . htmlspecialchars($share['source'], ENT_QUOTES | ENT_XML1) . "'/>
												<target dir='" . htmlspecialchars($share['target'], ENT_QUOTES | ENT_XML1) . "'/>
											</filesystem>";
					}
				}
			}

			$pcidevs='';
			$gpudevs_used=[];
			$multidevices = [] ; #Load?
			$vmrc='';
			$channelscopypaste = '';
			if (!empty($gpus)) {
				foreach ($gpus as $i => $gpu) {
					// Skip duplicate video devices
					if (empty($gpu['id']) || in_array($gpu['id'], $gpudevs_used)) {
						continue;
					}
					if ($gpu['id'] == 'nogpu') break;
					if ($gpu['id'] == 'virtual') {
						$strKeyMap = '';
						if (!empty($gpu['keymap'])) {
							if ($gpu['keymap'] != "none")  $strKeyMap = "keymap='" . $gpu['keymap'] . "'";
						}

						$passwdstr = '';
						if (!empty($domain['password'])){
							$passwdstr = "passwd='" . htmlspecialchars($domain['password'], ENT_QUOTES | ENT_XML1) . "'";
						}

						$strModelType = 'qxl';
						if (!empty($gpu['model'])) {
							$strModelType = $gpu['model'];

							if (!empty($domain['ovmf']) && $strModelType == 'vmvga') {
								// OVMF doesn't work with vmvga
								$strModelType = 'qxl';
							}
						}
						if (!empty($gpu['autoport'])) {
							$strAutoport = $gpu['autoport'];
						} else $strAutoport = "yes" ;



						if (!empty($gpu['protocol'])) {
							$strProtocol = $gpu['protocol'];
						} else $strProtocol = "vnc" ;

						if (!empty($gpu['wsport'])) {
							$strWSport = $gpu['wsport'];
						} else $strWSport = "-1" ;

						if (!empty($gpu['port'])) {
							$strPort = $gpu['port'];
						} else $strPort = "-1" ;

						if ($strAutoport == "yes") $strPort = $strWSport = "-1" ;
						if (($gpu['copypaste'] == "yes") && ($strProtocol == "spice")) $vmrcmousemode = "<mouse mode='server'/>" ; else $vmrcmousemode = ""  ;
						if ($strProtocol == "spice")  $virtualaudio = "spice" ; else  $virtualaudio = "none" ;

						$vmrc = "<input type='tablet' bus='usb'/>
								<input type='mouse' bus='ps2'/>
								<input type='keyboard' bus='ps2'/>
								<graphics type='$strProtocol' sharePolicy='ignore' port='$strPort' autoport='$strAutoport' websocket='$strWSport' listen='0.0.0.0' $passwdstr $strKeyMap>
									<listen type='address' address='0.0.0.0'/>
									$vmrcmousemode
								</graphics>
								<video>
									<model type='$strModelType'/>
									<address type='pci' domain='0x0000' bus='0x00' slot='0x1e' function='0x0'/>
								</video>
								<audio id='1' type='$virtualaudio'/>";
							

						if ($gpu['copypaste'] == "yes") {
							if ($strProtocol == "spice") {
								$channelscopypaste = 	"<channel type='spicevmc'>
															<target type='virtio' name='com.redhat.spice.0'/>
														</channel>" ;
							} else {
								$channelscopypaste = 	"<channel type='qemu-vdagent'>
														<source>
								  								<clipboard copypaste='yes'/>
																<mouse mode='client'/>
															</source>
															<target type='virtio' name='com.redhat.spice.0'/>
														</channel>" ;
							}
						} else $channelcopypaste = "";

						continue;
					}

					[$gpu_bus, $gpu_slot, $gpu_function] = my_explode(":", str_replace('.', ':', $gpu['id']), 3);

					$strXVGA = '';
					if (empty($gpudevs_used) && empty($domain['ovmf'])) {
						$strXVGA = " xvga='yes'";
					}

					//HACK: add special address for intel iGPU and remove x-vga attribute
					$strSpecialAddress = '';
					if ($gpu_bus == '00' && $gpu_slot == '02') {
						$strXVGA = '';
						$strSpecialAddress = "<address type='pci' domain='0x0000' bus='0x".$gpu_bus."' slot='0x".$gpu_slot."' function='0x".$gpu_function."'/>";
						if ($gpu_function == '00') {
							$strSpecialAddress = "<address type='pci' domain='0x0000' bus='0x".$gpu_bus."' slot='0x".$gpu_slot."' function='0x".$gpu_function."'/>";
							# Add support for SR-IOV
						} else {
							if ($machine_type == 'q35'){
								$strSpecialAddress = "<address type='pci' domain='0x0000' bus='0x06' slot='0x00' function='0x0'/>";
							} else {
								$strSpecialAddress = "<address type='pci' domain='0x0000' bus='0x06' slot='0x10' function='0x0'/>";
							}
						}
					}

					$strRomFile = '';
					if (!empty($gpu['rom'])) {
						$strRomFile = "<rom file='".$gpu['rom']."'/>";
					}

					if ($gpu['multi'] == "on"){
						$newgpu_bus= 0x07;
						if (!isset($multibus[$newgpu_bus])) {
							$multibus[$newgpu_bus] = 0x07;
						} else {
							#Get next bus
							$newgpu_bus = end($multibus) + 0x01;
							$multibus[$newgpu_bus] = $newgpu_bus;
						}
						if ($machine_type == "pc") $newgpu_slot = "0x01" ; else $newgpu_slot = "0x00" ; 
						$strSpecialAddress = "<address type='pci' domain='0x0000' bus='$newgpu_bus' slot='$newgpu_slot' function='0x".$gpu_function."' multifunction='on' />" ;
						$multidevices[$gpu_bus] = $newgpu_bus ;
					}

					

					$pcidevs .= "<hostdev mode='subsystem' type='pci' managed='yes'".$strXVGA.">
									<driver name='vfio'/>
									<source>
										<address domain='0x0000' bus='0x".$gpu_bus."' slot='0x".$gpu_slot."' function='0x".$gpu_function."'/>
									</source>
									$strSpecialAddress
									$strRomFile
								</hostdev>";

					$gpudevs_used[] = $gpu['id'];
				}
			}
			$audiodevs_used=[];
			if (!empty($audios)) {
				foreach ($audios as $i => $audio) {
					$strSpecialAddressAudio = "" ;
					// Skip duplicate audio devices
					if (empty($audio['id']) || in_array($audio['id'], $audiodevs_used)) {
						continue;
					}

					[$audio_bus, $audio_slot, $audio_function] = my_explode(":", str_replace('.', ':', $audio['id']), 3);
					if ($audio_function != 0) {
						if (isset($multidevices[$audio_bus]))	{
							$newaudio_bus = $multidevices[$audio_bus] ;
							if ($machine_type == "pc") $newaudio_slot = "0x01" ; else $newaudio_slot = "0x00" ; 
							$strSpecialAddressAudio = "<address type='pci' domain='0x0000' bus='$newaudio_bus' slot='$newaudio_slot'  function='0x".$audio_function."' />" ;
						}
					}

					$pcidevs .= "<hostdev mode='subsystem' type='pci' managed='yes'>
									<driver name='vfio'/>
									<source>
										<address domain='0x0000' bus='0x".$audio_bus."' slot='0x".$audio_slot."' function='0x".$audio_function."'/>
									</source>
									$strSpecialAddressAudio
								</hostdev>";

					$audiodevs_used[] = $audio['id'];
				}
			}

			$pcidevs_used=[];
			if (!empty($pcis)) {
				foreach ($pcis as $i => $pci_id) {
					$strSpecialAddressOther = "" ;
					// Skip duplicate other pci devices
					if (empty($pci_id) || in_array($pci_id, $pcidevs_used)) {
						continue;
					}
					if ($vmclone) [$pci_bus, $pci_slot, $pci_function] = my_explode(":", str_replace('.', ':', $pci_id['id']), 3);
					else [$pci_bus, $pci_slot, $pci_function] = my_explode(":", str_replace('.', ':', $pci_id), 3);
					
					if ($pci_function != 0) {
						if (isset($multidevices[$pci_bus]))	{
							$newpci_bus = $multidevices[$pci_bus];
							if ($machine_type == "pc") $newpci_slot = "0x01" ; else $newpci_slot = "0x00" ; 
							$strSpecialAddressOther = "<address type='pci' domain='0x0000' bus='$newpci_bus' slot='$newpci_slot' function='0x".$pci_function."' />" ;
						}
					}

					$pcidevs .= "<hostdev mode='subsystem' type='pci' managed='yes'>
									<driver name='vfio'/>
									<source>
										<address domain='0x0000' bus='0x" . $pci_bus . "' slot='0x" . $pci_slot . "' function='0x" . $pci_function . "'/>
									</source>
									$strSpecialAddressOther " ;

					if (!empty($pciboot[$pci_id]) && !$vmclone) {
						$pcidevs .= "<boot order='".$pciboot[$pci_id]."'/>" ;
					}
					if (!empty($pci_id["boot"]) && $vmclone) {
						$pcidevs .= "<boot order='".$pci_id["boot"]."'/>" ;
					}
					$pcidevs .= "</hostdev>";

					if ($vmclone) $pcidevs_used[] = $pci_id['d']; else $pcidevs_used[] = $pci_id ;
				}
			}

			$memballoon = "<memballoon model='none'/>";
			if (empty( array_filter(array_merge($gpudevs_used, $audiodevs_used, $pcidevs_used), function($k){ return strpos($k,'#remove')===false && $k!='virtual' ; }) )) {
				$memballoon = "<memballoon model='virtio'>
							<alias name='balloon0'/>
						</memballoon>";
			}
			#$osbootdev = "" ;
			$evdevstr = "";
			foreach($evdevs as $evdev) {
				if ($evdev['dev'] == "") continue;
				$evdevstr .= "<input type='evdev'>\n<source dev='{$evdev['dev']}'";
				if  ($evdev['grab'] != "") $evdevstr .= " grab='{$evdev['grab']}' ";
				if  ($evdev['grabToggle'] != "") $evdevstr .= " grabToggle='{$evdev['grabToggle']}' ";
				if  ($evdev['repeat'] != "") $evdevstr .= " repeat='{$evdev['repeat']}' ";
				$evdevstr .= "/>\n</input>\n";
			}

			$memorybackingXML = Array2XML::createXML('memoryBacking', $memorybacking);
			$memoryBackingXML = $memorybackingXML->saveXML($memorybackingXML->documentElement);
			return "<domain type='$type' xmlns:qemu='http://libvirt.org/schemas/domain/qemu/1.0'>
						<uuid>$uuid</uuid>
						<name>$name</name>
						<description>" . htmlspecialchars($domain['desc'], ENT_QUOTES | ENT_XML1) . "</description>
						$metadata
						<currentMemory unit='KiB'>$mem</currentMemory>
						<memory unit='KiB'>$maxmem</memory>
						$cpustr
						$memoryBackingXML
						<os>
							$loader
							<type arch='$arch' machine='$machine'>hvm</type>
							$osbootdev
						</os>
						<features>
							<acpi/>
							<apic/>
							$hyperv
							$pae
						</features>
						$clock
						<on_poweroff>destroy</on_poweroff>
						<on_reboot>restart</on_reboot>
						<on_crash>restart</on_crash>
						<devices>
							<emulator>$emulator</emulator>
							$diskstr
							$mediastr
							$driverstr
							$ctrl
							$sharestr
							$netstr
							$vmrc
							<console type='pty'/>
							$scsicontroller
							$pcidevs
							$usbstr
							<channel type='unix'>
								<target type='virtio' name='org.qemu.guest_agent.0'/>
							</channel>
							$channelscopypaste
							$swtpm
							$memballoon
							$evdevstr
						</devices>
					</domain>";

		}

		function appendqemucmdline($xml,$cmdline) {
			$newxml = $xml ;
			if ($cmdline != null) $newxml = str_replace("</domain>",$cmdline."\n</domain>",$xml) ;
			return $newxml ;
		}

		function domain_new($config) {

			# Set storage for disks.
			foreach ($config['disk'] as $i => $disk) { $config['disk'][$i]['storage'] = $config['template']['storage'];}
			// attempt to create all disk images if needed
			$diskcount = 0;
			if (!empty($config['disk'])) {
				foreach ($config['disk'] as $i => $disk) {
					if (!empty($disk['image']) | !empty($disk['new']) ) {
						$diskcount++;

						$disk = $this->create_disk_image($disk, $config['domain']['name'], $diskcount);

						if (!empty($disk['error'])) {
							$this->last_error = $disk['error'];
							return false;
						}

						$config['disk'][$i] = $disk;
					}
				}
			}

			// generate xml for this domain
			$strXML = $this->config_to_xml($config);
			$qemucmdline = $config['qemucmdline'];
			$strXML = $this->appendqemucmdline($strXML,$qemucmdline) ;


			// Start the VM now if requested
			if (!empty($config['domain']['startnow'])) {
				$tmp = libvirt_domain_create_xml($this->conn, $strXML);
				if (!$tmp)
					return $this->_set_last_error();
			}

			// Define the VM to persist
			if ($config['domain']['persistent']) {
				$tmp = libvirt_domain_define_xml($this->conn, $strXML);
				if (!$tmp)
					return $this->_set_last_error();

				$this->domain_set_autostart($tmp, $config['domain']['autostart'] == 1);
				return $tmp;
			}
			else
				return $tmp;

		}

		function vfio_bind($strPassthruDevice) {
			// Ensure we have leading 0000:
			$strPassthruDeviceShort = str_replace('0000:', '', $strPassthruDevice);
			$strPassthruDeviceLong = '0000:' . $strPassthruDeviceShort;

			// Determine the driver currently assigned to the device
			$strDriverSymlink = @readlink('/sys/bus/pci/devices/' . $strPassthruDeviceLong . '/driver');

			if ($strDriverSymlink !== false) {
				// Device is bound to a Driver already

				if (strpos($strDriverSymlink, 'vfio-pci') !== false) {
					// Driver bound to vfio-pci already - nothing left to do for this device now regarding vfio
					return true;
				}

				// Driver bound to some other driver - attempt to unbind driver
				if (file_put_contents('/sys/bus/pci/devices/' . $strPassthruDeviceLong . '/driver/unbind', $strPassthruDeviceLong) === false) {
					$this->last_error = 'Failed to unbind device ' . $strPassthruDeviceShort . ' from current driver';
					return false;
				}
			}

			// Get Vendor and Device IDs for the passthru device
			$strVendor = file_get_contents('/sys/bus/pci/devices/' . $strPassthruDeviceLong . '/vendor');
			$strDevice = file_get_contents('/sys/bus/pci/devices/' . $strPassthruDeviceLong . '/device');

			// Attempt to bind driver to vfio-pci
			if (file_put_contents('/sys/bus/pci/drivers/vfio-pci/new_id', $strVendor . ' ' . $strDevice) === false) {
				$this->last_error = 'Failed to bind device ' . $strPassthruDeviceShort . ' to vfio-pci driver';
				return false;
			}

			return true;
		}

		function connect($uri = 'null', $login = false, $password = false) {
			if ($login !== false && $password !== false) {
				$this->conn=libvirt_connect($uri, false, [VIR_CRED_AUTHNAME => $login, VIR_CRED_PASSPHRASE => $password]);
			} else {
				$this->conn=libvirt_connect($uri, false);
			}
			if ($this->conn==false)
				return $this->_set_last_error();

			return true;
		}

		function domain_change_boot_devices($domain, $first, $second) {
			$domain = $this->get_domain_object($domain);

			$tmp = libvirt_domain_change_boot_devices($domain, $first, $second);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_screen_dimensions($domain) {
			$dom = $this->get_domain_object($domain);

			$tmp = libvirt_domain_get_screen_dimensions($dom, $this->get_hostname() );
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_send_keys($domain, $keys) {
			$dom = $this->get_domain_object($domain);

			$tmp = libvirt_domain_send_keys($dom, $this->get_hostname(), $keys);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_send_pointer_event($domain, $x, $y, $clicked = 1, $release = false) {
			$dom = $this->get_domain_object($domain);

			$tmp = libvirt_domain_send_pointer_event($dom, $this->get_hostname(), $x, $y, $clicked, $release);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_disk_remove($domain, $dev) {
			$dom = $this->get_domain_object($domain);

			$tmp = libvirt_domain_disk_remove($dom, $dev);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function supports($name) {
			return libvirt_has_feature($name);
		}

		function macbyte($val) {
			if ($val < 16)
				return '0'.dechex($val);

			return dechex($val);
		}

		function generate_random_mac_addr($seed=false) {
			if (!$seed)
				$seed = 1;

			if ($this->get_hypervisor_name() == 'qemu')
				$prefix = '52:54:00';
			else
				$prefix = $this->macbyte(($seed * rand()) % 256).':'.
						  $this->macbyte(($seed * rand()) % 256).':'.
						  $this->macbyte(($seed * rand()) % 256);

			return $prefix.':'.
					$this->macbyte(($seed * rand()) % 256).':'.
					$this->macbyte(($seed * rand()) % 256).':'.
					$this->macbyte(($seed * rand()) % 256);
		}

		function get_connection() {
			return $this->conn;
		}

		function get_hostname() {
			return libvirt_connect_get_hostname($this->conn);
		}

		function get_domain_object($nameRes) {
			if (is_resource($nameRes))
				return $nameRes;

			$dom=libvirt_domain_lookup_by_name($this->conn, $nameRes);
			if (!$dom) {
				$dom=libvirt_domain_lookup_by_uuid_string($this->conn, $nameRes);
				if (!$dom)
					return $this->_set_last_error();
			}

			return $dom;
		}

		function get_xpath($domain, $xpath, $inactive = false) {
			$dom = $this->get_domain_object($domain);
			$flags = 0;
			if ($inactive)
				$flags = VIR_DOMAIN_XML_INACTIVE;

			$tmp = libvirt_domain_xml_xpath($dom, $xpath, $flags);
			if (!$tmp)
				return $this->_set_last_error();

			return $tmp;
		}

		function get_cdrom_stats($domain, $sort=true,$spincheck = false) {
			$unraiddisks = array_merge_recursive(@parse_ini_file('state/disks.ini',true)?:[], @parse_ini_file('state/devs.ini',true)?:[]);
			$dom = $this->get_domain_object($domain);
			$tmp = false;
			$buses =  $this->get_xpath($dom, '//domain/devices/disk[@device="cdrom"]/target/@bus', false);
			$cds =  $this->get_xpath($dom, '//domain/devices/disk[@device="cdrom"]/target/@dev', false);
			$files =  $this->get_xpath($dom, '//domain/devices/disk[@device="cdrom"]/source/@file', false);
			$boot  =  $this->get_xpath($dom, '//domain/devices/disk[@device="cdrom"]/boot/@*', false);

			$ret = [];
			for ($i = 0; $i < $cds['num']; $i++) {
				$spundown = 0;
				$reallocation = null;
				if (isset($files[$i])) $reallocation = trim(get_realvolume($files[$i]));
				if ($spincheck) {
					if (isset($unraiddisks[$reallocation]['spundown']) && $unraiddisks[$reallocation]['spundown'] == 1) $spundown = 1; else $tmp = libvirt_domain_get_block_info($dom, $cds[$i]);
				} else $tmp = libvirt_domain_get_block_info($dom, $cds[$i]);

				if ($tmp) {
					$tmp['bus'] = $buses[$i];
					$tmp["boot order"] = $boot[$i] ?? "";
					$tmp['reallocation'] = $reallocation;
					$tmp['spundown'] = $spundown;
					$ret[] = $tmp;
				}
				else {
					$this->_set_last_error();

					$ret[] = [
						'device' => $cds[$i],
						'file'   => $files[$i],
						'type'   => '-',
						'capacity' => '-',
						'allocation' => '-',
						'physical' => '-',
						'bus' => $buses[$i],
						'reallocation' => $reallocation,
						'spundown' => $spundown
					];
				}
			}

			if ($sort) {
				for ($i = 0; $i < sizeof($ret); $i++) {
					for ($ii = 0; $ii < sizeof($ret); $ii++) {
						if (strcmp($ret[$i]['device'], $ret[$ii]['device']) < 0) {
							$tmp = $ret[$i];
							$ret[$i] = $ret[$ii];
							$ret[$ii] = $tmp;
						}
					}
				}
			}

			unset($buses);
			unset($cds);
			unset($files);

			return $ret;
		}

		function get_disk_stats($domain, $sort=true) {
			$dom = $this->get_domain_object($domain);

			$domainXML = $this->domain_get_xml($dom) ;
			$arrDomain = new SimpleXMLElement($domainXML);
			$arrDomain = $arrDomain->devices->disk;

			$ret = [];
			foreach ($arrDomain as $disk) {
				if ($disk->attributes()->device != "disk") continue ;
				$tmp = libvirt_domain_get_block_info($dom, $disk->target->attributes()->dev);

				if ($tmp) {
					$tmp['bus'] = $disk->target->attributes()->bus->__toString();
					$tmp["boot order"] = $disk->boot->attributes()->order ?? "";
					$tmp["discard"] = $disk->driver->attributes()->discard ?? "ignore";
					$tmp["rotation"] = $disk->target->attributes()->rotation_rate ?? "0";
					$tmp['serial'] = $disk->serial ;

					// Libvirt reports 0 bytes for raw disk images that haven't been
					// written to yet so we just report the raw disk size for now
					if ( !empty($tmp['file']) &&
						 $tmp['type'] == 'raw' &&
						 empty($tmp['physical']) &&
						 is_file($tmp['file']) ) {

						$intSize = filesize($tmp['file']);
						$tmp['physical'] = $intSize;
						$tmp['capacity'] = $intSize;
					}

					$ret[] = $tmp;
				}
				else {
					$this->_set_last_error();

					$ret[] = [
						'device' => $disk->target->attributes()->dev->__toString(),
						'file'   => $disk->source->attributes()->file,
						'type'   => '-',
						'capacity' => '-',
						'allocation' => '-',
						'physical' => '-',
						'bus' =>  $disk->target->attributes()->bus->__toString(),
						'boot order' => $disk->boot->attributes()->order ,
						'rotation' => $disk->target->attributes()->rotation_rate ?? "0",
						'serial' => $disk->serial,
						'discard' => $disk->driver->attributes()->discard ?? "ignore"
					];
				}
			}

			if ($sort) {
				for ($i = 0; $i < sizeof($ret); $i++) {
					for ($ii = 0; $ii < sizeof($ret); $ii++) {
						if (strcmp($ret[$i]['device'], $ret[$ii]['device']) < 0) {
							$tmp = $ret[$i];
							$ret[$i] = $ret[$ii];
							$ret[$ii] = $tmp;
						}
					}
				}
			}

			unset($domainXML);
			unset($arrDomain) ;
			unset($disk) ;
			return $ret;
		}

		function get_domain_type($domain) {
			$dom = $this->get_domain_object($domain);

			$tmp = $this->get_xpath($dom, '//domain/@type', false);
			if ($tmp['num'] == 0)
				return $this->_set_last_error();

			$ret = $tmp[0];
			unset($tmp);

			return $ret;
		}

		function get_domain_emulator($domain) {
			$dom = $this->get_domain_object($domain);

			$tmp =  $this->get_xpath($dom, '//domain/devices/emulator', false);
				if ($tmp['num'] == 0)
					return $this->_set_last_error();

			 $ret = $tmp[0];
			 unset($tmp);

			 return $ret;
		}

		function get_disk_capacity($domain, $physical=false, $disk='*', $unit='?') {
			$dom = $this->get_domain_object($domain);
			$tmp = $this->get_disk_stats($dom);

			$ret = 0;
			for ($i = 0; $i < sizeof($tmp); $i++) {
				if (($disk == '*') || ($tmp[$i]['device'] == $disk))
					if ($physical) {
					    if($tmp[$i]['physical'] == "-") $tmp[$i]['physical'] = "0" ;
						$ret += $tmp[$i]['physical'];
					} else {
					    if($tmp[$i]['capacity'] == "-") $tmp[$i]['capacity'] = "0" ;
						$ret += $tmp[$i]['capacity'];
					}
			}
			unset($tmp);

			return $this->format_size($ret, 0, $unit);
		}

		function get_disk_count($domain) {
			$dom = $this->get_domain_object($domain);
			$tmp = $this->get_disk_stats($dom);
			$ret = sizeof($tmp);
			unset($tmp);

			return $ret;
		}

		function get_disk_fstype($domain) {
			$dom = $this->get_domain_object($domain);
			$tmp = $this->get_disk_stats($dom);
			$dirname = transpose_user_path($tmp[0]['file']);
			$pathinfo = pathinfo($dirname);
			$parent = $pathinfo["dirname"];
			$fstype = strtoupper(trim(shell_exec(" stat -f -c '%T' $parent")));
			if ($fstype != "ZFS") $fstype = "QEMU";
			#if ($fstype != "ZFS" && $fstype != "BTRFS") $fstype = "QEMU";
			unset($tmp);

			return $fstype;
		}

		function format_size($value, $decimals, $unit='?') {
			if ($value == '-')
				return 'unknown';

			/* Autodetect unit that's appropriate */
			if ($unit == '?') {
				/* (1 << 40) is not working correctly on i386 systems */
				if ($value >= 1099511627776)
					$unit = 'T';
				else
				if ($value >= (1 << 30))
					$unit = 'G';
				else
				if ($value >= (1 << 20))
					$unit = 'M';
				else
				if ($value >= (1 << 10))
					$unit = 'K';
				else
					$unit = 'B';
			}

			$unit = strtoupper($unit);

			switch ($unit) {
				case 'T': return number_format($value / (float)1099511627776, $decimals +2).'T';
				case 'G': return number_format($value / (float)(1 << 30), $decimals).'G';
				case 'M': return number_format($value / (float)(1 << 20), $decimals).'M';
				case 'K': return number_format($value / (float)(1 << 10), $decimals).'K';
				case 'B': return $value.'B';
			}

			return false;
		}

		function get_uri() {
			$tmp = libvirt_connect_get_uri($this->conn);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_domain_count() {
			$tmp = libvirt_domain_get_counts($this->conn);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function translate_volume_type($type) {
			if ($type == 1)
				return 'Block device';

			return 'File image';
		}

		function translate_perms($mode) {
			$mode = (string)((int)$mode);

			$tmp = '---------';

			for ($i = 0; $i < 3; $i++) {
				$bits = (int)$mode[$i];
				if ($bits & 4)
					$tmp[ ($i * 3) ] = 'r';
				if ($bits & 2)
					$tmp[ ($i * 3) + 1 ] = 'w';
				if ($bits & 1)
					$tmp[ ($i * 3) + 2 ] = 'x';
			}


			return $tmp;
		}

		function parse_size($size) {
			$unit = $size[ strlen($size) - 1 ];

			$size = (int)$size;
			switch (strtoupper($unit)) {
				case 'T': $size *= 1099511627776;
					  break;
				case 'G': $size *= 1073741824;
					  break;
				case 'M': $size *= 1048576;
					  break;
				case 'K': $size *= 1024;
					  break;
			}

			return $size;
		}

		//create a storage volume and add file extension
		/*function volume_create($name, $capacity, $allocation, $format) {
			$capacity = $this->parse_size($capacity);
			$allocation = $this->parse_size($allocation);
			($format != 'raw' ) ? $ext = $format : $ext = 'img';
			($ext == pathinfo($name, PATHINFO_EXTENSION)) ? $ext = '': $name .= '.';

			$xml = "<volume>\n".
					"   <name>$name$ext</name>\n".
					"   <capacity>$capacity</capacity>\n".
					"   <allocation>$allocation</allocation>\n".
					"   <target>\n".
					"      <format type='$format'/>\n".
					"   </target>\n".
					"</volume>";

			$tmp = libvirt_storagevolume_create_xml($pool, $xml);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}*/

		function get_hypervisor_name() {
			$tmp = libvirt_connect_get_information($this->conn);
			$hv = $tmp['hypervisor'];
			unset($tmp);

			switch (strtoupper($hv)) {
				case 'QEMU': $type = 'qemu';
					break;

				default:
					$type = $hv;
			}

			return $type;
		}

		function get_connect_information() {
			$tmp = libvirt_connect_get_information($this->conn);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_icon_url($domain) {
			global $docroot;

			$strIcon = $this->_get_single_xpath_result($domain, '//domain/metadata/*[local-name()=\'vmtemplate\']/@icon');
			if (empty($strIcon)) {
				$strIcon = ($this->domain_get_clock_offset($domain) == 'localtime' ? 'windows.png' : 'linux.png');
			}

			if (is_file($strIcon)) {
				return $strIcon;
			} elseif (is_file("$docroot/plugins/dynamix.vm.manager/templates/images/" . $strIcon)) {
				return '/plugins/dynamix.vm.manager/templates/images/' . $strIcon;
			} elseif (is_file("$docroot/boot/config/plugins/dynamix.vm.manager/templates/images/" . $strIcon)) {
				return '/boot/config/plugins/dynamix.vm.manager/templates/images/' . $strIcon;
			}

			return '/plugins/dynamix.vm.manager/templates/images/default.png';
		}

		function domain_change_xml($domain, $xml) {
			$dom = $this->get_domain_object($domain);

			if (!($old_xml = $this->domain_get_xml($dom)))
				return $this->_set_last_error();
			if (!libvirt_domain_undefine($dom))
				return $this->_set_last_error();
			if (!libvirt_domain_define_xml($this->conn, $xml)) {
				$this->last_error = libvirt_get_last_error();
				libvirt_domain_define_xml($this->conn, $old_xml);
				return false;
			}

			return true;
		}

		function get_domains() {
			$tmp = libvirt_list_domains($this->conn);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_active_domain_ids() {
			$tmp = libvirt_list_active_domain_ids($this->conn);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_domain_by_name($name) {
			$tmp = @libvirt_domain_lookup_by_name($this->conn, $name);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_node_devices($dev = false) {
			$tmp = ($dev == false) ? libvirt_list_nodedevs($this->conn) : libvirt_list_nodedevs($this->conn, $dev);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_interface_addresses($domain,$flag) {
			$tmp =  libvirt_domain_interface_addresses($domain,$flag);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_node_device_res($res) {
			if ($res == false)
				return false;
			if (is_resource($res))
				return $res;

			$tmp = libvirt_nodedev_get($this->conn, $res);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_node_device_caps($dev) {
			$dev = $this->get_node_device_res($dev);

			$tmp = libvirt_nodedev_capabilities($dev);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_node_device_cap_options() {
			$all = $this->get_node_devices();

			$ret = [];
			for ($i = 0; $i < sizeof($all); $i++) {
				$tmp = $this->get_node_device_caps($all[$i]);

				for ($ii = 0; $ii < sizeof($tmp); $ii++)
					if (!in_array($tmp[$ii], $ret))
						$ret[] = $tmp[$ii];
			}

			return $ret;
		}

		function get_node_device_xml($dev) {
			$dev = $this->get_node_device_res($dev);

			$tmp = libvirt_nodedev_get_xml_desc($dev, NULL);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function get_node_device_information($dev) {
			$dev = $this->get_node_device_res($dev);

			if ($dev) $tmp = libvirt_nodedev_get_information($dev); else $tmp = $dev ;
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_name($res) {
			return libvirt_domain_get_name($res);
		}

		function domain_get_info_call($name = false, $name_override = false) {
			$ret = [];

			if ($name != false) {
				$dom = $this->get_domain_object($name);
				if (!$dom)
					return false;

				if ($name_override)
					$name = $name_override;

				$ret[$name] = libvirt_domain_get_info($dom);
				return $ret;
			}
			else {
				$doms = libvirt_list_domains($this->conn);
				foreach ($doms as $dom) {
					$tmp = $this->get_domain_object($dom);
					$ret[$dom] = libvirt_domain_get_info($tmp);
				}
			}

			ksort($ret);
			return $ret;
		}

		function domain_get_info($name = false, $name_override = false) {
			if (!$name)
				return false;

			if (!$this->allow_cached)
				return $this->domain_get_info_call($name, $name_override);

			$domname = $name_override ? $name_override : $name;
			$dom = $this->get_domain_object($domname);
			$domkey  = $name_override ? $name_override : $this->domain_get_name($dom);
			if (!array_key_exists($domkey, $this->dominfos)) {
				$tmp = $this->domain_get_info_call($name, $name_override);
				$this->dominfos[$domkey] = $tmp[$domname];
			}

			return $this->dominfos[$domkey];
		}

		function get_last_error() {
			return $this->last_error;
		}

		function domain_get_xml($domain, $xpath = NULL) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_get_xml_desc($dom, 0);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_id($domain, $name = false) {
			$dom = $this->get_domain_object($domain);
			if ((!$dom) || (!$this->domain_is_running($dom, $name)))
				return false;

			$tmp = libvirt_domain_get_id($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_interface_stats($domain, $iface) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_interface_stats($dom, $iface);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_interface_devices($res) {
			$tmp = libvirt_domain_get_interface_devices($res);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_interface_addresses($domain,$flag) {
			$tmp =  libvirt_domain_interface_addresses($domain,$flag);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_memory_stats($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_memory_stats($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_all_domain_stats() {
			$tmp = libvirt_connect_get_all_domain_stats($this->conn);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_start($dom) {
			$dom=$this->get_domain_object($dom);
			if ($dom) {
				$ret = libvirt_domain_create($dom);
				$this->last_error = libvirt_get_last_error();
				return $ret;
			}

			$ret = libvirt_domain_create_xml($this->conn, $dom);
			$this->last_error = libvirt_get_last_error();
			return $ret;
		}

		function domain_define($xml, $autostart=false) {
			if (strpos($xml,'<qemu:commandline>') || strpos($xml,'<qemu:override>')) {
				$tmp = explode("\n", $xml);
				for ($i = 0; $i < sizeof($tmp); $i++)
					if (strpos('.'.$tmp[$i], "<domain type='kvm'") || strpos('.'.$tmp[$i], '<domain type="kvm"'))
						$tmp[$i] = "<domain type='kvm' xmlns:qemu='http://libvirt.org/schemas/domain/qemu/1.0'>";
				$xml = join("\n", $tmp);
			}

			if ($autostart) {
				$tmp = libvirt_domain_create_xml($this->conn, $xml);
				if (!$tmp)
					return $this->_set_last_error();
			}

			$tmp = libvirt_domain_define_xml($this->conn, $xml);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_destroy($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_destroy($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_reboot($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_reboot($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_suspend($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_suspend($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_save($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_managedsave($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_resume($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_resume($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_uuid($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_get_uuid_string($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_get_domain_by_uuid($uuid) {
			$dom = libvirt_domain_lookup_by_uuid_string($this->conn, $uuid);
			return ($dom) ? $dom : $this->_set_last_error();
		}

		function domain_get_name_by_uuid($uuid) {
			$dom = $this->domain_get_domain_by_uuid($uuid);
			if (!$dom)
				return false;
			$tmp = libvirt_domain_get_name($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_is_active($domain) {
			$domain = $this->get_domain_object($domain);
			$tmp = libvirt_domain_is_active($domain);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_qemu_agent_command($domain,$cmd,$timeout,$flags) {
			$domain = $this->get_domain_object($domain);
			$tmp = libvirt_domain_qemu_agent_command($domain,$cmd,$timeout,$flags);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function generate_uuid($seed=false) {
			if (!$seed)
				$seed = time();
			srand($seed);

			$ret = [];
			for ($i = 0; $i < 16; $i++)
				$ret[] = $this->macbyte(rand() % 256);

			$a = $ret[0].$ret[1].$ret[2].$ret[3];
			$b = $ret[4].$ret[5];
			$c = $ret[6].$ret[7];
			$d = $ret[8].$ret[9];
			$e = $ret[10].$ret[11].$ret[12].$ret[13].$ret[14].$ret[15];

			return $a.'-'.$b.'-'.$c.'-'.$d.'-'.$e;
		}

		function domain_generate_uuid() {
			$uuid = $this->generate_uuid();

			//while ($this->domain_get_name_by_uuid($uuid))
				//$uuid = $this->generate_uuid();

			return $uuid;
		}

		function domain_shutdown($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = libvirt_domain_shutdown($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_undefine($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$uuid = $this->domain_get_uuid($dom);
			// remove OVMF VARS if this domain had them
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd')) {
				unlink('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd');
			}
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd')) {
				unlink('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd');
			}

			$tmp = libvirt_domain_undefine($dom);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		function domain_delete($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;
			$disks = $this->get_disk_stats($dom);
			$tmp = $this->domain_undefine($dom);
			if (!$tmp)
				return $this->_set_last_error();

			// remove the first disk only
			if (array_key_exists('file', $disks[0])) {
				$disk = $disks[0]['file'];
				$pathinfo = pathinfo($disk);
				$dir = $pathinfo['dirname'];

				// remove the vm config
				$cfg_vm = $dir.'/'.$domain.'.cfg';
				if (is_file($cfg_vm)) unlink($cfg_vm);

				$cfg = $dir.'/'.$pathinfo['filename'].'.cfg';
				$xml = $dir.'/'.$pathinfo['filename'].'.xml';
				if (is_file($disk)) unlink($disk);
				if (is_file($cfg)) unlink($cfg);
				if (is_file($xml)) unlink($xml);
				if (is_dir($dir) && $this->is_dir_empty($dir)) {
					$result= my_rmdir($dir);
					if ($result['type'] == "zfs") {
						qemu_log("$domain","delete empty zfs $dir {$result['rtncode']}");
						if (isset($result['dataset'])) qemu_log("$domain","dataset {$result['dataset']} ");
						if (isset($result['cmd'])) qemu_log("$domain","Command {$result['cmd']} ");
						if (isset($result['output'])) {
							$outputlogs = implode(" ",$result['output']);
							qemu_log("$domain","Output $outputlogs end");
						}
					}
					else qemu_log("$domain","delete empty $dir {$result['rtncode']}");				
				} 
			}

			return true;
		}

		function nvram_backup($uuid) {
			// move OVMF VARS to a backup file if this domain has them
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd')) {
				rename('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd', '/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd_backup');
				return true;
			}
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd')) {
				rename('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd', '/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd_backup');
				return true;
			}

			return false;
		}

		function nvram_restore($uuid) {
			// restore backup OVMF VARS if this domain had them
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd_backup')) {
				rename('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd_backup', '/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd');
				return true;
			}
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd_backup')) {
				rename('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd_backup', '/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd');
				return true;
			}

			return false;
		}

		function nvram_rename($uuid,$newuuid) {
			// rename backup OVMF VARS if this domain had them
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd')) {
				rename('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd_backup', '/etc/libvirt/qemu/nvram/'.$newuuid.'_VARS-pure-efi.fd');
				return true;
			}
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd')) {
				rename('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd_backup', '/etc/libvirt/qemu/nvram/'.$newuuid.'_VARS-pure-efi-tpm.fd');
				return true;
			}

			return false;
		}

		function nvram_create_snapshot($uuid,$snapshotname) {
			// snapshot backup OVMF VARS if this domain had them
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd')) {
				copy('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd', '/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi.fd');
				return true;
			}
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd')) {
				copy('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd', '/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi-tpm.fd');
				return true;
			}
			return false;
		}

		function nvram_revert_snapshot($uuid,$snapshotname) {
			// snapshot backup OVMF VARS if this domain had them
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi.fd')) {
				copy('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi.fd', '/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi.fd');
				unlink('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi.fd') ;
				return true;
			}
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi-tpm.fd')) {
				copy('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi-tpm.fd', '/etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd');
				unlink('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi-tpm.fd') ;
				return true;
			}
			return false;
		}

		function nvram_delete_snapshot($uuid,$snapshotname) {
			// snapshot backup OVMF VARS if this domain had them
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi.fd')) {
				unlink('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi.fd') ;
				return true;
			}
			if (is_file('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi-tpm.fd')) {
				unlink('/etc/libvirt/qemu/nvram/'.$uuid.$snapshotname.'_VARS-pure-efi-tpm.fd') ;
				return true;
			}
			return false;
		}


		function is_dir_empty($dir) {
			if (!is_readable($dir)) return NULL;
			  $handle = opendir($dir);
			while (false !== ($entry = readdir($handle))) {
				if ($entry != "." && $entry != "..") {
					return FALSE;
				}
			}
			return TRUE;
		}

		function domain_is_running($domain, $name = false) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$tmp = $this->domain_get_info( $domain, $name );
			if (!$tmp)
				return $this->_set_last_error();
			$ret = ( ($tmp['state'] == VIR_DOMAIN_RUNNING) || ($tmp['state'] == VIR_DOMAIN_BLOCKED) || ($tmp['state'] == 7 /*VIR_DOMAIN_PMSUSPENDED*/) );
			unset($tmp);
			return $ret;
		}

		function domain_get_state($domain) {
			$dom = $this->get_domain_object($domain);
			if (!$dom)
				return false;

			$info = libvirt_domain_get_info($dom);
			if (!$info)
				return $this->_set_last_error();

			return $this->domain_state_translate($info['state']);
		}

		function domain_state_translate($state) {
			switch ($state) {
				case VIR_DOMAIN_RUNNING:  return 'running';
				case VIR_DOMAIN_NOSTATE:  return 'nostate';
				case VIR_DOMAIN_BLOCKED:  return 'blocked';
				case VIR_DOMAIN_PAUSED:   return 'paused';
				case VIR_DOMAIN_SHUTDOWN: return 'shutdown';
				case VIR_DOMAIN_SHUTOFF:  return 'shutoff';
				case VIR_DOMAIN_CRASHED:  return 'crashed';
				//VIR_DOMAIN_PMSUSPENDED is 7 (not defined in libvirt-php yet)
				case 7:  return 'pmsuspended';
			}

			return 'unknown';
		}

		function domain_get_vnc_port($domain) {
			$tmp = $this->get_xpath($domain, '//domain/devices/graphics/@port', false);
			$var = (int)$tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_vmrc_autoport($domain) {
			$tmp = $this->get_xpath($domain, '//domain/devices/graphics/@autoport', false);
			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_vmrc_protocol($domain) {
			$tmp = $this->get_xpath($domain, '//domain/devices/graphics/@type', false);
			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_vnc_model($domain) {
			$tmp = $this->get_xpath($domain, '//domain/devices/video/model/@type', false);
			if (!$tmp)
				return 'qxl';

			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_vnc_keymap($domain) {
			$tmp = $this->get_xpath($domain, '//domain/devices/graphics/@keymap', false);
			if (!$tmp)
				return 'none';

			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_vnc_password($domain) {
			$domain_name = $this->domain_get_name($domain) ;
			$password = shell_exec("cat /etc/libvirt/qemu/'{$domain_name}.xml' | grep 'passwd'") ;

			if (!$password)
				return '';

			$strpos = strpos($password, "passwd=") +8 ;
			$endpos = strpos($password, "'",$strpos) ;
			$password = substr($password,$strpos, $endpos-$strpos) ;

			return $password ;
		}

		function domain_get_ws_port($domain) {
			$tmp = $this->get_xpath($domain, '//domain/devices/graphics/@websocket', false);
			$var = (int)$tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_arch($domain) {
			$tmp = $this->get_xpath($domain, '//domain/os/type/@arch', false);
			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_machine($domain) {
			$tmp = $this->get_xpath($domain, '//domain/os/type/@machine', false);
			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_description($domain) {
			$tmp = $this->get_xpath($domain, '//domain/description', false);
			$var = $tmp[0] ?? "";
			unset($tmp);

			return $var;
		}

		function domain_get_clock_offset($domain) {
			$tmp = $this->get_xpath($domain, '//domain/clock/@offset', false);
			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_cpu_type($domain) {
			$tmp = $this->get_xpath($domain, '//domain/cpu/@mode', false);
			if (!$tmp)
				return 'emulated';

			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_cpu_migrate($domain) {
			$tmp = $this->get_xpath($domain, '//domain/cpu/@migratable', false);
			if (!$tmp)
				return 'no';

			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_vcpu($domain) {
			$tmp = $this->get_xpath($domain, '//domain/vcpu', false);
			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_vcpu_pins($domain) {
			$tmp = $this->get_xpath($domain, '//domain/cputune/vcpupin/@cpuset', false);
			if (!$tmp)
				return false;

			$devs = [];
			for ($i = 0; $i < $tmp['num']; $i++)
				$devs[] = $tmp[$i];

			return $devs;
		}

		function domain_get_memory($domain) {
			$tmp = $this->get_xpath($domain, '//domain/memory', false);
			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_current_memory($domain) {
			$tmp = $this->get_xpath($domain, '//domain/currentMemory', false);
			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		function domain_get_feature($domain, $feature) {
			$tmp = $this->get_xpath($domain, '//domain/features/'.$feature.'/..', false);
			$ret = ($tmp != false);
			unset($tmp);

			return $ret;
		}

		function domain_get_boot_devices($domain) {
			$tmp = $this->get_xpath($domain, '//domain/os/boot/@dev', false);
			if (!$tmp)
				return false;

			$devs = [];
			for ($i = 0; $i < $tmp['num']; $i++)
				$devs[] = $tmp[$i];

			return $devs;
		}

		function domain_get_mount_filesystems($domain) {
			$ret = [];
			$strXML = $this->domain_get_xml($domain) ;
			$xml = new SimpleXMLElement($strXML);
			$FS=$xml->xpath('//domain/devices/filesystem[@type="mount"]') ;
			foreach($FS as $FSD){
					$target=$FSD->target->attributes()->dir ;
					$source=$FSD->source->attributes()->dir ;
					$mode=$FSD->driver->attributes()->type ;
					$ret[] = [
						'source' => $source,
						'target' => $target ,
						'mode' => $mode
						];
			}
			return $ret;
		}

		function _get_single_xpath_result($domain, $xpath) {
			$tmp = $this->get_xpath($domain, $xpath, false);
			if (!$tmp)
				return false;

			if ($tmp['num'] == 0)
				return false;

			return $tmp[0];
		}

		function domain_get_ovmf($domain) {
			return $this->_get_single_xpath_result($domain, '//domain/os/loader');
		}

		function domain_get_multimedia_device($domain, $type, $display=false) {
			$domain = $this->get_domain_object($domain);

			if ($type == 'console') {
				$type = $this->_get_single_xpath_result($domain, '//domain/devices/console/@type');
				$targetType = $this->_get_single_xpath_result($domain, '//domain/devices/console/target/@type');
				$targetPort = $this->_get_single_xpath_result($domain, '//domain/devices/console/target/@port');

				if ($display)
					return $type.' ('.$targetType.' on port '.$targetPort.')';
				else
					return ['type' => $type, 'targetType' => $targetType, 'targetPort' => $targetPort];
			}
			else
			if ($type == 'input') {
				$type = $this->_get_single_xpath_result($domain, '//domain/devices/input/@type');
				$bus  = $this->_get_single_xpath_result($domain, '//domain/devices/input/@bus');

				if ($display)
					return $type.' on '.$bus;
				else
					return ['type' => $type, 'bus' => $bus];
			}
			else
			if ($type == 'graphics') {
				$type = $this->_get_single_xpath_result($domain, '//domain/devices/graphics/@type');
				$port = $this->_get_single_xpath_result($domain, '//domain/devices/graphics/@port');
				$autoport = $this->_get_single_xpath_result($domain, '//domain/devices/graphics/@autoport');

				if ($display)
					return $type.' on port '.$port.' with'.($autoport ? '' : 'out').' autoport enabled';
				else
					return ['type' => $type, 'port' => $port, 'autoport' => $autoport];
			}
			else
			if ($type == 'video') {
				$type  = $this->_get_single_xpath_result($domain, '//domain/devices/video/model/@type');
				$vram  = $this->_get_single_xpath_result($domain, '//domain/devices/video/model/@vram');
				$heads = $this->_get_single_xpath_result($domain, '//domain/devices/video/model/@heads');

				if ($display)
					return $type.' with '.($vram / 1024).' MB VRAM, '.$heads.' head(s)';
				else
					return ['type' => $type, 'vram' => $vram, 'heads' => $heads];
			}
			else
				return false;
		}

		function domain_get_host_devices_pci($domain) {
			$devs = [];

			$res = $this->get_domain_object($domain);
			$strDOMXML = $this->domain_get_xml($res);
			$xmldoc = new DOMDocument();
			$xmldoc->loadXML($strDOMXML);
			$xpath = new DOMXPath($xmldoc);
			$objNodes = $xpath->query('//domain/devices/hostdev[@type="pci"]');
			if ($objNodes->length > 0) {
				foreach ($objNodes as $objNode) {
					$dom  = $xpath->query('source/address/@domain', $objNode)->Item(0)->nodeValue;
					$bus  = $xpath->query('source/address/@bus', $objNode)->Item(0)->nodeValue;
					$rotation  = $xpath->query('target/address/@rotation_rate', $objNode)->Item(0)->nodeValue;
					$slot = $xpath->query('source/address/@slot', $objNode)->Item(0)->nodeValue;
					$func = $xpath->query('source/address/@function', $objNode)->Item(0)->nodeValue;
					$rom = $xpath->query('rom/@file', $objNode);
					$rom = ($rom->length > 0 ? $rom->Item(0)->nodeValue : '');
					$boot =$xpath->query('boot/@order', $objNode)->Item(0)->nodeValue;
					$devid = str_replace('0x', '', 'pci_'.$dom.'_'.$bus.'_'.$slot.'_'.$func);
					$tmp2 = $this->get_node_device_information($devid);
					$guest["multi"] = $xpath->query('address/@multifunction', $objNode)->Item(0)->nodeValue ? "on" : "off" ;
					$guest["dom"]  = $xpath->query('address/@domain', $objNode)->Item(0)->nodeValue;
					$guest["bus"]  = $xpath->query('address/@bus', $objNode)->Item(0)->nodeValue;
					$guest["slot"] = $xpath->query('address/@slot', $objNode)->Item(0)->nodeValue;
					$guest["func"] = $xpath->query('address/@function', $objNode)->Item(0)->nodeValue;
					$devs[] = [
						'domain' => $dom,
						'bus' => $bus,
						'slot' => $slot,
						'func' => $func,
						'id' => str_replace('0x', '', $bus.':'.$slot.'.'.$func),
						'vendor' => $tmp2['vendor_name'],
						'vendor_id' => $tmp2['vendor_id'],
						'product' => $tmp2['product_name'],
						'product_id' => $tmp2['product_id'],
						'boot' => $boot,
						'rotation' => $rotation,
						'rom' => $rom,
						'guest' => $guest
					];
				}
			}

			// Get any pci devices contained in the qemu args
			$args = $this->get_xpath($domain, '//domain/*[name()=\'qemu:commandline\']/*[name()=\'qemu:arg\']/@value', false);
			if (isset($args['num'])) {
				for ($i = 0; $i < $args['num']; $i++) {
					if (strpos($args[$i], 'vfio-pci') !== 0) {
						continue;
					}

					$arg_list = explode(',', $args[$i]);

					foreach ($arg_list as $arg) {
						$keypair = explode('=', $arg);

						if ($keypair[0] == 'host' && !empty($keypair[1])) {
							$devid = 'pci_0000_' . str_replace([':', '.'], '_', $keypair[1]);
							$tmp2 = $this->get_node_device_information($devid);
							[$bus, $slot, $func] = my_explode(":", str_replace('.', ':', $keypair[1]), 3);
							$devs[] = [
								'domain' => '0x0000',
								'bus' => '0x' . $bus,
								'slot' => '0x' . $slot,
								'func' => '0x' . $func,
								'id' => $keypair[1],
								'vendor' => $tmp2['vendor_name'],
								'vendor_id' => $tmp2['vendor_id'],
								'product' => $tmp2['product_name'],
								'product_id' => $tmp2['product_id']
							];
							break;
						}
					}
				}
			}

			return $devs;
		}

		function _lookup_device_usb($vendor_id, $product_id) {
			$tmp = $this->get_node_devices(false);
			for ($i = 0; $i < sizeof($tmp); $i++) {
				$tmp2 = $this->get_node_device_information($tmp[$i]);
				if (array_key_exists('product_id', $tmp2)) {
					if (($tmp2['product_id'] == $product_id)
						&& ($tmp2['vendor_id'] == $vendor_id))
							return $tmp2;
				}
			}

			return false;
		}

		function domain_get_host_devices_usb($domain) {
			$xpath = '//domain/devices/hostdev[@type="usb"]/source/';

			$vid = $this->get_xpath($domain, $xpath.'vendor/@id', false);
			$pid = $this->get_xpath($domain, $xpath.'product/@id', false);

			$devs = [];
			if (isset($vid['num'])) {
				for ($i = 0; $i < $vid['num']; $i++) {
					$dev = $this->_lookup_device_usb($vid[$i], $pid[$i]);
					$devs[] = [
						'id' => str_replace('0x', '', $vid[$i] . ':' . $pid[$i]),
						'vendor_id' => $vid[$i],
						'product_id' => $pid[$i],
						'product' => $dev['product_name'],
						'vendor' => $dev['vendor_name']
					];
				}
			}

			return $devs;
		}

		function domain_get_host_devices($domain) {
			$domain = $this->get_domain_object($domain);

			$devs_pci = $this->domain_get_host_devices_pci($domain);
			$devs_usb = $this->domain_get_host_devices_usb($domain);

			return ['pci' => $devs_pci, 'usb' => $devs_usb];
		}

		function get_nic_info($domain) {
			$macs = $this->get_xpath($domain, "//domain/devices/interface/mac/@address", false);

			if (!$macs)
				return $this->_set_last_error();
			$ret = [];
			for ($i = 0; $i < $macs['num']; $i++) {
				$net = $this->get_xpath($domain, "//domain/devices/interface/mac[@address='$macs[$i]']/../source/@*", false);
				$model = $this->get_xpath($domain, "//domain/devices/interface/mac[@address='$macs[$i]']/../model/@type", false);
				$boot = $this->get_xpath($domain, "//domain/devices/interface/mac[@address='$macs[$i]']/../boot/@order", false);

				if(empty($macs[$i]) && empty($net[0])) {
					$this->_set_last_error();
					continue;
				}
				$ret[] = [
					'mac' => $macs[$i],
					'network' => $net[0],
					'model' => $model[0],
					'boot' => $boot[0] ?? ""
				];
			}

			return $ret;
		}

		function domain_set_feature($domain, $feature, $val) {
			$domain = $this->get_domain_object($domain);

			if ($this->domain_get_feature($domain, $feature) == $val)
				return true;

			$xml = $this->domain_get_xml($domain);
			if ($val) {
				if (strpos('features', $xml))
					$xml = str_replace('<features>', "<features>\n<$feature/>", $xml);
				else
					$xml = str_replace('</os>', "</os><features>\n<$feature/></features>", $xml);
			}
			else
				$xml = str_replace("<$feature/>\n", '', $xml);

			return $this->domain_define($xml);
		}

		function domain_set_clock_offset($domain, $offset) {
			$domain = $this->get_domain_object($domain);

			if (($old_offset = $this->domain_get_clock_offset($domain)) == $offset)
				return true;

			$xml = $this->domain_get_xml($domain);
			$xml = str_replace("<clock offset='$old_offset'/>", "<clock offset='$offset'/>", $xml);

			return $this->domain_define($xml);
		}

		//change vpus for domain
		function domain_set_vcpu($domain, $vcpu) {
			$domain = $this->get_domain_object($domain);

			if (($old_vcpu = $this->domain_get_vcpu($domain)) == $vcpu)
				return true;

			$xml = $this->domain_get_xml($domain);
			$xml = str_replace("$old_vcpu</vcpu>", "$vcpu</vcpu>", $xml);

			return $this->domain_define($xml);
		}

		//change memory for domain
		function domain_set_memory($domain, $memory) {
			$domain = $this->get_domain_object($domain);
			if (($old_memory = $this->domain_get_memory($domain)) == $memory)
				return true;

			$xml = $this->domain_get_xml($domain);
			$xml = str_replace("$old_memory</memory>", "$memory</memory>", $xml);

			return $this->domain_define($xml);
		}

		//change memory for domain
		function domain_set_current_memory($domain, $memory) {
			$domain = $this->get_domain_object($domain);
			if (($old_memory = $this->domain_get_current_memory($domain)) == $memory)
				return true;

			$xml = $this->domain_get_xml($domain);
			$xml = str_replace("$old_memory</currentMemory>", "$memory</currentMemory>", $xml);

			return $this->domain_define($xml);
		}

		//change domain disk dev name
		function domain_set_disk_dev($domain, $olddev, $dev) {
			$domain = $this->get_domain_object($domain);

			$xml = $this->domain_get_xml($domain);
			$tmp = explode("\n", $xml);
			for ($i = 0; $i < sizeof($tmp); $i++)
				if (strpos('.'.$tmp[$i], "<target dev='".$olddev))
					$tmp[$i] = str_replace("<target dev='".$olddev, "<target dev='".$dev, $tmp[$i]);

			$xml = join("\n", $tmp);

			return $this->domain_define($xml);
		}

		//set domain description
		function domain_set_description($domain, $desc) {
			$domain = $this->get_domain_object($domain);

			$description = $this->domain_get_description($domain);
			if ($description == $desc)
				return true;

			$xml = $this->domain_get_xml($domain);
			if (!$description)
				$xml = str_replace("</uuid>", "</uuid><description>$desc</description>", $xml);
			else {
				$tmp = explode("\n", $xml);
				for ($i = 0; $i < sizeof($tmp); $i++)
					if (strpos('.'.$tmp[$i], '<description'))
						$tmp[$i] = "<description>$desc</description>";

				$xml = join("\n", $tmp);
			}

			return $this->domain_define($xml);
		}

		//create metadata node for domain
		function domain_set_metadata($domain) {
			$domain = $this->get_domain_object($domain);

			$xml = $this->domain_get_xml($domain);
			$metadata = $this->get_xpath($domain, '//domain/metadata', false);
			if (empty($metadata)){
				$description = $this->domain_get_description($domain);
				if(!$description)
					$node = "</uuid>";
				else
					$node = "</description>";
				$desc = "$node\n<metadata>\n<snapshots/>\n</metadata>";
				$xml = str_replace($node, $desc, $xml);
			}
			return $this->domain_define($xml);
		}

		//set description for snapshot
		function snapshot_set_metadata($domain, $name, $desc) {
			$this->domain_set_metadata($domain);
			$domain = $this->get_domain_object($domain);

			$xml = $this->domain_get_xml($domain);
			$metadata = $this->get_xpath($domain, '//domain/metadata/snapshot'.$name, false);
			if (empty($metadata)){
				$desc = "<metadata>\n<snapshot$name>$desc</snapshot$name>\n";
				$xml = str_replace('<metadata>', $desc, $xml);
			} else {
				$tmp = explode("\n", $xml);
				for ($i = 0; $i < sizeof($tmp); $i++)
					if (strpos('.'.$tmp[$i], '<snapshot'.$name))
						$tmp[$i] = "<snapshot$name>$desc</snapshot$name>";

				$xml = join("\n", $tmp);
			}
			return $this->domain_define($xml);
		}

		//get host node info
		function host_get_node_info() {
			$tmp = libvirt_node_get_info($this->conn);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		//get domain autostart status true or false
		function domain_get_autostart($domain) {
			$domain = $this->get_domain_object($domain);
			$tmp = libvirt_domain_get_autostart($domain);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		//set domain to start with libvirt
		function domain_set_autostart($domain,$flags) {
			$domain = $this->get_domain_object($domain);
			$tmp = libvirt_domain_set_autostart($domain,$flags);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		//list all snapshots for domain
		function domain_snapshots_list($domain) {
			$tmp = libvirt_list_domain_snapshots($domain);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		//list all snapshots for domain
		function domain_snapshot_get_xml($domain) {
			$tmp = libvirt_domain_snapshot_get_xml($domain);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		// create a snapshot and metadata node for description
		function domain_snapshot_create($domain) {
			$this->domain_set_metadata($domain);
			$domain = $this->get_domain_object($domain);
			$tmp = libvirt_domain_snapshot_create($domain);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		//delete snapshot and metadata
		function domain_snapshot_delete($domain, $name, $flags=0) {
			$name = $this->domain_snapshot_lookup_by_name($domain, $name);
			$tmp = libvirt_domain_snapshot_delete($name,$flags);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		//get resource number of snapshot
		function domain_snapshot_lookup_by_name($domain, $name) {
			$domain = $this->get_domain_object($domain);
			$tmp = libvirt_domain_snapshot_lookup_by_name($domain, $name);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		//revert domain to snapshot state
		function domain_snapshot_revert($domain, $name) {
			$name = $this->domain_snapshot_lookup_by_name($domain, $name);
			$tmp = libvirt_domain_snapshot_revert($name);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		//get snapshot description
		function domain_snapshot_get_info($domain, $name) {
			$domain = $this->get_domain_object($domain);
			$tmp = $this->get_xpath($domain, '//domain/metadata/snapshot'.$name, false);
			$var = $tmp[0];
			unset($tmp);

			return $var;
		}

		//remove snapshot metadata
		function snapshot_remove_metadata($domain, $name) {
			$domain = $this->get_domain_object($domain);

			$xml = $this->domain_get_xml($domain);
			$tmp = explode("\n", $xml);
			for ($i = 0; $i < sizeof($tmp); $i++)
				if (strpos('.'.$tmp[$i], '<snapshot'.$name))
					$tmp[$i] = null;

			$xml = join("\n", $tmp);

			return $this->domain_define($xml);
		}

		//change cdrom media
		function domain_change_cdrom($domain, $iso, $dev, $bus) {
			$domain = $this->get_domain_object($domain);
			$tmp = libvirt_domain_update_device($domain, "<disk type='file' device='cdrom'><driver name='qemu' type='raw'/><source file=".escapeshellarg($iso)."/><target dev='$dev' bus='$bus'/><readonly/></disk>", VIR_DOMAIN_DEVICE_MODIFY_CONFIG);
			if ($this->domain_is_active($domain))
				libvirt_domain_update_device($domain, "<disk type='file' device='cdrom'><driver name='qemu' type='raw'/><source file=".escapeshellarg($iso)."/><target dev='$dev' bus='$bus'/><readonly/></disk>", VIR_DOMAIN_DEVICE_MODIFY_LIVE);
			return ($tmp) ? $tmp : $this->_set_last_error();
		}

		//change disk capacity
		/*function disk_set_cap($disk, $cap) {
			$xml = $this->domain_get_xml($domain);
			$tmp = explode("\n", $xml);
			for ($i = 0; $i < sizeof($tmp); $i++)
				if (strpos('.'.$tmp[$i], "<target dev='".$olddev))
					$tmp[$i] = str_replace("<target dev='".$olddev, "<target dev='".$dev, $tmp[$i]);

			$xml = join("\n", $tmp);

			return $this->domain_define($xml);
		}*/

		//change domain boot device
		function domain_set_boot_device($domain, $bootdev) {
			$xml = $this->domain_get_xml($domain);
			$tmp = explode("\n", $xml);
			for ($i = 0; $i < sizeof($tmp); $i++)
				if (strpos('.'.$tmp[$i], "<boot dev="))
					$tmp[$i] = "<boot dev='$bootdev'/>";

			$xml = join("\n", $tmp);

			return $this->domain_define($xml);
		}

		function libvirt_get_net_res($conn, $net) {
			return libvirt_network_get($conn, $net);
		}

		function libvirt_get_net_list($conn, $opt=VIR_NETWORKS_ALL) {
			// VIR_NETWORKS_{ACTIVE|INACTIVE|ALL}
			return libvirt_list_networks($conn, $opt);
		}

		function libvirt_get_net_xml($res, $xpath=NULL) {
			return libvirt_network_get_xml_desc($res, $xpath);
		}
	}
?>
