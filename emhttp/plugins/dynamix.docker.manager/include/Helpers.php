<?PHP
/* Copyright 2005-2023, Lime Technology
 * Copyright 2012-2023, Bergware International.
 * Copyright 2014-2021, Guilherme Jardim, Eric Schultz, Jon Panozzo.
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
function addRoute($ct) {
  // add static route(s) for remote WireGuard access
  [$pid,$net] = array_pad(explode(' ',exec("docker inspect --format='{{.State.Pid}} {{.NetworkSettings.Networks}}' $ct")),2,'');
  $net = substr($net,4,strpos($net,':')-4);
  if (!$pid || $net != 'br0') return;
  $thisip  = ipaddr();
  foreach (glob('/etc/wireguard/wg*.cfg') as $cfg) {
    $network = exec("grep -Pom1 '^Network:0=\"\\K[^\"]+' $cfg");
    if ($network) exec("nsenter -n -t $pid ip -4 route add $network via $thisip 2>/dev/null");
  }
}

function xml_encode($string) {
  return htmlspecialchars($string, ENT_XML1, 'UTF-8');
}

function xml_decode($string) {
  return strval(html_entity_decode($string, ENT_XML1, 'UTF-8'));
}

function postToXML($post, $setOwnership=false) {
  $dom = new domDocument;
  $dom->appendChild($dom->createElement("Container"));
  $xml = simplexml_import_dom($dom);
  $xml['version']          = 2;
  $xml->Name               = xml_encode(preg_replace('/\s+/', '', $post['contName']));
  $xml->Repository         = xml_encode(trim($post['contRepository']));
  $xml->Registry           = xml_encode(trim($post['contRegistry']));
  $xml->Network            = xml_encode($post['contNetwork']);
  $xml->MyIP               = xml_encode($post['contMyIP']);
  $xml->Shell              = xml_encode($post['contShell']);
  $xml->Privileged         = strtolower($post['contPrivileged']??'')=='on' ? 'true' : 'false';
  $xml->Support            = xml_encode($post['contSupport']);
  $xml->Project            = xml_encode($post['contProject']);
  $xml->Overview           = xml_encode($post['contOverview']);
  $xml->Category           = xml_encode($post['contCategory']);
  $xml->WebUI              = xml_encode(trim($post['contWebUI']));
  $xml->TemplateURL        = xml_encode($post['contTemplateURL']);
  $xml->Icon               = xml_encode(trim($post['contIcon']));
  $xml->ExtraParams        = xml_encode($post['contExtraParams']);
  $xml->PostArgs           = xml_encode($post['contPostArgs']);
  $xml->CPUset             = xml_encode($post['contCPUset']);
  $xml->DateInstalled      = xml_encode(time());
  $xml->DonateText         = xml_encode($post['contDonateText']);
  $xml->DonateLink         = xml_encode($post['contDonateLink']);
  $xml->Requires           = xml_encode($post['contRequires']);

  $size = is_array($post['confName']??null) ? count($post['confName']) : 0;
  for ($i = 0; $i < $size; $i++) {
    $Type                  = $post['confType'][$i];
    $config                = $xml->addChild('Config', xml_encode($post['confValue'][$i]));
    $config['Name']        = xml_encode($post['confName'][$i]);
    $config['Target']      = xml_encode($post['confTarget'][$i]);
    $config['Default']     = xml_encode($post['confDefault'][$i]);
    $config['Mode']        = xml_encode($post['confMode'][$i]);
    $config['Description'] = xml_encode($post['confDescription'][$i]);
    $config['Type']        = xml_encode($post['confType'][$i]);
    $config['Display']     = xml_encode($post['confDisplay'][$i]);
    $config['Required']    = xml_encode($post['confRequired'][$i]);
    $config['Mask']        = xml_encode($post['confMask'][$i]);
  }
  $dom = new DOMDocument('1.0');
  $dom->preserveWhiteSpace = false;
  $dom->formatOutput = true;
  $dom->loadXML($xml->asXML());
  return $dom->saveXML();
}

function xmlToVar($xml) {
  global $subnet;
  $xml                = is_file($xml) ? simplexml_load_file($xml) : simplexml_load_string($xml);
  $out                = [];
  $out['Name']        = preg_replace('/\s+/', '', xml_decode($xml->Name));
  $out['Repository']  = xml_decode($xml->Repository);
  $out['Registry']    = xml_decode($xml->Registry);
  $out['Network']     = xml_decode($xml->Network);
  $out['MyIP']        = xml_decode($xml->MyIP ?? '');
  $out['Shell']       = xml_decode($xml->Shell ?? 'sh');
  $out['Privileged']  = xml_decode($xml->Privileged);
  $out['Support']     = xml_decode($xml->Support);
  $out['Project']     = xml_decode($xml->Project);
  $out['Overview']    = stripslashes(xml_decode($xml->Overview));
  $out['Category']    = xml_decode($xml->Category);
  $out['WebUI']       = xml_decode($xml->WebUI);
  $out['TemplateURL'] = xml_decode($xml->TemplateURL);
  $out['Icon']        = xml_decode($xml->Icon);
  $out['ExtraParams'] = xml_decode($xml->ExtraParams);
  $out['PostArgs']    = xml_decode($xml->PostArgs);
  $out['CPUset']      = xml_decode($xml->CPUset);
  $out['DonateText']  = xml_decode($xml->DonateText);
  $out['DonateLink']  = xml_decode($xml->DonateLink);
  $out['Requires']    = xml_decode($xml->Requires);
  $out['Config']      = [];
  if (isset($xml->Config)) {
    foreach ($xml->Config as $config) {
      $c = [];
      $c['Value'] = strlen(xml_decode($config)) ? xml_decode($config) : xml_decode($config['Default']);
      foreach ($config->attributes() as $key => $value) {
        $value = xml_decode($value);
        if ($key == 'Mode') {
          switch (xml_decode($config['Type'])) {
          case 'Path':
            $value = in_array(strtolower($value),['rw','rw,slave','rw,shared','ro','ro,slave','ro,shared']) ? $value : "rw";
            break;
          case 'Port':
            $value = in_array(strtolower($value),['tcp','udp']) ? $value : "tcp";
            break;
          }
        }
        $c[$key] = strip_tags(html_entity_decode($value));
      }
      $out['Config'][] = $c;
    }
  }
  // some xml templates advertise as V2 but omit the new <Network> element
  // check for and use the V1 <Networking> element when this occurs
  if (empty($out['Network']) && isset($xml->Networking->Mode)) {
    $out['Network'] = xml_decode($xml->Networking->Mode);
  }
  // check if network exists
  if (!key_exists($out['Network'],$subnet)) $out['Network'] = 'none';
  // V1 compatibility
  if ($xml['version'] != '2') {
    if (isset($xml->Description)) {
      $out['Overview'] = stripslashes(xml_decode($xml->Description));
    }
    if (isset($xml->Networking->Publish->Port)) {
      $portNum = 0;
      foreach ($xml->Networking->Publish->Port as $port) {
        if (empty(xml_decode($port->ContainerPort))) continue;
        $portNum += 1;
        $out['Config'][] = [
          'Name'        => "Host Port {$portNum}",
          'Target'      => xml_decode($port->ContainerPort),
          'Default'     => xml_decode($port->HostPort),
          'Value'       => xml_decode($port->HostPort),
          'Mode'        => xml_decode($port->Protocol) ? xml_decode($port->Protocol) : "tcp",
          'Description' => '',
          'Type'        => 'Port',
          'Display'     => 'always',
          'Required'    => 'true',
          'Mask'        => 'false'
        ];
      }
    }
    if (isset($xml->Data->Volume)) {
      $volNum = 0;
      foreach ($xml->Data->Volume as $vol) {
        if (empty(xml_decode($vol->ContainerDir))) continue;
        $volNum += 1;
        $out['Config'][] = [
          'Name'        => "Host Path {$volNum}",
          'Target'      => xml_decode($vol->ContainerDir),
          'Default'     => xml_decode($vol->HostDir),
          'Value'       => xml_decode($vol->HostDir),
          'Mode'        => xml_decode($vol->Mode) ? xml_decode($vol->Mode) : "rw",
          'Description' => '',
          'Type'        => 'Path',
          'Display'     => 'always',
          'Required'    => 'true',
          'Mask'        => 'false'
        ];
      }
    }
    if (isset($xml->Environment->Variable)) {
      $varNum = 0;
      foreach ($xml->Environment->Variable as $varitem) {
        if (empty(xml_decode($varitem->Name))) continue;
        $varNum += 1;
        $out['Config'][] = [
          'Name'        => "Key {$varNum}",
          'Target'      => xml_decode($varitem->Name),
          'Default'     => xml_decode($varitem->Value),
          'Value'       => xml_decode($varitem->Value),
          'Mode'        => '',
          'Description' => '',
          'Type'        => 'Variable',
          'Display'     => 'always',
          'Required'    => 'false',
          'Mask'        => 'false'
        ];
      }
    }
    if (isset($xml->Labels->Variable)) {
      $varNum = 0;
      foreach ($xml->Labels->Variable as $varitem) {
        if (empty(xml_decode($varitem->Name))) continue;
        $varNum += 1;
        $out['Config'][] = [
          'Name'        => "Label {$varNum}",
          'Target'      => xml_decode($varitem->Name),
          'Default'     => xml_decode($varitem->Value),
          'Value'       => xml_decode($varitem->Value),
          'Mode'        => '',
          'Description' => '',
          'Type'        => 'Label',
          'Display'     => 'always',
          'Required'    => 'false',
          'Mask'        => 'false'
        ];
      }
    }
  }
  xmlSecurity($out);
  return $out;
}

function xmlSecurity(&$template) {
  foreach ($template as &$element) {
    if (is_array($element)) {
      xmlSecurity($element);
    } else {
      if (is_string($element)) {
        $tempElement = htmlspecialchars_decode($element);
        $tempElement = str_replace("[","<",$tempElement);
        $tempElement = str_replace("]",">",$tempElement);
        if (preg_match('#<script(.*?)>(.*?)</script>#is',$tempElement) || preg_match('#<iframe(.*?)>(.*?)</iframe>#is',$tempElement) || (stripos($tempElement,"<link") !== false) ) {
          $element = "REMOVED";
        }
      }
    }
  }
}

function xmlToCommand($xml, $create_paths=false) {
  global $docroot, $var, $driver;
  $xml           = xmlToVar($xml);
  $cmdName       = strlen($xml['Name']) ? '--name='.escapeshellarg($xml['Name']) : '';
  $cmdPrivileged = strtolower($xml['Privileged'])=='true' ? '--privileged=true' : '';
  $cmdNetwork    = preg_match('/\-\-net(work)?=/',$xml['ExtraParams']) ? "" : '--net='.escapeshellarg(strtolower($xml['Network']));
  $cmdMyIP       = '';
  foreach (explode(' ',str_replace(',',' ',$xml['MyIP'])) as $myIP) if ($myIP) $cmdMyIP .= (strpos($myIP,':')?'--ip6=':'--ip=').escapeshellarg($myIP).' ';
  $cmdCPUset     = strlen($xml['CPUset']) ? '--cpuset-cpus='.escapeshellarg($xml['CPUset']) : '';
  $Volumes       = [''];
  $Ports         = [''];
  $Variables     = [''];
  $Labels        = [''];
  $Devices       = [''];
  // Bind Time
  $Variables[]   = 'TZ="'.$var['timeZone'].'"';
  // Add HOST_OS variable
  $Variables[]   = 'HOST_OS="Unraid"';
  // Add HOST_HOSTNAME variable 
  $Variables[]   = 'HOST_HOSTNAME="'.$var['NAME'].'"';
  // Add HOST_CONTAINERNAME variable
  $Variables[]   = 'HOST_CONTAINERNAME="'.$xml['Name'].'"';
  // Docker labels for WebUI and Icon
  $Labels[]      = 'net.unraid.docker.managed=dockerman';
  if (strlen($xml['WebUI']))  $Labels[] = 'net.unraid.docker.webui='.escapeshellarg($xml['WebUI']);
  if (strlen($xml['Icon'])) $Labels[] = 'net.unraid.docker.icon='.escapeshellarg($xml['Icon']);

  foreach ($xml['Config'] as $key => $config) {
    $confType        = strtolower(strval($config['Type']));
    $hostConfig      = strlen($config['Value']) ? $config['Value'] : $config['Default'];
    $containerConfig = strval($config['Target']);
    $Mode            = strval($config['Mode']);
    if ($confType != "device" && !strlen($containerConfig)) continue;
    if ($confType == "path") {
      if ( ! trim($hostConfig) || ! trim($containerConfig) )
        continue;
      $Volumes[] = escapeshellarg($hostConfig).':'.escapeshellarg($containerConfig).':'.escapeshellarg($Mode);
      if (!file_exists($hostConfig) && $create_paths) {
        @mkdir($hostConfig, 0777, true);
        @chown($hostConfig, 99);
        @chgrp($hostConfig, 100);
      }
    } elseif ($confType == 'port') {
      switch ($driver[$xml['Network']]) {
      case 'host':
      case 'macvlan':
      case 'ipvlan':
        // Export ports as variable if network is set to host or macvlan or ipvlan
        $Variables[] = strtoupper(escapeshellarg($Mode.'_PORT_'.$containerConfig).'='.escapeshellarg($hostConfig));
        break;
      case 'bridge':
        // Export ports as port if network is set to (custom) bridge
        $Ports[] = escapeshellarg($hostConfig.':'.$containerConfig.'/'.$Mode);
        break;
      case 'none':
        // No export of ports if network is set to none
      }
    } elseif ($confType == "label") {
      $Labels[] = escapeshellarg($containerConfig).'='.escapeshellarg($hostConfig);
    } elseif ($confType == "variable") {
      $Variables[] = escapeshellarg($containerConfig).'='.escapeshellarg($hostConfig);
    } elseif ($confType == "device") {
      $Devices[] = escapeshellarg($hostConfig);
    }
  }

  /* Read the docker configuration file. */
  $cfgfile		= "/boot/config/docker.cfg";
  $config_ini	= @parse_ini_file($cfgfile, true, INI_SCANNER_RAW);
  $docker_cfg	= ($config_ini !== false) ? $config_ini : [];

  // Add pid limit if user has not specified it as an extra parameter
  $pidsLimit = preg_match('/--pids-limit (\d+)/', $xml['ExtraParams'], $matches) ? $matches[1] : null;
  if ($pidsLimit === null) {
    $pid_limit = "--pids-limit ";
    if (($docker_cfg['DOCKER_PID_LIMIT']??'') != "") {
      $pid_limit .= $docker_cfg['DOCKER_PID_LIMIT'];
    } else {
      $pid_limit .= "2048";
    }
  } else {
    $pid_limit = "";
  }

  $cmd = sprintf($docroot.'/plugins/dynamix.docker.manager/scripts/docker create %s %s %s %s %s %s %s %s %s %s %s %s %s %s',
         $cmdName, $cmdNetwork, $cmdMyIP, $cmdCPUset, $pid_limit, $cmdPrivileged, implode(' -e ', $Variables), implode(' -l ', $Labels), implode(' -p ', $Ports), implode(' -v ', $Volumes), implode(' --device=', $Devices), $xml['ExtraParams'], escapeshellarg($xml['Repository']), $xml['PostArgs']);
  return [preg_replace('/\s\s+/', ' ', $cmd), $xml['Name'], $xml['Repository']];
}
function stopContainer($name, $t=false, $echo=true) {
  global $DockerClient;
  $waitID = mt_rand();
  if ($echo) {
    echo "<p class=\"logLine\" id=\"logBody\"></p>";
    echo "<script>addLog('<fieldset style=\"margin-top:1px;\" class=\"CMD\"><legend>"._('Stopping container').": ",addslashes(htmlspecialchars($name)),"</legend><p class=\"logLine\" id=\"logBody\"></p><span id=\"wait{$waitID}\">"._('Please wait')." </span></fieldset>');show_Wait($waitID);</script>\n";
    @flush();
  }
  $retval = $DockerClient->stopContainer($name, $t);
  $out = ($retval === true) ? _('Successfully stopped container')." '$name'" : _('Error').": ".$retval;
  if ($echo) {
    echo "<script>stop_Wait($waitID);addLog('<b>",addslashes(htmlspecialchars($out)),"</b>');</script>\n";
    @flush();
  }
}

