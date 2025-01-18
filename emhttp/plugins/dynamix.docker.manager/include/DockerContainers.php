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
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";

// add translations
$_SERVER['REQUEST_URI'] = 'docker';
require_once "$docroot/webGui/include/Translations.php";

$DockerClient    = new DockerClient();
$DockerTemplates = new DockerTemplates();
$containers      = $DockerClient->getDockerContainers();
$images          = $DockerClient->getDockerImages();
$user_prefs      = $dockerManPaths['user-prefs'];
$autostart_file  = $dockerManPaths['autostart-file'];

if (!$containers && !$images) {
  echo "<tr><td colspan='7' style='text-align:center;padding-top:12px'>"._('No Docker containers installed')."</td></tr>";
  return;
}

if (file_exists($user_prefs)) {
  $prefs = (array)@parse_ini_file($user_prefs);
  $sort = [];
  foreach ($containers as $ct) $sort[] = array_search($ct['Name'],$prefs);
  array_multisort($sort,SORT_NUMERIC,$containers);
  unset($sort);
}

// Read container info
$allInfo = $DockerTemplates->getAllInfo();
$docker = [];
$null = '0.0.0.0';

$autostart = (array)@file($autostart_file,FILE_IGNORE_NEW_LINES);
$names = array_map('var_split',$autostart);

// Grab Tailscale json from container
function tailscale_stats($name) {
  exec("docker exec -i ".$name." /bin/sh -c \"tailscale status --json | jq '{Self: .Self, ExitNodeStatus: .ExitNodeStatus, Version: .Version}'\" 2>/dev/null", $TS_stats);
  if (!empty($TS_stats)) {
    $TS_stats = implode("\n", $TS_stats);
    return json_decode($TS_stats, true);
  }
  return '';
}

// Download Tailscal JSON and return Array, refresh file if older than 24 hours
function tailscale_json_dl($file, $url) {
  $dl_status = 0;
  if (!is_dir('/tmp/tailscale')) {
    mkdir('/tmp/tailscale', 0777, true);
  }
  if (!file_exists($file)) {
    exec("wget -T 3 -q -O ".$file." ".$url, $output, $dl_status);
  } else {
    $fileage =  time() - filemtime($file);
    if ($fileage > 86400) {
      unlink($file);
      exec("wget -T 3 -q -O ".$file." ".$url, $output, $dl_status);
    }
  }
  if ($dl_status === 0) {
    return json_decode(@file_get_contents($file), true);
  } elseif ($dl_status === 0 && is_file($file)) {
    return json_decode(@file_get_contents($file), true);
  } else {
    unlink($file);
    return '';
  }
}

// Grab Tailscale DERP map JSON
$TS_derp_url = 'https://login.tailscale.com/derpmap/default';
$TS_derp_file = '/tmp/tailscale/tailscale-derpmap.json';
$TS_derp_list = tailscale_json_dl($TS_derp_file, $TS_derp_url);

// Grab Tailscale version JSON
$TS_version_url = 'https://pkgs.tailscale.com/stable/?mode=json';
$TS_version_file = '/tmp/tailscale/tailscale-latest-version.json';
// Extract tarbal version string
$TS_latest_version = tailscale_json_dl($TS_version_file, $TS_version_url);
if (!empty($TS_latest_version)) {
  $TS_latest_version = $TS_latest_version["TarballsVersion"];
}

