<?PHP
/* Copyright 2005-2021, Lime Technology
 * Copyright 2014-2021, Guilherme Jardim, Eric Schultz, Jon Panozzo.
 * Copyright 2012-2021, Bergware International.
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
libxml_use_internal_errors(true); # Suppress any warnings from xml errors.

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

// add translations
if (substr($_SERVER['REQUEST_URI'],0,7) != '/Docker') {
  $_SERVER['REQUEST_URI'] = 'docker';
  require_once "$docroot/webGui/include/Translations.php";
}

require_once "$docroot/plugins/dynamix.docker.manager/include/Helpers.php";

$dockerManPaths = [
	'autostart-file' => "/var/lib/docker/unraid-autostart",
	'update-status'  => "/var/lib/docker/unraid-update-status.json",
	'templates-user' => "/boot/config/plugins/dockerMan/templates-user",
	'templates-usb'  => "/boot/config/plugins/dockerMan/templates",
	'images'         => "/var/lib/docker/unraid/images",
	'user-prefs'     => "/boot/config/plugins/dockerMan/userprefs.cfg",
	'plugin'         => "$docroot/plugins/dynamix.docker.manager",
	'images-ram'     => "$docroot/state/plugins/dynamix.docker.manager/images",
	'webui-info'     => "$docroot/state/plugins/dynamix.docker.manager/docker.json"
];

// load network variables if needed.
if (!isset($eth0)) extract(parse_ini_file("$docroot/state/network.ini",true));
$host = $eth0['IPADDR:0'] ?? '0.0.0.0';

// get network drivers
$driver = DockerUtil::driver();

// Docker configuration file - guaranteed to exist
$docker_cfgfile = '/boot/config/docker.cfg';
$defaults = @parse_ini_file("$docroot/plugins/dynamix.docker.manager/default.cfg") ?: [];
$dockercfg = array_replace_recursive($defaults, parse_ini_file($docker_cfgfile));

function var_split($item, $i=0) {
	return explode(' ',$item)[$i];
}

#######################################
##       DOCKERTEMPLATES CLASS       ##
#######################################

class DockerTemplates {
	public $verbose = false;

	private function debug($m) {
		if ($this->verbose) echo $m."\n";
	}

	private function removeDir($path) {
		if (is_dir($path)) {
			$files = array_diff(scandir($path), ['.', '..']);
			foreach ($files as $file) {
				$this->removeDir(realpath($path).'/'.$file);
			}
			return rmdir($path);
		} elseif (is_file($path)) return unlink($path);
		return false;
	}

	public function download_url($url, $path='') {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, 45);
		curl_setopt($ch, CURLOPT_ENCODING, "");
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_REFERER, "");
		$out = curl_exec($ch) ?: false;
		curl_close($ch);
		if ($path && $out) file_put_contents($path,$out); elseif ($path) @unlink($path);
		return $out;
	}

	public function listDir($root, $ext=null) {
		$iter = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator($root,
						RecursiveDirectoryIterator::SKIP_DOTS),
						RecursiveIteratorIterator::SELF_FIRST,
						RecursiveIteratorIterator::CATCH_GET_CHILD);
		$paths = [];
		foreach ($iter as $path => $fileinfo) {
			$fext = $fileinfo->getExtension();
			if ($ext && $ext != $fext) continue;
			if (substr(basename($path),0,1) == ".")  continue;
			if ($fileinfo->isFile()) $paths[] = ['path' => $path, 'prefix' => basename(dirname($path)), 'name' => $fileinfo->getBasename(".$fext")];
		}
		return $paths;
	}

	public function getTemplates($type) {
		global $dockerManPaths;
		$tmpls = $dirs = [];
		switch ($type) {
		case 'all':
			$dirs[] = $dockerManPaths['templates-user'];
			$dirs[] = $dockerManPaths['templates-usb'];
			break;
		case 'user':
			$dirs[] = $dockerManPaths['templates-user'];
			break;
		case 'default':
			$dirs[] = $dockerManPaths['templates-usb'];
			break;
		default:
			$dirs[] = $type;
		}
		foreach ($dirs as $dir) {
			if (!is_dir($dir)) @mkdir($dir, 0755, true);
			$tmpls = array_merge($tmpls, $this->listDir($dir, 'xml'));
		}
		array_multisort(array_column($tmpls,'name'), SORT_NATURAL|SORT_FLAG_CASE, $tmpls);
		return $tmpls;
	}

	public function getTemplateValue($Repository, $field, $scope='all',$name='') {
		foreach ($this->getTemplates($scope) as $file) {
			$doc = new DOMDocument();
			$doc->load($file['path']);
			$TemplateRepository = DockerUtil::ensureImageTag($doc->getElementsByTagName('Repository')->item(0)->nodeValue);
			if ( $name )
				if ($doc->getElementsByTagName('Name')->item(0)->nodeValue !== $name)
					continue;
			if ($Repository == $TemplateRepository) {
				$TemplateField = $doc->getElementsByTagName($field)->item(0)->nodeValue;
				return trim($TemplateField);
			}
		}
		return null;
	}
	
	public function getUserTemplate($Container) {
		foreach ($this->getTemplates('user') as $file) {
			$doc = new DOMDocument('1.0', 'utf-8');
			$doc->load($file['path']);
			$Name = $doc->getElementsByTagName('Name')->item(0)->nodeValue;
			if ($Name==$Container) return $file['path'];
		}
		return false;
	}

	private function getControlURL(&$ct, $myIP) {
		global $host;
		$port = &$ct['Ports'][0];
		$myIP = $myIP ?: $this->getTemplateValue($ct['Image'], 'MyIP') ?: ($ct['NetworkMode']=='host'||$port['NAT'] ? $host : ($port['IP'] ?: DockerUtil::myIP($ct['Name'])));
		$WebUI = preg_replace("%\[IP\]%", $myIP, $this->getTemplateValue($ct['Image'], 'WebUI'));
		if (preg_match("%\[PORT:(\d+)\]%", $WebUI, $matches)) {
			$ConfigPort = $matches[1];
			foreach ($ct['Ports'] as $port) {
				if ($port['NAT'] && $port['PrivatePort']==$ConfigPort) {$ConfigPort = $port['PublicPort']; break;}
			}
			$WebUI = preg_replace("%\[PORT:\d+\]%", $ConfigPort, $WebUI);
		}
		return $WebUI;
	}

	public function getAllInfo($reload=false,$com=true) {
		global $dockerManPaths, $host;
		$DockerClient = new DockerClient();
		$DockerUpdate = new DockerUpdate();
		//$DockerUpdate->verbose = $this->verbose;
		$info = DockerUtil::loadJSON($dockerManPaths['webui-info']);
		$autoStart = array_map('var_split', @file($dockerManPaths['autostart-file'], FILE_IGNORE_NEW_LINES) ?: []);
		foreach ($DockerClient->getDockerContainers() as $ct) {
			$name = $ct['Name'];
			$image = $ct['Image'];
			$tmp = &$info[$name] ?? [];
			$tmp['running'] = $ct['Running'];
			$tmp['paused'] = $ct['Paused'];
			$tmp['autostart'] = in_array($name, $autoStart);
			$tmp['cpuset'] = $ct['CPUset'];
			// read docker label for WebUI & Icon
			if ($ct['Url'] && !$tmp['url']) $tmp['url'] = $ct['Url'];
			if ($ct['Icon']) $tmp['icon'] = $ct['Icon'];
			if (!is_file($tmp['icon']) || $reload) $tmp['icon'] = $this->getIcon($image,$name);
			if ($ct['Running']) {
				$port = &$ct['Ports'][0];
				$ip = ($ct['NetworkMode']=='host'||$port['NAT'] ? $host : $port['IP']);
				$tmp['url'] = $ip ? (strpos($tmp['url'],$ip)!==false ? $tmp['url'] : $this->getControlURL($ct, $ip)) : $tmp['url'];
				$tmp['shell'] = $tmp['shell'] ?? $this->getTemplateValue($image, 'Shell');
			}
			$tmp['registry'] = $tmp['registry'] ?? $this->getTemplateValue($image, 'Registry');
			$tmp['Support'] = $tmp['Support'] ?? $this->getTemplateValue($image, 'Support');
			$tmp['Project'] = $tmp['Project'] ?? $this->getTemplateValue($image, 'Project');
			if (!$tmp['updated'] || $reload) {
				if ($reload) $DockerUpdate->reloadUpdateStatus($image);
				$tmp['updated'] = var_export($DockerUpdate->getUpdateStatus($image),true);
			}
			if (!$com) $tmp['updated'] = 'undef';
			if (!$tmp['template'] || $reload) $tmp['template'] = $this->getUserTemplate($name);
			if ($reload) $DockerUpdate->updateUserTemplate($name);
			//$this->debug("\n$name");
			//foreach ($tmp as $c => $d) $this->debug(sprintf('   %-10s: %s', $c, $d));
		}
		DockerUtil::saveJSON($dockerManPaths['webui-info'], $info);
		return $info;
	}

	public function getIcon($Repository,$contName) {
		global $docroot, $dockerManPaths;
		$imgUrl = $this->getTemplateValue($Repository, 'Icon','all',$contName);
		if (!$imgUrl) return '';

		$imageName = $contName ?: $name;
		$iconRAM = sprintf('%s/%s-%s.png', $dockerManPaths['images-ram'], $contName, 'icon');
		$icon    = sprintf('%s/%s-%s.png', $dockerManPaths['images'], $contName, 'icon');
		
		if (!is_dir(dirname($iconRAM))) mkdir(dirname($iconRAM), 0755, true);
		if (!is_dir(dirname($icon))) mkdir(dirname($icon), 0755, true);

		if (!is_file($iconRAM)) {
			if (!is_file($icon)) $this->download_url($imgUrl, $icon);
			@copy($icon, $iconRAM);
		}
		if (!is_file($icon) && is_file($iconRAM)) {
			@copy($iconRAM,$icon);
		}
		return (is_file($iconRAM)) ? str_replace($docroot, '', $iconRAM) : '';
	}
}

####################################
##       DOCKERUPDATE CLASS       ##
####################################

class DockerUpdate{
	public $verbose = false;

	private function debug($m) {
		if ($this->verbose) echo $m."\n";
	}

	private function xml_encode($string) {
		return htmlspecialchars($string, ENT_XML1, 'UTF-8');
	}

	private function xml_decode($string) {
		return strval(html_entity_decode($string, ENT_XML1, 'UTF-8'));
	}

	public function download_url($url, $path='') {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, 45);
		curl_setopt($ch, CURLOPT_ENCODING, "");
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_REFERER, "");
		$out = curl_exec($ch) ?: false;
		curl_close($ch);
		if ($path && $out) file_put_contents($path,$out); elseif ($path) @unlink($path);
		return $out;
	}

	public function download_url_and_headers($url, $headers=[], $path='') {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, 45);
		curl_setopt($ch, CURLOPT_ENCODING, "");
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_REFERER, "");
		$out = curl_exec($ch) ?: false;
		curl_close($ch);
		if ($path && $out) file_put_contents($path,$out); elseif ($path) @unlink($path);
		return $out;
	}

	// DEPRECATED: Only used for Docker Index V1 type update checks
	public function getRemoteVersion($image) {
		[$strRepo, $strTag] = array_pad(explode(':', DockerUtil::ensureImageTag($image)),2,'');
		$apiUrl = sprintf('http://index.docker.io/v1/repositories/%s/tags/%s', $strRepo, $strTag);
		//$this->debug("API URL: $apiUrl");
		$apiContent = $this->download_url($apiUrl);
		return ($apiContent===false) ? null : substr(json_decode($apiContent, true)[0]['id'], 0, 8);
	}

	public function getRemoteVersionV2($image) {
		[$strRepo, $strTag] = array_pad(explode(':', DockerUtil::ensureImageTag($image)),2,'');
		/*
		 * Step 1: Check whether or not the image is in a private registry, get corresponding auth data and generate manifest url
		 */
		$DockerClient = new DockerClient();
		$registryAuth = $DockerClient->getRegistryAuth($image);
		$manifestURL = sprintf('%s%s%s/manifests/%s', $registryAuth['apiUrl'], $registryAuth['repository'], $registryAuth['imageName'], $registryAuth['imageTag']);
		//$this->debug('Manifest URL: '.$manifestURL);
		$ch = getCurlHandle($manifestURL, 'HEAD');
		$reply = curl_exec($ch);
		if (curl_errno($ch) !== 0) {
			//$this->debug('Error: curl error getting manifest: '.curl_error($ch));
			return null;
		}
		/*
		 * Step 2: Get www-authenticate header from manifest url to generate token or basic auth
		 */
		$digest_ch = getCurlHandle($manifestURL, 'HEAD', $header);
		$header = ['Accept: application/vnd.docker.distribution.manifest.list.v2+json,application/vnd.docker.distribution.manifest.v2+json'];
		preg_match('@www-authenticate:\s*Bearer\s*(.*)@i', $reply, $matches);
		if (!empty($matches[1])) {
			$strArgs = explode(',', $matches[1]);
			$args = [];
			foreach ($strArgs as $arg) {
				$arg = explode('=', $arg);
				$args[$arg[0]] = trim($arg[1], "\" \r\n");
			}
			if (empty($args['realm']) || empty($args['service']) || empty($args['scope'])) return null;
			$url = $args['realm'].'?service='.urlencode($args['service']).'&scope='.urlencode($args['scope']);
			//$this->debug('Token URL: '.$url);
			/**
			 * Step 2a: Get token from API and authenticate via username / password if in private registry and auth data was found
			 */
			$ch = getCurlHandle($url);
			if (!empty($registryAuth['password'])) {
				curl_setopt($ch, CURLOPT_USERPWD, $registryAuth['username'].':'.$registryAuth['password']);
			}
			$reply = curl_exec($ch);
			if (curl_errno($ch) !== 0) {
				//$this->debug('Error: curl error getting token: '.curl_error($ch));
				return null;
			}
			$reply_json = json_decode($reply, true);
			if (!$reply_json || empty($reply_json['token'])) {
				//$this->debug('Error: Token response was empty or missing token');
				return null;
			}
			$token = $reply_json['token'];
			array_push($header, 'Authorization: Bearer '.$token);
		}
		if (preg_match('@www-authenticate:\s*Basic\s*@i', $reply)) {
			if (!empty($registryAuth['password'])) curl_setopt($digest_ch, CURLOPT_USERPWD, $registryAuth['username'].':'.$registryAuth['password']);
			else return null;
		}
		/**
		 * Step 3: Get Docker-Content-Digest header from manifest file
		 */
		curl_setopt($digest_ch, CURLOPT_HTTPHEADER, $header);
		$reply = curl_exec($digest_ch);
		if (curl_errno($ch) !== 0) {
			//$this->debug('Error: curl error getting manifest: '.curl_error($ch));
			return null;
		}
		preg_match('@Docker-Content-Digest:\s*(.*)@i', $reply, $matches);
		if (empty($matches[1])) {
			//$this->debug('Error: Docker-Content-Digest header is empty or missing');
			return null;
		}
		$digest = trim($matches[1]);
		//$this->debug('Remote Digest: '.$digest);
		return $digest;
	}

	// DEPRECATED: Only used for Docker Index V1 type update checks
	public function getLocalVersion($image) {
		$DockerClient = new DockerClient();
		return substr($DockerClient->getImageID($image), 0, 8);
	}

	public function getUpdateStatus($image) {
		global $dockerManPaths;
		$image = DockerUtil::ensureImageTag($image);
		$updateStatus = DockerUtil::loadJSON($dockerManPaths['update-status']);
		if (isset($updateStatus[$image])) {
			if (isset($updateStatus[$image]['status']) && $updateStatus[$image]['status']=='undef') return null;
			if ($updateStatus[$image]['local'] || $updateStatus[$image]['remote']) return ($updateStatus[$image]['local']==$updateStatus[$image]['remote']);
		}
		return null;
	}

	public function reloadUpdateStatus($image=null) {
		global $dockerManPaths;
		$DockerClient = new DockerClient();
		$updateStatus = DockerUtil::loadJSON($dockerManPaths['update-status']);
		$images = $image ? [DockerUtil::ensureImageTag($image)] : array_map(function($ar){return $ar['Tags'][0];}, $DockerClient->getDockerImages());
		foreach ($images as $img) {
			$localVersion = null;
			if (!empty($updateStatus[$img]) && array_key_exists('local', $updateStatus[$img])) {
				$localVersion = $updateStatus[$img]['local'];
			}
			if ($localVersion === null) {
				$localVersion = $this->inspectLocalVersion($img);
			}
			$remoteVersion = $this->getRemoteVersionV2($img);
			$status = ($localVersion && $remoteVersion) ? (($remoteVersion == $localVersion) ? 'true' : 'false') : 'undef';
			$updateStatus[$img] = ['local' => $localVersion, 'remote' => $remoteVersion, 'status' => $status];
			//$this->debug("Update status: Image='$img', Local='$localVersion', Remote='$remoteVersion', Status='$status'");
		}
		DockerUtil::saveJSON($dockerManPaths['update-status'], $updateStatus);
	}

	public function inspectLocalVersion($image) {
		$DockerClient = new DockerClient();
		$inspect      = $DockerClient->getDockerJSON('/images/'.$image.'/json');
		if (empty($inspect['RepoDigests'])) return null;

		$shaPos = strpos($inspect['RepoDigests'][0], '@sha256:');
		if ($shaPos === false) return null;

		return substr($inspect['RepoDigests'][0], $shaPos + 1);
	}

	public function setUpdateStatus($image, $version) {
		global $dockerManPaths;
		$image = DockerUtil::ensureImageTag($image);
		$updateStatus = DockerUtil::loadJSON($dockerManPaths['update-status']);
		$updateStatus[$image] = ['local' => $version, 'remote' => $version, 'status' => 'true'];
		//$this->debug("Update status: Image='$image', Local='$version', Remote='$version', Status='true'");
		DockerUtil::saveJSON($dockerManPaths['update-status'], $updateStatus);
	}

	public function updateUserTemplate($Container) {
		$changed = false;
		$DockerTemplates = new DockerTemplates();
		$validElements = ['Support', 'Overview', 'Category', 'Project', 'Icon'];
		$validAttributes = ['Name', 'Default', 'Description', 'Display', 'Required', 'Mask'];
		// Get user template file and abort if fail
		if (!$file = $DockerTemplates->getUserTemplate($Container)) {
			//$this->debug("User template for container '$Container' not found, aborting.");
			return null;
		}
		// Load user template XML, verify if it's valid and abort if doesn't have TemplateURL element
		$template = simplexml_load_file($file);
		if (empty($template->TemplateURL)) {
			//$this->debug("Template doesn't have TemplateURL element, aborting.");
			return null;
		}
		// Load a user template DOM for import remote template new Config
		$dom_local = dom_import_simplexml($template);
		// Try to download the remote template and abort if it fail.
		if (!$dl = $this->download_url($this->xml_decode($template->TemplateURL))) {
			//$this->debug("Download of remote template failed, aborting.");
			return null;
		}
		// Try to load the downloaded template and abort if fail.
		if (!$remote_template = @simplexml_load_string($dl)) {
			//$this->debug("The downloaded template is not a valid XML file, aborting.");
			return null;
		}
		// Loop through remote template elements and compare them to local ones
		foreach ($remote_template->children() as $name => $remote_element) {
			$name = $this->xml_decode($name);
			// Compare through validElements
			if ($name != 'Config' && in_array($name, $validElements)) {
				$local_element = $template->xpath("//$name")[0];
				$rvalue  = $this->xml_decode($remote_element);
				$value   = $this->xml_decode($local_element);
				// Values changed, updating.
				if ($value != $rvalue) {
					$local_element[0] = $this->xml_encode($rvalue);
					//$this->debug("Updating $name from [$value] to [$rvalue]");
					$changed = true;
				}
			// Compare atributes on Config if they are in the validAttributes list
			} elseif ($name == 'Config') {
				$type   = $this->xml_decode($remote_element['Type']);
				$target = $this->xml_decode($remote_element['Target']);
				if ($type == 'Port') {
					$mode = $this->xml_decode($remote_element['Mode']);
					$local_element = $template->xpath("//Config[@Type='$type'][@Target='$target'][@Mode='$mode']")[0];
				} else {
					$local_element = $template->xpath("//Config[@Type='$type'][@Target='$target']")[0];
				}
				// If the local template already have the pertinent Config element, loop through it's attributes and update those on validAttributes
				if (!empty($local_element)) {
					foreach ($remote_element->attributes() as $key => $value) {
						$rvalue  = $this->xml_decode($value);
						$value = $this->xml_decode($local_element[$key]);
						// Values changed, updating.
						// Leave pre-existing value if description is simply Container {type}: xxx
						if ($value != $rvalue && in_array($key, $validAttributes)) {
							if ( ! ($key == "Description" && preg_match("/^(Container Path:|Container Port:|Container Label:|Container Variable:|Container Device:)/",$rvalue) ) ) {
								//$this->debug("Updating $type '$target' attribute '$key' from [$value] to [$rvalue]");
								$local_element[$key] = $this->xml_encode($rvalue);
								$changed = true;
							}
						}
					}
				// New Config element, add it to the local template
				} else {
					$dom_remote  = dom_import_simplexml($remote_element);
					$new_element = $dom_local->ownerDocument->importNode($dom_remote, true);
					$dom_local->appendChild($new_element);
					$changed = true;
				}
			}
		}
		if ($changed) {
			// Format output and save to file if there were any commited changes
			//$this->debug("Saving template modifications to '$file");
			$dom = new DOMDocument('1.0');
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = true;
			$dom->loadXML($template->asXML());
			file_put_contents($file, $dom->saveXML());
		} else {
			//$this->debug("Template is up to date.");
		}
	}
}

