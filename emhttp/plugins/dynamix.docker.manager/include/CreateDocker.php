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
  if ($startContainer) $cmd = str_replace('/docker create ', '/docker run -d ', $cmd);
  execCommand($cmd);
  if ($startContainer) addRoute($Name); // add route for remote WireGuard access

  echo '<div style="text-align:center"><button type="button" onclick="done()">'._('Done').'</button></div><br>';
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
    // force kill container if still running after time-out
    if (empty($_GET['communityApplications'])) removeContainer($Name, $echo);
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
?>
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.ui.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.switchbutton.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.filetree.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/plugins/dynamix.docker.manager/styles/DockerManager.css")?>">

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
: <input type="text" name="contName" pattern="[a-zA-Z0-9][a-zA-Z0-9_.-]+" required>

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
  } else {
    $('.myIP').show();
    $('#myIP').html('Subnet: '+subnet[bridge]);
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
