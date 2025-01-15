<?PHP
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
require_once "$docroot/webGui/include/Helpers.php";

// add translations
$_SERVER['REQUEST_URI'] = 'settings';
require_once "$docroot/webGui/include/Translations.php";

$cpus = explode(';',$_POST['cpus']??'');
$corecount = 0;
foreach ($cpus as $pair) {
  unset($cpu1,$cpu2);
  [$cpu1, $cpu2] = my_preg_split('/[,-]/',$pair);
  if (!$cpu2) 	$corecount++; else $corecount=$corecount+2;
}

function scan($area, $text) {
  return isset($area) ? strpos($area,$text)!==false : false;
}

function create($id, $name, $vcpu) {
  // create the list of checkboxes. Make multiple rows when CPU cores are many ;)
  global $cpus;
  $total = count($cpus);
  $loop = floor(($total-1)/32)+1;
  $text = [];
  $unit = urlencode(str_replace([' ','(',')','[',']'],'',$name));
  $name = urlencode($name);
  echo "<td><span id='$id-$unit' style='color:#267CA8;display:none'><i class='fa fa-refresh fa-spin'></i> "._('updating')."</span></td>";
  for ($c = 0; $c < $loop; $c++) {
    $max = ($c == $loop-1 ? ($total%32?:32) : 32);
    for ($n = 0; $n < $max; $n++) {
      unset($cpu1,$cpu2);
      [$cpu1, $cpu2] = my_preg_split('/[,-]/',$cpus[$c*32+$n]);
      $check1 = ($vcpu && in_array($cpu1, $vcpu)) ? 'checked':'';
      $check2 = $cpu2 ? ($vcpu && (in_array($cpu2, $vcpu)) ? 'checked':''):'';
      if (empty($text[$n])) $text[$n] = '';
      $text[$n] .="<label class='checkbox'><input type='checkbox' name='$name:$cpu1' $check1><span class='checkmark'></span></label><br>";
      if ($cpu2) $text[$n] .= "<label class='checkbox'><input type='checkbox' name='$name:$cpu2' $check2><span class='checkmark'></span></label><br>";
    }
  }
  echo implode(array_map(function($t){return "<td>$t</td>";},$text));
}