####################################
##       DOCKERCLIENT CLASS       ##
####################################

class DockerClient {
	private static $containersCache = null;
	private static $imagesCache = null;
	private static $codes = [
		'200' => true, // No error
		'201' => true,
		'204' => true,
		'304' => 'Container already started',
		'400' => 'Bad parameter',
		'404' => 'No such container',
		'409' => 'Image can not be deleted, in use by other container(s)',
		'500' => 'Server error'
	];

	private function extractID($object) {
		return substr(str_replace('sha256:', '', $object), 0, 12);
	}

	private function usedBy($imageId) {
		$out = [];
		foreach ($this->getDockerContainers() as $ct) {
			if ($ct['ImageId']==$imageId) $out[] = $ct['Name'];
		}
		return $out;
	}

	private function flushCache(&$cache) {
		$cache = null;
	}

	public function flushCaches() {
		$this->flushCache($this::$containersCache);
		$this->flushCache($this::$imagesCache);
	}

	public function humanTiming($time) {
		$time = time() - $time; // to get the time since that moment
		$tokens = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day',3600 => 'hour', 60 => 'minute', 1 => 'second'];
		foreach ($tokens as $unit => $text) {
			if ($time < $unit) continue;
			$numberOfUnits = floor($time / $unit);
			return $numberOfUnits.' '.$text.(($numberOfUnits==1)?'':'s').' ago';
		}
	}

