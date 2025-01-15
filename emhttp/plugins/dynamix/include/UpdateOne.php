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
$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/webGui/include/Helpers.php";

/* add translations */
$_SERVER['REQUEST_URI'] = 'settings';
require_once "$docroot/webGui/include/Translations.php";

$vcpus = $map = $changes = [];

$data = json_decode($_POST['data'], true);

function decode($data) {
	return str_replace('%2e', '.', urldecode($data));
}
foreach (array_map('decode', explode(';', $data['names'])) as $name) $map[$name] = '';

foreach ($data['cpus'] as $key => $val) {
	if ($val != 'on') continue;
	[$name, $cpu] = my_explode(':', $key);
	$map[decode($name)] .= "$cpu,";
}
foreach ($data['cores'] as $name => $val) {
	$vcpu[decode($name)] .= $val;
}
/* map holds the list of each vm, container or isolcpus and its newly proposed cpu assignments */
$map = array_map(function($d) {
	return substr($d, 0, -1);
}, $map);

switch ($data['id']) {
	case 'vm':
		/* report changed vms in temporary file */
		require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";
		foreach ($map as $name => $cpuset) {
			$uuid = $lv->domain_get_uuid($lv->get_domain_by_name($name));
			$cfg = domain_to_config($uuid);
			if ($cfg['domain']['vcpu']) $cpus = implode(',', $cfg['domain']['vcpu']); else $cpus = "";
			if (!strlen($cpuset)) {
				$cpuset = -1 * $vcpu[$name];
				$cpus = -1 * $cfg['domain']['vcpus'];
			}
			/* only act on changes */
			if ($cpus != $cpuset || strlen($cpus) != strlen($cpuset)) {
				$changes[] = $name;
				/* used by UpdateTwo.php to read new assignments */
				file_put_contents_atomic("/var/tmp/$name.tmp", $cpuset);
			}
		}
		$reply = ['success' => (count($changes) ? implode(';', $changes) : '')];
		break;
	case 'ct':
		/* update the XML file of the container */
		require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
		$DockerClient = new DockerClient();
		$DockerTemplates = new DockerTemplates();
		$containers = $DockerClient->getDockerContainers();
		foreach ($map as $name => $cpuset) {
			/* set full path of template file */
			$file = $DockerTemplates->getUserTemplate($name);
			$xml = simplexml_load_file($file);
			if ($xml->CPUset) {
				/* update node */
				if ($xml->CPUset != $cpuset || strlen($xml->CPUset) != strlen($cpuset)) $xml->CPUset = $cpuset;
			} else {
				/* add node */
				$xml->addChild('CPUset', $cpuset);
			}
			/* only act on changes */
			foreach ($containers as $ct) if ($ct['Name'] == $name) break;
			if ($ct['CPUset'] != $cpuset || strlen($ct['CPUset']) != strlen($cpuset)) {
				$changes[] = $name;
				/* used by UpdateTwo.php to read new assignments */
				file_put_contents_atomic($file, $xml->saveXML());
				exec("sed -ri 's/^(<CPUset)/  \\1/;s/><(\\/Container)/>\\n  <\\1/' \"$file\""); /* aftercare */
			}
		}
		$reply = ['success' => (count($changes) ? implode(';', $changes) : '')];
		break;
	case 'is':
		/* report changed isolcpus in temporary file */
		foreach ($map as $name => $isolcpu) {
			file_put_contents_atomic("/var/tmp/$name.tmp", $isolcpu);
			$changes[] = $name;
		}
		$reply = ['success' => (count($changes) ? implode(';', $changes) : '')];
		break;
}
/* signal changes */
header('Content-Type: application/json');
die(json_encode($reply));
?>