function my_lang_time($text) {
  [$number, $text] = my_explode(' ',$text,2);
  return sprintf(_("%s $text"),$number);
}
function my_lang_log($text) {
  global $language;
  if (isset($language['healthy'])) $text = str_replace('healthy',$language['healthy'],$text);
  if (isset($language['Exited'])) $text = str_replace('Exited',$language['Exited'],$text);
  if (strpos($text,'ago')!==false) {
    [$t1,$t2] = my_explode(') ',$text);
    return $t1.'): '.my_lang_time($t2);
  }
  return _(_($text),2);
}
foreach ($containers as $ct) {
  $name = $ct['Name'];
  $id = $ct['Id'];
  $info = &$allInfo[$name];
  $running = $info['running'] ? 1 : 0;
  $paused = $info['paused'] ? 1 : 0;
  $is_autostart = $info['autostart'] ? 'true':'false';
  $composestack = isset($ct['ComposeProject']) ? $ct['ComposeProject'] : '';
  $updateStatus = substr($ct['NetworkMode'], -4) == ':???' ? 2 : ($info['updated'] == 'true' ? 0 : ($info['updated'] == 'false' ? 1 : 3));
  $template = $info['template']??'';
  $shell = $info['shell']??'';
  $webGui = html_entity_decode($info['url']??'');
  $TShostname = isset($ct['TSHostname']) ? $ct['TSHostname'] : '';
  $TSwebGui = html_entity_decode($info['TSurl']??'');
  $support = html_entity_decode($info['Support']??'');
  $project = html_entity_decode($info['Project']??'');
  $registry = html_entity_decode($info['registry']??'');
  $donateLink = html_entity_decode($info['DonateLink']??'');
  $readme = html_entity_decode($info['ReadMe']??'');
  $menu = sprintf("onclick=\"addDockerContainerContext('%s','%s','%s',%s,%s,%s,%s,'%s','%s','%s','%s','%s','%s','%s', '%s','%s')\"", addslashes($name), addslashes($ct['ImageId']), addslashes($template), $running, $paused, $updateStatus, $is_autostart, addslashes($webGui), addslashes($TSwebGui), $shell, $id, addslashes($support), addslashes($project),addslashes($registry),addslashes($donateLink),addslashes($readme));
  $docker[] = "docker.push({name:'$name',id:'$id',state:$running,pause:$paused,update:$updateStatus});";
  $shape = $running ? ($paused ? 'pause' : 'play') : 'square';
  $status = $running ? ($paused ? 'paused' : 'started') : 'stopped';
  $color = $status=='started' ? 'green-text' : ($status=='paused' ? 'orange-text' : 'red-text');
  $update = $updateStatus==1 && !empty($compose) ? 'blue-text' : '';
  $icon = $info['icon'] ?: '/plugins/dynamix.docker.manager/images/question.png';
  $image = substr($icon,-4)=='.png' ? "<img src='$icon?".filemtime("$docroot{$info['icon']}")."' class='img' onerror=this.src='/plugins/dynamix.docker.manager/images/question.png';>" : (substr($icon,0,5)=='icon-' ? "<i class='$icon img'></i>" : "<i class='fa fa-$icon img'></i>");
  $wait = var_split($autostart[array_search($name,$names)]??'',1);
  $networks = [];
  $network_ips = [];
  $ports_internal = [];
  $ports_external = [];
  if (isset($ct['Ports']['vlan'])) {
    foreach ($ct['Ports']['vlan'] as $i)
      $ports_external[] = sprintf('%s', $i);
    $ports_internal[0] = sprintf('%s', 'all');
  }
  foreach($ct['Networks'] as $netName => $netVals) {
    $networks[] = $netName;
    $network_ips[] = $running ? $netVals['IPAddress'] : null;

    if (isset($ct['Networks']['host'])) {
      $ports_external[] = sprintf('%s', $netVals['IPAddress']);
      $ports_internal[0] = sprintf('%s', 'all');
    } else if (!isset($ct['Ports']['vlan']) || strpos($ct['NetworkMode'], 'container:') != 0) {
      foreach ($ct['Ports'] as $port) {
        if (_var($port,'PublicPort') && _var($port,'Driver') == 'bridge')
          $ports_external[] = sprintf('%s:%s', $host, strtoupper(_var($port,'PublicPort')));
        if ((!isset($ct['Networks']['host'])) || (!isset($ct['Networks']['vlan'])))
          $ports_internal[] = sprintf('%s:%s', _var($port,'PrivatePort'), strtoupper(_var($port,'Type')));
      }
    }
  }
  $paths = [];
  $ct['Volumes'] = is_array($ct['Volumes']) ? $ct['Volumes'] : [];
  foreach ($ct['Volumes'] as $mount) {
    [$host_path,$container_path,$access_mode] = my_explode(':',$mount,3);
    $paths[] = sprintf('%s<i class="fa fa-%s" style="margin:0 6px"></i>%s', htmlspecialchars($container_path), $access_mode=='ro'?'long-arrow-left':'arrows-h', htmlspecialchars($host_path));
  }
  echo "<tr class='sortable'><td class='ct-name' style='width:220px;padding:8px'><i class='fa fa-arrows-v mover orange-text'></i>";
  if ($template && empty($composestack)) {
    $appname = "<a class='exec' onclick=\"editContainer('".addslashes(htmlspecialchars($name))."','".addslashes(htmlspecialchars($template))."')\">".htmlspecialchars($name)."</a>";
  } else {
    $appname = htmlspecialchars($name);
  }
  echo "<span class='outer'><span id='$id' $menu class='hand'>$image</span><span class='inner'><span class='appname $update'>$appname</span><br><i id='load-$id' class='fa fa-$shape $status $color'></i><span class='state'>"._($status).(!empty($composestack) ? '<br/>Compose Stack: '.$composestack : '')."</span></span></span>";
  echo "<div class='advanced' style='margin-top:8px'>"._('Container ID').": $id<br>";
  if ($ct['BaseImage']) echo "<i class='fa fa-cubes' style='margin-right:5px'></i>".htmlspecialchars($ct['BaseImage'])."<br>";
  echo _('By').": ";
  $registry = $info['registry'];
  ['strRepo' => $author, 'strTag' => $version] = DockerUtil::parseImageTag($ct['Image']);
  if ($registry) {
    echo "<a href='".htmlspecialchars($registry)."' target='_blank'>".htmlspecialchars(compress($author,24))."</a>";
  } else {
    echo htmlspecialchars(compress($author,24));
  }
  echo "</div></td><td class='updatecolumn'>";
  switch ($updateStatus) {
    case 0:
      if ($ct['Manager'] == "dockerman") {
        echo "<span class='green-text' style='white-space:nowrap;'><i class='fa fa-check fa-fw'></i> "._('up-to-date')."</span>";
        echo "<div class='advanced'><a class='exec' onclick=\"updateContainer('".addslashes(htmlspecialchars($name))."');\"><span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> "._('force update')."</span></a></div>";
      } elseif (!empty($composestack)) {
        echo "<div><span><i class='fa fa-docker fa-fw'/></i> "._("Compose")."</span></div>";
        echo "<span tyle='white-space:nowrap;'><i class='fa fa-check fa-fw'></i> "._('up-to-date')."</span>";
      } else {
        echo "<div><span><i class='fa fa-docker fa-fw'/></i> "._("3rd Party")."</span></div>";
        echo "<span tyle='white-space:nowrap;'><i class='fa fa-check fa-fw'></i> "._('up-to-date')."</span>";
      }
      break;
    case 1:
      echo "<div class='advanced'><span class='orange-text' style='white-space:nowrap;'><i class='fa fa-flash fa-fw'></i> "._('update ready')."</span></div>";
      if ($ct['Manager'] == "dockerman") {
        echo "<a class='exec' onclick=\"updateContainer('".addslashes(htmlspecialchars($name))."');\"><span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> "._('apply update')."</span></a>";
      } elseif (!empty($composestack)) {
        echo "<div><span><i class='fa fa-docker fa-fw'/></i> Compose</span></a></div>";
        echo "<span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> "._('update available')."</span>";
      } else {
        echo "<div><span><i class='fa fa-docker fa-fw'/></i> 3rd Party</span></div>";
        echo "<span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> "._('update available')."</span>";
      }
      break;
    case 2:
      echo "<div class='advanced'><span class='orange-text' style='white-space:nowrap;'><i class='fa fa-flash fa-fw'></i> "._('rebuild ready')."</span></div>";
      echo "<a class='exec'><span style='white-space:nowrap;'><i class='fa fa-recycle fa-fw'></i> "._('rebuilding')."</span></a>";
      break;
    default:
      if ($ct['Manager'] == "dockerman") {
        echo "<span class='orange-text' style='white-space:nowrap;'><i class='fa fa-unlink'></i> "._('not available')."</span>";
        echo "<div class='advanced'><a class='exec' onclick=\"updateContainer('".addslashes(htmlspecialchars($name))."');\"><span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> "._('force update')."</span></a></div>";
      } elseif (!empty($composestack)) {
        echo "<div><span><i class='fa fa-docker fa-fw'/></i> "._("Compose")."</span></div>";
        echo "<span style='white-space:nowrap;'><i class='fa fa-unlink'></i> "._('not available')."</span>";
      } else {
        echo "<div><span><i class='fa fa-docker fa-fw'/></i> "._("3rd Party")."</span></div>";
        echo "<span style='white-space:nowrap;'><i class='fa fa-unlink'></i> "._('not available')."</span>";
      }
      break;
    }
  // Check if Tailscale for container is enabled by checking if TShostname is set
  $TS_status = '';
  if (!empty($TShostname)) {
    if ($running) {
      // Get stats from container and check if they are not empty
      $TSstats = tailscale_stats($name);
      if (!empty($TSstats)) {
        // Construct TSinfo from TSstats
        $TSinfo = '';
        if (!$TSstats["Self"]["Online"]) {
          $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Online").":</span><span class='ui-tailscale-value'>&#10060;<br/>"._("Please check the logs")."!</span></div>";
        } else {
          $TS_version = explode('-', $TSstats["Version"])[0];
          if (!empty($TS_version)) {
            if (!empty($TS_latest_version)) {
              if ($TS_version !== $TS_latest_version) {
                $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Tailscale").":</span><span class='ui-tailscale-value'>v".$TS_version." &#10132; v".$TS_latest_version." "._("available")."!</span></div>";
              } else {
                $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Tailscale").":</span><span class='ui-tailscale-value'>v".$TS_version."</span></div>";
              }
            } else {
              $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>".("Tailscale").":</span><span class='ui-tailscale-value'>v".$TS_version."</span></div>";
            }
          }
          $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Online").":</span><span class='ui-tailscale-value'>&#9989;</span></div>";
          $TS_DNSName = $TSstats["Self"]["DNSName"];
          $TS_HostNameActual = substr($TS_DNSName, 0, strpos($TS_DNSName, '.'));
          if (strcasecmp($TS_HostNameActual, $TShostname) !== 0 && !empty($TS_DNSName)) {
            $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Hostname").":</span><span class='ui-tailscale-value'>"._("Real Hostname")." &#10132; ".$TS_HostNameActual."</span></div>";
          } else {
            $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Hostname").":</span><span class='ui-tailscale-value'>".$TShostname."</span></div>";
          }
          // Map region relay code to cleartext region if TS_derp_list is available
          if (!empty($TS_derp_list)) {
            foreach ($TS_derp_list['Regions'] as $region) {
              if ($region['RegionCode'] === $TSstats["Self"]["Relay"]) {
                $TSregion = $region['RegionName'];
                break;
              }
            }
            if (!empty($TSregion)) {
              $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("DERP Relay").":</span><span class='ui-tailscale-value'>".$TSregion."</span></div>";
            } else {
              $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("DERP Relay").":</span><span class='ui-tailscale-value'>".$TSstats["Self"]["Relay"]."</span></div>";
            }
          } else {
            $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("DERP Relay").":</span><span class='ui-tailscale-value'>".$TSstats["Self"]["Relay"]."</span></div>";
          }
          if (!empty($TSstats["Self"]["TailscaleIPs"])) {
            $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Addresses").":</span><span class='ui-tailscale-value'>".implode("<br/>", $TSstats["Self"]["TailscaleIPs"])."</span></div>";
          }
          if (!empty($TSstats["Self"]["PrimaryRoutes"])) {
            $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Routes").":</span><span class='ui-tailscale-value'>".implode("<br/>", $TSstats["Self"]["PrimaryRoutes"])."</span></div>";
          }
          if ($TSstats["Self"]["ExitNodeOption"]) {
            $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Is Exit Node").":</span><span class='ui-tailscale-value'>&#9989;</span></div>";
          } else {
            if (!empty($TSstats["ExitNodeStatus"])) {
              $TS_exit_node_status = ($TSstats["ExitNodeStatus"]["Online"]) ? "&#9989;" : "&#10060;";
              $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Exit Node").":</span><span class='ui-tailscale-value'>".strstr($TSstats["ExitNodeStatus"]["TailscaleIPs"][0], '/', true)." | Status: ".$TS_exit_node_status ."</span></div>";
            } else {
              $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Is Exit Node").":</span><span class='ui-tailscale-value'>&#10060;</span></div>";
            }
          }
          if (!empty($TSwebGui)) {
            $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("URL").":</span><span class='ui-tailscale-value'>".$TSwebGui."</span></div>";
          }
          if (!empty($TSstats["Self"]["KeyExpiry"])) {
            $TS_expiry = new DateTime($TSstats["Self"]["KeyExpiry"]);
            $current_Date = new DateTime();
            $TS_expiry_formatted = $TS_expiry->format('Y-m-d');
            $TS_expiry_diff = $current_Date->diff($TS_expiry);
            if ($TS_expiry_diff->invert) {
              $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Key Expiry").":</span><span class='ui-tailscale-value'>&#10060; "._("Expired! Renew/Disable key expiry!")."</span></div>";
            } else {
              $TSinfo .= "<div class='ui-tailscale-row'><span class='ui-tailscale-label'>"._("Key Expiry").":</span><span class='ui-tailscale-value'>".$TS_expiry_formatted." (".$TS_expiry_diff->days." days)</span></div>";
            }
          }
        }
        // Display TSinfo if data was fetched correctly
        $TS_status = "<br/><div class='TS_tooltip' style='cursor:pointer; display: inline-block;' data-tstitle='".htmlspecialchars($TSinfo)."'><img src='/plugins/dynamix.docker.manager/images/tailscale.png' style='height: 1.23em;'> Tailscale</div>";
      } else {
        // Display message to refresh page if Tailscale in the container wasn't maybe ready to get the data
        $TS_status = "<br/><div class='TS_tooltip' style='display: inline-block;' data-tstitle='"._("Error gathering Tailscale information from container").".<br/>"._("Please check the logs and refresh the page")."'><img src='/plugins/dynamix.docker.manager/images/tailscale.png' style='height: 1.23em;'> Tailscale</div>";
      }
    } else {
      // Display message that container isn't running
      $TS_status = "<br/><div class='TS_tooltip' style='cursor:pointer;display: inline-block;' data-tstitle='"._("Container not running")."'><img src='/plugins/dynamix.docker.manager/images/tailscale.png' style='height: 1.23em;'> Tailscale</div>";
    }
  }
  echo "<div class='advanced'><i class='fa fa-info-circle fa-fw'></i> ".compress(_($version),12,0)."</div></td>";
  echo "<td style='white-space:nowrap'><span class='docker_readmore'> ".implode('<br>',$networks).$TS_status."</span></td>";
  echo "<td style='white-space:nowrap'><span class='docker_readmore'> ".implode('<br>',$network_ips)."</span></td>";
  echo "<td style='white-space:nowrap'><span class='docker_readmore'>".implode('<br>',$ports_internal)."</span></td>";
  echo "<td style='white-space:nowrap'><span class='docker_readmore'>".implode('<br>',$ports_external)."</span></td>";
  echo "<td style='word-break:break-all'><span class='docker_readmore'>".implode('<br>',$paths)."</span></td>";
  echo "<td class='advanced'><span class='cpu-$id'>0%</span><div class='usage-disk mm'><span id='cpu-$id' style='width:0'></span><span></span></div>";
  echo "<br><span class='mem-$id'>0 / 0</span></td>";
  if (empty($composestack)) {
    if ($ct['Manager'] == "dockerman") {
      echo "<td><input type='checkbox' id='$id-auto' class='autostart' container='".htmlspecialchars($name)."'".($info['autostart'] ? ' checked':'').">";
    } else {
      echo "<td><i class='fa fa-docker fa-fw'/></i> 3rd Party";
    }
  } else {
    echo "<td><i class='fa fa-docker'/></i> Compose";
  }
  echo "<span id='$id-wait' style='float:right;display:none'>"._('wait')."<input class='wait' container='".htmlspecialchars($name)."' type='number' value='$wait' placeholder='0' title=\""._('seconds')."\"></span></td>";
  echo "<td><div style='white-space:nowrap'>".htmlspecialchars(str_replace('Up',_('Uptime').':',my_lang_log($ct['Status'])))."<div style='margin-top:4px'>"._('Created').": ".htmlspecialchars(my_lang_time($ct['Created']))."</div></div></td></tr>";
}
foreach ($images as $image) {
  if (count($image['usedBy'])) continue;
  $id = $image['Id'];
  $menu = sprintf("onclick=\"addDockerImageContext('%s','%s')\"", $id, implode(',',$image['Tags']));
  echo "<tr class='advanced'><td style='width:220px;padding:8px'>";
  echo "<span class='outer apps'><span id='$id' $menu class='hand'><img src='/webGui/images/disk.png' class='img'></span><span class='inner'>("._('orphan image').")<br><i class='fa fa-square stopped grey-text'></i><span class='state'>"._('stopped')."</span></span></span>";
  echo "</td><td colspan='6'>"._('Image ID').": $id<br>";
  echo implode(', ',$image['Tags']);
  echo "</td><td>"._('Created')." ".htmlspecialchars(_($image['Created'],0))."</td></tr>";
}
echo "\0".implode($docker)."\0".(pgrep('rc.docker')!==false ? 1:0);
?>