	public function formatBytes($size) {
		if ($size == 0) return '0 B';
		$base = log($size) / log(1024);
		$suffix = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
		return round(pow(1024, $base - floor($base)), 0).' '.$suffix[floor($base)];
	}

	public function getDockerJSON($url, $method='GET', &$code=null, $callback=null, $unchunk=false, $headers=null) {
		$api = '/v1.37'; // used to force an API version. See https://docs.docker.com/develop/sdk/#api-version-matrix
		$fp = stream_socket_client('unix:///var/run/docker.sock', $errno, $errstr);
		if ($fp === false) {
			echo "Couldn't create socket: [$errno] $errstr";
			return null;
		}
		$protocol = $unchunk ? 'HTTP/1.0' : 'HTTP/1.1';
		$out = "$method {$api}{$url} $protocol\r\nHost:127.0.0.1\r\nConnection:Close\r\n";
		if (!empty($headers)) {
			$out .= $headers;
		}
		$out .= "\r\n";
		fwrite($fp, $out);
		// Strip headers out
		$headers = '';
		while (($line = fgets($fp)) !== false) {
			if (strpos($line, 'HTTP/1') !== false) {$error = vsprintf('%2$s',preg_split("#\s+#", $line)); $code = $this::$codes[$error] ?? "Error code $error";}
			//$headers .= $line;
			if (rtrim($line)=='') break;
		}
		$data = [];
		while (($line = fgets($fp)) !== false) {
			if (is_array($j = json_decode($line, true))) $data = array_merge($data, $j);
			if ($callback) $callback($line);
		}
		fclose($fp);
		return $data;
	}