function removeContainer($name, $cache=false, $echo=true) {
  global $DockerClient;
  $waitID = mt_rand();
  if ($echo) {
    echo "<p class=\"logLine\" id=\"logBody\"></p>";
    echo "<script>addLog('<fieldset style=\"margin-top:1px;\" class=\"CMD\"><legend>"._('Removing container').": ",addslashes(htmlspecialchars($name)),"</legend><p class=\"logLine\" id=\"logBody\"></p><span id=\"wait{$waitID}\">"._('Please wait')." </span></fieldset>');show_Wait($waitID);</script>\n";
    @flush();
  }
  $retval = $DockerClient->removeContainer($name, false, $cache);
  $out = ($retval === true) ? _('Successfully removed container')." '$name'" : _('Error').": ".$retval;
  if ($echo) {
    echo "<script>stop_Wait($waitID);addLog('<b>",addslashes(htmlspecialchars($out)),"</b>');</script>\n";
    @flush();
  }
}

function removeImage($image, $echo=true) {
  global $DockerClient;
  $waitID = mt_rand();
  if ($echo) {
    echo "<p class=\"logLine\" id=\"logBody\"></p>";
    echo "<script>addLog('<fieldset style=\"margin-top:1px;\" class=\"CMD\"><legend>"._('Removing orphan image').": ",addslashes(htmlspecialchars($image)),"</legend><p class=\"logLine\" id=\"logBody\"></p><span id=\"wait{$waitID}\">"._('Please wait')." </span></fieldset>');show_Wait($waitID);</script>\n";
    @flush();
  }
  $retval = $DockerClient->removeImage($image);
  $out = ($retval === true) ? _('Successfully removed orphan image')." '$image'" : _('Error').": ".$retval;
  if ($echo) {
    echo "<script>stop_Wait($waitID);addLog('<b>",addslashes(htmlspecialchars($out))."</b>');</script>\n";
    @flush();
  }
}

