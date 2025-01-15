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
$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
require_once "$docroot/webGui/include/Helpers.php";
extract(parse_plugin_cfg('dynamix',true));

$var = parse_ini_file('state/var.ini');
ignore_user_abort(true);

$DockerClient = new DockerClient();
$DockerUpdate = new DockerUpdate();
$DockerTemplates = new DockerTemplates();

#   ███████╗██╗   ██╗███╗   ██╗ ██████╗████████╗██╗ ██████╗ ███╗   ██╗███████╗
#   ██╔════╝██║   ██║████╗  ██║██╔════╝╚══██╔══╝██║██╔═══██╗████╗  ██║██╔════╝
#   █████╗  ██║   ██║██╔██╗ ██║██║        ██║   ██║██║   ██║██╔██╗ ██║███████╗
#   ██╔══╝  ██║   ██║██║╚██╗██║██║        ██║   ██║██║   ██║██║╚██╗██║╚════██║
#   ██║     ╚██████╔╝██║ ╚████║╚██████╗   ██║   ██║╚██████╔╝██║ ╚████║███████║
#   ╚═╝      ╚═════╝ ╚═╝  ╚═══╝ ╚═════╝   ╚═╝   ╚═╝ ╚═════╝ ╚═╝  ╚═══╝╚══════╝

$custom = DockerUtil::custom();
$subnet = DockerUtil::network($custom);
$cpus   = DockerUtil::cpus();

function cpu_pinning() {
  global $xml,$cpus;
  $vcpu = explode(',',_var($xml,'CPUset'));
  $total = count($cpus);
  $loop = floor(($total-1)/16)+1;
  for ($c = 0; $c < $loop; $c++) {
    $row1 = $row2 = [];
    $max = ($c == $loop-1 ? ($total%16?:16) : 16);
    for ($n = 0; $n < $max; $n++) {
      unset($cpu1,$cpu2);
      [$cpu1, $cpu2] = my_preg_split('/[,-]/',$cpus[$c*16+$n]);
      $check1 = in_array($cpu1, $vcpu) ? ' checked':'';
      $check2 = $cpu2 ? (in_array($cpu2, $vcpu) ? ' checked':''):'';
      $row1[] = "<label id='cpu$cpu1' class='checkbox'>$cpu1<input type='checkbox' id='box$cpu1'$check1><span class='checkmark'></span></label>";
      if ($cpu2) $row2[] = "<label id='cpu$cpu2' class='checkbox'>$cpu2<input type='checkbox' id='box$cpu2'$check2><span class='checkmark'></span></label>";
    }
    if ($c) echo '<hr>';
    echo "<span class='cpu'>"._('CPU').":</span>".implode($row1);
    if ($row2) echo "<br><span class='cpu'>"._('HT').":</span>".implode($row2);
  }
}

#    ██████╗ ██████╗ ██████╗ ███████╗
#   ██╔════╝██╔═══██╗██╔══██╗██╔════╝
#   ██║     ██║   ██║██║  ██║█████╗
#   ██║     ██║   ██║██║  ██║██╔══╝
#   ╚██████╗╚██████╔╝██████╔╝███████╗
#    ╚═════╝ ╚═════╝ ╚═════╝ ╚══════╝

##########################
##   CREATE CONTAINER   ##
##########################

if (isset($_POST['contName'])) {
  $postXML = postToXML($_POST, true);
  $dry_run = isset($_POST['dryRun']) && $_POST['dryRun']=='true';
  $existing = _var($_POST,'existingContainer',false);
  $create_paths = $dry_run ? false : true;
  // Get the command line
  [$cmd, $Name, $Repository] = xmlToCommand($postXML, $create_paths);
  readfile("$docroot/plugins/dynamix.docker.manager/log.htm");
  @flush();
  // Saving the generated configuration file.
  $userTmplDir = $dockerManPaths['templates-user'];
  if (!is_dir($userTmplDir)) mkdir($userTmplDir, 0777, true);
  if ($Name) {
    $filename = sprintf('%s/my-%s.xml', $userTmplDir, $Name);
    if (is_file($filename)) {
      $oldXML = simplexml_load_file($filename);
      if ($oldXML->Icon != $_POST['contIcon']) {
        if (!strpos($Repository,":")) $Repository .= ":latest";
        $iconPath = $DockerTemplates->getIcon($Repository,$Name);
        @unlink("$docroot/$iconPath");
        @unlink("{$dockerManPaths['images']}/".basename($iconPath));
      }
    }
    file_put_contents($filename, $postXML);
  }
  // Run dry
  if ($dry_run) {
    echo "<h2>XML</h2>";
    echo "<pre>".htmlspecialchars($postXML)."</pre>";
    echo "<h2>COMMAND:</h2>";
    echo "<pre>".htmlspecialchars($cmd)."</pre>";
    echo "<div style='text-align:center'><button type='button' onclick='window.location=window.location.pathname+window.location.hash+\"?xmlTemplate=edit:$filename\"'>"._('Back')."</button>";
    echo "<button type='button' onclick='done()'>"._('Done')."</button></div><br>";
    goto END;
  }
  // Will only pull image if it's absent
  if (!$DockerClient->doesImageExist($Repository)) {
    // Pull image
    if (!pullImage($Name, $Repository)) {
      echo '<div style="text-align:center"><button type="button" onclick="done()">'._('Done').'</button></div><br>';
      goto END;
    }
  }
  $startContainer = true;
  // Remove existing container
  if ($DockerClient->doesContainerExist($Name)) {
    // attempt graceful stop of container first
    $oldContainerInfo = $DockerClient->getContainerDetails($Name);
    if (!empty($oldContainerInfo) && !empty($oldContainerInfo['State']) && !empty($oldContainerInfo['State']['Running'])) {
      // attempt graceful stop of container first
      stopContainer($Name);
    }
    // force kill container if still running after 10 seconds
    removeContainer($Name);
  }
  // Remove old container if renamed
  if ($existing && $DockerClient->doesContainerExist($existing)) {
    // determine if the container is still running
    $oldContainerInfo = $DockerClient->getContainerDetails($existing);
    if (!empty($oldContainerInfo) && !empty($oldContainerInfo['State']) && !empty($oldContainerInfo['State']['Running'])) {
      // attempt graceful stop of container first
      stopContainer($existing);
    } else {
      // old container was stopped already, ensure newly created container doesn't start up automatically
      $startContainer = false;
    }
    // force kill container if still running after 10 seconds
    removeContainer($existing,1);
    // remove old template
    if (strtolower($filename) != strtolower("$userTmplDir/my-$existing.xml")) {
      @unlink("$userTmplDir/my-$existing.xml");
    }
  }
  // Extract real Entrypoint and Cmd from container for Tailscale
  if (isset($_POST['contTailscale']) && $_POST['contTailscale'] == 'on') {
    // Create preliminary base container but don't run it
    exec("/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/docker create --name '" . escapeshellarg($Name) . "' '" . escapeshellarg($Repository) . "'");
    // Get Entrypoint and Cmd from docker inspect
    $containerInfo = $DockerClient->getContainerDetails($Name);
    $ts_env  = isset($containerInfo['Config']['Entrypoint']) ? '-e ORG_ENTRYPOINT="' . implode(' ', $containerInfo['Config']['Entrypoint']) . '" ' : '';
    $ts_env .= isset($containerInfo['Config']['Cmd']) ? '-e ORG_CMD="' . implode(' ', $containerInfo['Config']['Cmd']) . '" ' : '';
    // Insert Entrypoint and Cmd to docker command
    $cmd = str_replace('-l net.unraid.docker.managed=dockerman', $ts_env . '-l net.unraid.docker.managed=dockerman' , $cmd);
    // Remove preliminary container
    exec("/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/docker rm '" . escapeshellarg($Name) . "'");
  }
  if ($startContainer) $cmd = str_replace('/docker create ', '/docker run -d ', $cmd);
  execCommand($cmd);
  if ($startContainer) addRoute($Name); // add route for remote WireGuard access

  echo '<div style="text-align:center"><button type="button" onclick="openTerminal(\'docker\',\''.addslashes($Name).'\',\'.log\')">'._('View Container Log').'</button> <button type="button" onclick="done()">'._('Done').'</button></div><br>';
  goto END;
}

##########################
##   UPDATE CONTAINER   ##
##########################

if (isset($_GET['updateContainer'])){
  $echo = empty($_GET['mute']);
  if ($echo) {
    readfile("$docroot/plugins/dynamix.docker.manager/log.htm");
    @flush();
  }
  foreach ($_GET['ct'] as $value) {
    $tmpl = $DockerTemplates->getUserTemplate(unscript(urldecode($value)));
    if ($echo && !$tmpl) {
      echo "<script>addLog('<p>"._('Configuration not found').". "._('Was this container created using this plugin')."?</p>');</script>";
      @flush();
      continue;
    }
    $xml = file_get_contents($tmpl);
    [$cmd, $Name, $Repository] = xmlToCommand($tmpl);
    $Registry = getXmlVal($xml, "Registry");
    $ExtraParams = getXmlVal($xml, "ExtraParams");
    $Network = getXmlVal($xml, "Network");
    $TS_Enabled = getXmlVal($xml, "TailscaleEnabled");
    $oldImageID = $DockerClient->getImageID($Repository);
    // pull image
    if ($echo && !pullImage($Name, $Repository)) continue;
    $oldContainerInfo = $DockerClient->getContainerDetails($Name);
    // determine if the container is still running
    $startContainer = false;
    if (!empty($oldContainerInfo) && !empty($oldContainerInfo['State']) && !empty($oldContainerInfo['State']['Running'])) {
      // since container was already running, put it back it to a running state after update
      $cmd = str_replace('/docker create ', '/docker run -d ', $cmd);
      $startContainer = true;
      // attempt graceful stop of container first
      stopContainer($Name, false, $echo);
    }
    // check if network from another container is specified in xml (Network & ExtraParams)
    if (preg_match('/^container:(.*)/', $Network)) {
      $Net_Container = str_replace("container:", "", $Network);
    } else {
      preg_match("/--(net|network)=container:[^\s]+/", $ExtraParams, $NetworkParam);
      if (!empty($NetworkParam[0])) {
        $Net_Container = explode(':', $NetworkParam[0])[1];
        $Net_Container = str_replace(['"', "'"], '', $Net_Container);
      }
    }
    // check if the container still exists from which the network should be used, if it doesn't exist any more recreate container with network none and don't start it
    if (!empty($Net_Container)) {
      $Net_Container_ID = $DockerClient->getContainerID($Net_Container);
      if (empty($Net_Container_ID)) {
        $cmd = str_replace('/docker run -d ', '/docker create ', $cmd);
        $cmd = preg_replace("/--(net|network)=(['\"]?)container:[^'\"]+\\2/", "--network=none ", $cmd);
      }
    }
    // force kill container if still running after time-out
    if (empty($_GET['communityApplications'])) removeContainer($Name, $echo);
    // Extract real Entrypoint and Cmd from container for Tailscale
    if ($TS_Enabled == 'true') {
      // Create preliminary base container but don't run it
      exec("/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/docker create --name '" . escapeshellarg($Name) . "' '" . escapeshellarg($Repository) . "'");
      // Get Entrypoint and Cmd from docker inspect
      $containerInfo = $DockerClient->getContainerDetails($Name);
      $ts_env  = isset($containerInfo['Config']['Entrypoint']) ? '-e ORG_ENTRYPOINT="' . implode(' ', $containerInfo['Config']['Entrypoint']) . '" ' : '';
      $ts_env .= isset($containerInfo['Config']['Cmd']) ? '-e ORG_CMD="' . implode(' ', $containerInfo['Config']['Cmd']) . '" ' : '';
      // Insert Entrypoint and Cmd to docker command
      $cmd = str_replace('-l net.unraid.docker.managed=dockerman', $ts_env . '-l net.unraid.docker.managed=dockerman' , $cmd);
      // Remove preliminary container
      exec("/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/docker rm '" . escapeshellarg($Name) . "'");
    }
    execCommand($cmd, $echo);
    if ($startContainer) addRoute($Name); // add route for remote WireGuard access
    $DockerClient->flushCaches();
    $newImageID = $DockerClient->getImageID($Repository);
    // remove old orphan image since it's no longer used by this container
    if ($oldImageID && $oldImageID != $newImageID) removeImage($oldImageID, $echo);
  }
  echo '<div style="text-align:center"><button type="button" onclick="window.parent.jQuery(\'#iframe-popup\').dialog(\'close\')">'._('Done').'</button></div><br>';
  goto END;
}

