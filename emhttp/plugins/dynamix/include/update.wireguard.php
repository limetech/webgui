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
require_once "$docroot/webGui/include/Wrappers.php";

// add translations
$_SERVER['REQUEST_URI'] = 'settings';
// special case when script is called on form-submit and processed by update.php
if (!isset($_SESSION['locale'])) $_SESSION['locale'] = _var($_POST,'#locale');

require_once "$docroot/webGui/include/Translations.php";

$dockerd   = is_file('/var/run/dockerd.pid') && is_dir('/proc/'.file_get_contents('/var/run/dockerd.pid'));
$etc       = '/etc/wireguard';
$validIP4  = "(?:(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)){3})";
$validIP6  = "(?:([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|(:|([0-9a-fA-F]{1,4}:)+):(([0-9a-fA-F]{1,4}:)*[0-9a-fA-F]{1,4})?)";
$normalize = ['address'=>'Address', 'dns'=>'DNS', 'privatekey'=>'PrivateKey', 'publickey'=>'PublicKey', 'allowedips'=>'AllowedIPs', 'endpoint'=>'Endpoint','listenport'=>'ListenPort','mtu'=>'MTU','persistentkeepalive'=>'PersistentKeepalive'];
$dockernet = "172.31";

$t1 = '10'; // 10 sec timeout
$t2 = '15'; // 15 sec timeout

