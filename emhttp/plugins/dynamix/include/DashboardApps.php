<?PHP
/* Copyright 2005-2024, Lime Technology
 * Copyright 2012-2024, Bergware International.
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
// add translations
$_SERVER['REQUEST_URI'] = 'dashboard';
require_once "$docroot/webGui/include/Translations.php";
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";

if (isset($_POST['ntp'])) {
  if (exec("pgrep -cf /usr/sbin/ptp4l")) {
    // ptp sync
    if (exec("pmc -ub0 'GET TIME_STATUS'|awk '$1==\"gmPresent\"{print $2;exit}'")) {
      $ptp = abs(exec("pmc -ub0 'GET CURRENT'|awk '$1==\"offsetFromMaster\"{print $2;exit}'"));
      switch (true) {
        case ($ptp == 0)   : $unit = 'ns'; $ptp = '???'; break;
        case ($ptp  < 1E+3): $unit = 'ns'; $ptp = round($ptp); break;
        case ($ptp  < 1E+6): $unit = 'μs'; $ptp = round($ptp/1E+3); break;
        case ($ptp  < 1E+9): $unit = 'ms'; $ptp = round($ptp/1E+6); break;
        default            : $unit = 's' ; $ptp = round($ptp/1E+9); break;
      }
      $gm = exec("pmc -ub0 'GET CURRENT'|awk '$1==\"stepsRemoved\"{print $2;exit}'")==1 ? _('grandmaster') : _('master');
      die(sprintf(_('Clock is synchronized using %s PTP server, time offset is %s %s'),$gm,$ptp,$unit));
    } else {
      die(_('Clock is unsynchronized with no PTP servers'));
    }
  } elseif (exec("pgrep -cf /usr/sbin/ntpd")) {
    // ntp sync
    $ntp = exec("ntpq -pn|awk '$1~/^\*/{print $9;exit}'");
    if ($ntp) {
      $ntp = abs($ntp);
      switch (true) {
        case ($ntp == 0)   : $unit = 'μs'; $ntp = '< 1'; break;
        case ($ntp  < 1)   : $unit = 'μs'; $ntp = round($ntp*1E+3); break;
        case ($ntp  < 1E+3): $unit = 'ms'; $ntp = round($ntp); break;
        default            : $unit = 's' ; $ntp = round($ntp/1E+3); break;
      }
      $count = exec("ntpq -pn|grep -Pc '^[*+]'");
      die(sprintf(_('Clock is synchronized using %s NTP servers, time offset is %s %s'),$count,$ntp,$unit));
    } else {
      die(_('Clock is unsynchronized with no NTP servers'));
    }
  }
  // manual sync
  die(_('Clock is unsynchronized, free-running clock'));
}