#########################
##   REMOVE TEMPLATE   ##
#########################

if (isset($_POST['rmTemplate'])) {
  if (file_exists($_POST['rmTemplate']) && dirname($_POST['rmTemplate'])==$dockerManPaths['templates-user']) unlink($_POST['rmTemplate']);
}

#########################
##    LOAD TEMPLATE    ##
#########################

$xmlType = $xmlTemplate = '';
if (isset($_GET['xmlTemplate'])) {
  [$xmlType, $xmlTemplate] = my_explode(':', unscript(urldecode($_GET['xmlTemplate'])));
  if (is_file($xmlTemplate)) {
    $xml = xmlToVar($xmlTemplate);
    $templateName = $xml['Name'];
    if (preg_match('/^container:(.*)/', $xml['Network'])) {
      $xml['Network'] = explode(':', $xml['Network'], 2);
    }
    if ($xmlType == 'default') {
      if (!empty($dockercfg['DOCKER_APP_CONFIG_PATH']) && file_exists($dockercfg['DOCKER_APP_CONFIG_PATH'])) {
        // override /config
        foreach ($xml['Config'] as &$arrConfig) {
          if ($arrConfig['Type'] == 'Path' && strtolower($arrConfig['Target']) == '/config') {
            $arrConfig['Default'] = $arrConfig['Value'] = realpath($dockercfg['DOCKER_APP_CONFIG_PATH']).'/'.$xml['Name'];
            if (empty($arrConfig['Display']) || preg_match("/^Host Path\s\d/", $arrConfig['Name'])) {
              $arrConfig['Display'] = 'advanced-hide';
            }
            if (empty($arrConfig['Name']) || preg_match("/^Host Path\s\d/", $arrConfig['Name'])) {
              $arrConfig['Name'] = 'AppData Config Path';
            }
          }
          $arrConfig['Name'] = strip_tags(_var($arrConfig,'Name'));
          $arrConfig['Description'] = strip_tags(_var($arrConfig,'Description'));
          $arrConfig['Requires'] = strip_tags(_var($arrConfig,'Requires'));
        }
      }
      if (!empty($dockercfg['DOCKER_APP_UNRAID_PATH']) && file_exists($dockercfg['DOCKER_APP_UNRAID_PATH'])) {
        // override /unraid
        $boolFound = false;
        foreach ($xml['Config'] as &$arrConfig) {
          if ($arrConfig['Type'] == 'Path' && strtolower($arrConfig['Target']) == '/unraid') {
            $arrConfig['Default'] = $arrConfig['Value'] = realpath($dockercfg['DOCKER_APP_UNRAID_PATH']);
            $arrConfig['Display'] = 'hidden';
            $arrConfig['Name'] = 'Unraid Share Path';
            $boolFound = true;
          }
        }
        if (!$boolFound) {
          $xml['Config'][] = [
            'Name'        => 'Unraid Share Path',
            'Target'      => '/unraid',
            'Default'     => realpath($dockercfg['DOCKER_APP_UNRAID_PATH']),
            'Value'       => realpath($dockercfg['DOCKER_APP_UNRAID_PATH']),
            'Mode'        => 'rw',
            'Description' => '',
            'Type'        => 'Path',
            'Display'     => 'hidden',
            'Required'    => 'false',
            'Mask'        => 'false'
          ];
        }
      }
    }
    $xml['Overview'] = str_replace(['[', ']'], ['<', '>'], $xml['Overview']);
    $xml['Description'] = $xml['Overview'] = strip_tags(str_replace("<br>","\n", $xml['Overview']));
    echo "<script>var Settings=".json_encode($xml).";</script>";
  }
}
echo "<script>var Allocations=".json_encode(getAllocations()).";</script>";
$authoringMode = $dockercfg['DOCKER_AUTHORING_MODE'] == "yes" ? true : false;
$authoring     = $authoringMode ? 'advanced' : 'noshow';
$disableEdit   = $authoringMode ? 'false' : 'true';
$showAdditionalInfo = '';
$bgcolor = strstr('white,azure',$display['theme']) ? '#f2f2f2' : '#1c1c1c';

# Search for existing TAILSCALE_ entries in the Docker template
$TS_existing_vars = false;
if (isset($xml["Config"]) && is_array($xml["Config"])) {
  foreach ($xml["Config"] as $config) {
    if (isset($config["Target"]) && strpos($config["Target"], "TAILSCALE_") === 0) {
      $TS_existing_vars = true;
      break;
    }
  }
}

# Try to detect port from WebUI and set webui_url
$TSwebuiport = '';
$webui_url = '';
if (empty($xml['TailscalePort'])) {
  if (!empty($xml['WebUI'])) {
    $webui_url = parse_url($xml['WebUI']);
    preg_match('/:(\d+)\]/', $webui_url['host'], $matches);
    $TSwebuiport = $matches[1];
  }
}

$TS_raw = [];
$TS_container_raw = [];
$TS_HostNameWarning = "";
$TS_HTTPSDisabledWarning = "";
$TS_ExitNodeNeedsApproval = false;
$TS_MachinesLink = "https://login.tailscale.com/admin/machines/";
$TS_DirectMachineLink = $TS_MachinesLink;
$TS_HostNameActual = "";
$TS_not_approved = "";
$TS_https_enabled = false;
$ts_exit_nodes = [];
$ts_en_check = false;
// Get Tailscale information and create arrays/variables
!empty($xml) && exec("docker exec -i " . escapeshellarg($xml['Name']) . " /bin/sh -c \"tailscale status --peers=false --json\"", $TS_raw);
$TS_no_peers = json_decode(implode('', $TS_raw),true);
$TS_container = json_decode(implode('', $TS_raw),true);
$TS_container = $TS_container['Self']??'';

# Look for Exit Nodes through Tailscale plugin (if installed) when container is not running
if (empty($TS_container) && file_exists('/usr/local/sbin/tailscale') && exec('pgrep --ns $$ -f "/usr/local/sbin/tailscaled"')) {
  exec('tailscale exit-node list', $ts_exit_node_list, $retval);
  if ($retval === 0) {
    foreach ($ts_exit_node_list as $line) {
      if (!empty(trim($line))) {
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+(.+)$/', trim($line), $matches)) {
          $parts = preg_split('/\s+/', $matches[2]);
          $ts_exit_nodes[] = [
            'ip' => $matches[1],
            'hostname' => $parts[0],
            'country' => $parts[1],
            'city' => $parts[2],
            'status' => $parts[3]
          ];
          $ts_en_check = true;
        }
      }
    }
  }
}