switch ($_POST['id']??'') {
case 'vm':
  // create the current vm assignments
  require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";
  $vms = $libvirt_running=='yes' ? ($lv->get_domains() ?: []) : [];
  $user_prefs = '/boot/config/plugins/dynamix.vm.manager/userprefs.cfg';
  // list vms per user preference
  if (file_exists($user_prefs)) {
    $prefs = parse_ini_file($user_prefs); $sort = [];
    foreach ($vms as $vm) $sort[] = array_search($vm,$prefs) ?? 999;
    array_multisort($sort,SORT_NUMERIC,$vms);
  } else {
    natcasesort($vms);
  }
  foreach ($vms as $vm) {
    $uuid = $lv->domain_get_uuid($lv->get_domain_by_name($vm));
    $cfg = domain_to_config($uuid);
    echo "<tr><td>$vm</td>";
    if ($cfg['domain']['vcpu'] >0 ) $disabled = "disabled"; else $disabled = "";
    $vmenc = urlencode($vm);
    $vcpuselect="<select id='vm-$vmenc-vcpu' name='$vmenc' class='narrow' title='"._("vcpu allocated to vm")."' $disabled>";
    for ($i = 1; $i <= ($corecount); $i++) {
        $vcpuselect .= mk_option($cfg['domain']['vcpus'], $i, $i);
      }
    $vcpuselect .= '</select>';
    echo "<td>$vcpuselect</td>";
    create('vm', $vm, $cfg['domain']['vcpu']);
    echo "</tr>";
  }
  // return the cpu assignments and available VM names
  echo "\0".implode(';',array_map('urlencode',$vms));
  break;
case 'ct':
  // create the current container assignments
  require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
  $DockerClient = new DockerClient();
  $DockerTemplates = new DockerTemplates();
  $containers = $DockerClient->getDockerContainers();
  $allInfo = $DockerTemplates->getAllInfo();
  $user_prefs = $dockerManPaths['user-prefs'];
  $cts = []; foreach ($containers as $ct) $cts[] = $ct['Name'];
  // list containers per user preference
  if (file_exists($user_prefs)) {
    $prefs = parse_ini_file($user_prefs); $sort = [];
    foreach ($containers as $ct) $sort[] = array_search($ct['Name'],$prefs) ?? 999;
    array_multisort($sort,SORT_NUMERIC,$containers);
    unset($sort);
  }
  foreach ($containers as $ct) {
    if ( ! is_file($allInfo[$ct['Name']]['template']) ) continue;
    echo "<tr><td>{$ct['Name']}</td>";
    create('ct', $ct['Name'], explode(',',$ct['CPUset']));
    echo "</tr>";
  }
  // return the cpu assignments and available container names
  echo "\0".implode(';',array_map('urlencode',$cts));
  break;
case 'is':
  if (is_file('/boot/syslinux/syslinux.cfg')) {
    $menu = $i = 0;
    $isol = "";
    $isolcpus = [];
    $bootcfg = file('/boot/syslinux/syslinux.cfg', FILE_IGNORE_NEW_LINES+FILE_SKIP_EMPTY_LINES);
    $size = count($bootcfg);
    // find the default section
    while ($i < $size) {
      if (scan($bootcfg[$i],'label ')) {
        $n = $i + 1;
        // find the current isolcpus setting
        while (!scan($bootcfg[$n],'label ') && $n < $size) {
          if (scan($bootcfg[$n],'menu default')) $menu = 1;
          if (scan($bootcfg[$n],'append')) foreach (explode(' ',$bootcfg[$n]) as $cmd) if (scan($cmd,'isolcpus')) {$isol = explode('=',$cmd)[1]; break;}
          $n++;
        }
        if ($menu) break; else $i = $n - 1;
      }
      $i++;
    }
  } elseif (is_file('/boot/grub/grub.cfg')) {
    $isol = "";
    $isolcpus = [];
    $bootcfg = file('/boot/grub/grub.cfg', FILE_IGNORE_NEW_LINES);
    // find the default section
    $menu_entries = [];
    foreach ($bootcfg as $line) {
      if (preg_match('/set default=(\d+)/', $line, $match)) {
        $bootentry = (int)$match[1];
        break;
      }
    }
    // split boot entries
    foreach ($bootcfg as $line) {
      if (strpos($line, 'menuentry ') === 0) {
        $in_menuentry = true;
        $current_entry = $line . "\n";
      } elseif ($in_menuentry) {
        $current_entry .= $line . "\n";
        if (trim($line) === "}") {
          $menu_entries[] = $current_entry;
          $in_menuentry = false;
        }
      }
    }
    // search in selected menuentry
    $menuentry = explode("\n", $menu_entries[$bootentry]);
    // find the current isolcpus setting
    if (scan($menu_entries[$bootentry],'linux ')) {
      foreach ($menuentry as $cmd) {
        if (scan($cmd,'isolcpus')) {
          $isol = explode('=',$cmd)[1];
          break;
        }
      }
    }
  }
  if ($isol != '') {
    // convert to individual numbers
    foreach (explode(',',$isol) as $cpu) {
      [$first,$last] = my_explode('-',$cpu);
      $last = $last ?: $first;
      for ($x = $first; $x <= $last; $x++) $isolcpus[] = $x;
    }
    sort($isolcpus,SORT_NUMERIC);
    $isolcpus = array_unique($isolcpus,SORT_NUMERIC);
  }
  echo "<tr><td>"._('Isolated CPUs')."</td>";
  create('is', 'isolcpus', $isolcpus);
  echo "</tr>";
  break;
case 'cmd':
  $isolcpus_now = $isolcpus_new = '';
  $cmdline = explode(' ',file_get_contents('/proc/cmdline'));
  if (is_file('/boot/syslinux/syslinux.cfg')) {
    $bootcfg = file('/boot/syslinux/syslinux.cfg',FILE_IGNORE_NEW_LINES+FILE_SKIP_EMPTY_LINES);
    foreach ($cmdline as $cmd) if (scan($cmd,'isolcpus')) {$isolcpus_now = $cmd; break;}
    $size = count($bootcfg);
    $menu = $i = 0;
    // find the default section
    while ($i < $size) {
      if (scan($bootcfg[$i],'label ')) {
        $n = $i + 1;
        // find the current isolcpus setting
        while (!scan($bootcfg[$n],'label ') && $n < $size) {
          if (scan($bootcfg[$n],'menu default')) $menu = 1;
          if (scan($bootcfg[$n],'append')) foreach (explode(' ',$bootcfg[$n]) as $cmd) if (scan($cmd,'isolcpus')) {$isolcpus_new = $cmd; break;}
          $n++;
        }
        if ($menu) break; else $i = $n - 1;
      }
      $i++;
    }
  } elseif (is_file('/boot/grub/grub.cfg')) {
    $bootcfg = file('/boot/grub/grub.cfg', FILE_IGNORE_NEW_LINES);
  }
  echo $isolcpus_now==$isolcpus_new ? 0 : 1;
  break;
}
?>