	public function doesContainerExist($container) {
		foreach ($this->getDockerContainers() as $ct) {
			if ($ct['Name']==$container) return true;
		}
		return false;
	}

	public function doesImageExist($image) {
		foreach ($this->getDockerImages() as $img) {
			if (strpos($img['Tags'][0], $image)!==false) return true;
		}
		return false;
	}

	public function getInfo() {
		$info = $this->getDockerJSON("/info");
		$version = $this->getDockerJSON("/version");
		return array_merge($info, $version);
	}

	public function getContainerLog($id, $callback, $tail=null, $since=null) {
		$this->getDockerJSON("/containers/$id/logs?stderr=1&stdout=1&tail=".urlencode($tail)."&since=".urlencode($since), 'GET', $code, $callback, true);
	}

	public function getContainerDetails($id) {
		return $this->getDockerJSON("/containers/$id/json");
	}

	public function startContainer($id) {
		$this->getDockerJSON("/containers/$id/start", 'POST', $code);
		$this->flushCache($this::$containersCache);
		return $code;
	}

	public function pauseContainer($id) {
		$this->getDockerJSON("/containers/$id/pause", 'POST', $code);
		$this->flushCache($this::$containersCache);
		return $code;
	}

	public function stopContainer($id, $t=false) {
		global $dockercfg;

		if ( ! $t )
			$t = intval($dockercfg['DOCKER_TIMEOUT']) ?: 10;
		$this->getDockerJSON("/containers/$id/stop?t=$t", 'POST', $code);
		$this->flushCache($this::$containersCache);
		return $code;
	}