if (!empty($TS_no_peers) && !empty($TS_container)) {
  // define the direct link to this machine on the Tailscale website
  if (!empty($TS_container['TailscaleIPs']) && !empty($TS_container['TailscaleIPs'][0])) {
    $TS_DirectMachineLink = $TS_MachinesLink.$TS_container['TailscaleIPs'][0];
  }
  // warn if MagicDNS or HTTPS is disabled
  if (isset($TS_no_peers['Self']['Capabilities']) && is_array($TS_no_peers['Self']['Capabilities'])) {
    $TS_https_enabled = in_array("https", $TS_no_peers['Self']['Capabilities'], true) ? true : false;
  }
  if (empty($TS_no_peers['CurrentTailnet']['MagicDNSEnabled']) || !$TS_no_peers['CurrentTailnet']['MagicDNSEnabled'] || $TS_https_enabled !== true) {
    $TS_HTTPSDisabledWarning = "<span><b><a href='https://tailscale.com/kb/1153/enabling-https' target='_blank'>Enable HTTPS</a> on your Tailscale account to use Tailscale Serve/Funnel.</b></span>";
  }
  // In $TS_container, 'HostName' is what the user requested, need to parse 'DNSName' to find the actual HostName in use
  $TS_DNSName = _var($TS_container,'DNSName','');
  $TS_HostNameActual = substr($TS_DNSName, 0, strpos($TS_DNSName, '.'));
  // compare the actual HostName in use to the one in the XML file
  if (strcasecmp($TS_HostNameActual, _var($xml, 'TailscaleHostname')) !== 0 && !empty($TS_DNSName)) {
    // they are different, show a warning
    $TS_HostNameWarning = "<span><b>Warning: the actual Tailscale hostname is '".$TS_HostNameActual."'</b></span>";
  }
  // If this is an Exit Node, show warning if it still needs approval
  if (_var($xml,'TailscaleIsExitNode') == 'true' && _var($TS_container, 'ExitNodeOption') === false) {
    $TS_ExitNodeNeedsApproval = true;
  }
  //Check for key expiry
  if(!empty($TS_container['KeyExpiry'])) {
    $TS_expiry = new DateTime($TS_container['KeyExpiry']);
    $current_Date = new DateTime();
    $TS_expiry_diff = $current_Date->diff($TS_expiry);
  }
  // Check for non approved routes
  if(!empty($xml['TailscaleRoutes'])) {
    $TS_advertise_routes = str_replace(' ', '', $xml['TailscaleRoutes']);
    if (empty($TS_container['PrimaryRoutes'])) {
      $TS_container['PrimaryRoutes'] = [];
    }
    $routes = explode(',', $TS_advertise_routes);
    foreach ($routes as $route) {
      if (!in_array($route, $TS_container['PrimaryRoutes'])) {
        $TS_not_approved .= " " . $route;
      }
    }
  }
  // Check for exit nodes if ts_en_check was not already done
  if (!$ts_en_check) {
    exec("docker exec -i ".$xml['Name']." /bin/sh -c \"tailscale exit-node list\"", $ts_exit_node_list, $retval);
    if ($retval === 0) {
      foreach ($ts_exit_node_list as $line) {
        if (!empty(trim($line))) {
          if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+(.+)$/', trim($line), $matches)) {
            $parts = preg_split('/\s+/', $matches[2]);
            $ts_exit_nodes[] = [
              'ip' => $matches[1],
              'hostname' => $parts[0],
              'country' => $parts[1],
              'city' => $parts[2],
              'status' => $parts[3]
            ];
          }
        }
      }
    }
  }
  // Construct WebUI URL on container template page
  // Check if webui_url, Tailscale WebUI and MagicDNS are not empty and make sure that MagicDNS is enabled
  if ( !empty($webui_url) && !empty($xml['TailscaleWebUI']) && (!empty($TS_no_peers['CurrentTailnet']['MagicDNSEnabled']) || ($TS_no_peers['CurrentTailnet']['MagicDNSEnabled']??false))) {
    // Check if serve or funnel are enabled by checking for [hostname] and replace string with TS_DNSName
    if (!empty($xml['TailscaleWebUI']) && strpos($xml['TailscaleWebUI'], '[hostname]') !== false && isset($TS_DNSName)) {
      $TS_webui_url = str_replace("[hostname][magicdns]", rtrim($TS_DNSName, '.'), $xml['TailscaleWebUI']);
      $TS_webui_url = preg_replace('/\[IP\]/', rtrim($TS_DNSName, '.'), $TS_webui_url);
      $TS_webui_url = preg_replace('/\[PORT:(\d{1,5})\]/', '443', $TS_webui_url);
    // Check if serve is disabled, construct url with port, path and query if present and replace [noserve] with url
    } elseif (strpos($xml['TailscaleWebUI'], '[noserve]') !== false && isset($TS_container['TailscaleIPs'])) {
      $ipv4 = '';
      foreach ($TS_container['TailscaleIPs'] as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
          $ipv4 = $ip;
          break;
        }
      }
      if (!empty($ipv4)) {
        $webui_url = isset($xml['WebUI']) ? parse_url($xml['WebUI']) : '';
        $webui_port = (preg_match('/\[PORT:(\d+)\]/', $xml['WebUI'], $matches)) ? ':' . $matches[1] : '';
        $webui_path = $webui_url['path'] ?? '';
        $webui_query = isset($webui_url['query']) ? '?' . $webui_url['query'] : '';
        $webui_query = preg_replace('/\[IP\]/', $ipv4, $webui_query);
        $webui_query = preg_replace('/\[PORT:(\d{1,5})\]/', ltrim($webui_port, ':'), $webui_query);
        $TS_webui_url = 'http://' . $ipv4 . $webui_port . $webui_path . $webui_query;
      }
    // Check if TailscaleWebUI in the xml is custom and display instead
    } elseif (strpos($xml['TailscaleWebUI'], '[hostname]') === false && strpos($xml['TailscaleWebUI'], '[noserve]') === false) {
      $TS_webui_url = $xml['TailscaleWebUI'];
    }
  }
}
?>
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.ui.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.switchbutton.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.filetree.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/plugins/dynamix.docker.manager/styles/style-{$display['theme']}.css")?>">

<script src="<?autov('/webGui/javascript/jquery.switchbutton.js')?>"></script>
<script src="<?autov('/webGui/javascript/jquery.filetree.js')?>" charset="utf-8"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/javascript/dynamix.vm.manager.js')?>"></script>
<script src="<?autov('/plugins/dynamix.docker.manager/javascript/markdown.js')?>"></script>
<script>
var confNum = 0;
var drivers = {};
<?foreach ($driver as $d => $v) echo "drivers['$d']='$v';\n";?>

if (!Array.prototype.forEach) {
  Array.prototype.forEach = function(fn, scope) {
    for (var i = 0, len = this.length; i < len; ++i) fn.call(scope, this[i], i, this);
  };
}
if (!String.prototype.format) {
  String.prototype.format = function() {
    var args = arguments;
    return this.replace(/{(\d+)}/g, function(match, number) {
      return typeof args[number] != 'undefined' ? args[number] : match;
    });
  };
}
if (!String.prototype.replaceAll) {
  String.prototype.replaceAll = function(str1, str2, ignore) {
    return this.replace(new RegExp(str1.replace(/([\/\,\!\\\^\$\{\}\[\]\(\)\.\*\+\?\|\<\>\-\&])/g,"\\$&"),(ignore?"gi":"g")),(typeof(str2)=="string")?str2.replace(/\$/g,"$$$$"):str2);
  };
}
// Create config nodes using templateDisplayConfig
function makeConfig(opts) {
  confNum += 1;
  var icons = {'Path':'folder-o', 'Port':'minus-square-o', 'Variable':'file-text-o', 'Label':'tags', 'Device':'play-circle-o'};
  var newConfig = $("#templateDisplayConfig").html();
  newConfig =  newConfig.format(
    stripTags(opts.Name),
    opts.Target,
    opts.Default,
    opts.Mode,
    opts.Description,
    opts.Type,
    opts.Display,
    opts.Required,
    opts.Mask,
    escapeQuote(opts.Value),
    opts.Buttons,
    opts.Required=='true' ? 'required' : '',
    sprintf('Container %s',opts.Type),
    icons[opts.Type] || 'question'
  );
  newConfig = "<div id='ConfigNum"+opts.Number+"' class='config_"+opts.Display+"'' >"+newConfig+"</div>";
  newConfig = $($.parseHTML(newConfig));
  value     = newConfig.find("input[name='confValue[]']");
  if (opts.Type == "Path") {
    value.attr("onclick", "openFileBrowser(this,$(this).val(),$(this).val(),'',true,false);");
  } else if (opts.Type == "Device") {
    value.attr("onclick", "openFileBrowser(this,'/dev','/dev','',true,true);")
  } else if (opts.Type == "Variable" && opts.Default.split("|").length > 1) {
    var valueOpts = opts.Default.split("|");
    var newValue = "<select name='confValue[]' class='selectVariable' default='"+valueOpts[0]+"'>";
    for (var i = 0; i < valueOpts.length; i++) {
      newValue += "<option value='"+valueOpts[i]+"' "+(opts.Value == valueOpts[i] ? "selected" : "")+">"+valueOpts[i]+"</option>";
    }
    newValue += "</select>";
    value.replaceWith(newValue);
  } else if (opts.Type == "Port") {
    value.addClass("numbersOnly");
  }
  if (opts.Mask == "true") {
    value.prop("autocomplete","new-password");
    value.prop("type", "password");
  }
  return newConfig.prop('outerHTML');
}

function stripTags(string) {
  return string.replace(/(<([^>]+)>)/ig,"");
}

function escapeQuote(string) {
  return string.replace(new RegExp('"','g'),"&quot;");
}

function makeAllocations(container,current) {
  var html = [];
  for (var i=0,ct; ct=container[i]; i++) {
    var highlight = ct.Name.toLowerCase()==current.toLowerCase() ? "font-weight:bold" : "";
    html.push($("#templateAllocations").html().format(highlight,ct.Name,ct.Port));
  }
  return html.join('');
}

function getVal(el, name) {
  var el = $(el).find("*[name="+name+"]");
  if (el.length) {
    return ($(el).attr('type') == 'checkbox') ? ($(el).is(':checked') ? "on" : "off") : $(el).val();
  } else {
    return "";
  }
}

function dialogStyle() {
  $('.ui-dialog-titlebar-close').css({'display':'none'});
  $('.ui-dialog-title').css({'text-align':'center','width':'100%','font-size':'1.8rem'});
  $('.ui-dialog-content').css({'padding-top':'15px','vertical-align':'bottom'});
  $('.ui-button-text').css({'padding':'0px 5px'});
}

function addConfigPopup() {
  var title = "_(Add Configuration)_";
  var popup = $("#dialogAddConfig");

  // Load popup the popup with the template info
  popup.html($("#templatePopupConfig").html());

  // Add switchButton to checkboxes
  popup.find(".switch").switchButton({labels_placement:"right",on_label:"_(Yes)_",off_label:"_(No)_"});
  popup.find(".switch-button-background").css("margin-top", "6px");

  // Load Mode field if needed and enable field
  toggleMode(popup.find("*[name=Type]:first"),false);

  // Start Dialog section
  popup.dialog({
    title: title,
    height: 'auto',
    width: 900,
    resizable: false,
    modal: true,
    buttons: {
    "_(Add)_": function() {
        $(this).dialog("close");
        confNum += 1;
        var Opts = Object;
        var Element = this;
        ["Name","Target","Default","Mode","Description","Type","Display","Required","Mask","Value"].forEach(function(e){
          Opts[e] = getVal(Element, e);
        });
        if (!Opts.Name){
          Opts.Name = makeName(Opts.Type);
        }

        if (Opts.Required == "true") {
          Opts.Buttons  = "<span class='advanced'><button type='button' onclick='editConfigPopup("+confNum+",false)'>_(Edit)_</button>";
          Opts.Buttons += "<button type='button' onclick='removeConfig("+confNum+")'>_(Remove)_</button></span>";
        } else {
          Opts.Buttons  = "<button type='button' onclick='editConfigPopup("+confNum+",false)'>_(Edit)_</button>";
          Opts.Buttons += "<button type='button' onclick='removeConfig("+confNum+")'>_(Remove)_</button>";
        }
        Opts.Number = confNum;
        if (Opts.Type == "Device") {
          Opts.Target = Opts.Value;
        }
        newConf = makeConfig(Opts);
        $("#configLocation").append(newConf);
        reloadTriggers();
        $('input[name="contName"]').trigger('change'); // signal change
      },
    "_(Cancel)_": function() {
        $(this).dialog("close");
      }
    }
  });
  dialogStyle();
}