function pullImage($name, $image, $echo=true) {
  global $DockerClient, $DockerTemplates, $DockerUpdate;
  $waitID = mt_rand();
  if (!preg_match("/:\S+$/", $image)) $image .= ":latest";
  if ($echo) {
    echo "<p class=\"logLine\" id=\"logBody\"></p>";
    echo "<script>addLog('<fieldset style=\"margin-top:1px;\" class=\"CMD\"><legend>"._('Pulling image').": ",addslashes(htmlspecialchars($image)),"</legend><p class=\"logLine\" id=\"logBody\"></p><span id=\"wait{$waitID}\">"._('Please wait')." </span></fieldset>');show_Wait($waitID);</script>\n";
    @flush();
  }
  $alltotals = [];
  $laststatus = [];
  $strError = '';
  $DockerClient->pullImage($image, function ($line) use (&$alltotals, &$laststatus, &$waitID, &$strError, $image, $DockerClient, $DockerUpdate, $echo) {
    $cnt = json_decode($line, true);
    $id = $cnt['id'] ?? '';
    $status = $cnt['status'] ?? '';
    if (isset($cnt['error'])) $strError = $cnt['error'];
    if ($waitID !== false) {
      if ($echo) {
        echo "<script>stop_Wait($waitID);</script>\n";
        @flush();
      }
      $waitID = false;
    }
    if (empty($status)) return;
    if (!empty($id)) {
      if (!empty($cnt['progressDetail']) && !empty($cnt['progressDetail']['total'])) {
        $alltotals[$id] = $cnt['progressDetail']['total'];
      }
      if (empty($laststatus[$id])) {
        $laststatus[$id] = '';
      }
      switch ($status) {
      case 'Waiting':
        // Omit
        break;
      case 'Downloading':
        if ($laststatus[$id] != $status) {
          if ($echo) echo "<script>addToID('$id','",addslashes(htmlspecialchars($status)),"');</script>\n";
        }
        $total = $cnt['progressDetail']['total'];
        $current = $cnt['progressDetail']['current'];
        if ($total > 0) {
          $percentage = round(($current / $total) * 100);
          if ($echo) echo "<script>progress('$id',' ",$percentage,"% ",_('of')," ",$DockerClient->formatBytes($total),"');</script>\n";
        } else {
          // Docker must not know the total download size (http-chunked or something?)
          // just show the current download progress without the percentage
          $alltotals[$id] = $current;
          if ($echo) echo "<script>progress('$id',' ",$DockerClient->formatBytes($current),"');</script>\n";
        }
        break;
      default:
        if ($laststatus[$id] == "Downloading") {
          if ($echo) echo "<script>progress('$id',' 100% ",_('of')," ",$DockerClient->formatBytes($alltotals[$id]),"');</script>\n";
        }
        if ($laststatus[$id] != $status) {
          if ($echo) echo "<script>addToID('",($id=='latest'?mt_rand():$id),"','",addslashes(htmlspecialchars($status)),"');</script>\n";
        }
        break;
      }
      $laststatus[$id] = $status;
    } else {
      if (strpos($status, 'Status: ') === 0) {
        if ($echo) echo "<script>addLog('",addslashes(htmlspecialchars($status)),"');</script>\n";
      }
      if (strpos($status, 'Digest: ') === 0) {
        $DockerUpdate->setUpdateStatus($image, substr($status, 8));
      }
    }
    if ($echo) @flush();
  });
  if ($echo) {
    echo "<script>addLog('<br><b>",_('TOTAL DATA PULLED'),":</b> ",$DockerClient->formatBytes(array_sum($alltotals)),"');</script>\n";
    @flush();
  }
  if (!empty($strError)) {
    if ($echo) {
      echo "<script>addLog('<br><span class=\"error\"><b>",_('Error'),":</b> ",addslashes(htmlspecialchars($strError)),"</span>');</script>\n";
      @flush();
    }
    return false;
  }
  return true;
}

