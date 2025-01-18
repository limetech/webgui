<?PHP
/* Copyright 2005-2025, Lime Technology
 * Copyright 2012-2025, Bergware International.
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
$task = $_POST['task'];
switch ($task) {
case 'delete':
  $gateway = str_replace(['via ',' '],['','-'],trim($_POST['gateway']));
  $route = trim($_POST['route']);
  $metric = trim($_POST['metric'] ?: "1");
  if ($gateway && $route) exec("/etc/rc.d/rc.inet1 ".escapeshellarg("{$gateway}_{$route}_{$metric}_del"));
  break;
case 'Add Route':
  $gateway = str_replace(['via ',' '],['','-'],trim($_POST['gateway']));
  $route = trim($_POST['route']);
  $metric = trim($_POST['metric'] ?: "1");
  if ($gateway && $route) exec("/etc/rc.d/rc.inet1 ".escapeshellarg("{$gateway}_{$route}_{$metric}_add"));
  break;
default:
  exec("ip -4 route show table all|grep -Pv '^(127\\.0\\.0\\.0)|table local|unreachable'",$ipv4);
  exec("ip -6 route show table all|grep -Pv '^([am:]|(f[ef][0-9][0-9])::)|expires|table local|unreachable'",$ipv6);
  foreach ($ipv4 as $info) {
    $cell = explode(' ',$info);
    $route = $cell[0];
    $gateway = $cell[2];
    if ($route=='default')  $gateway .= " via {$cell[4]}";
    $metric = '0';
    for ($i=3; $i<count($cell); $i++) if ($cell[$i]=='metric') {$metric = $cell[$i+1]; break;}
    echo "<tr><td>IPv4</td><td>$route</td><td>$gateway</td><td>$metric</td><td style='text-align:center'><a href='#' onclick='deleteRoute(\"$gateway\",\"$route\",\"$metric\");return false'><i class='fa fa-trash-o'></i></a></td></tr>";
  }
  if ($ipv6) echo "<tr class='tr_last'><td colspan='5'>&nbsp;</td></tr>";
  foreach ($ipv6 as $info) {
    $cell = explode(' ',$info);
    $route = $cell[0];
    $gateway = $route=='anycast' ? $cell[3] : $cell[2];
    if ($route=='default')  $gateway .= " via {$cell[4]}";
    $metric = '1';
    for ($i=3; $i<count($cell); $i++) if ($cell[$i]=='metric') {$metric = $cell[$i+1]; break;}
    echo "<tr><td>IPv6</td><td>$route</td><td>$gateway</td><td>$metric</td><td style='text-align:center'><a href='#' onclick='deleteRoute(\"$gateway\",\"$route\",\"$metric\");return false'><i class='fa fa-trash-o'></i></a></td></tr>";
  }
  echo "<tr class='tr_last'><td colspan='5'>&nbsp;</td></tr>";
}
?>