function editConfigPopup(num,disabled) {
  var title = "_(Edit Configuration)_";
  var popup = $("#dialogAddConfig");

  // Load popup the popup with the template info
  popup.html($("#templatePopupConfig").html());

  // Load existing config info
  var config = $("#ConfigNum"+num);
  config.find("input").each(function(){
    var name = $(this).attr("name").replace("conf", "").replace("[]", "");
    popup.find("*[name='"+name+"']").val($(this).val());
  });

  // Hide passwords if needed
  if (popup.find("*[name='Mask']").val() == "true") {
    popup.find("*[name='Value']").prop("type", "password");
  }

  // Load Mode field if needed
  var mode = config.find("input[name='confMode[]']").val();
  toggleMode(popup.find("*[name=Type]:first"),disabled);
  popup.find("*[name=Mode]:first").val(mode);

  // Add switchButton to checkboxes
  popup.find(".switch").switchButton({labels_placement:"right",on_label:"_(Yes)_",off_label:"_(No)_"});

  // Start Dialog section
  popup.find(".switch-button-background").css("margin-top", "6px");
  popup.dialog({
    title: title,
    height: 'auto',
    width: 900,
    resizable: false,
    modal: true,
    buttons: {
    "_(Save)_": function() {
        $(this).dialog("close");
        var Opts = Object;
        var Element = this;
        ["Name","Target","Default","Mode","Description","Type","Display","Required","Mask","Value"].forEach(function(e){
          Opts[e] = getVal(Element, e);
        });
        if (Opts.Display == "always-hide" || Opts.Display == "advanced-hide") {
          Opts.Buttons  = "<span class='advanced'><button type='button' onclick='editConfigPopup("+num+",<?=$disableEdit?>)'>_(Edit)_</button>";
          Opts.Buttons += "<button type='button' onclick='removeConfig("+num+")'>_(Remove)_</button></span>";
        } else {
          Opts.Buttons  = "<button type='button' onclick='editConfigPopup("+num+",<?=$disableEdit?>)'>_(Edit)_</button>";
          Opts.Buttons += "<button type='button' onclick='removeConfig("+num+")'>_(Remove)_</button>";
        }
        if (!Opts.Name){
          Opts.Name = makeName(Opts.Type);
        }

        Opts.Number = num;
        if (Opts.Type == "Device") {
          Opts.Target = Opts.Value;
        }
        newConf = makeConfig(Opts);
        if (config.hasClass("config_"+Opts.Display)) {
          config.html(newConf);
          config.removeClass("config_always config_always-hide config_advanced config_advanced-hide").addClass("config_"+Opts.Display);
        } else {
          config.remove();
          if (Opts.Display == 'advanced' || Opts.Display == 'advanced-hide') {
            $("#configLocationAdvanced").append(newConf);
          } else {
            $("#configLocation").append(newConf);
          }
        }
       reloadTriggers();
        $('input[name="contName"]').trigger('change'); // signal change
      },
    "_(Cancel)_": function() {
        $(this).dialog("close");
      }
    }
  });
  dialogStyle();
  $('.desc_readmore').readmore({maxHeight:10});
}

function removeConfig(num) {
  $('#ConfigNum'+num).fadeOut("fast", function() {$(this).remove();});
  $('input[name="contName"]').trigger('change'); // signal change
}

function prepareConfig(form) {
  var types = [], values = [], targets = [], vcpu = [];
  if ($('select[name="contNetwork"]').val()=='host') {
    $(form).find('input[name="confType[]"]').each(function(){types.push($(this).val());});
    $(form).find('input[name="confValue[]"]').each(function(){values.push($(this));});
    $(form).find('input[name="confTarget[]"]').each(function(){targets.push($(this));});
    for (var i=0; i < types.length; i++) if (types[i]=='Port') $(targets[i]).val($(values[i]).val());
  }
  $(form).find('input[id^="box"]').each(function(){if ($(this).prop('checked')) vcpu.push($('#'+$(this).prop('id').replace('box','cpu')).text());});
  form.contCPUset.value = vcpu.join(',');
}

function makeName(type) {
  var i = $("#configLocation input[name^='confType'][value='"+type+"']").length+1;
  return "Host "+type.replace('Variable','Key')+" "+i;
}

function toggleMode(el,disabled) {
  var div        = $(el).closest('div');
  var targetDiv  = div.find('#Target');
  var valueDiv   = div.find('#Value');
  var defaultDiv = div.find('#Default');
  var mode       = div.find('#Mode');
  var value      = valueDiv.find('input[name=Value]');
  var target     = targetDiv.find('input[name=Target]');
  var driver     = drivers[$('select[name="contNetwork"]')[0].value];
  value.unbind();
  target.unbind();
  valueDiv.css('display', '');
  defaultDiv.css('display', '');
  targetDiv.css('display', '');
  mode.html('');
  $(el).prop('disabled',disabled);
  switch ($(el)[0].selectedIndex) {
  case 0: // Path
    mode.html("<dl><dt>_(Access Mode)_:</dt><dd><select name='Mode'><option value='rw'>_(Read/Write)_</option><option value='rw,slave'>_(Read/Write - Slave)_</option><option value='rw,shared'>_(Read/Write - Shared)_</option><option value='ro'>_(Read Only)_</option><option value='ro,slave'>_(Read Only - Slave)_</option><option value='ro,shared'>_(Read Only - Shared)_</option></select></dd></dl>");
    value.bind("click", function(){openFileBrowser(this,$(this).val(),$(this).val(),'',true,false);});
    targetDiv.find('#dt1').text("_(Container Path)_");
    valueDiv.find('#dt2').text("_(Host Path)_");
    break;
  case 1: // Port
    mode.html("<dl><dt>_(Connection Type)_:</dt><dd><select name='Mode'><option value='tcp'>_(TCP)_</option><option value='udp'>_(UDP)_</option></select></dd></dl>");
    value.addClass("numbersOnly");
    if (driver=='bridge') {
      if (target.val()) target.prop('disabled',<?=$disableEdit?>); else target.addClass("numbersOnly");
      targetDiv.find('#dt1').text("_(Container Port)_");
      targetDiv.show();
    } else {
      targetDiv.hide();
    }
    if (driver!='null') {
      valueDiv.find('#dt2').text("_(Host Port)_");
      valueDiv.show();
    } else {
      valueDiv.hide();
      mode.html('');
    }
    break;
  case 2: // Variable
    targetDiv.find('#dt1').text("_(Key)_");
    valueDiv.find('#dt2').text("_(Value)_");
    break;
  case 3: // Label
    targetDiv.find('#dt1').text("_(Key)_");
    valueDiv.find('#dt2').text("_(Value)_");
    break;
  case 4: // Device
    targetDiv.hide();
    defaultDiv.hide();
    valueDiv.find('#dt2').text("_(Value)_");
    value.bind("click", function(){openFileBrowser(this,'/dev','/dev','',true,true);});
    break;
  }
  reloadTriggers();
}

function loadTemplate(el) {
  var template = $(el).val();
  if (template.length) {
    $('#formTemplate').find("input[name='xmlTemplate']").val(template);
    $('#formTemplate').submit();
  }
}

function rmTemplate(tmpl) {
  var name = tmpl.split(/[\/]+/).pop();
  swal({title:"_(Are you sure)_?",text:"_(Remove template)_: "+name,type:"warning",html:true,showCancelButton:true,confirmButtonText:"_(Proceed)_",cancelButtonText:"_(Cancel)_"},function(){$("#rmTemplate").val(tmpl);$("#formTemplate1").submit();});
}

function openFileBrowser(el, top, root, filter, on_folders, on_files, close_on_select) {
  if (on_folders === undefined) on_folders = true;
  if (on_files   === undefined) on_files = true;
  if (!filter && !on_files) filter = 'HIDE_FILES_FILTER';
  if (!root.trim()) {root = "/mnt/user/"; top = "/mnt/";}
  p = $(el);
  // Skip if fileTree is already open
  if (p.next().hasClass('fileTree')) return null;
  // create a random id
  var r = Math.floor((Math.random()*10000)+1);
  // Add a new span and load fileTree
  p.after("<span id='fileTree"+r+"' class='textarea fileTree'></span>");
  var ft = $('#fileTree'+r);
  ft.fileTree({top:top, root:root, filter:filter, allowBrowsing:true},
    function(file){if(on_files){p.val(file);p.trigger('change');if(close_on_select){ft.slideUp('fast',function(){ft.remove();});}}},
    function(folder){if(on_folders){p.val(folder.replace(/\/\/+/g,'/'));p.trigger('change');if(close_on_select){$(ft).slideUp('fast',function(){$(ft).remove();});}}}
  );
  // Format fileTree according to parent position, height and width
  ft.css({'left':p.position().left,'top':(p.position().top+p.outerHeight()),'width':(p.width())});
  // close if click elsewhere
  $(document).mouseup(function(e){if(!ft.is(e.target) && ft.has(e.target).length === 0){ft.slideUp('fast',function(){$(ft).remove();});}});
  // close if parent changed
  p.bind("keydown", function(){ft.slideUp('fast', function(){$(ft).remove();});});
  // Open fileTree
  ft.slideDown('fast');
}

function resetField(el) {
  var target = $(el).prev();
  reset = target.attr("default");
  if (reset.length) target.val(reset);
}

function prepareCategory() {
  var values = $.map($('#catSelect option'),function(option) {
    if ($(option).is(":selected")) return option.value;
  });
  $("input[name='contCategory']").val(values.join(" "));
}

$(function() {
  var ctrl = "<span class='status <?=$tabbed?'':'vhshift'?>'><input type='checkbox' class='advancedview'></span>";
<?if ($tabbed):?>
  $('.tabs').append(ctrl);
<?else:?>
  $('div[class=title]').append(ctrl);
<?endif;?>
  $('.advancedview').switchButton({labels_placement:'left', on_label: "_(Advanced View)_", off_label: "_(Basic View)_"});
  $('.advancedview').change(function() {
    var status = $(this).is(':checked');
    toggleRows('advanced', status, 'basic');
    load_contOverview();
    $("#catSelect").dropdownchecklist("destroy");
    $("#catSelect").dropdownchecklist({emptyText:"_(Select categories)_...", maxDropHeight:200, width:300, explicitClose:"..._(close)_"});
  });
});
</script>

<?php
if (isset($xml["Config"])) {
  foreach ($xml["Config"] as $config) {
    if (isset($config["Target"]) && is_array($config) && strpos($config["Target"], "TAILSCALE_") === 0) {
      $tailscaleTargetFound = true;
      break;
    }
  }
}
?>