function execCommand($command, $echo=true) {
  $waitID = mt_rand();
  if ($echo) {
    [$cmd,$args] = explode(' ',$command,2);
    echo '<p class="logLine" id="logBody"></p>';
    echo '<script>addLog(\'<fieldset style="margin-top:1px;" class="CMD"><legend>',_('Command execution'),'</legend>';
    echo basename($cmd),' ',str_replace(" -","<br>&nbsp;&nbsp;-",addslashes(htmlspecialchars($args))),'<br>';
    echo '<span id="wait'.$waitID.'">',_('Please wait').' </span>';
    echo '<p class="logLine" id="logBody"></p></fieldset>\');show_Wait('.$waitID.');</script>';
    @flush();
  }
  $proc = popen("$command 2>&1",'r');
  while ($out = fgets($proc)) {
    $out = preg_replace("%[\t\n\x0B\f\r]+%", '', $out);
    if ($echo) {
      echo '<script>addLog("',htmlspecialchars($out),'");</script>';
      @flush();
    }
  }
  $retval = pclose($proc);
  if ($echo) echo '<script>stop_Wait('.$waitID.');</script>';
  $out = $retval ?  _('The command failed').'.' : _('The command finished successfully').'!';
  if ($echo) echo '<script>addLog(\'<br><b>',$out,'</b>\');</script>';
  return $retval===0;
}