	public function resumeContainer($id) {
		$this->getDockerJSON("/containers/$id/unpause", 'POST', $code);
		$this->flushCache($this::$containersCache);
		return $code;
	}

	public function restartContainer($id) {
		$this->getDockerJSON("/containers/$id/restart", 'POST', $code);
		$this->flushCache($this::$containersCache);
		return $code;
	}

	public function removeContainer($name, $id=false, $cache=false) {
		global $docroot, $dockerManPaths;
		$id = $id ?: $name;
		$info = DockerUtil::loadJSON($dockerManPaths['webui-info']);
		// Attempt to remove container
		$this->getDockerJSON("/containers/$id?force=1", 'DELETE', $code);
		if (isset($info[$name])) {
			if (isset($info[$name]['icon'])) {
				$iconRAM = $docroot.$info[$name]['icon'];
				$iconUSB = str_replace($dockerManPaths['images-ram'], $dockerManPaths['images'], $iconRAM);
				if ($cache>=1 && is_file($iconRAM)) unlink($iconRAM);
				if ($cache==2 && $code===true && is_file($iconUSB)) unlink($iconUSB);
			}
			unset($info[$name]);
			DockerUtil::saveJSON($dockerManPaths['webui-info'], $info);
		}
		$this->flushCaches();
		return $code;
	}