<div id="canvas">
<form markdown="1" method="POST" autocomplete="off" onsubmit="prepareConfig(this)">
<input type="hidden" name="csrf_token" value="<?=$var['csrf_token']?>">
<input type="hidden" name="contCPUset" value="">
<?if ($xmlType=='edit'):?>
<?if ($DockerClient->doesContainerExist($templateName)):?>
<input type="hidden" name="existingContainer" value="<?=$templateName?>">
<?endif;?>
<?else:?>
<div markdown="1" class="TemplateDropDown">
_(Template)_:
: <select id="TemplateSelect" onchange="loadTemplate(this);">
  <?echo mk_option(0,"",_('Select a template'));
  $rmadd = '';
  $templates = [];
  $templates['default'] = $DockerTemplates->getTemplates('default');
  $templates['user'] = $DockerTemplates->getTemplates('user');
  foreach ($templates as $section => $template) {
    $title = ucfirst($section)." templates";
    printf("<optgroup class='title bold' label='[ %s ]'>", htmlspecialchars($title));
    foreach ($template as $value){
      if ( $value['name'] == "my-ca_profile" || $value['name'] == "ca_profile" ) continue;
      $name = str_replace('my-', '', $value['name']);
      $selected = (isset($xmlTemplate) && $value['path']==$xmlTemplate) ? ' selected ' : '';
      if ($selected && $section=='default') $showAdditionalInfo = 'advanced';
      if ($selected && $section=='user') $rmadd = $value['path'];
      printf("<option class='list' value='%s:%s' $selected>%s</option>", htmlspecialchars($section), htmlspecialchars($value['path']), htmlspecialchars($name));
    }
    if (!$template) echo("<option class='list' disabled>&lt;"._('None')."&gt;</option>");
    printf("</optgroup>");
  }
  ?></select><?if ($rmadd):?><i class="fa fa-window-close button" title="<?=htmlspecialchars($rmadd)?>" onclick="rmTemplate('<?=addslashes(htmlspecialchars($rmadd))?>')"></i><?endif;?>

:docker_client_general_help:

</div>
<?endif;?>

<div markdown="1" class="<?=$showAdditionalInfo?>">
_(Name)_:
: <input type="text" name="contName" pattern="[a-zA-Z0-9][a-zA-Z0-9_.\-]+" required>

:docker_client_name_help:

</div>
<div markdown="1" class="basic">
_(Overview)_:
: <span id="contDescription" class="boxed blue-text"></span>

</div>
<div markdown="1" class="advanced">
_(Overview)_:
: <textarea name="contOverview" spellcheck="false" cols="80" rows="15" style="width:56%"></textarea>

:docker_client_overview_help:

</div>
<div markdown="1" class="basic">
_(Additional Requirements)_:
: <span id="contRequires" class="boxed blue-text"></span>

</div>
<div markdown="1" class="advanced">
_(Additional Requirements)_:
: <textarea name="contRequires" spellcheck="false" cols="80" Rows="3" style="width:56%"></textarea>

:docker_client_additional_requirements_help:

</div>

<div markdown="1" class="<?=$showAdditionalInfo?>">
_(Repository)_:
: <input type="text" name="contRepository" required>

:docker_client_repository_help:

</div>
<div markdown="1" class="<?=$authoring?>">
_(Categories)_:
: <input type="hidden" name="contCategory">
  <select id="catSelect" size="1" multiple="multiple" style="display:none" onchange="prepareCategory();">
  <optgroup label="_(Categories)_">
  <option value="AI:">_(AI)_</option>
  <option value="Backup:">_(Backup)_</option>
  <option value="Cloud:">_(Cloud)_</option>
  <option value="Crypto:">_(Crypto Currency)_</option>
  <option value="Downloaders:">_(Downloaders)_</option>
  <option value="Drivers:">_(Drivers)_</option>
  <option value="GameServers:">_(Game Servers)_</option>
  <option value="HomeAutomation:">_(Home Automation)_</option>
  <option value="Productivity:">_(Productivity)_</option>
  <option value="Security:">_(Security)_</option>
  <option value="Tools:">_(Tools)_</option>
  <option value="Other:">_(Other)_</option>
  </optgroup>
  <optgroup label="_(MediaApp)_">
  <option value="MediaApp:Video">_(MediaApp)_:_(Video)_</option>
  <option value="MediaApp:Music">_(MediaApp)_:_(Music)_</option>
  <option value="MediaApp:Books">_(MediaApp)_:_(Books)_</option>
  <option value="MediaApp:Photos">_(MediaApp)_:_(Photos)_</option>
  <option value="MediaApp:Other">_(MediaApp)_:_(Other)_</option>
  </optgroup>
  <optgroup label="_(MediaServer)_">
  <option value="MediaServer:Video">_(MediaServer)_:_(Video)_</option>
  <option value="MediaServer:Music">_(MediaServer)_:_(Music)_</option>
  <option value="MediaServer:Books">_(MediaServer)_:_(Books)_</option>
  <option value="MediaServer:Photos">_(MediaServer)_:_(Photos)_</option>
  <option value="MediaServer:Other">_(MediaServer)_:_(Other)_</option>
  </optgroup>
  <optgroup label="_(Network)_">
  <option value="Network:Web">_(Network)_:_(Web)_</option>
  <option value="Network:DNS">_(Network)_:_(DNS)_</option>
  <option value="Network:FTP">_(Network)_:_(FTP)_</option>
  <option value="Network:Proxy">_(Network)_:_(Proxy)_</option>
  <option value="Network:Voip">_(Network)_:_(Voip)_</option>
  <option value="Network:Management">_(Network)_:_(Management)_</option>
  <option value="Network:Messenger">_(Network)_:_(Messenger)_</option>
  <option value="Network:VPN">_(Network)_:_(VPN)_</option>
  <option value="Network:Privacy">_(Network)_:_(Privacy)_</option>
  <option value="Network:Other">_(Network)_:_(Other)_</option>
  </optgroup>
  <optgroup label="_(Development Status)_">
  <option value="Status:Stable">_(Status)_:_(Stable)_</option>
  <option value="Status:Beta">_(Status)_:_(Beta)_</option>
  </optgroup>
  </select>

_(Support Thread)_:
: <input type="text" name="contSupport">

:docker_client_support_thread_help:

_(Project Page)_:
: <input type="text" name="contProject">

:docker_client_project_page_help:

_(Read Me First)_:
: <input type="text" name="contReadMe">

:docker_client_readme_help:

</div>
<div markdown="1" class="advanced">
_(Registry URL)_:
: <input type="text" name="contRegistry"></td>

:docker_client_hub_url_help:

</div>
<div markdown="1" class="noshow"> <!-- Deprecated for author to enter or change, but needs to be present -->
Donation Text:
: <input type="text" name="contDonateText">

Donation Link:
: <input type="text" name="contDonateLink">

Template URL:
: <input type="text" name="contTemplateURL">

</div>
<div markdown="1" class="advanced">
_(Icon URL)_:
: <input type="text" name="contIcon">

:docker_client_icon_url_help:

_(WebUI)_:
: <input type="text" name="contWebUI">

:docker_client_webui_help:

_(Extra Parameters)_:
: <input type="text" name="contExtraParams">

:docker_extra_parameters_help:

_(Post Arguments)_:
: <input type="text" name="contPostArgs">

:docker_post_arguments_help:

_(CPU Pinning)_:
: <span style="display:inline-block"><?cpu_pinning()?></span>

:docker_cpu_pinning_help:

</div>
_(Network Type)_:
: <select name="contNetwork" onchange="showSubnet(this.value)">
  <?=mk_option(1,'bridge',_('Bridge'))?>
  <?=mk_option(1,'host',_('Host'))?>
  <?=mk_option(1,'container',_('Container'))?>
  <?=mk_option(1,'none',_('None'))?>
  <?foreach ($custom as $network):?>
  <?$name = $network;
  if (preg_match('/^(br|bond|eth)[0-9]+(\.[0-9]+)?$/',$network)) {
    [$eth,$x] = my_explode('.',$network);
    $eth = str_replace(['br','bond'],'eth',$eth);
    $n = $x ? 1 : 0; while (isset($$eth["VLANID:$n"]) && $$eth["VLANID:$n"] != $x) $n++;
    if ($$eth["DESCRIPTION:$n"]) $name .= ' -- '.compress(trim($$eth["DESCRIPTION:$n"]));
  } elseif (preg_match('/^wg[0-9]+$/',$network)) {
    $conf = file("/etc/wireguard/$network.conf");
    if ($conf[1][0]=='#') $name .= ' -- '.compress(trim(substr($conf[1],1)));
  }
  ?>
  <?=mk_option(1,$network,_('Custom')." : $name")?>
  <?endforeach;?></select>

<div markdown="1" class="myIP noshow">
_(Fixed IP address)_ (_(optional)_):
: <input type="text" name="contMyIP"><span id="myIP"></span>

:docker_fixed_ip_help:

</div>

<div markdown="1" class="netCONT noshow">
_(Container Network)_:
: <select name="netCONT" id="netCONT">
  <?php
  $container_name = !empty($xml['Name']) ? $xml['Name'] : '';
  foreach ($DockerClient->getDockerContainers() as $ct) {
    if ($ct['Name'] !== $container_name) {
      $list[] = $ct['Name'];
      echo mk_option($ct['Name'], $ct['Name'], $ct['Name']);
    }
  }
  ?>
</select>

:docker_container_network_help:

</div>

<div markdown="1" class="TSdivider noshow"><hr></div>

<?if ($TS_existing_vars == 'true'):?>
<div markdown="1" class="TSwarning noshow">
<b style="color:red;">_(WARNING)_</b>:
:  <b>_(Existing TAILSCALE variables found, please remove any existing modifications in the Template for Tailscale before using this function!)_</b>
</div>
<?endif;?>

<?if (empty($xml['TailscaleEnabled'])):?>
<div markdown="1" class="TSdeploy noshow">
<b>_(First deployment)_</b>:
:  <p>_(After deploying the container, open the log and follow the link to register the container to your Tailnet!)_</p>
</div>

<?if (!file_exists('/usr/local/sbin/tailscale')):?>
<div markdown="1" class="TSdeploy noshow">
<b>_(Recommendation)_</b>:
:  <p>_(For the best experience with Tailscale, install "Tailscale (Plugin)" from)_ <a href="/Apps?search=Tailscale%20(Plugin)" target='_blank'> Community Applications</a>.</p>
</div>
<?endif;?>

<?endif;?>

<div markdown="1">
_(Use Tailscale)_:
: <input type="checkbox" class="switch-on-off" name="contTailscale" id="contTailscale" <?php if (!empty($xml['TailscaleEnabled']) && $xml['TailscaleEnabled'] == 'true') echo 'checked'; ?> onchange="showTailscale(this)">

:docker_tailscale_help:

</div>

<div markdown="1" class="TSdivider noshow">
<b>_(NOTE)_</b>:
:  <i>_(This option will install Tailscale and dependencies into the container.)_</i>
</div>

<?if($TS_ExitNodeNeedsApproval):?>
<div markdown="1" class="TShostname noshow">
<b>Warning:</b>
: Exit Node not yet approved. Navigate to the <a href="<?=$TS_DirectMachineLink?>" target='_blank'>Tailscale website</a> and approve it.
</div>
<?endif;?>