if ($_POST['docker']) {
  $user_prefs = $dockerManPaths['user-prefs'];
  $DockerClient = new DockerClient();
  $DockerTemplates = new DockerTemplates();
  $containers = $DockerClient->getDockerContainers() ?: [];
  $allInfo = $DockerTemplates->getAllInfo();
  if (file_exists($user_prefs)) {
    $prefs = parse_ini_file($user_prefs); $sort = [];
    foreach ($containers as $ct) $sort[] = array_search($ct['Name'],$prefs) ?? 999;
    array_multisort($sort,SORT_NUMERIC,$containers);
  }
  echo "<tr title='' class='updated'><td>";
  foreach ($containers as $ct) {
    $name = $ct['Name'];
    $id = $ct['Id'];
    $info = &$allInfo[$name];
    $running = $info['running'] ? 1:0;
    $paused = $info['paused'] ? 1:0;
    $is_autostart = $info['autostart'] ? 'true':'false';
    $updateStatus = substr($ct['NetworkMode'],-4)==':???' ? 2 : ($info['updated']=='true' ? 0 : ($info['updated']=='false' ? 1 : 3));
    $template = $info['template'];
    $shell = $info['shell'];
    $webGui = html_entity_decode($info['url']);
    $TSwebGui = html_entity_decode($info['TSurl']);
    $support = html_entity_decode($info['Support']);
    $project = html_entity_decode($info['Project']);
    $registry = html_entity_decode($info['registry']);
    $donateLink = html_entity_decode($info['DonateLink']);
    $readme = html_entity_decode($info['ReadMe']);
    $menu = sprintf("onclick=\"addDockerContainerContext('%s','%s','%s',%s,%s,%s,%s,'%s','%s','%s','%s','%s','%s','%s','%s','%s')\"", addslashes($name), addslashes($ct['ImageId']), addslashes($template), $running, $paused, $updateStatus, $is_autostart, addslashes($webGui), addslashes($TSwebGui), $shell, $id, addslashes($support), addslashes($project), addslashes($registry), addslashes($donateLink), addslashes($readme));
    $shape = $running ? ($paused ? 'pause' : 'play') : 'square';
    $status = $running ? ($paused ? 'paused' : 'started') : 'stopped';
    $color = $status=='started' ? 'green-text' : ($status=='paused' ? 'orange-text' : 'red-text');
    $update = $updateStatus==1 ? 'blue-text' : '';
    $icon = $info['icon'] ?: '/plugins/dynamix.docker.manager/images/question.png';
    $image = substr($icon,-4)=='.png' ? "<img src='$icon?".filemtime("$docroot{$info['icon']}")."' class='img' onerror=this.src='/plugins/dynamix.docker.manager/images/question.png';>" : (substr($icon,0,5)=='icon-' ? "<i class='$icon img'></i>" : "<i class='fa fa-$icon img'></i>");
    echo "<span class='outer solid apps $status'><span id='$id' $menu class='hand'>$image</span><span class='inner'><span class='$update'>$name</span><br><i class='fa fa-$shape $status $color'></i><span class='state'>"._($status)."</span></span></span>";
  }
  $none = count($containers) ? _('No running docker containers') : _('No docker containers defined');
  echo "<span id='no_apps' style='display:none'>$none<br><br></span>";
  echo "</td></tr>";
}
echo "\0";
if ($_POST['vms']) {
  $vmusage = $_POST['vmusage'];
  $vmusagehtml = [];
  $user_prefs = '/boot/config/plugins/dynamix.vm.manager/userprefs.cfg';
  $vms = $lv->get_domains() ?: [];
  if (file_exists($user_prefs)) {
    $prefs = parse_ini_file($user_prefs); $sort = [];
    foreach ($vms as $vm) $sort[] = array_search($vm,$prefs) ?? 999;
    array_multisort($sort,SORT_NUMERIC,$vms);
  } else {
    natcasesort($vms);
  }
  echo "<tr title='' class='updated'><td>";
  $running = 0;
  foreach ($vms as $vm) {
    $res = $lv->get_domain_by_name($vm);
    $uuid = libvirt_domain_get_uuid_string($res);
    $dom = $lv->domain_get_info($res);
    $id = $lv->domain_get_id($res);
    $fstype ="QEMU";
    if (($diskcnt = $lv->get_disk_count($res)) > 0) $fstype = $lv->get_disk_fstype($res);
    $state = $lv->domain_state_translate($dom['state']);
    $vmrcport = $lv->domain_get_vnc_port($res);
    $autoport = $lv->domain_get_vmrc_autoport($res);
    $vmrcurl = '';
    $arrConfig = domain_to_config($uuid);
    if ($vmrcport > 0) {
      $wsport = $lv->domain_get_ws_port($res);
      $vmrcprotocol = $lv->domain_get_vmrc_protocol($res);
      if ($vmrcprotocol == "vnc") $vmrcscale = "&resize=scale"; else $vmrcscale = "";
      $vmrcurl = autov('/plugins/dynamix.vm.manager/'.$vmrcprotocol.'.html',true).$vmrcscale.'&autoconnect=true&host=' . $_SERVER['HTTP_HOST'];
      if ($vmrcprotocol == "spice") $vmrcurl .= '&vmname='. urlencode($vm) . '&port=/wsproxy/'.$vmrcport.'/'; else $vmrcurl .= '&port=&path=/wsproxy/' . $wsport . '/';
    } elseif ($vmrcport == -1 || $autoport) {
      $vmrcprotocol = $lv->domain_get_vmrc_protocol($res);
      $auto = ($autoport == "yes") ? "auto" : "manual";
    } elseif (!empty($arrConfig['gpu'])) {
      $arrValidGPUDevices = getValidGPUDevices();
      foreach ($arrConfig['gpu'] as $arrGPU) {
        foreach ($arrValidGPUDevices as $arrDev) {
          if ($arrGPU['id'] == $arrDev['id']) {
            if (count(array_filter($arrValidGPUDevices, function($v) use ($arrDev) { return $v['name'] == $arrDev['name']; })) > 1) {
              $vmrcprotocol = "VGA";
            } else {
              $vmrcprotocol = "VGA";
            }
          }
        }
      }
    }
    $template = $lv->_get_single_xpath_result($res, '//domain/metadata/*[local-name()=\'vmtemplate\']/@name');
    if (empty($template)) $template = 'Custom';
    $log = (is_file("/var/log/libvirt/qemu/$vm.log") ? "libvirt/qemu/$vm.log" : '');
    if (!isset($domain_cfg["CONSOLE"])) $vmrcconsole = "web"; else $vmrcconsole = $domain_cfg["CONSOLE"];
    if (!isset($domain_cfg["RDPOPT"])) $vmrcconsole .= ";no"; else $vmrcconsole .= ";".$domain_cfg["RDPOPT"];
    $WebUI = html_entity_decode($arrConfig["template"]["webui"]);
    $menu = sprintf("onclick=\"addVMContext('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')\"", htmlentities($vm,ENT_QUOTES), addslashes($uuid), addslashes($template), $state, addslashes($vmrcurl), strtoupper($vmrcprotocol), htmlentities($log,ENT_QUOTES),addslashes($fstype), $vmrcconsole,false,addslashes(str_replace('"',"'",$WebUI)));
    $icon = $lv->domain_get_icon_url($res);
    switch ($state) {
    case 'running':
      $shape = 'play';
      $status = 'started';
      $color = 'green-text';
      $running++;
      break;
    case 'paused':
    case 'pmsuspended':
      $shape = 'pause';
      $status = 'paused';
      $color = 'orange-text';
      break;
    default:
      $shape = 'square';
      $status = 'stopped';
      $color = 'red-text';
      break;
    }
    $image = substr($icon,-4)=='.png' ? "<img src='$icon' class='img'>" : (substr($icon,0,5)=='icon-' ? "<i class='$icon img'></i>" : "<i class='fa fa-$icon img'></i>");
    echo "<span class='outer solid vms $status'><span id='vm-$uuid' $menu class='hand'>$image</span><span class='inner'>$vm<br><i class='fa fa-$shape $status $color'></i><span class='state'>"._($status)."</span></span></span>";
    if ($state == "running") {
      #Build VM Usage array.
      $menuusage = sprintf("onclick=\"addVMContext('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')\"", addslashes($vm), addslashes($uuid), addslashes($template), $state, addslashes($vmrcurl), strtoupper($vmrcprotocol), addslashes($log),addslashes($fstype), $vmrcconsole,true,addslashes(str_replace('"',"'",$WebUI)));
      $vmusagehtml[] = "<span class='outer solid vmsuse $status'><span id='vmusage-$uuid' $menuusage class='hand'>$image</span><span class='inner'>$vm<br><i class='fa fa-$shape $status $color'></i><span class='state'>"._($status)."</span></span>";
      $vmusagehtml[] = "<br><br><span id='vmmetrics-gcpu-".$uuid."'>"._("Loading")."....</span>";
      $vmusagehtml[] = "<br><span id='vmmetrics-hcpu-".$uuid."'>"._("Loading")."....</span>";
      $vmusagehtml[] = "<br><span id='vmmetrics-mem-".$uuid."'>"._("Loading")."....</span>";
      $vmusagehtml[] = "<br><span id='vmmetrics-disk-".$uuid."'>"._("Loading")."....</span>";
      $vmusagehtml[] = "<br><span id='vmmetrics-net-".$uuid."'>"._("Loading")."....</span>";
      $vmusagehtml[] = "</span>";
    }
  }
  $none = count($vms) ? _('No running virtual machines') : _('No virtual machines defined');
  echo "<span id='no_vms' style='display:none'>$none<br><br></span>";
  echo "</td></tr>";

  echo "\0";
  echo "<tr title='' class='useupdated'><td>";
  if ($vmusage=='Y') {
    foreach ($vmusagehtml as $vmhtml) echo $vmhtml;
    if (!count($vmusagehtml)) echo "<span id='no_usagevms'><br> "._('No running virtual machines')."<br></span>";
    if ($running<1 && count($vmusagehtml)) echo "<span id='no_usagevms'><br>". _('No running virtual machines')."<br></span>";
    echo "</td></tr>";
  }
}