	public function pullImage($image, $callback=null) {
		$header = null;
		$registryAuth = $this->getRegistryAuth($image);
		if ($registryAuth) {
			$header = 'X-Registry-Auth: '.base64_encode(json_encode([
					'username'      => $registryAuth['username'],
					'password'      => $registryAuth['password'],
					'serveraddress' => $registryAuth['apiUrl'],
				]))."\r\n";
		}

		$ret = $this->getDockerJSON("/images/create?fromImage=".urlencode($image), 'POST', $code, $callback, false, $header);
		$this->flushCache($this::$imagesCache);
		return $ret;
	}

	public function getRegistryAuth($image) {
		$image = DockerUtil::ensureImageTag($image);
		//$this->debug('Image: '.$image);
		preg_match('@^([^/]+\.[^/]+/)?([^/]+/)?(.+:)(.+)$@', $image, $matches);
		list(,$registry, $repository, $image, $tag) = $matches;
		$ret = [
			'username'     => '',
			'password'     => '',
			'registryName' => substr($registry, 0, -1),
			'repository' => $repository,
			'imageName'    => substr($image, 0, -1),
			'imageTag'     => $tag,
			'apiUrl'       => 'https://'.$registry.'v2/',
		];
		$configKey = $ret['registryName'];
		if (empty($registry) || $configKey == 'docker.io') {
			$ret['apiUrl'] = 'https://registry-1.docker.io/v2/';
		}
		// Use credentials if docker.io registry is specified
		if ($configKey == 'docker.io') {
			$configKey = 'https://index.docker.io/v1/';
		}
		$dockerConfig = '/root/.docker/config.json';
		if (!file_exists($dockerConfig)) {
			return $ret;
		}
		$dockerConfig = json_decode(file_get_contents($dockerConfig), true);
		if (empty($dockerConfig['auths']) || empty($dockerConfig['auths'][ $configKey ])) {
			return $ret;
		}
		[$ret['username'], $ret['password']] = array_pad(explode(':', base64_decode($dockerConfig['auths'][ $configKey ]['auth'])),2,'');

		return $ret; 
	}