function mask2cidr($mask) {
  $long = ip2long($mask);
  $base = ip2long('255.255.255.255');
  return 32-log(($long ^ $base)+1,2);
}
function thisNet($ethX='eth0') {
  extract(parse_ini_file('state/network.ini',true));
  $net = long2ip(ip2long(_var($$ethX,'IPADDR:0')) & ip2long(_var($$ethX,'NETMASK:0'))).'/'.mask2cidr(_var($$ethX,'NETMASK:0'));
  $dev = _var($$ethX,'BRIDGING')=='yes' ? _var($$ethX,'BRNAME') : (_var($$ethX,'BONDING')=='yes' ? _var($$ethX,'BONDNAME') : $ethX);
  return [$dev,$net,$$ethX['GATEWAY:0']];
}
function ipv4($ip) {
  return strpos($ip,'.')!==false;
}
function ipv6($ip) {
  return strpos($ip,':')!==false;
}
function ipset($ip) {
  return ipv4($ip) ? $ip : "[$ip]";
}
function ipsplit($ip) {
  return ipv4($ip) ? ':' : ']:';
}
function ipv4Addr($value) {
  return array_filter(array_map('trim',explode(',',$value)),'ipv4');
}
function ipv6Addr($value) {
  return array_filter(array_map('trim',explode(',',$value)),'ipv6');
}
function ipfilter(&$list) {
  // we only import IPv4 addresses, strip any IPv6 addresses
  $list = implode(', ',ipv4Addr($list));
}
function host($ip) {
  return strpos($ip,'/')!==false ? $ip : (ipv4($ip) ? "$ip/32" : "$ip/128");
}
function isNet($network) {
  return !empty(exec("ip rule|grep -Pom1 'from $network'"));
}
function newNet($vtun) {
  global $dockernet;
  $i = substr($vtun,2)+200;
  return [$i,"$dockernet.$i.0/24"];
}
function wgState($vtun,$state,$type=0) {
  global $t1,$etc;
  $tmp = '/tmp/wg-quick.tmp';
  $log = '/var/log/wg-quick.log';
  exec("timeout $t1 wg-quick $state $vtun 2>$tmp");
  file_put_contents($log, "wg-quick $state $vtun\n".file_get_contents($tmp)."\n", FILE_APPEND);
  if ($type==8) {
    // make VPN tunneled access for Docker containers only
    $table = exec("grep -Pom1 'fwmark \K[\d]+' $tmp");
    $route = implode(ipv4Addr(exec("grep -Pom1 '^Address=\K.+$' $etc/$vtun.conf")));
    sleep(1);
    exec("ip -4 route flush table $table");
    exec("ip -4 route add $route dev $vtun table $table");
  }
  delete_file($tmp);
}
function status($vtun) {
  return in_array($vtun,explode(" ",exec("wg show interfaces")));
}
function vtun() {
  global $etc;
  $x = 0; while (file_exists("$etc/wg{$x}.conf")) $x++;
  return "wg{$x}";
}
function normalize(&$id) {
  // ensure correct capitalization of keywords, some VPN providers use the wrong case
  global $normalize;
  // allow fallback for non-included keywords
  $id = $normalize[strtolower($id)] ?? $id;
}
function dockerNet($vtun) {
  return empty(exec("docker network ls --filter name='$vtun' --format='{{.Name}}'"));
}
function addDocker($vtun) {
  global $dockerd;
  $error = false;
  [$index,$network] = newNet($vtun);
  if ($dockerd && dockerNet($vtun)) {
    exec("docker network create -o 'com.docker.network.driver.mtu'='1420' $vtun --subnet=$network 2>/dev/null");
    $error = dockerNet($vtun);
  }
  if (!$error && !isNet($network)) {
    [$device,$thisnet,$gateway] = thisNet();
    // TODO: Add IPv6 support
    if ($gateway != "" && $thisnet != "") {
      exec("ip -4 rule add from $network table $index");
      exec("ip -4 route add unreachable default table $index");
      exec("ip -4 route add $thisnet via $gateway dev $device table $index");
    }
  }
  return $error;
}
function delDocker($vtun) {
  global $dockerd;
  $error = false;
  [$index,$network] = newNet($vtun);
  if ($dockerd && !dockerNet($vtun)) {
    exec("docker network rm $vtun 2>/dev/null");
    $error = !dockerNet($vtun);
  }
  if (!$error && isNet($network)) {
    exec("ip -4 route flush table $index");
    exec("ip -4 rule del from $network table $index");
  }
  return $error;
}
function delPeer($vtun,$id='') {
  global $etc,$name;
  $dir = "$etc/peers";
  foreach (glob("$dir/peer-$name-$vtun-$id*",GLOB_NOSORT) as $peer) delete_file($peer);
}
function addPeer(&$x) {
  global $peers,$var;
  $peers[$x] = ['[Interface]'];                                         // [Interface]
  if (isset($var['client'])) $peers[$x][] = $var['client'];             // #name
  if (isset($var['privateKey'])) $peers[$x][] = $var['privateKey'];     // PrivateKey
  $peers[$x][] = _var($var,'address');                                  // Address
  if (isset($var['listenport'])) $peers[$x][] = $var['listenport'];     // ListenPort
  if (isset($var['dns'])) $peers[$x][] = $var['dns'];                   // DNS server
  if (isset($var['mtu'])) $peers[$x][] = $var['mtu'];                   // MTU
  $peers[$x][] = '';
  $peers[$x][] = "[Peer]";                                              // [Peer]
  if (isset($var['server'])) $peers[$x][] = $var['server'];             // #name
  if (isset($var['handshake'])) $peers[$x][] = $var['handshake'];       // PersistentKeepalive
  if (isset($var['presharedKey'])) $peers[$x][] = $var['presharedKey']; // PresharedKey
  $peers[$x][] = _var($var,'publicKey');                                // PublicKey
  if (isset($var['tunnel'])) $peers[$x][] = $var['tunnel'];             // Tunnel address
  $peers[$x][] = _var($var,'endpoint') ?: _var($var,'internet');        // Endpoint
  $peers[$x][] = _var($var,'allowedIPs');                               // AllowedIPs
  $x++;
}
function autostart($vtun,$cmd) {
  global $etc;
  $autostart = "$etc/autostart";
  $list = file_exists($autostart) ? array_filter(explode(' ',file_get_contents($autostart))) : [];
  $key = array_search($vtun,$list);
  switch ($cmd) {
    case 'off': if ($key!==false) unset($list[$key]); break;
    case 'on' : if ($key===false) $list[] = $vtun; break;
  }
  if (count($list)) file_put_contents($autostart,implode(' ',$list)); else delete_file($autostart);
}
function createPeerFiles($vtun) {
  global $etc,$peers,$name,$gone,$vpn;
  $dir = "$etc/peers";
  $tmp = "/tmp/list.tmp";
  if (is_dir($dir)) {
    if (count($gone)) {
      foreach ($gone as $peer) {
        // one or more peers are removed, delete the associated files
        [$n,$i] = my_explode('-',$peer);
        delPeer($n,$i);
      }
      $new = 1;
      $peer = "$dir/peer-$name-$vtun";
      $files = glob("$peer-*.conf",GLOB_NOSORT);
      natsort($files);
      foreach ($files as $file) {
        $id = explode('-',basename($file,'.conf'))[3];
        if ($id > $new) {
          // rename files to match revised peers list
          rename($file,"$peer-$new.conf");
          rename(str_replace('.conf','.png',$file),"$peer-$new.png");
        }
        $new++;
      }
    }
  } else {
    mkdir($dir);
  }
  $list = [];
  foreach ($peers as $id => $peer) {
    if (empty($peer[1])) break; // tunnel without any peers
    $cfg    = "$dir/peer-$name-$vtun-$id.conf";
    $cfgold = @file_get_contents($cfg) ?: '';
    $cfgnew = implode("\n",$peer)."\n";
    if ($cfgnew !== $cfgold && $vpn==0) {
      $list[] = "$vtun: peer $id (".($peer[1][0]=='#' ? substr($peer[1],1) : _('no name')).')';
      file_put_contents($cfg,$cfgnew);
      $png = str_replace('.conf','.png',$cfg);
      exec("qrencode -t PNG -r $cfg -o $png");
    }
  }
  // store the peer names which are updated
  if (count($list)) file_put_contents($tmp,implode("<br>",$list)); else delete_file($tmp);
}
function createList($list) {
  return implode(', ',array_unique(array_filter(array_map('trim',explode(',',$list)))));
}
function createIPs($list) {
  return implode(', ',array_map('host',array_filter(array_map('trim',explode(',',$list)))));
}
function parseInput($vtun,&$input,&$x) {
  // assign values to parameters, be aware that certain parameters are assigned by parseInput itself
  // this is based on the sequence of processing
  global $conf,$user,$var,$default4,$default6,$vpn,$tunip;
  $tunnel = $protocol = null; // satisfy code checkers
  $section = 0; $addPeer = false;
  foreach ($input as $key => $value) {
    if ($key[0]=='#') continue;
    [$id,$i] = array_pad(explode(':',$key),2,0);
    if ($i != $section) {
      if ($section==0) {
        // add WG routing for docker containers. 
        // TODO: Add IPv6 support!
        [$index,$network] = newNet($vtun);
        [$device,$thisnet,$gateway] = thisNet();

        $conf[]  = "PostUp=ip -4 route flush table $index";
        $conf[]  = "PostDown=ip -4 route flush table $index";

        if ($tunip != "") {
          // Tunnel supports IPv4
          $conf[]  = "PostUp=ip -4 route add default via $tunip dev $vtun table $index";
          $conf[]  = "PostDown=ip -4 route add unreachable default table $index";
        }

        if ($gateway != "" && $thisnet != "") {
          // local network supports IPv4
          $conf[]  = "PostUp=ip -4 route add $thisnet via $gateway dev $device table $index";
          $conf[]  = "PostDown=ip -4 route add $thisnet via $gateway dev $device table $index";
        }
        
      }
      $conf[] = "\n[Peer]";
      // add peers, this is only used for peer sections
      $addPeer ? addPeer($x) : $addPeer = true;
      $section = $i;
    }
    switch ($id) {
    case 'Name':
      if ($value) $conf[] = "#$value";
      if ($i==0) {
        $var['server'] = $value ? "#$value" : false;
      } else {
        $var['client'] = $value ? "#$value" : false;
      }
      break;
    case 'PrivateKey':
      if ($i==0) {
        $conf[] = "$id=$value";
      } else {
        if ($value) $user[] = "$id:$x=\"$value\"";
        $var['privateKey'] = $value ? "$id=$value" : false;
      }
      break;
    case 'PublicKey':
      if ($i==0) {
        $user[] = "$id:0=\"$value\"";
        $var['publicKey'] = "$id=$value";
      } else {
        $conf[] = "$id=$value";
      }
      break;
    case 'DNS':
      if ($i > 0 && $value) {
        $user[] = "$id:$x=\"$value\"";
        $var['dns'] = "$id=$value";
      } else $var['dns'] = false;
      break;
    case 'PROT':
      $protocol = $value;
      $user[] = "$id:0=\"$value\"";
      switch ($protocol) {
        case '46': $var['default']  = "AllowedIPs=$default4, $default6"; break;
        case '6' : $var['default']  = "AllowedIPs=$default6"; break;
        default  : $var['default']  = "AllowedIPs=$default4"; break;
      }
      break;
    case 'TYPE':
      $list = $value<4 ? ($value%2==1 ? _var($var,'subnets1') : _var($var,'subnets2')) : ($value<6 ? ($value%2==1 ? _var($var,'shared1') : _var($var,'shared2')) : _var($var,'default'));
      $var['allowedIPs'] = createIPs($list);
      $var['tunnel'] = ($value==2||$value==3) ? $tunnel : false;
      $user[] = "$id:$x=\"$value\"";
      if ($value>=7) $vpn = $value;
      break;
    case 'Network6': if (!$protocol) break;
    case 'Network':
    case 'UPNP':
    case 'DROP':
    case 'RULE':
    case 'NAT':
      $user[] = "$id:0=\"$value\"";
      break;
    case 'Address':
      $hosts = createIPs($value);
      if ($i==0) {
        $conf[] = "$id=$value";
        $tunnel = "$id=$hosts";
        $tunip  = implode(ipv4Addr($value));
      } else {
        $user[] = "$id:$x=\"$value\"";
        $var['address'] = "$id=$hosts";
      }
      break;
    case 'MTU':
      if ($value) $conf[] = "$id=$value";
      $var['mtu'] = $value ? "$id=$value" : false;
      break;
    case 'Endpoint':
      if ($i==0) {
        $user[] = "$id:0=\"$value\"";
        $var['endpoint'] = $value ? "Endpoint=".ipset($value) : false;
      } else {
        if ($value) $conf[] = "$id=$value";
        $var['listenport'] = $value ? "ListenPort=".(explode(ipsplit($value),$value)[1]??'') : false;
        if ($var['endpoint'] && strpos(_var($var,'endpoint'),ipsplit(_var($var,'endpoint')))===false) $var['endpoint'] .= ":".(explode(ipsplit(_var($var,'internet')),_var($var,'internet'))[1]??'');
      }
      break;
    case 'PersistentKeepalive':
      if ($value) $conf[] = "$id=$value";
      $var['handshake'] = $value ? "$id=$value" : false;
      break;
    case 'PresharedKey':
      if ($value) $conf[] = "$id=$value";
      $var['presharedKey'] = $value ? "$id=$value" : false;
      break;
    case 'AllowedIPs':
      $conf[] = "$id=".createList($value);
      break;
    default:
      if ($value) $conf[] = "$id=$value";
      break;
    }
  }
}
$default4 = '0.0.0.0/0';
$default6 = '::/0';

