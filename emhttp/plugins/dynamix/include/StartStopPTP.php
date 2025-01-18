<?PHP
// start/stop service
exec("/etc/rc.d/rc.ptpd {$_POST['cmd']}");
?>