<?if(!empty($TS_expiry_diff)):?>
<div markdown="1" class="TSdivider noshow">
<b>_(Warning)_</b>:
<?if($TS_expiry_diff->invert):?>
: <b>Tailscale Key expired!</b> <a href="<?=$TS_MachinesLink?>" target='_blank'>Renew/Disable key expiry</a> for '<b><?=$TS_HostNameActual?></b>'.
<?else:?>
: Tailscale Key will expire in <b><?=$TS_expiry_diff->days?> days</b>! <a href="<?=$TS_MachinesLink?>" target='_blank'>Disable Key Expiry</a> for '<b><?=$TS_HostNameActual?></b>'.
<?endif;?>
<label>See <a href="https://tailscale.com/kb/1028/key-expiry" target='_blank'>key-expiry</a>.</label>
</div>
<?endif;?>

<?if(!empty($TS_not_approved)):?>
<div markdown="1" class="TSdivider noshow">
<b>_(Warning)_</b>:
: The following route(s) are not approved: <b><?=trim($TS_not_approved)?></b>
</div>
<?endif;?>

<div markdown="1" class="TShostname noshow">
_(Tailscale Hostname)_:
: <input type="text" pattern="[A-Za-z0-9_\-]*" name="TShostname" <?php if (!empty($xml['TailscaleHostname'])) echo 'value="' . $xml['TailscaleHostname'] . '"'; ?> placeholder="_(Hostname for the container)_"> <?=$TS_HostNameWarning?>

:docker_tailscale_hostname_help:

</div>

<div markdown="1" class="TSisexitnode noshow">
_(Be a Tailscale Exit Node)_:
: <select name="TSisexitnode" id="TSisexitnode" onchange="showTailscale(this)">
    <?=mk_option(1,'false',_('No'))?>
    <?=mk_option(1,'true',_('Yes'))?>
  </select>
  <span id='TSisexitnode_msg' style='font-style: italic;'></span>

:docker_tailscale_be_exitnode_help:

</div>

<div markdown="1" class="TSexitnodeip noshow">
_(Use a Tailscale Exit Node)_:
<?if($ts_en_check !== true && empty($ts_exit_nodes)):?>
: <input type="text" name="TSexitnodeip" <?php if (!empty($xml['TailscaleExitNodeIP'])) echo 'value="' . $xml['TailscaleExitNodeIP'] . '"'; ?> placeholder="_(IP/Hostname from Exit Node)_" onchange="processExitNodeoptions(this)">
<?else:?>
: <select name="TSexitnodeip" id="TSexitnodeip" onchange="processExitNodeoptions(this)">
  <?=mk_option(1,'',_('None'))?>
  <?foreach ($ts_exit_nodes as $ts_exit_node):?>
    <?=$node_offline = $ts_exit_node['status'] === 'offline' ? ' - OFFLINE' : '';?>
    <?=mk_option(1,$ts_exit_node['ip'],$ts_exit_node['ip'] . ' - ' . $ts_exit_node['hostname'] . $node_offline)?>
  <?endforeach;?></select>
<?endif;?>
  </select>
  <span id='TSexitnodeip_msg' style='font-style: italic;'></span>

:docker_tailscale_exitnode_ip_help:

</div>

<div markdown="1" class="TSallowlanaccess noshow">
_(Tailscale Allow LAN Access)_:
: <select name="TSallowlanaccess" id="TSallowlanaccess">
    <?=mk_option(1,'false',_('No'))?>
    <?=mk_option(1,'true',_('Yes'))?>
  </select>

:docker_tailscale_lanaccess_help:

</div>

<div markdown="1" class="TSuserspacenetworking noshow">
_(Tailscale Userspace Networking)_:
: <select name="TSuserspacenetworking" id="TSuserspacenetworking" onchange="setExitNodeoptions()">
    <?=mk_option(1,'true',_('Enabled'))?>
    <?=mk_option(1,'false',_('Disabled'))?>
  </select>
  <span id='TSuserspacenetworking_msg' style='font-style: italic;'></span>

:docker_tailscale_userspace_networking_help:

</div>

<div markdown="1" class="TSssh noshow">
_(Enable Tailscale SSH)_:
: <select name="TSssh" id="TSssh">
    <?=mk_option(1,'false',_('No'))?>
    <?=mk_option(1,'true',_('Yes'))?>
  </select>

:docker_tailscale_ssh_help:

</div>

<div markdown="1" class="TSserve noshow">
_(Tailscale Serve)_:
: <select name="TSserve" id="TSserve" onchange="showServe(this.value)">
    <?=mk_option(1,'no',_('No'))?>
    <?=mk_option(1,'serve',_('Serve'))?>
    <?=mk_option(1,'funnel',_('Funnel'))?>
  </select>
<?=$TS_HTTPSDisabledWarning?><?php if (!empty($TS_webui_url)) echo '<label for="TSserve"><a href="' . $TS_webui_url . '" target="_blank">' . $TS_webui_url . '</a></label>'; ?>

:docker_tailscale_serve_mode_help:

</div>

<div markdown="1" class="TSserveport noshow">
_(Tailscale Serve Port)_:
: <input type="text" name="TSserveport" value="<?php echo !empty($xml['TailscaleServePort']) ? $xml['TailscaleServePort'] : (!empty($TSwebuiport) ? $TSwebuiport : ''); ?>" placeholder="_(Will be detected automatically if possible)_">

:docker_tailscale_serve_port_help:

</div>

<div markdown="1" class="TSadvanced noshow">
_(Tailscale Show Advanced Settings)_:
: <input type="checkbox" name="TSadvanced" class="switch-on-off" onchange="showTSAdvanced(this.checked)">

:docker_tailscale_show_advanced_help:

</div>

<div markdown="1" class="TSservelocalpath noshow">
_(Tailscale Serve Local Path)_:
: <input type="text" name="TSservelocalpath" <?php if (!empty($xml['TailscaleServeLocalPath'])) echo 'value="' . $xml['TailscaleServeLocalPath'] . '"'; ?> placeholder="_(Leave empty if unsure)_">

:docker_tailscale_serve_local_path_help:

</div>

<div markdown="1" class="TSserveprotocol noshow">
_(Tailscale Serve Protocol)_:
: <input type="text" name="TSserveprotocol" <?php if (!empty($xml['TailscaleServeProtocol'])) echo 'value="' . $xml['TailscaleServeProtocol'] . '"'; ?> placeholder="_(Leave empty if unsure, defaults to https)_">

:docker_tailscale_serve_protocol_help:

</div>

<div markdown="1" class="TSserveprotocolport noshow">
_(Tailscale Serve Protocol Port)_:
: <input type="text" name="TSserveprotocolport" <?php if (!empty($xml['TailscaleServeProtocolPort'])) echo 'value="' . $xml['TailscaleServeProtocolPort'] . '"'; ?> placeholder="_(Leave empty if unsure, defaults to =443)_">

:docker_tailscale_serve_protocol_port_help:

</div>

<div markdown="1" class="TSservepath noshow">
_(Tailscale Serve Path)_:
: <input type="text" name="TSservepath" <?php if (!empty($xml['TailscaleServePath'])) echo 'value="' . $xml['TailscaleServePath'] . '"'; ?> placeholder="_(Leave empty if unsure)_">

:docker_tailscale_serve_path_help:

</div>

<div markdown="1" class="TSwebui noshow">
_(Tailscale WebUI)_:
: <input type="text" name="TSwebui" value="<?php echo !empty($TS_webui_url) ? $TS_webui_url : ''; ?>" placeholder="Will be determined automatically if possible" disabled>
<input type="hidden" name="TSwebui" <?php if (!empty($xml['TailscaleWebUI'])) echo 'value="' . $xml['TailscaleWebUI'] . '"'; ?>>

:docker_tailscale_serve_webui_help:

</div>

<div markdown="1" class="TSroutes noshow">
_(Tailscale Advertise Routes)_:
: <input type="text" pattern="[0-9:., ]*" name="TSroutes" <?php if (!empty($xml['TailscaleRoutes'])) echo 'value="' . $xml['TailscaleRoutes'] . '"'?> placeholder="_(Leave empty if unsure)_">

:docker_tailscale_advertise_routes_help:

</div>

<div markdown="1" class="TSacceptroutes noshow">
_(Tailscale Accept Routes)_:
: <select name="TSacceptroutes" id="TSacceptroutes">
    <?=mk_option(1,'false',_('No'))?>
    <?=mk_option(1,'true',_('Yes'))?>
  </select>

:docker_tailscale_accept_routes_help:

</div>

<div markdown="1" class="TSdaemonparams noshow">
_(Tailscale Daemon Parameters)_:
: <input type="text" name="TSdaemonparams" <?php if (!empty($xml['TailscaleDParams'])) echo 'value="' . $xml['TailscaleDParams'] . '"'; ?> placeholder="_(Leave empty if unsure)_">

:docker_tailscale_daemon_extra_params_help:

</div>

<div markdown="1" class="TSextraparams noshow">
_(Tailscale Extra Parameters)_:
: <input type="text" name="TSextraparams" <?php if (!empty($xml['TailscaleParams'])) echo 'value="' . $xml['TailscaleParams'] . '"'; ?> placeholder="_(Leave empty if unsure)_">

:docker_tailscale_extra_param_help:

</div>

<div markdown="1" class="TSstatedir noshow">
_(Tailscale State Directory)_:
: <input type="text" name="TSstatedir" <?php if (!empty($xml['TailscaleStateDir'])) echo 'value="' . $xml['TailscaleStateDir'] . '"'; ?> placeholder="_(Leave empty if unsure)_">

:docker_tailscale_statedir_help:

</div>

<div markdown="1" class="TStroubleshooting noshow">
_(Tailscale Install Troubleshooting Packages)_:
: <input type="checkbox" class="switch-on-off" name="TStroubleshooting" <?php if (!empty($xml['TailscaleTroubleshooting']) && $xml['TailscaleTroubleshooting'] == 'true') echo 'checked'; ?>>

:docker_tailscale_troubleshooting_packages_help:

</div>

<div markdown="1" class="TSdivider noshow">
  <hr>
</div>

_(Console shell command)_:
: <select name="contShell">
  <?=mk_option(1,'sh',_('Shell'))?>
  <?=mk_option(1,'bash',_('Bash'))?>
  </select>

_(Privileged)_:
: <input type="checkbox" class="switch-on-off" name="contPrivileged">

:docker_privileged_help:

<div id="configLocation"></div>