	public function removeImage($id) {
		global $dockerManPaths;
		$image = $this->getImageName($id);
		// Attempt to remove image
		$this->getDockerJSON("/images/$id?force=1", 'DELETE', $code);
		if ($code===true) {
			// Purge cached image information (only if delete was successful)
			$image = DockerUtil::ensureImageTag($image);
			$updateStatus = DockerUtil::loadJSON($dockerManPaths['update-status']);
			if (isset($updateStatus[$image])) {
				unset($updateStatus[$image]);
				DockerUtil::saveJSON($dockerManPaths['update-status'], $updateStatus);
			}
		}
		$this->flushCache($this::$imagesCache);
		return $code;
	}

	public function getDockerContainers() {
		global $driver;
		// Return cached values
		if (is_array($this::$containersCache)) return $this::$containersCache;
		$this::$containersCache = [];
		foreach ($this->getDockerJSON("/containers/json?all=1") as $ct) {
			$info = $this->getContainerDetails($ct['Id']);
			$c = [];
			$c['Image']       = DockerUtil::ensureImageTag($info['Config']['Image']);
			$c['ImageId']     = $this->extractID($ct['ImageID']);
			$c['Name']        = substr($info['Name'], 1);
			$c['Status']      = $ct['Status'] ?: 'None';
			$c['Running']     = $info['State']['Running'];
			$c['Paused']      = $info['State']['Paused'];
			$c['Cmd']         = $ct['Command'];
			$c['Id']          = $this->extractID($ct['Id']);
			$c['Volumes']     = $info['HostConfig']['Binds'];
			$c['Created']     = $this->humanTiming($ct['Created']);
			$c['NetworkMode'] = $ct['HostConfig']['NetworkMode'];
			[$net, $id]       = array_pad(explode(':',$c['NetworkMode']),2,'');
			$c['CPUset']      = $info['HostConfig']['CpusetCpus'];
			$c['BaseImage']   = $ct['Labels']['BASEIMAGE'] ?? false;
			$c['Icon']        = $info['Config']['Labels']['net.unraid.docker.icon'] ?? false;
			$c['Url']         = $info['Config']['Labels']['net.unraid.docker.webui'] ?? false;
			$c['Ports']       = [];
			if ($id) $c['NetworkMode'] = $net.str_replace('/',':',DockerUtil::ctMap($id)?:'/???');
			if ($driver[$c['NetworkMode']]=='bridge') {
				$ports = &$info['HostConfig']['PortBindings'];
				$nat = true;
			} else {
				$ports = &$info['Config']['ExposedPorts'];
				$nat = false;
			}
			$ip = $ct['NetworkSettings']['Networks'][$c['NetworkMode']]['IPAddress'];
			$ports = is_array($ports) ? $ports : array();
			foreach ($ports as $port => $value) {
				[$PrivatePort, $Type] = array_pad(explode('/', $port),2,'');
				$c['Ports'][] = ['IP' => $ip, 'PrivatePort' => $PrivatePort, 'PublicPort' => $nat ? $value[0]['HostPort']:$PrivatePort, 'NAT' => $nat, 'Type' => $Type ];
			}
			$this::$containersCache[] = $c;
		}
		array_multisort(array_column($this::$containersCache,'Name'), SORT_NATURAL|SORT_FLAG_CASE, $this::$containersCache);
		return $this::$containersCache;
	}

