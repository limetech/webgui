<?php
/* Copyright 2005-2023, Lime Technology
 * Copyright 2012-2023, Bergware International.
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
$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');

require_once "$docroot/webGui/include/Wrappers.php";

/* add translations */
$_SERVER['REQUEST_URI'] = 'settings';
require_once "$docroot/webGui/include/Translations.php";

function scan($line, $text) {
	return stripos($line ?? '', $text) !== false;
}

$reply = [];
$name = urldecode($_POST['name']);
switch ($_POST['id']) {
case 'vm':
	/* Update VM */
	require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";

	/* Path to the temporary file containing new CPU assignments */
	$file = "/var/tmp/$name.tmp";
	if (!file_exists($file)) {
		$reply = ['error' => "File: '$file' not found"];
		break;
	}

	/* Read new CPU assignments and delete the temporary file */
	$cpuset = explode(',', file_get_contents($file));
	unlink($file);
	$nopin = false;
	if ($cpuset[0] >= 0) $vcpus = count($cpuset); else {$vcpus = -1 * $cpuset[0]; $nopin = true; }

	/* Initial cores/threads assignment */
	$cores = $vcpus;
	$threads = 1;
	$ht = exec("lscpu|grep -Po '^Thread\\(s\\) per core:\\s+\\K\\d+'") ?: 1; /* Fetch hyperthreading */

	/* Adjust for hyperthreading */
	if ($vcpus > $ht && $vcpus % $ht === 0) {
		$cores /= $ht;
		$threads = $ht;
	}

	/* Get the UUID of the VM */
	$uuid = $lv->domain_get_uuid($lv->get_domain_by_name($name));
	$dom = $lv->domain_get_domain_by_uuid($uuid);
	$auto = $lv->domain_get_autostart($dom) == 1;

	/* Load the VM's XML configuration */
	$xml = simplexml_load_string($lv->domain_get_xml($dom));

	/* Update topology and vpcus in the XML configuration */
	$xml->cpu->topology['cores'] = $cores;
	$xml->cpu->topology['threads'] = $threads;
	$xml->vcpu = $vcpus;

	/* Preserve existing emulatorpin attributes */
	$pin = [];
	if (isset($xml->cputune)) { 
		foreach ($xml->cputune->emulatorpin->attributes() as $key => $value) {
			$pin[$key] = (string) $value;
		}
	}
	unset($xml->cputune);

	/* Add new cputune configuration */
	if (!$nopin) {
		$xml->addChild('cputune');
		for ($i = 0; $i < $vcpus; $i++) {
			$vcpu = $xml->cputune->addChild('vcpupin');
			$vcpu['vcpu'] = $i;
			$vcpu['cpuset'] = _var($cpuset, $i);
		}
		if ($pin) {
			$attr = $xml->cputune->addChild('emulatorpin');
			foreach ($pin as $key => $value) {
				$attr[$key] = $value;
			}
		}
	}

	/* Stop the running VM first if it is running */
	$running = $lv->domain_get_state($dom) == 'running';
	if ($running) {
		$lv->domain_shutdown($dom);
		for ($n = 0; $n < 30; $n++) { /* Allow up to 30s for VM to shutdown */
			sleep(1);
			if ($stopped = $lv->domain_get_state($dom) == 'shutoff') {
				break;
			}
		}
	} else {
		$stopped = true;
	}

	/* If the VM failed to stop, return an error */
	if (!$stopped) {
		$reply = ['error' => _('Failed to stop') . " '$name'"];
		break;
	}

	/* Backup NVRAM, undefine the domain, and restore NVRAM */
	$lv->nvram_backup($uuid);
	#$lv->domain_undefine($dom);
	$lv->nvram_restore($uuid);

	/* Define the domain with the updated XML configuration */
	if (!$lv->domain_define($xml->saveXML())) {
		$reply = ['error' => $lv->get_last_error()];
		break;
	}

	/* Set autostart for the domain */
	$lv->domain_set_autostart($dom, $auto);

	/* If the VM was running before, start it again */
	if ($running && !$lv->domain_start($dom)) {
		$reply = ['error' => $lv->get_last_error()];
	} else {
		$reply = ['success' => $name];
	}
	break;

case 'ct':
	/* update docker container */
	require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
	$DockerClient = new DockerClient();
	$DockerTemplates = new DockerTemplates();

	/* get available networks */
	$subnet = DockerUtil::network(DockerUtil::custom());

	/* get full template path */
	$xml = $DockerTemplates->getUserTemplate($name);
	list($cmd, $ct, $repository) = xmlToCommand($xml);
	$imageID = $DockerClient->getImageID($repository);

	/* pull image */
	$container = $DockerClient->getContainerDetails($ct);

	/* determine if the container is still running */
	if (!empty($container) && !empty($container['State']) && !empty($container['State']['Running'])) {
		/* since container was already running, put it back it to a running state after update */
		$cmd = str_replace('/docker create ', '/docker run -d ', $cmd);

		/* attempt graceful stop of container first */
		$DockerClient->stopContainer($ct);
	}

	/* force kill container if still running after time-out */
	$DockerClient->removeContainer($ct);
	execCommand($cmd, false);
	$DockerClient->flushCaches();
	$newImageID = $DockerClient->getImageID($repository);

	/* remove old orphan image since it's no longer used by this container */
	if ($imageID && $imageID != $newImageID) {
		$DockerClient->removeImage($imageID);
	}
	$reply = ['success' => $name];
	break;

case 'is':
 	/* Path to the temporary file containing new isolcpus settings */
	$file = "/var/tmp/$name.tmp";
 	$isolcpus = file_get_contents($file);
	if ($isolcpus != '') {
		/* Convert isolcpus string to an array of numbers and sort them */
		$numbers = explode(',', $isolcpus);
		sort($numbers, SORT_NUMERIC);
		/* Initialize variables for range conversion */
		$isolcpus = $previous = array_shift($numbers);
		$range = false;
		/* Convert sequential numbers to a range */
		foreach ($numbers as $number) {
			if ($number == $previous + 1) {
				$range = true;
			} else {
				if ($range) {
					$isolcpus .= '-' . $previous;
					$range = false;
				}
				$isolcpus .= ',' . $number;
			}
			$previous = $number;
		}
		if ($range) {
			$isolcpus .= '-' . $previous;
		}
		/* Format isolcpus string for configuration */
		$isolcpus = "isolcpus=$isolcpus";
	}
	if (is_file('/boot/syslinux/syslinux.cfg')) {
		/* Path to syslinux configuration file */
		$cfg = '/boot/syslinux/syslinux.cfg';
		/* Read the syslinux configuration file into an array, ignoring empty lines */
		$bootcfg = file($cfg, FILE_IGNORE_NEW_LINES + FILE_SKIP_EMPTY_LINES);
		$size = count($bootcfg);
		$make = false;
		/* Remove the temporary file */
		unlink($file);
		$i = 0;
		while ($i < $size) {
			/* Find sections in syslinux config and exclude safemode */
			if (scan($bootcfg[$i], 'label ') && !scan($bootcfg[$i], 'safe mode') && !scan($bootcfg[$i], 'safemode')) {
				$n = $i + 1;
				/* Find the current requested setting */
				while ($n < $size && !scan($bootcfg[$n], 'label ')) {
					if (scan($bootcfg[$n], 'append ')) {
						$cmd = preg_split('/\s+/', trim($bootcfg[$n]));
						/* Replace an existing isolcpus setting */
						for ($c = 1; $c < count($cmd); $c++) {
							if (scan($cmd[$c], 'isolcpus')) {
								$make |= ($cmd[$c] != $isolcpus);
								$cmd[$c] = $isolcpus;
								break;
							}
						}
						/* Or insert a new isolcpus setting if not found */
						if ($c == count($cmd) && $isolcpus) {
								array_splice($cmd, -1, 0, $isolcpus);
							$make = true;
						}
						/* Update the syslinux configuration line */
						$bootcfg[$n] = '  ' . str_replace('  ', ' ', implode(' ', $cmd));
					}
					$n++;
				}
				$i = $n - 1;
			}
			$i++;
		}
	} elseif (is_file('/boot/grub/grub.cfg')) {
		/* Path to grub configuration file */
		$cfg = '/boot/grub/grub.cfg';
		/* Read the grub configuration file into an array, ignoring empty lines */
		$bootcfg = file($cfg, FILE_IGNORE_NEW_LINES+FILE_SKIP_EMPTY_LINES);
		$size = count($bootcfg);
		$make = false;
		/* Remove the temporary file */
		unlink($file);
		$i = 0;
		while ($i < $size) {
			// find sections and exclude safemode/memtest
			if (scan($bootcfg[$i],'menuentry ') && !scan($bootcfg[$i],'safe mode') && !scan($bootcfg[$i],'safemode') && !scan($bootcfg[$i],'memtest')) {
				$n = $i + 1;
				// find the current requested setting
				while (!scan($bootcfg[$n],'menuentry ') && $n < $size) {
					if (scan($bootcfg[$n],'linux ')) {
						$cmd = preg_split('/\s+/',trim($bootcfg[$n]));
						/* Replace an existing isolcpus setting */
						for ($c = 1; $c < count($cmd); $c++) {
							if (scan($cmd[$c], 'isolcpus')) {
								$make |= ($cmd[$c] != $isolcpus);
								$cmd[$c] = $isolcpus;
								break;
							}
						}
						/* Or insert a new isolcpus setting if not found */
						if ($c == count($cmd) && $isolcpus) {
							$cmd[] = $isolcpus;
							$make = true;
						}
						/* Update the grub configuration line */
						$bootcfg[$n] = '  ' . str_replace('  ', ' ', implode(' ', $cmd));
					}
					$n++;
				}
				$i = $n - 1;
			}
			$i++;
		}
	}
	/* Write the updated configuration back to the file if changes were made */
	if ($make) {
		file_put_contents_atomic($cfg, implode("\n", $bootcfg) . "\n");
	}
	$reply = ['success' => $name];
	break;
}
header('Content-Type: application/json');
echo json_encode($reply);
exit;
?>