&nbsp;
: <span id="readmore_toggle" class="readmore_collapsed"><a onclick="toggleReadmore()" style="cursor:pointer"><i class="fa fa-fw fa-chevron-down"></i> _(Show more settings)_ ...</a></span><div id="configLocationAdvanced" style="display:none"></div>

&nbsp;
: <span id="allocations_toggle" class="readmore_collapsed"><a onclick="toggleAllocations()" style="cursor:pointer"><i class="fa fa-fw fa-chevron-down"></i> _(Show docker allocations)_ ...</a></span><div id="dockerAllocations" style="display:none"></div>

&nbsp;
: <a href="javascript:addConfigPopup()"><i class="fa fa-fw fa-plus"></i> _(Add another Path, Port, Variable, Label or Device)_</a>

&nbsp;
: <input type="submit" value="<?=$xmlType=='edit' ? "_(Apply)_" : " _(Apply)_ "?>"><input type="button" value="_(Done)_" onclick="done()">
  <?if ($authoringMode):?><button type="submit" name="dryRun" value="true" onclick="$('*[required]').prop('required', null);">_(Save)_</button><?endif;?>

</form>
</div>

<form method="GET" id="formTemplate">
  <input type="hidden" id="xmlTemplate" name="xmlTemplate" value="">
</form>
<form method="POST" id="formTemplate1">
  <input type="hidden" name="csrf_token" value="<?=$var['csrf_token']?>">
  <input type="hidden" id="rmTemplate" name="rmTemplate" value="">
</form>

<div id="dialogAddConfig" style="display:none"></div>

<?
#        ██╗███████╗    ████████╗███████╗███╗   ███╗██████╗ ██╗      █████╗ ████████╗███████╗███████╗
#        ██║██╔════╝    ╚══██╔══╝██╔════╝████╗ ████║██╔══██╗██║     ██╔══██╗╚══██╔══╝██╔════╝██╔════╝
#        ██║███████╗       ██║   █████╗  ██╔████╔██║██████╔╝██║     ███████║   ██║   █████╗  ███████╗
#   ██   ██║╚════██║       ██║   ██╔══╝  ██║╚██╔╝██║██╔═══╝ ██║     ██╔══██║   ██║   ██╔══╝  ╚════██║
#   ╚█████╔╝███████║       ██║   ███████╗██║ ╚═╝ ██║██║     ███████╗██║  ██║   ██║   ███████╗███████║
#    ╚════╝ ╚══════╝       ╚═╝   ╚══════╝╚═╝     ╚═╝╚═╝     ╚══════╝╚═╝  ╚═╝   ╚═╝   ╚══════╝╚══════╝
?>
<div markdown="1" id="templatePopupConfig" style="display:none">
_(Config Type)_:
: <select name="Type" onchange="toggleMode(this,false)">
  <option value="Path">_(Path)_</option>
  <option value="Port">_(Port)_</option>
  <option value="Variable">_(Variable)_</option>
  <option value="Label">_(Label)_</option>
  <option value="Device">_(Device)_</option>
  </select>

_(Name)_:
: <input type="text" name="Name" autocomplete="off" spellcheck="false">

<div markdown="1" id="Target">
<span id="dt1">_(Target)_</span>:
: <input type="text" name="Target" autocomplete="off" spellcheck="false">
</div>

<div markdown="1" id="Value">
<span id="dt2">_(Value)_</span>:
: <input type="text" name="Value" autocomplete="off" spellcheck="false">
</div>

<div markdown="1" id="Default">
_(Default Value)_:
: <input type="text" name="Default" autocomplete="off" spellcheck="false">
</div>

<div id="Mode"></div>

_(Description)_:
: <textarea name="Description" spellcheck="false" cols="80" rows="3" style="width:304px;"></textarea>

<div markdown="1" class="advanced">
_(Display)_:
: <select name="Display">
  <option value="always" selected>_(Always)_</option>
  <option value="always-hide">_(Always)_ - _(Hide Buttons)_</option>
  <option value="advanced">_(Advanced)_</option>
  <option value="advanced-hide">_(Advanced)_ - _(Hide Buttons)_</option>
  </select>

_(Required)_:
: <select name="Required">
  <option value="false" selected>_(No)_</option>
  <option value="true">_(Yes)_</option>
  </select>

_(Password Mask)_:
: <select name="Mask">
  <option value="false" selected>_(No)_</option>
  <option value="true">_(Yes)_</option>
  </select>
</div>
</div>

<div markdown="1" id="templateDisplayConfig" style="display:none">
<input type="hidden" name="confName[]" value="{0}">
<input type="hidden" name="confTarget[]" value="{1}">
<input type="hidden" name="confDefault[]" value="{2}">
<input type="hidden" name="confMode[]" value="{3}">
<input type="hidden" name="confDescription[]" value="{4}">
<input type="hidden" name="confType[]" value="{5}">
<input type="hidden" name="confDisplay[]" value="{6}">
<input type="hidden" name="confRequired[]" value="{7}">
<input type="hidden" name="confMask[]" value="{8}">
<span class="{11}"><i class="fa fa-fw fa-{13}"></i>&nbsp;&nbsp;{0}:</span>
: <span class="boxed"><input type="text" class="setting_input" name="confValue[]" default="{2}" value="{9}" autocomplete="off" spellcheck="false" {11}>{10}<br><span class='orange-text'>{12}: {1}</span><br><span class="orange-text">{4}</span><br></span>
</div>

<div markdown="1" id="templateAllocations" style="display:none">
&nbsp;
: <span class="boxed"><span class="ct">{1}</span>{2}</span>
</div>

<script>
var subnet = {};
<?foreach ($subnet as $network => $value):?>
subnet['<?=$network?>'] = '<?=$value?>';
<?endforeach;?>

function showSubnet(bridge) {
  if (bridge.match(/^(bridge|host|none)$/i) !== null) {
    $('.myIP').hide();
    $('input[name="contMyIP"]').val('');
    $('.netCONT').hide();
    $('#netCONT').val('');
  } else if (bridge.match(/^(container)$/i) !== null) {
    $('.netCONT').show();
    $('#netCONT').val('<?php echo (isset($xml) && isset($xml['Network'][1])) ? $xml['Network'][1] : ''; ?>');
    $('.myIP').hide();
    $('input[name="contMyIP"]').val('');
  } else {
    $('.myIP').show();
    $('#myIP').html('Subnet: '+subnet[bridge]);
    $('.netCONT').hide();
    $('#netCONT').val('');
  }
  // make sure to re-trigger Tailscale check when network is changed
  if ($('#contTailscale').prop('checked')) {
    showTailscale(true);
  }
}

function processExitNodeoptions(value) {
  val = null;
  if (value.tagName.toLowerCase() === "input") {
    val = value.value.trim();
  } else if (value.tagName.toLowerCase() === "select") {
    val = value.value;
  }
  if (val) {
    $('.TSallowlanaccess').show();
  } else {
    $('#TSallowlanaccess').val('false');
    $('.TSallowlanaccess').hide();
  }
  setUserspaceNetworkOptions();
  setIsExitNodeoptions();
}

function setUserspaceNetworkOptions() {
  optTrueDisabled = false;
  optFalseDisabled = false;
  optMessage = "";
  value = null;

  var network = $('select[name="contNetwork"]')[0].value;
  var isExitnode = $('#TSisexitnode').val();
  if (network == 'host' || isExitnode == 'true') {
    // in host mode or if this container is an Exit Node
    // then Userspace Networking MUST be enabled ('true')
    value = 'true';
    optTrueDisabled = false;
    optFalseDisabled = true;
    optMessage = (isExitnode == 'true') ? "Enabled because this is an Exit Node" : "Enabled due to Docker "+network+" mode";
  } else {
    if (document.querySelector('input[name="TSexitnodeip"], select[name="TSexitnodeip"]').value) {
      // If an Exit Node IP is set, Userspace Networking MUST be disabled ('false')
      value = 'false';
      optTrueDisabled = true;
      optFalseDisabled = false;
      optMessage = "Disabled due to use of an Exit Node";
    } else {
      // Exit Node IP is not set, user can decide whether to enable/disable Userspace Networking
      optTrueDisabled = false;
      optFalseDisabled = false;
      optMessage = "";
    }
  }

  $("#TSuserspacenetworking option[value='true']").prop("disabled", optTrueDisabled);
  $("#TSuserspacenetworking option[value='false']").prop("disabled", optFalseDisabled);
  if (value != null) $('#TSuserspacenetworking').val(value);
  $('#TSuserspacenetworking_msg').text(optMessage);
  setExitNodeoptions();
}

function setIsExitNodeoptions() {
  optTrueDisabled = false;
  optFalseDisabled = false;
  optMessage = "";
  value = null;

  var network = $('select[name="contNetwork"]')[0].value;
  if (network == 'host') {
    // in host mode then this cannot be an Exit Node
    value = 'false';
    optTrueDisabled = true;
    optFalseDisabled = false;
    optMessage = "Disabled due to Docker "+network+" mode";
  } else {
    if (document.querySelector('input[name="TSexitnodeip"], select[name="TSexitnodeip"]').value) {
      // If an Exit Node IP is set, this cannot be an Exit Node
      value = 'false';
      optTrueDisabled = true;
      optFalseDisabled = false;
      optMessage = "Disabled due to use of an Exit Node";
    } else {
      optTrueDisabled = false;
      optFalseDisabled = false;
    }
  }
  $("#TSisexitnode option[value='true']").prop("disabled", optTrueDisabled);
  $("#TSisexitnode option[value='false']").prop("disabled", optFalseDisabled);
  if (value != null) $('#TSisexitnode').val(value);
  $('#TSisexitnode_msg').text(optMessage);
}

function setExitNodeoptions() {
  optMessage = "";
  var $exitNodeInput = $('input[name="TSexitnodeip"]');
  var $exitNodeSelect = $('#TSexitnodeip');
  // In host mode, TSuserspacenetworking is true
  if ($('#TSuserspacenetworking').val() == 'true') {
    // if TSuserspacenetworking is true, then TSexitnodeip must be "" and all options are disabled
    optMessage = "Disabled because Userspace Networking is Enabled.";
    $exitNodeInput.val('').prop('disabled', true);  // Disable the input field
    $exitNodeSelect.val('').prop('disabled', true).find('option').each(function() {
      if ($(this).val() === "") {
        $(this).prop('disabled', false);  // Enable the option with value=""
      } else {
        $(this).prop('disabled', true);   // Disable all other options
      }
    });
  } else {
    // if TSuserspacenetworking is false, then all TSexitnodeip options can be enabled
    $exitNodeInput.prop('disabled', false);  // Enable the input field
    $exitNodeSelect.prop('disabled', false).find('option').each(function() {
      $(this).prop('disabled', false);   // Enable all options
    });
  }
  $('#TSexitnodeip_msg').text(optMessage);
}