	public function getContainerID($Container) {
		foreach ($this->getDockerContainers() as $ct) {
			if (preg_match('%'.preg_quote($Container, '%').'%', $ct['Name'])) return $ct['Id'];
		}
		return null;
	}

	public function getImageID($Image) {
		if (!strpos($Image,':')) $Image .= ':latest';
		foreach ($this->getDockerImages() as $img) {
			foreach ($img['Tags'] as $tag) {
				if ($Image==$tag) return $img['Id'];
			}
		}
		return null;
	}

	public function getImageName($id) {
		foreach ($this->getDockerImages() as $img) {
			if ($img['Id']==$id) return $img['Tags'][0];
		}
		return null;
	}

	public function getDockerImages() {
		// Return cached values
		if (is_array($this::$imagesCache)) return $this::$imagesCache;
		$this::$imagesCache = [];
		foreach ($this->getDockerJSON("/images/json?all=0") as $ct) {
			$c = [];
			$c['Created']     = $this->humanTiming($ct['Created']);
			$c['Id']          = $this->extractID($ct['Id']);
			$c['ParentId']    = $this->extractID($ct['ParentId']);
			$c['Size']        = $this->formatBytes($ct['Size']);
			$c['VirtualSize'] = $this->formatBytes($ct['VirtualSize']);
			$c['Tags']        = array_map('htmlspecialchars', $ct['RepoTags'] ?? []);
			$c['Repository']  = vsprintf('%1$s/%2$s', preg_split("#[:\/]#", DockerUtil::ensureImageTag($ct['RepoTags'][0])));
			$c['usedBy']      = $this->usedBy($c['Id']);
			$this::$imagesCache[$c['Id']] = $c;
		}
		return $this::$imagesCache;
	}
}

##################################
##       DOCKERUTIL CLASS       ##
##################################

class DockerUtil {
	public static function ensureImageTag($image) {
		[$strRepo, $strTag] = array_map('trim', array_pad(explode(':', $image.':'),2,''));
		if (strpos($strRepo, 'sha256:') === 0) {
			// sha256 was provided instead of actual repo name so truncate it for display:
			$strRepo = substr($strRepo, 7, 12);
		} elseif (strpos($strRepo, '/') === false) {
			// Prefix library/ if there's no author (maybe a Docker offical image?)
			$strRepo = "library/$strRepo";
		}
		// Add :latest tag to image if it's absent
		if (empty($strTag)) $strTag = 'latest';
		return "$strRepo:$strTag";
	}

	public static function loadJSON($path) {
		$objContent = (file_exists($path)) ? json_decode(file_get_contents($path), true) : [];
		if (empty($objContent)) $objContent = [];
		return $objContent;
	}

	public static function saveJSON($path, $content) {
		if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
		return file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	}

	public static function docker($cmd, $a=false) {
		$data = exec("docker $cmd 2>/dev/null", $array);
		return $a ? $array : $data;
	}

	public static function myIP($name, $version=4) {
		$ipaddr = $version==4 ? 'IPAddress' : 'GlobalIPv6Address';
		return rtrim(static::docker("inspect --format='{{range .NetworkSettings.Networks}}{{.$ipaddr}} {{end}}' $name"));
	}

	public static function driver() {
		$list = [];
		foreach (static::docker("network ls --format='{{.Name}}={{.Driver}}'",true) as $network) {[$net,$driver] = array_pad(explode('=',$network),2,''); $list[$net] = $driver;}
		return $list;
	}

	public static function custom() {
		return static::docker("network ls --filter driver='bridge' --filter driver='macvlan' --filter driver='ipvlan' --format='{{.Name}}' 2>/dev/null|grep -v '^bridge$'",true);
	}

	public static function network($custom) {
		$list = ['bridge'=>'', 'host'=>'', 'none'=>''];
		foreach ($custom as $net) $list[$net] = implode(', ',array_filter(static::docker("network inspect --format='{{range .IPAM.Config}}{{println .Subnet}}{{end}}' $net",true)));
		return $list;
	}

	public static function cpus() {
		exec('cat /sys/devices/system/cpu/*/topology/thread_siblings_list|sort -nu', $cpus);
		return $cpus;
	}
	public static function ctMap($ct, $type='Name') {
		return static::docker("inspect --format='{{.$type}}' $ct");
	}
}
?>
