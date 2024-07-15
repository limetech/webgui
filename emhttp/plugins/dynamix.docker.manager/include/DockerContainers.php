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
  $updateStatus = substr($ct['NetworkMode'],-4)==':???' ? 2 : ($info['updated']=='true' ? 0 : ($info['updated']=='false' ? 1 : 3));
  $template = $info['template']??'';
  $shell = $info['shell']??'';
  $webGui = html_entity_decode($info['url']??'');
  $support = html_entity_decode($info['Support']??'');
  $project = html_entity_decode($info['Project']??'');
  $registry = html_entity_decode($info['registry']??'');
  $donateLink = html_entity_decode($info['DonateLink']??'');
  $readme = html_entity_decode($info['ReadMe']??'');
  $menu = sprintf("onclick=\"addDockerContainerContext('%s','%s','%s',%s,%s,%s,%s,'%s','%s','%s','%s','%s','%s', '%s','%s')\"", addslashes($name), addslashes($ct['ImageId']), addslashes($template), $running, $paused, $updateStatus, $is_autostart, addslashes($webGui), $shell, $id, addslashes($support), addslashes($project),addslashes($registry),addslashes($donateLink),addslashes($readme));
  $docker[] = "docker.push({name:'$name',id:'$id',state:$running,pause:$paused,update:$updateStatus});";
  $shape = $running ? ($paused ? 'pause' : 'play') : 'square';
  $status = $running ? ($paused ? 'paused' : 'started') : 'stopped';
  $color = $status=='started' ? 'green-text' : ($status=='paused' ? 'orange-text' : 'red-text');
  $update = $updateStatus==1 ? 'blue-text' : '';
  $icon = $info['icon'] ?: '/plugins/dynamix.docker.manager/images/question.png';
  $image = substr($icon,-4)=='.png' ? "<img src='$icon?".filemtime("$docroot{$info['icon']}")."' class='img' onerror=this.src='/plugins/dynamix.docker.manager/images/question.png';>" : (substr($icon,0,5)=='icon-' ? "<i class='$icon img'></i>" : "<i class='fa fa-$icon img'></i>");
  $wait = var_split($autostart[array_search($name,$names)]??'',1);
  $ports = [];
  foreach ($ct['Ports'] as $port) {
    $intern = $running ? ($ct['NetworkMode']=='host' ? $host : _var($port,'IP')) : $null;
    $extern = $running ? (_var($port,'NAT') ? $host : $intern) : $null;
    $ports[] = sprintf('%s:%s/%s<i class="fa fa-arrows-h" style="margin:0 6px"></i>%s:%s', $intern, _var($port,'PrivatePort'), strtoupper(_var($port,'Type')), $extern, _var($port,'PublicPort'));
  }
  $paths = [];
  $ct['Volumes'] = is_array($ct['Volumes']) ? $ct['Volumes'] : [];
  foreach ($ct['Volumes'] as $mount) {
    [$host_path,$container_path,$access_mode] = my_explode(':',$mount,3);
    $paths[] = sprintf('%s<i class="fa fa-%s" style="margin:0 6px"></i>%s', htmlspecialchars($container_path), $access_mode=='ro'?'long-arrow-left':'arrows-h', htmlspecialchars($host_path));
  }
  echo "<tr class='sortable'><td class='ct-name' style='width:220px;padding:8px'><i class='fa fa-arrows-v mover orange-text'></i>";
  if ($template) {
    $appname = "<a class='exec' onclick=\"editContainer('".addslashes(htmlspecialchars($name))."','".addslashes(htmlspecialchars($template))."')\">".htmlspecialchars($name)."</a>";
  } else {
    $appname = htmlspecialchars($name);
  }
  echo "<span class='outer'><span id='$id' $menu class='hand'>$image</span><span class='inner'><span class='appname $update'>$appname</span><br><i id='load-$id' class='fa fa-$shape $status $color'></i><span class='state'>"._($status)."</span></span></span>";
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
    echo "<span class='green-text' style='white-space:nowrap;'><i class='fa fa-check fa-fw'></i> "._('up-to-date')."</span>";
    if ($ct['Manager'] == "dockerman")
      echo "<div class='advanced'><a class='exec' onclick=\"updateContainer('".addslashes(htmlspecialchars($name))."');\"><span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> "._('force update')."</span></a></div>";
    break;
    case 1:
      echo "<div class='advanced'><span class='orange-text' style='white-space:nowrap;'><i class='fa fa-flash fa-fw'></i> "._('update ready')."</span></div>";
      if ($ct['Manager'] == "dockerman")
        echo "<a class='exec' onclick=\"updateContainer('".addslashes(htmlspecialchars($name))."');\"><span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> "._('apply update')."</span></a>";
      else
        echo "<span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> "._('update available')."</span>";
      break;
    case 2:
      echo "<div class='advanced'><span class='orange-text' style='white-space:nowrap;'><i class='fa fa-flash fa-fw'></i> "._('rebuild ready')."</span></div>";
      echo "<a class='exec'><span style='white-space:nowrap;'><i class='fa fa-recycle fa-fw'></i> "._('rebuilding')."</span></a>";
      break;
    default:
      echo "<span class='orange-text' style='white-space:nowrap;'><i class='fa fa-unlink'></i> "._('not available')."</span>";
      if ($ct['Manager'] == "dockerman")
        echo "<div class='advanced'><a class='exec' onclick=\"updateContainer('".addslashes(htmlspecialchars($name))."');\"><span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> "._('force update')."</span></a></div>";
      break;
  }
  echo "<div class='advanced'><i class='fa fa-info-circle fa-fw'></i> ".compress(_($version),12,0)."</div></td>";
  echo "<td>{$ct['NetworkMode']}</td>";
  echo "<td style='white-space:nowrap'><span class='docker_readmore'>".implode('<br>',$ports)."</span></td>";
  echo "<td style='word-break:break-all'><span class='docker_readmore'>".implode('<br>',$paths)."</span></td>";
  echo "<td class='advanced'><span class='cpu-$id'>0%</span><div class='usage-disk mm'><span id='cpu-$id' style='width:0'></span><span></span></div>";
  echo "<br><span class='mem-$id'>0 / 0</span></td>";
  echo "<td><input type='checkbox' id='$id-auto' class='autostart' container='".htmlspecialchars($name)."'".($info['autostart'] ? ' checked':'').">";
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