function showTSAdvanced(checked) {
  if (!checked) {
    <?if (!empty($TSwebuiport)):?>
      $('.TSserveport').hide();
    <?elseif (empty($contTailscale) || $contTailscale == 'false'):?>
      $('.TSserveport').hide();
    <?else:?>
      $('.TSserveport').show();
    <?endif;?>
    $('.TSdaemonparams').hide();
    $('.TSextraparams').hide();
    $('.TSstatedir').hide();
    $('.TSservepath').hide();
    $('.TSserveprotocol').hide();
    $('.TSserveprotocolport').hide();
    $('.TSservelocalpath').hide();
    $('.TSwebui').hide();
    $('.TStroubleshooting').hide();
    $('.TSroutes').hide();
    $('.TSacceptroutes').hide();
  } else {
    $('.TSdaemonparams').show();
    $('.TSextraparams').show();
    $('.TSstatedir').show();
    $('.TSserveport').show();
    $('.TSservepath').show();
    $('.TSserveprotocol').show();
    $('.TSserveprotocolport').show();
    $('.TSservelocalpath').show();
    $('.TSwebui').show();
    $('.TStroubleshooting').show();
    $('.TSroutes').show();
    $('.TSacceptroutes').show();
  }
}

function showTailscale(source) {
  if (!$.trim($('#TSallowlanaccess').val())) {
    $('#TSallowlanaccess').val('false');
  }
  if (!$.trim($('#TSserve').val())) {
    $('#TSserve').val('no');
  }
  checked = $('#contTailscale').prop('checked');
  if (!checked) {
    $('.TSdivider').hide();
    $('.TSwarning').hide();
    $('.TSdeploy').hide();
    $('.TSisexitnode').hide();
    $('.TShostname').hide();
    $('.TSexitnodeip').hide();
    $('.TSssh').hide();
    $('.TSallowlanaccess').hide();
    $('.TSdaemonparams').hide();
    $('.TSextraparams').hide();
    $('.TSstatedir').hide();
    $('.TSserve').hide();
    $('.TSuserspacenetworking').hide();
    $('.TSservepath').hide();
    $('.TSserveprotocol').hide();
    $('.TSserveprotocolport').hide();
    $('.TSservelocalpath').hide();
    $('.TSwebui').hide();
    $('.TSserveport').hide();
    $('.TSadvanced').hide();
    $('.TSroutes').hide();
    $('.TSacceptroutes').hide();
    $('.TStroubleshooting').hide();
  } else {
    // reset these vals back to what they were in the XML
    $('#TSssh').val('<?php echo (!empty($xml) && !empty($xml['TailscaleSSH'])) ? $xml['TailscaleSSH'] : 'false'; ?>');
    $('#TSallowlanaccess').val('<?php echo (!empty($xml) && !empty($xml['TailscaleLANAccess'])) ? $xml['TailscaleLANAccess'] : 'false'; ?>');
    $('#TSserve').val('<?php echo (!empty($xml) && !empty($xml['TailscaleServe'])) ? $xml['TailscaleServe'] : 'false'; ?>');
    $('#TSexitnodeip').val('<?php echo (!empty($xml) && !empty($xml['TailscaleExitNodeIP'])) ? $xml['TailscaleExitNodeIP'] : ''; ?>');
    $('#TSuserspacenetworking').val('<?php echo (!empty($xml) && !empty($xml['TailscaleUserspaceNetworking'])) ? $xml['TailscaleUserspaceNetworking'] : 'false'; ?>');
    $('#TSacceptroutes').val('<?php echo (!empty($xml) && !empty($xml['TailscaleAcceptRoutes'])) ? $xml['TailscaleAcceptRoutes'] : 'false'; ?>');
    <?if (empty($xml['TailscaleServe']) && !empty($TSwebuiport) && empty($xml['TailscaleServePort'])):?>
      $('#TSserve').val('serve');
    <?elseif (empty($xml['TailscaleServe']) && empty($TSwebuiport) && empty($xml['TailscaleServePort'])):?>
      $('#TSserve').val('no');
    <?endif;?>
    // don't reset this field if caller was the onchange event for this field
    if (source.id != 'TSisexitnode') $('#TSisexitnode').val('<?php echo !empty($xml['TailscaleIsExitNode']) ? $xml['TailscaleIsExitNode'] : 'false'; ?>');
    $('.TSisexitnode').show();
    $('.TShostname').show();
    $('.TSssh').show();
    $('.TSexitnodeip').show();
    $('.TSallowlanaccess').hide();
    $('.TSserve').show();
    $('.TSuserspacenetworking').show();
    processExitNodeoptions(document.querySelector('input[name="TSexitnodeip"], select[name="TSexitnodeip"]'));
    $('.TSdivider').show();
    $('.TSwarning').show();
    $('.TSdeploy').show();
    $('.TSadvanced').show();
  }
}

function reloadTriggers() {
  $(".basic").toggle(!$(".advancedview").is(":checked"));
  $(".advanced").toggle($(".advancedview").is(":checked"));
  $(".numbersOnly").keypress(function(e){if(e.which != 45 && e.which != 8 && e.which != 0 && (e.which < 48 || e.which > 57)){return false;}});
}

function toggleReadmore() {
  var readm = $('#readmore_toggle');
  if (readm.hasClass('readmore_collapsed')) {
    readm.removeClass('readmore_collapsed').addClass('readmore_expanded');
    $('#configLocationAdvanced').slideDown('fast');
    readm.find('a').html('<i class="fa fa-fw fa-chevron-up"></i> _(Hide more settings)_ ...');
  } else {
    $('#configLocationAdvanced').slideUp('fast');
    readm.removeClass('readmore_expanded').addClass('readmore_collapsed');
    readm.find('a').html('<i class="fa fa-fw fa-chevron-down"></i> _(Show more settings)_ ...');
  }
}

function toggleAllocations() {
  var readm = $('#allocations_toggle');
  if (readm.hasClass('readmore_collapsed')) {
    readm.removeClass('readmore_collapsed').addClass('readmore_expanded');
    $('#dockerAllocations').slideDown('fast');
    readm.find('a').html('<i class="fa fa-fw fa-chevron-up"></i> _(Hide docker allocations)_ ...');
  } else {
    $('#dockerAllocations').slideUp('fast');
    readm.removeClass('readmore_expanded').addClass('readmore_collapsed');
    readm.find('a').html('<i class="fa fa-fw fa-chevron-down"></i> _(Show docker allocations)_ ...');
  }
}

function load_contOverview() {
  var new_overview = $("textarea[name='contOverview']").val();
  new_overview = new_overview.replaceAll("[","<").replaceAll("]",">");
  // Handle code block being created by authors indenting (manually editing the xml and spacing)
  new_overview = new_overview.replaceAll("    ","&nbsp;&nbsp;&nbsp;&nbsp;");
  new_overview = marked(new_overview);
  new_overview = new_overview.replaceAll("\n","<br>"); // has to be after marked
  $("#contDescription").html(new_overview);

  var new_requires = $("textarea[name='contRequires']").val();
  new_requires = new_requires.replaceAll("[","<").replaceAll("]",">");
  // Handle code block being created by authors indenting (manually editing the xml and spacing)
  new_requires = new_requires.replaceAll("    ","&nbsp;&nbsp;&nbsp;&nbsp;");
  new_requires = marked(new_requires);
  new_requires = new_requires.replaceAll("\n","<br>"); // has to be after marked
  new_requires = new_requires ? new_requires : "<em>_(None Listed)_</em>";
  $("#contRequires").html(new_requires);
}

$(function() {
  // Load container info on page load
  if (typeof Settings != 'undefined') {
    for (var key in Settings) {
      if (Settings.hasOwnProperty(key)) {
        var target = $('#canvas').find('*[name=cont'+key+']:first');
        if (target.length) {
          var value = Settings[key];
          if (target.attr("type") == 'checkbox') {
            target.prop('checked', (value == 'true'));
          } else if ($(target).prop('nodeName') == 'DIV') {
            target.html(value);
          } else {
            target.val(value);
          }
        }
      }
    }
    load_contOverview();
    // Load the confCategory input into the s1 select
    categories=$("input[name='contCategory']").val().split(" ");
    for (var i = 0; i < categories.length; i++) {
      $("#catSelect option[value='"+categories[i]+"']").prop("selected", true);
    }
    // Remove empty description
    if (!Settings.Description.length) {
      $('#canvas').find('#Overview:first').hide();
    }
    // Load config info
    var network = $('select[name="contNetwork"]')[0].selectedIndex;
    for (var i = 0; i < Settings.Config.length; i++) {
      confNum += 1;
      Opts = Settings.Config[i];
      if (Opts.Display == "always-hide" || Opts.Display == "advanced-hide") {
        Opts.Buttons  = "<span class='advanced'><button type='button' onclick='editConfigPopup("+confNum+",<?=$disableEdit?>)'>_(Edit)_</button>";
        Opts.Buttons += "<button type='button' onclick='removeConfig("+confNum+")'>_(Remove)_</button></span>";
      } else {
        Opts.Buttons  = "<button type='button' onclick='editConfigPopup("+confNum+",<?=$disableEdit?>)'>_(Edit)_</button>";
        Opts.Buttons += "<button type='button' onclick='removeConfig("+confNum+")'>_(Remove)_</button>";
      }
      Opts.Number = confNum;
      if (Opts.Type == "Device") {
        Opts.Target = Opts.Value;
      }
      newConf = makeConfig(Opts);
      if (Opts.Display == 'advanced' || Opts.Display == 'advanced-hide') {
        $("#configLocationAdvanced").append(newConf);
      } else {
        $("#configLocation").append(newConf);
      }
    }
  } else {
    $('#canvas').find('#Overview:first').hide();
  }
  // Show associated subnet with fixed IP (if existing)
  showSubnet($('select[name="contNetwork"]').val());
  // Add list of docker allocations
  $("#dockerAllocations").html(makeAllocations(Allocations,$('input[name="contName"]').val()));
  // Add switchButton
  $('.switch-on-off').switchButton({labels_placement:'right',on_label:"_(On)_",off_label:"_(Off)_"});
  // Add dropdownchecklist to Select Categories
  $("#catSelect").dropdownchecklist({emptyText:"_(Select categories)_...", maxDropHeight:200, width:300, explicitClose:"..._(close)_"});
  <?if ($authoringMode){
    echo "$('.advancedview').prop('checked','true'); $('.advancedview').change();";
    echo "$('.advancedview').siblings('.switch-button-background').click();";
  }?>
});

if (window.location.href.indexOf("/Apps/") > 0  && <? if (is_file($xmlTemplate)) echo "true"; else echo "false"; ?> ) {
  $(".TemplateDropDown").hide();
}
</script>
<?END:?>