function getXmlVal($xml, $element, $attr=null, $pos=0) {
  $xml = (is_file($xml)) ? simplexml_load_file($xml) : simplexml_load_string($xml);
  $element = $xml->xpath("//$element")[$pos];
  return isset($element) ? (isset($element[$attr]) ? strval($element[$attr]) : strval($element)) : "";
}

function setXmlVal(&$xml, $value, $el, $attr=null, $pos=0) {
  $xml = (is_file($xml)) ? simplexml_load_file($xml) : simplexml_load_string($xml);
  $element = $xml->xpath("//$el")[$pos];
  if (!isset($element)) $element = $xml->addChild($el);
  if ($attr) {
    $element[$attr] = $value;
  } else {
    $element->{0} = $value;
  }
  $dom = new DOMDocument('1.0');
  $dom->preserveWhiteSpace = false;
  $dom->formatOutput = true;
  $dom->loadXML($xml->asXML());
  $xml = $dom->saveXML();
}

function getAllocations() {
  global $DockerClient, $host;
  
  $ports = [];
  foreach ($DockerClient->getDockerContainers() as $ct) {
    $list = $port = [];
    $nat = $ip = false;
    $list['Name'] = $ct['Name'];
    foreach ($ct['Ports'] as $tmp) {
      $nat = $tmp['NAT'];
      $ip = $tmp['IP'];
      $port[] = $tmp['PublicPort'];
    }
    sort($port);
    $ip = $ct['NetworkMode']=='host'||$nat ? $host : ($ip ?: DockerUtil::myIP($ct['Name']) ?: '0.0.0.0');
    $list['Port'] = "<span class='net'>{$ct['NetworkMode']}</span><span class='ip'>$ip</span>".(implode(', ',array_unique($port)) ?: '???');
    $ports[] = $list;
  }
  return $ports;
}

function getCurlHandle($url, $method='GET') {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
  curl_setopt($ch, CURLOPT_TIMEOUT, 45);
  curl_setopt($ch, CURLOPT_ENCODING, "");
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_REFERER, "");
  if ($method === 'HEAD') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
  }
  return $ch;
}
?>
