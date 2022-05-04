<?PHP
/* Copyright 2005-2022, Lime Technology
 * Copyright 2012-2022, Bergware International.
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
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$certPath = "/boot/config/ssl/certs/certificate_bundle.pem";
// add translations
$_SERVER['REQUEST_URI'] = 'settings';
require_once "$docroot/webGui/include/Translations.php";
require_once "$docroot/webGui/include/Wrappers.php";

$cli = php_sapi_name()=='cli';

function response_complete($httpcode, $result, $cli_success_msg='') {
  global $cli;
  if ($cli) {
    $json = @json_decode($result,true);
    if (!empty($json['error'])) {
      echo 'Error: '.$json['error'].PHP_EOL;
      exit(1);
    }
    exit($cli_success_msg.PHP_EOL);
  }
  header('Content-Type: application/json');
  http_response_code($httpcode);
  exit((string)$result);
}

$var = parse_ini_file("/var/local/emhttp/var.ini");
extract(parse_ini_file('/var/local/emhttp/network.ini',true));

if (file_exists('/boot/config/plugins/dynamix.my.servers/myservers.cfg')) {
  @extract(parse_ini_file('/boot/config/plugins/dynamix.my.servers/myservers.cfg',true));
}

$certPresent = file_exists($certPath);
if ($certPresent) {
  // renew existing cert
  $certSubject = exec("/usr/bin/openssl x509 -subject -noout -in ".escapeshellarg($certPath));
  $isLegacyCert = preg_match('/.*\.unraid\.net$/', $certSubject);
  $isWildcardCert = preg_match('/.*\.myunraid\.net$/', $certSubject);
  if ($isLegacyCert || $isWildcardCert) {    
    exec("/usr/bin/openssl x509 -checkend 2592000 -noout -in ".escapeshellarg($certPath), $arrout, $retval_expired);
    if ($retval_expired === 0) {
      // not within 30 days of cert expire date
      response_complete(406, '{"error":"'._('Cannot renew cert until within 30 days of expiry').'"}');
    }
  } else {
    // assume custom cert
    response_complete(406, '{"error":"'._('Cannot renew a custom cert at').' '.$certPath.'"}');
  }
}
$endpoint = ($certPresent && $isLegacyCert) ? "provisioncert" : "provisionwildcard";

$keyfile = @file_get_contents($var['regFILE']);
if ($keyfile === false) {
  response_complete(406, '{"error":"'._('License key required').'"}');
}
$keyfile      = @base64_encode($keyfile);

$ch = curl_init("https://keys.lime-technology.com/account/ssl/$endpoint");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
  'keyfile' => $keyfile
]);
$result = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// save the cert
if ($cli) {
  $json = @json_decode($result,true);
  if (empty($json['bundle'])) {
    $strError = _('Server was unable to provision SSL certificate');
    if (!empty($json['error'])) {
      $strError .= ' - '.$json['error'];
    }
    response_complete(406, '{"error":"'.$strError.'"}');
  }
  $_POST['text'] = $json['bundle']; // nice way to leverage CertUpload.php to save the cert
  include(__DIR__.'/CertUpload.php');
}

response_complete($httpcode, $result, _('LE Cert Provisioned successfully'));
?>
