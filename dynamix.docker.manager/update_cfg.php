<?
include_once("/usr/local/emhttp/plugins/dynamix.docker.manager/dockerClient.php");

// Autostart file
global $dockerManPaths;
$autostart_file = $dockerManPaths['autostart-file'];
$template_repos = $dockerManPaths['template-repos'];

// Update the start/stop configuration
if ($_POST['action'] == "autostart" ){
  $json = ($_POST['response'] == 'json') ? true : false;

  if (! $json) readfile("/usr/local/emhttp/update.htm");

  $container = urldecode(($_POST['container']));
  unset($_POST['container']);

  $allAutoStart = @file($autostart_file, FILE_IGNORE_NEW_LINES);
  if ($allAutoStart===FALSE) $allAutoStart = array();
  $key = array_search($container, $allAutoStart);
  if ($key===FALSE) {
    array_push($allAutoStart, $container);
    if ($json) echo json_encode(array( 'autostart' => true ));
  }
  else {
    unset($allAutoStart[$key]);
    if ($json) echo json_encode(array( 'autostart' => false ));
  }
  file_put_contents($autostart_file, implode(PHP_EOL, $allAutoStart).(count($allAutoStart)? PHP_EOL : ""));
}

if ($_POST['#action'] == "templates" ){
  readfile("/usr/local/emhttp/update.htm");
  $repos = $_POST['template_repos'];
  file_put_contents($template_repos, $repos);
  $DockerTemplates = new DockerTemplates();
  $DockerTemplates->downloadTemplates(TRUE);
}

if ( isset($_GET['is_dir'] )) {
  echo json_encode( array( 'is_dir' => is_dir( $_GET['is_dir'] )));
}
?>