switch (_var($_POST,'#cmd')) {
case 'keypair':
  $private = exec("wg genkey");
  $public = exec("wg pubkey <<<'$private'");
  echo $private."\0".$public;
  break;
case 'presharedkey':
  echo exec("wg genpsk");
  break;
case 'update':
  if (!exec("iptables -S|grep -om1 'WIREGUARD$'")) {
    exec("iptables -N WIREGUARD;iptables -A FORWARD -j WIREGUARD");
  }
  if (!exec("ip6tables -S|grep -om1 'WIREGUARD$'")) {
    exec("ip6tables -N WIREGUARD;ip6tables -A FORWARD -j WIREGUARD");
  }
  $cfg  = _var($_POST,'#cfg');
  $wg   = _var($_POST,'#wg');
  $name = _var($_POST,'#name');
  $vtun = _var($_POST,'#vtun');
  $gone = explode(',',_var($_POST,'#deleted'));
  $conf = ['[Interface]'];
  $user = $peers = $var = [];
  $tunip = "";
  $var['subnets1'] = "AllowedIPs=".createList(_var($_POST,'#subnets1'));
  $var['subnets2'] = "AllowedIPs=".createList(_var($_POST,'#subnets2'));
  $var['shared1']  = "AllowedIPs=".createList(_var($_POST,'#shared1'));
  $var['shared2']  = "AllowedIPs=".createList(_var($_POST,'#shared2'));
  $var['internet'] = "Endpoint=".createList(_var($_POST,'#internet'));
  $x = 1; $vpn = 0;
  parseInput($vtun,$_POST,$x);
  addPeer($x);
  addDocker($vtun);
  $upstate = status($vtun);
  wgState($vtun,'down');
  file_put_contents($file,implode("\n",$conf)."\n");
  file_put_contents($cfg,implode("\n",$user)."\n");
  createPeerFiles($vtun);
  if ($upstate) wgState($vtun,'up',_var($_POST,'#type'));
  // if $tunip (with dots to slashes) not found in nginx config, then reload nginx to add it
  $nginx = parse_ini_file('/var/local/emhttp/nginx.ini');
  if (stripos($nginx['NGINX_CERTNAME'],'.myunraid.net')!==false) {
    $key = 'NGINX_'.strtoupper($vtun).'FQDN';
    if (!isset($nginx[$key]) || stripos($nginx[$key],str_replace('.','-',$tunip))===false) {
      exec("/etc/rc.d/rc.nginx reload");
    }
  }
  $save = false;
  break;
case 'toggle':
  $vtun = _var($_POST,'#vtun');
  switch (_var($_POST,'#wg')) {
  case 'stop':
    wgState($vtun,'down');
    echo status($vtun) ? 1 : 0;
    break;
  case 'start':
    [$index,$network] = newNet($vtun);
    if (!isNet($network)) {
      exec("ip -4 rule add from $network table $index");
      exec("ip -4 route add unreachable default table $index");
    }
    wgState($vtun,'up',_var($_POST,'#type'));
    echo status($vtun) ? 0 : 1;
    break;
  }
  break;
case 'ping':
  $addr = $_POST['#addr'];
  echo exec("ping -qc1 -W4 $addr|grep -Pom1 '1 received'");
  break;
case 'public':
  $ip = _var($_POST,'#ip');
  $v4 = _var($_POST,'#prot')!='6';
  $v6 = _var($_POST,'#prot')!='';
  $int_ipv4 = $v4 ? (preg_match("/^$validIP4$/",$ip) ? $ip : (@dns_get_record($ip,DNS_A)[0]['ip'] ?: '')) : '';
  $ext_ipv4 = $v4 ? (http_get_contents('https://wanip4.unraid.net') ?: '') : '';
  $int_ipv6 = $v6 ? (preg_match("/^$validIP6$/",$ip) ? $ip : (@dns_get_record($ip,DNS_AAAA)[0]['ipv6'] ?: '')) : '';
  $ext_ipv6 = $v6 ? (http_get_contents('https://wanip6.unraid.net') ?: '') : '';
  echo "$int_ipv4;$ext_ipv4;$int_ipv6;$ext_ipv6";
  break;
case 'addtunnel':
  $vtun = vtun();
  $name = _var($_POST,'#name');
  touch("$etc/$vtun.conf");
  wgState($vtun,'down');
  delete_file("$etc/$vtun.cfg");
  delPeer($vtun);
  autostart($vtun,'off');
  break;
case 'deltunnel':
  $vtun = _var($_POST,'#vtun');
  $name = _var($_POST,'#name');
  $error = delDocker($vtun);
  if (!$error) {
    wgState($vtun,'down');
    delete_file("$etc/$vtun.conf","$etc/$vtun.cfg");
    delPeer($vtun);
    autostart($vtun,'off');
    // remove tunnel url from nginx config
    $nginx = parse_ini_file('/var/local/emhttp/nginx.ini');
    $key = 'NGINX_'.strtoupper($vtun).'FQDN';
    if (isset($nginx[$key])) {
      exec("/etc/rc.d/rc.nginx reload");
    }
  }
  echo $error ? 1 : 0;
  break;
case 'import':
  $name = _var($_POST,'#name');
  $user = $peers = $var = $import = $sort = [];
  $entries = array_filter(array_map('trim',preg_split('/\[(Interface|Peer)\]/',_var($_POST,'#data'))));
  foreach($entries as $key => $entry) {
    $i = $key-1;
    foreach (explode("\n",$entry) as $row) {
      if (ltrim($row)[0]!='#') {
        [$id,$data] = array_map('trim',my_explode('=',$row));
        normalize($id);
        $import["$id:$i"] = $data;
      } elseif ($i >= 0) {
        $import["Name:$i"] = substr(trim($row),1);
      }
    }
  }
  if (_var($import,'PrivateKey:0') && !_var($import,'PublicKey:0')) $import['PublicKey:0'] = exec("wg pubkey <<<'"._var($import,'PrivateKey:0')."'");
  // delete ListenPort and let WG generate a random local port
  unset($import['ListenPort:0']);
  $import['UPNP:0'] = 'no';
  $import['NAT:0'] = 'no';
  [$subnet,$mask] = my_explode('/',_var($import,'Address:0'));
  if (ipv4($subnet)) {
    $mask = ($mask > 0 && $mask < 32) ? $mask : 24;
    $import['Network:0'] = long2ip(ip2long($subnet) & (0x100000000-2**(32-$mask))).'/'.$mask;
    $import['Address:0'] = $subnet;
    $import['PROT:0'] = '';
  } else {
    $mask = ($mask > 0 && $mask < 128) ? $mask : 64;
    $import['Network6:0'] = strstr($subnet,'::',true).'::/'.$mask;
    $import['Address:0'] = $subnet;
    $import['PROT:0'] = '6';
  }
  $import['Endpoint:0'] = '';
  for ($n = 1; $n <= $i; $n++) {
    $vpn = array_map('trim',explode(',',_var($import,"AllowedIPs:$n")));
    $vpn = (in_array($default4,$vpn) || in_array($default6,$vpn)) ? 8 : 0;
    if ($vpn==8) $import["Address:$n"] = '';
    $import["TYPE:$n"] = $vpn;
    ipfilter(_var($import,"AllowedIPs:$n"));
    if (_var($import,"TYPE:$n")==0) $var['subnets1'] = "AllowedIPs="._var($import,"AllowedIPs:$n");
  }
  foreach ($import as $key => $val) $sort[] = explode(':',$key)[1];
  array_multisort($sort,$import);
  $x = 1;
  $conf = ['[Interface]'];
  $var['default'] = _var($import,'PROT:0')=='' ? "AllowedIPs=$default4" : "AllowedIPs=$default6";
  $var['internet'] = "Endpoint=unknown";
  $vtun = vtun();
  parseInput($vtun,$import,$x);
  addPeer($x);
  file_put_contents("$etc/$vtun.conf",implode("\n",$conf)."\n");
  file_put_contents("$etc/$vtun.cfg",implode("\n",$user)."\n");
  delPeer($vtun);
  addDocker($vtun);
  autostart($vtun,'off');
  echo $vtun;
  break;
case 'autostart':
  autostart(_var($_POST,'#vtun'),_var($_POST,'#start'));
  break;
case 'upnp':
  $upnp = '/var/tmp/upnp';
  if (is_executable('/usr/bin/upnpc')) {
    $gw = _var($_POST,'#gw').':';
    $link = _var($_POST,'#link');
    $xml = @file_get_contents($upnp) ?: '';
    if ($xml) {
      exec("timeout $t1 stdbuf -o0 upnpc -u $xml -m $link -l 2>&1|grep -qm1 'refused'",$output,$code);
      if ($code!=1) $xml = '';
    }
    if (!$xml) {
      exec("timeout $t2 stdbuf -o0 upnpc -m $link -l 2>/dev/null|grep -Po 'desc: \K.+'",$desc);
      foreach ($desc as $url) if ($url && strpos($url,$gw)!==false) {$xml = $url; break;}
    }
  } else $xml = "";
  file_put_contents($upnp,$xml);
  echo $xml;
  break;
case 'upnpc':
  if (!is_executable('/usr/bin/upnpc')) break;
  $xml  = _var($_POST,'#xml');
  $vtun = _var($_POST,'#vtun');
  $link = _var($_POST,'#link');
  $ip   = _var($_POST,'#ip');
  if (_var($_POST,'#wg')=='active') {
    exec("timeout $t1 stdbuf -o0 upnpc -u $xml -m $link -l 2>/dev/null|grep -Po \"^(ExternalIPAddress = \K.+|.+\KUDP.+>$ip:[0-9]+ 'WireGuard-$vtun')\"",$upnp);
    [$addr,$upnp] = array_pad($upnp,2,'');
    [$type,$rule] = my_explode(' ',$upnp);
    echo $rule ? "UPnP: $addr:$rule/$type" : _("UPnP: forwarding not set");
  } else {
    echo _("UPnP: tunnel is inactive");
  }
  break;
}
?>
