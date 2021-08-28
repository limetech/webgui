<?PHP
/* Copyright 2005-2021, Lime Technology
 * Copyright 2012-2021, Bergware International.
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
$display['font'] = filter_var($_COOKIE['fontSize']??$display['font'], FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION);
$theme   = strtok($display['theme'],'-');
$header  = $display['header'];
$backgnd = $display['background'];
$themes1 = in_array($theme,['black','white']);
$themes2 = in_array($theme,['gray','azure']);
$config  = "/boot/config";

function annotate($text) {echo "\n<!--\n".str_repeat("#",strlen($text))."\n$text\n".str_repeat("#",strlen($text))."\n-->\n";}
?>
<!DOCTYPE html>
<html <?=$display['rtl']?>lang="<?=strtok($locale,'_')?:'en'?>">
<head>
<title><?=$var['NAME']?>/<?=$myPage['name']?></title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<meta name="format-detection" content="telephone=no">
<meta name="viewport" content="width=1600">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<link type="image/png" rel="shortcut icon" href="/webGui/images/<?=$var['mdColor']?>.png">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-fonts.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-cases.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/font-awesome.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/context.standalone.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/jquery.sweetalert.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-{$display['theme']}.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/dynamix-{$display['theme']}.css")?>">
<style>
<?if ($display['font']):?>
html{font-size:<?=$display['font']?>%}
<?endif;?>
<?if ($header):?>
#header,#header .logo,#header .text-right a{color:#<?=$header?>}
#header .block{background-color:transparent}
<?endif;?>
<?if ($backgnd):?>
#header{background-color:#<?=$backgnd?>}
<?if ($themes1):?>
#menu{background-color:#<?=$backgnd?>}
#nav-block #nav-item a{color:#<?=$header?>}
#nav-block #nav-item.active:after{background-color:#<?=$header?>}
<?endif;?>
<?endif;?>
.inline_help{display:none}
.upgrade_notice{position:fixed;top:1px;left:0;width:100%;height:40px;line-height:40px;color:#e68a00;background:#feefb3;border-bottom:#e68a00 1px solid;text-align:center;font-size:1.4rem;z-index:999}
.upgrade_notice i{margin:14px;float:right;cursor:pointer}
.back_to_top{display:none;position:fixed;bottom:30px;right:12px;color:#e22828;font-size:2.5rem;z-index:999}
<?
$nchan = ['webGui/nchan/notify_poller','webGui/nchan/session_check'];
$safemode = $var['safeMode']=='yes';
$tasks = find_pages('Tasks');
$buttons = find_pages('Buttons');
$banner = "$config/plugins/dynamix/banner.png";
echo "#header.image{background-image:url(";
echo file_exists($banner) ? autov($banner) : '/webGui/images/banner.png';
echo ")}\n";
if ($themes2) {
  foreach ($tasks as $button) if ($button['Code']) echo "#nav-item a[href='/{$button['name']}']:before{content:'\\{$button['Code']}'}\n";
  foreach ($buttons as $button) if ($button['Code']) echo "#nav-item.{$button['name']} a:before{content:'\\{$button['Code']}'}\n";
}
$notes = '/var/tmp/unRAIDServer.txt';
if (!file_exists($notes)) file_put_contents($notes,shell_exec("$docroot/plugins/dynamix.plugin.manager/scripts/plugin changes $docroot/plugins/unRAIDServer/unRAIDServer.plg"));
$notes = "&nbsp;<a href='#' title='"._('View Release Notes')."' onclick=\"openBox('/plugins/dynamix.plugin.manager/include/ShowChanges.php?tmp=1&file=$notes','"._('Release Notes')."',600,900);return false\"><span class='fa fa-info-circle fa-fw'></span></a>"
?>
</style>

<noscript>
<div class="upgrade_notice"><?=_("Your browser has JavaScript disabled")?></div>
</noscript>

<script src="<?autov('/webGui/javascript/dynamix.js')?>"></script>
<script src="<?autov('/webGui/javascript/translate.'.($locale?:'en_US').'.js')?>"></script>
<script>
String.prototype.actionName = function(){return this.split(/[\\/]/g).pop();}

Shadowbox.init({skipSetup:true});

// server uptime
var uptime = <?=strtok(exec("cat /proc/uptime"),' ')?>;
var expiretime = <?=$var['regTy']=='Trial'||strstr($var['regTy'],'expired')?$var['regTm2']:0?>;
var before = new Date();

// page timer events
var timers = {};
timers.bannerWarning = null;

// current csrf_token
var csrf_token = "<?=$var['csrf_token']?>";

// form has unsaved changes indicator
var formHasUnsavedChanges = false;

function pauseEvents(id) {
  $.each(timers, function(i,timer){
    if (!id || i==id) clearTimeout(timer);
  });
}
function resumeEvents(id,delay) {
  var startDelay = delay||50;
  $.each(timers, function(i,timer) {
    if (!id || i==id) timers[i] = setTimeout(i+'()', startDelay);
    startDelay += 50;
  });
}
function plus(value,single,plural,last) {
  return value>0 ? (value+' '+(value==1?single:plural)+(last?'':', ')) : '';
}
function updateTime() {
  var now = new Date();
  var days = parseInt(uptime/86400);
  var hour = parseInt(uptime/3600%24);
  var mins = parseInt(uptime/60%60);
  $('span.uptime').html(((days|hour|mins)?plus(days,"<?=_('day')?>","<?=_('days')?>",(hour|mins)==0)+plus(hour,"<?=_('hour')?>","<?=_('hours')?>",mins==0)+plus(mins,"<?=_('minute')?>","<?=_('minutes')?>",true):"<?=_('less than a minute')?>"));
  uptime += Math.round((now.getTime() - before.getTime())/1000);
  before = now;
  if (expiretime > 0) {
    var remainingtime = expiretime - now.getTime()/1000;
    if (remainingtime > 0) {
      days = parseInt(remainingtime/86400);
      hour = parseInt(remainingtime/3600%24);
      mins = parseInt(remainingtime/60%60);
      if (days) {
        $('#licenseexpire').html(plus(days,"<?=_('day')?>","<?=_('days')?>",true)+" <?=_('remaining')?>");
      } else if (hour) {
        $('#licenseexpire').html(plus(hour,"<?=_('hour')?>","<?=_('hours')?>",true)+" <?=_('remaining')?>").addClass('orange-text');
      } else if (mins) {
        $('#licenseexpire').html(plus(mins,"<?=_('minute')?>","<?=_('minutes')?>",true)+" <?=_('remaining')?>").addClass('red-text');
      } else {
        $('#licenseexpire').html("<?=_('less than a minute remaining')?>").addClass('red-text');
      }
    } else {
      $('#licenseexpire').addClass('red-text');
    }
  }
  setTimeout(updateTime,1000);
}
function refresh(top) {
  if (typeof top === 'undefined') {
    for (var i=0,element; element=document.querySelectorAll('input,button,select')[i]; i++) { element.disabled = true; }
    for (var i=0,link; link=document.getElementsByTagName('a')[i]; i++) { link.style.color = "gray"; } //fake disable
    location.reload();
  } else {
    $.cookie('top',top,{path:'/'});
    location.reload();
  }
}
function initab() {
  $.removeCookie('one',{path:'/'});
  $.removeCookie('tab',{path:'/'});
}
function settab(tab) {
<?switch ($myPage['name']):?>
<?case'Main':?>
  $.cookie('tab',tab,{path:'/'});
<?if ($var['fsState']=='Started'):?>
  $.cookie('one','tab1',{path:'/'});
<?endif;?>
<?break;?>
<?case'Cache':case'Data':case'Flash':case'Parity':?>
  $.cookie('one',tab,{path:'/'});
<?break;?>
<?default:?>
  $.cookie(($.cookie('one')==null?'tab':'one'),tab,{path:'/'});
<?endswitch;?>
}
function done(key) {
  var url = location.pathname.split('/');
  var path = '/'+url[1];
  if (key) for (var i=2; i<url.length; i++) if (url[i]==key) break; else path += '/'+url[i];
  $.removeCookie('one',{path:'/'});
  location.replace(path);
}
function chkDelete(form, button) {
  button.value = form.confirmDelete.checked ? "<?=_('Delete')?>" : "<?=_('Apply')?>";
  button.disabled = false;
}
function openBox(cmd,title,height,width,load,func,id) {
  // open shadowbox window (run in foreground)
  var uri = cmd.split('?');
  var run = uri[0].substr(-4)=='.php' ? cmd+(uri[1]?'&':'?')+'done=<?=urlencode(_("Done"))?>' : '/logging.htm?cmd='+cmd+'&csrf_token='+csrf_token+'&done=<?=urlencode(_("Done"))?>';
  var options = load ? (func ? {modal:true,onClose:function(){setTimeout(func+'('+'"'+(id||'')+'")',0);}} : {modal:true,onClose:function(){location.reload();}}) : {modal:false};
  Shadowbox.open({content:run, player:'iframe', title:title, height:Math.min(height,screen.availHeight), width:Math.min(width,screen.availWidth), options:options});
}
function openWindow(cmd,title,height,width) {
  // open regular window (run in background)
  var window_name = title.replace(/ /g,"_");
  var form_html = '<form action="/logging.htm" method="post" target="'+window_name+'">'+'<input type="hidden" name="csrf_token" value="'+csrf_token+'">'+'<input type="hidden" name="title" value="'+title+'">';
  var vars = cmd.split('&');
  form_html += '<input type="hidden" name="cmd" value="'+vars[0]+'">';
  for (var i = 1; i < vars.length; i++) {
    var pair = vars[i].split('=');
    form_html += '<input type="hidden" name="'+pair[0]+'" value="'+pair[1]+'">';
  }
  form_html += '</form>';
  var form = $(form_html);
  $('body').append(form);
  var top = (screen.availHeight-height)/2;
  if (top < 0) {top = 0; height = screen.availHeight;}
  var left = (screen.availWidth-width)/2;
  if (left < 0) {left = 0; width = screen.availWidth;}
  var options = 'resizeable=yes,scrollbars=yes,height='+height+',width='+width+',top='+top+',left='+left;
  window.open('', window_name, options);
  form.submit();
}
function showStatus(name,plugin,job) {
  $.post('/webGui/include/ProcessStatus.php',{name:name,plugin:plugin,job:job},function(status){$(".tabs").append(status);});
}
function showFooter(data, id) {
  if (id !== undefined) $('#'+id).remove();
  $('#copyright').prepend(data);
}
function showNotice(data) {
  $('#user-notice').html(data.replace(/<a>(.*)<\/a>/,"<a href='/Plugins'>$1</a>"));
}
function escapeQuotes(form) {
  $(form).find('input[type=text]').each(function(){$(this).val($(this).val().replace(/"/g,'\\"'));});
}

// Banner warning system

var bannerWarnings = [];
var currentBannerWarning = 0;
var osUpgradeWarning = false;

function addBannerWarning(text,warning=true,noDismiss=false) {
  var cookieText = text.replace(/[^a-z0-9]/gi,'');
  if ($.cookie(cookieText) == "true") return false;
  if (warning) text = "<i class='fa fa-warning' style='float:initial;'></i> "+text;
  if (bannerWarnings.indexOf(text) < 0) {
    var arrayEntry = bannerWarnings.push("placeholder") - 1;
    if (!noDismiss) text += "<a class='bannerDismiss' onclick='dismissBannerWarning("+arrayEntry+",&quot;"+cookieText+"&quot;)'></a>";
    bannerWarnings[arrayEntry] = text;
  } else return bannerWarnings.indexOf(text);
  if (timers.bannerWarning==null) showBannerWarnings();
  return arrayEntry;
}

function dismissBannerWarning(entry,cookieText) {
  $.cookie(cookieText,"true",{expires:365,path:'/'});
  removeBannerWarning(entry);
}

function removeBannerWarning(entry) {
  bannerWarnings[entry] = false;
  clearTimeout(timers.bannerWarning);
  showBannerWarnings();
}

function bannerFilterArray(array) {
  var newArray = [];
  array.filter(function(value,index,arr) {
    if (value) newArray.push(value);
  });
  return newArray;
}

function showBannerWarnings() {
  var allWarnings = bannerFilterArray(Object.values(bannerWarnings));
  if (allWarnings.length == 0) {
    $(".upgrade_notice").hide();
    timers.bannerWarning = null;
    return;
  }
  if (currentBannerWarning >= allWarnings.length) currentBannerWarning = 0;
  $(".upgrade_notice").show().html(allWarnings[currentBannerWarning]);
  currentBannerWarning++;
  timers.bannerWarning = setTimeout(showBannerWarnings,10000);
}

function addRebootNotice(message="<?=_('You must reboot for changes to take effect')?>") {
  addBannerWarning("<i class='fa fa-warning' style='float:initial;'></i> "+message,false,true);
  $.post("/plugins/dynamix.plugin.manager/scripts/PluginAPI.php",{action:'addRebootNotice',message:message});
}

function removeRebootNotice(message="<?=_('You must reboot for changes to take effect')?>") {
  var bannerIndex = bannerWarnings.indexOf("<i class='fa fa-warning' style='float:initial;'></i> "+message);
  if (bannerIndex < 0) return;
  removeBannerWarning(bannerIndex);
  $.post("/plugins/dynamix.plugin.manager/scripts/PluginAPI.php",{action:'removeRebootNotice',message:message});
}

function showUpgrade(text,noDismiss=false) {
  if ($.cookie('os_upgrade')==null) {
    if (osUpgradeWarning) removeBannerWarning(osUpgradeWarning);
    osUpgradeWarning = addBannerWarning(text.replace(/<a>(.*)<\/a>/,"<a href='#' onclick='openUpgrade()'>$1</a>").replace(/<b>(.*)<\/b>/,"<a href='#' onclick='document.rebootNow.submit()'>$1</a>"),false,noDismiss);
  }
}
function hideUpgrade(set) {
  removeBannerWarning(osUpgradeWarning);
  if (set)
    $.cookie('os_upgrade','true',{path:'/'});
  else
    $.removeCookie('os_upgrade',{path:'/'});
}
function openUpgrade() {
  hideUpgrade();
  swal({title:"<?=_('Update')?> Unraid OS",text:"<?=_('Do you want to update to the new version')?>?",type:'warning',html:true,showCancelButton:true,confirmButtonText:"<?=_('Proceed')?>",cancelButtonText:"<?=_('Cancel')?>"},function(){
    openBox("/plugins/dynamix.plugin.manager/scripts/plugin&arg1=update&arg2=unRAIDServer.plg","<?=_('Update')?> Unraid OS",600,900,true);
  });
}
function digits(number) {
  if (number < 10) return 'one';
  if (number < 100) return 'two';
  return 'three';
}
function openNotifier(filter) {
  notifier.stop();
  $.post('/webGui/include/Notify.php',{cmd:'get'},function(json) {
    var data = $.parseJSON(json);
    $.each(data, function(i, notify) {
      if (notify.importance == filter) {
        $.jGrowl(notify.subject+'<br>'+notify.description, {
          group: notify.importance,
          header: notify.event+': '+notify.timestamp,
          theme: notify.file,
          beforeOpen: function(e,m,o){if ($('div.jGrowl-notification').hasClass(notify.file)) return(false);},
          beforeClose: function(e,m,o){$.post('/webGui/include/Notify.php',{cmd:'archive',file:notify.file});}
        });
      }
    });
    notifier.start();
  });
}
function closeNotifier(filter) {
  notifier.stop();
  $.post('/webGui/include/Notify.php',{cmd:'get'},function(json) {
    var data = $.parseJSON(json);
    $.each(data, function(i, notify) {
      if (notify.importance == filter) $.post('/webGui/include/Notify.php',{cmd:'archive',file:notify.file});
    });
    $('div.jGrowl').find('.'+filter).find('div.jGrowl-close').trigger('click');
    notifier.start();
  });
}
function viewHistory(filter) {
  location.replace('/Tools/NotificationsArchive?filter='+filter);
}
function flashReport() {
  $.post('/webGui/include/Report.php',{cmd:'config'},function(check){
    if (check>0) addBannerWarning("<?=_('Your flash drive is corrupted or offline').'. '._('Post your diagnostics in the forum for help').'.'?> <a target='_blank' href='https://wiki.unraid.net/Manual/Changing_The_Flash_Device'><?=_('See also here')?></a>");
  });
}
$(function() {
  var tab = $.cookie('one')||$.cookie('tab')||'tab1';
  if (tab=='tab0') tab = 'tab'+$('input[name$="tabs"]').length; else if ($('#'+tab).length==0) {initab(); tab = 'tab1';}
  if ($.cookie('help')=='help') {$('.inline_help').show(); $('#nav-item.HelpButton').addClass('active');}
  $('#'+tab).attr('checked', true);
  updateTime();
  $.jGrowl.defaults.closeTemplate = '<i class="fa fa-close"></i>';
  $.jGrowl.defaults.closerTemplate = '<?=$notify['position'][0]=='b' ? '<div class="bottom">':'<div class="top">'?>[ <?=_("close all notifications")?> ]</div>';
  $.jGrowl.defaults.sticky = true;
  $.jGrowl.defaults.check = 100;
  $.jGrowl.defaults.position = '<?=$notify['position']?>';
  $.jGrowl.defaults.themeState = '';
  Shadowbox.setup('a.sb-enable', {modal:true});
// add any pre-existing reboot notices
  $.post('/webGui/include/Report.php',{cmd:'notice'},function(notices){
    notices = notices.split('\n');
    for (var i=0,notice; notice=notices[i]; i++) addBannerWarning("<i class='fa fa-warning' style='float:initial;'></i> "+notice,false,true);
  });
// check for flash offline / corrupted (delayed).
  timers.flashReport = setTimeout(flashReport,6000);
});

var mobiles=['ipad','iphone','ipod','android'];
var device=navigator.platform.toLowerCase();
for (var i=0,mobile; mobile=mobiles[i]; i++) {
  if (device.indexOf(mobile)>=0) {$('#footer').css('position','static'); break;}
}
$.ajaxPrefilter(function(s, orig, xhr){
  if (s.type.toLowerCase() == "post" && !s.crossDomain) {
    s.data = s.data || "";
    s.data += s.data?"&":"";
    s.data += "csrf_token="+csrf_token;
  }
});
</script>
<?include "$docroot/plugins/dynamix.my.servers/include/myservers1.php"?>
</head>
<body>
 <div id="template">
  <div class="upgrade_notice" style="display:none"></div>
  <div id="header" class="<?=$display['banner']?>">
   <div class="logo">
   <a href="https://unraid.net" target="_blank"><?readfile("$docroot/webGui/images/UN-logotype-gradient.svg")?></a>
   <?=_('Version')?>: <?=$var['version']?><?=$notes?>
   </div>
   <?include "$docroot/plugins/dynamix.my.servers/include/myservers2.php"?>
  </div>
  <a href="#" class="back_to_top" title="<?=_('Back To Top')?>"><i class="fa fa-arrow-circle-up"></i></a>
<?
// Build page menus
echo "<div id='menu'><div id='nav-block'><div id='nav-left'>";
foreach ($tasks as $button) {
  $page = $button['name'];
  echo "<div id='nav-item'";
  echo $task==$page ? " class='active'>" : ">";
  echo "<a href='/$page' onclick='initab()'>"._($button['Name'] ?? $page)."</a></div>";
  // create list of nchan scripts to be started
  if (isset($button['Nchan'])) nchan_merge($button['root'], $button['Nchan']);
}
unset($tasks);
if ($display['usage']) my_usage();
echo "</div>";
echo "<div id='nav-right'>";
foreach ($buttons as $button) {
  annotate($button['file']);
  eval('?>'.parse_text($button['text']));
  if (empty($button['Link'])) {
    $icon = $button['Icon'];
    if (substr($icon,-4)=='.png') {
      $icon = "<img src='/{$button['root']}/icons/$icon' class='system'>";
    } elseif (substr($icon,0,5)=='icon-') {
      $icon = "<i class='$icon system'></i>";
    } else {
      if (substr($icon,0,3)!='fa-') $icon = "fa-$icon";
      $icon = "<i class='fa $icon system'></i>";
    }
    $title = $themes2 ? "" : " title=\""._($button['Title'])."\"";
    echo "<div id='nav-item' class='{$button['name']} util'><a href='".($button['Href'] ?? '#')."' onclick='{$button['name']}();return false;'{$title}>$icon<span>"._($button['Title'])."</span></a></div>";
  } else {
    echo "<div id='{$button['Link']}'></div>";
  }
  // create list of nchan scripts to be started
  if (isset($button['Nchan'])) nchan_merge($button['root'], $button['Nchan']);
}
unset($buttons,$button);
if ($notify['display']) {
  echo "<span id='nav-tub1' class='tub'><i id='box-tub1' class='fa fa-square grey-text'></i><span id='txt-tub1' class='score one'>0</span></span>";
  echo "<span id='nav-tub2' class='tub'><i id='box-tub2' class='fa fa-square grey-text'></i><span id='txt-tub2' class='score one'>0</span></span>";
  echo "<span id='nav-tub3' class='tub'><i id='box-tub3' class='fa fa-square grey-text'></i><span id='txt-tub3' class='score one'>0</span></span>";
}
echo "</div></div></div>";

// Build page content
// Reload page every X minutes during extended viewing?
if (isset($myPage['Load']) && $myPage['Load']>0) echo "\n<script>timers.reload = setTimeout(function(){location.reload();},".($myPage['Load']*60000).");</script>\n";
echo "<div class='tabs'>";
$tab = 1;
$pages = [];
if (!empty($myPage['text'])) $pages[$myPage['name']] = $myPage;
if (($myPage['Type'] ?? '')=='xmenu') $pages = array_merge($pages, find_pages($myPage['name']));
if (isset($myPage['Tabs'])) $display['tabs'] = strtolower($myPage['Tabs'])=='true' ? 0 : 1;
$tabbed = $display['tabs']==0 && count($pages)>1;

foreach ($pages as $page) {
  $close = false;
  if (isset($page['Title'])) {
    eval("\$title=\"".htmlspecialchars($page['Title'])."\";");
    if ($tabbed) {
      echo "<div class='tab'><input type='radio' id='tab{$tab}' name='tabs' onclick='settab(this.id)'><label for='tab{$tab}'>";
      echo tab_title($title,$page['root'],$page['Tag']??false);
      echo "</label><div class='content'>";
      $close = true;
    } else {
      if ($tab==1) echo "<div class='tab'><input type='radio' id='tab{$tab}' name='tabs'><div class='content shift'>";
      echo "<div id='title'><span class='left'>";
      echo tab_title($title,$page['root'],$page['Tag']??false);
      echo "</span></div>";
    }
    $tab++;
  }
  if (isset($page['Type']) && $page['Type']=='menu') {
    $pgs = find_pages($page['name']);
    foreach ($pgs as $pg) {
      @eval("\$title=\"".htmlspecialchars($pg['Title'])."\";");
      $link = "$path/{$pg['name']}";
      $icon = $pg['Icon'] ?? "<i class='icon-app PanelIcon'></i>";
      if (substr($icon,-4)=='.png') {
        $root = $pg['root'];
        if (file_exists("$docroot/$root/images/$icon")) {
          $icon = "<img src='/$root/images/$icon' class='PanelImg'>";
        } elseif (file_exists("$docroot/$root/$icon")) {
          $icon = "<img src='/$root/$icon' class='PanelImg'>";
        } else {
          $icon = "<i class='icon-app PanelIcon'></i>";
        }
      } elseif (substr($icon,0,5)=='icon-') {
        $icon = "<i class='$icon PanelIcon'></i>";
      } elseif ($icon[0]!='<') {
        if (substr($icon,0,3)!='fa-') $icon = "fa-$icon";
        $icon = "<i class='fa $icon PanelIcon'></i>";
      }
      echo "<div class=\"Panel\"><a href=\"$link\" onclick=\"$.cookie('one','tab1',{path:'/'})\"><span>$icon</span><div class=\"PanelText\">"._($title)."</div></a></div>";
    }
  }
  // create list of nchan scripts to be started
  if (isset($page['Nchan'])) nchan_merge($page['root'], $page['Nchan']);
  annotate($page['file']);
  empty($page['Markdown']) || $page['Markdown']=='true' ? eval('?>'.Markdown(parse_text($page['text']))) : eval('?>'.parse_text($page['text']));
  if ($close) echo "</div></div>";
}
if (count($pages)) {
  $running = file_exists($nchan_pid) ? explode("\n",file_get_contents($nchan_pid)) : [];
  $start   = array_diff($nchan, $running);  // returns any new scripts to be started
  $stop    = array_diff($running, $nchan);  // returns any old scripts to be stopped
  $running = array_merge($start, $running); // update list of current running nchan scripts
  // start nchan scripts which are new
  foreach ($start as $row) {
    $script = explode(':',$row)[0];
    exec("$docroot/$script &>/dev/null &");
  }
  // stop nchan scripts with the :stop option
  foreach ($stop as $row) {
    [$script,$opt] = my_explode(':',$row);
    if ($opt == 'stop') {
      exec("pkill -f $docroot/$script >/dev/null &");
      array_splice($running,array_search($row,$running),1);
    }
  }
  if (count($running)) file_put_contents($nchan_pid,implode("\n",$running)); else @unlink($nchan_pid);
}
unset($pages,$page,$pgs,$pg,$icon,$nchan,$running,$start,$stop,$row,$script,$opt,$nchan_run);
?>
</div></div>
<div class="spinner fixed"></div>
<form name="rebootNow" method="POST" action="/webGui/include/Boot.php"><input type="hidden" name="cmd" value="reboot"></form>
<iframe id="progressFrame" name="progressFrame" frameborder="0"></iframe>
<?
// Build footer
annotate('Footer');
echo '<div id="footer"><span id="statusraid"><span id="statusbar">';
$progress = ($var['fsProgress']!='')? "&bullet;<span class='blue strong'>{$var['fsProgress']}</span>" : '';
switch ($var['fsState']) {
case 'Stopped':
  echo "<span class='red strong'><i class='fa fa-stop-circle'></i> "._('Array Stopped')."</span>$progress"; break;
case 'Starting':
  echo "<span class='orange strong'><i class='fa fa-pause-circle'></i> "._('Array Starting')."</span>$progress"; break;
case 'Stopping':
  echo "<span class='orange strong'><i class='fa fa-pause-circle'></i> "._('Array Stopping')."</span>$progress"; break;
default:
  echo "<span class='green strong'><i class='fa fa-play-circle'></i> "._('Array Started')."</span>$progress"; break;
}
echo "</span></span><span id='countdown'></span><span id='user-notice' class='red-text'></span>";
echo "<span id='copyright'>Unraid&reg; webGui &copy;2021, Lime Technology, Inc.";
echo " <a href='https://wiki.unraid.net/Manual' target='_blank' title=\""._('Online manual')."\"><i class='fa fa-book'></i> "._('manual')."</a>";
echo "</span></div>";
?>
<script>
// Firefox specific workaround
if (typeof InstallTrigger!=='undefined') $('#nav-block').addClass('mozilla');

function parseINI(data){
  var regex = {
    section: /^\s*\[\s*\"*([^\]]*)\s*\"*\]\s*$/,
    param: /^\s*([^=]+?)\s*=\s*\"*(.*?)\s*\"*$/,
    comment: /^\s*;.*$/
  };
  var value = {};
  var lines = data.split(/[\r\n]+/);
  var section = null;
  lines.forEach(function(line) {
    if (regex.comment.test(line)) {
      return;
    } else if (regex.param.test(line)) {
      var match = line.match(regex.param);
      if (section) {
        value[section][match[1]] = match[2];
      } else {
        value[match[1]] = match[2];
      }
    } else if (regex.section.test(line)) {
      var match = line.match(regex.section);
      value[match[1]] = {};
      section = match[1];
    } else if (line.length==0 && section) {
      section = null;
    };
  });
  return value;
}
// unraid animated logo
var unraid_logo = '<?readfile("$docroot/webGui/images/animated-logo.svg")?>';

var keepalive = new NchanSubscriber('/sub/session');
keepalive.on('message', function(token) {
  if (csrf_token != token) {
    // Stale session, force login
    $(location).attr('href','/');
  }
});

var notifier = new NchanSubscriber('/sub/notify');
notifier.on('message', function(d) {
  var tub1 = 0, tub2 = 0, tub3 = 0;
  var data = $.parseJSON(d);
  $.each(data, function(i, notify) {
<?if ($notify['display']):?>
    switch (notify.importance) {
      case 'alert'  : tub1++; break;
      case 'warning': tub2++; break;
      case 'normal' : tub3++; break;
    }
<?else:?>
    $.jGrowl(notify.subject+'<br>'+notify.description, {
      group: notify.importance,
      header: notify.event+': '+notify.timestamp,
      theme: notify.file,
      click: function(e,m,o) { if (notify.link) location=notify.link;},
      beforeOpen: function(e,m,o){if ($('div.jGrowl-notification').hasClass(notify.file)) return(false);},
      beforeClose: function(e,m,o){$.post('/webGui/include/Notify.php',{cmd:'archive',file:notify.file});},
      afterOpen: function(e,m,o){if (notify.link) $(e).css("cursor","pointer");}
    });
<?endif;?>
  });
<?if ($notify['display']):?>
  $('#txt-tub1').removeClass('one two three').addClass(digits(tub1)).text(tub1);
  $('#txt-tub2').removeClass('one two three').addClass(digits(tub2)).text(tub2);
  $('#txt-tub3').removeClass('one two three').addClass(digits(tub3)).text(tub3);
  if (tub1) $('#box-tub1').removeClass('grey-text').addClass('red-text'); else $('#box-tub1').removeClass('red-text').addClass('grey-text');
  if (tub2) $('#box-tub2').removeClass('grey-text').addClass('orange-text'); else $('#box-tub2').removeClass('orange-text').addClass('grey-text');
  if (tub3) $('#box-tub3').removeClass('grey-text').addClass('green-text'); else $('#box-tub3').removeClass('green-text').addClass('grey-text');
<?endif;?>
});

var watchdog = new NchanSubscriber('/sub/var');
watchdog.on('message', function(d) {
  var ini = parseINI(d);
  var state = ini['fsState'];
  var progress = ini['fsProgress'];
  var status;
  if (state=='Stopped') {
    status = "<span class='red strong'><i class='fa fa-stop-circle'></i> <?=_('Array Stopped')?></span>";
  } else if (state=='Started') {
    status = "<span class='green strong'><i class='fa fa-play-circle'></i> <?=_('Array Started')?></span>";
  } else if (state=='Formatting') {
    status = "<span class='green strong'><i class='fa fa-play-circle'></i> <?=_('Array Started')?></span>&bullet;<span class='orange strong'><?=_('Formatting device(s)')?></span>";
  } else {
    status = "<span class='orange strong'><i class='fa fa-pause-circle'></i> "+_('Array '+state)+"</span>";
  }
  if (ini['mdResyncPos']>0) {
    var action;
    if (ini['mdResyncAction'].indexOf("recon")>=0) action = "<?=_('Parity-Sync / Data-Rebuild')?>";
    else if (ini['mdResyncAction'].indexOf("clear")>=0) action = "<?=_('Clearing')?>";
    else if (ini['mdResyncAction']=="check") action = "<?=_('Read-Check')?>";
    else if (ini['mdResyncAction'].indexOf("check")>=0) action = "<?=_('Parity-Check')?>";
    action += " "+(ini['mdResyncPos']/(ini['mdResyncSize']/100+1)).toFixed(1)+" %";
    status += "&bullet;<span class='orange strong'>"+action.replace('.','<?=$display['number'][0]?>')+"</span>";
    if (ini['mdResync']==0) status += "(<?=_('Paused')?>)";
  }
  if (progress) status += "&bullet;<span class='blue strong'>"+_(progress)+"</span>";
  $('#statusbar').html(status);
});
var backtotopoffset = 250;
var backtotopduration = 500;
$(window).scroll(function() {
  if ($(this).scrollTop() > backtotopoffset) {
    $('.back_to_top').fadeIn(backtotopduration);
  } else {
    $('.back_to_top').fadeOut(backtotopduration);
  }
<?if ($themes1):?>
  var top = $('div#header').height()-1; // header height has 1 extra pixel to cover overlap
  $('div#menu').css($(this).scrollTop() > top ? {position:'fixed',top:'0'} : {position:'absolute',top:top+'px'});
<?endif;?>
});
$('.back_to_top').click(function(event) {
  event.preventDefault();
  $('html,body').animate({scrollTop:0},backtotopduration);
  return false;
});

$(function() {
  watchdog.start();
  keepalive.start();
  $('div.spinner.fixed').html(unraid_logo);
  setTimeout(function(){$('div.spinner').not('.fixed').each(function(){$(this).html(unraid_logo);});},500); // display animation if page loading takes longer than 0.5s
  shortcut.add('F1',function(){HelpButton();});
<?if ($var['regTy']=='unregistered'):?>
  $('#licensetype').addClass('orange-text');
<?elseif (!in_array($var['regTy'],['Trial','Basic','Plus','Pro'])):?>
  $('#licensetype').addClass('red-text');
<?endif;?>
<?if ($notify['entity'] & 1 == 1):?>
  $.post('/webGui/include/Notify.php',{cmd:'init'},function(){notifier.start();});
<?endif;?>
  $('input[value="<?=_("Apply")?>"],input[value="Apply"],input[name="cmdEditShare"],input[name="cmdUserEdit"]').prop('disabled',true);
  $('form').find('select,input[type=text],input[type=number],input[type=password],input[type=checkbox],input[type=radio],input[type=file],textarea').each(function(){$(this).on('input change',function() {
    var form = $(this).parentsUntil('form').parent();
    form.find('input[value="<?=_("Apply")?>"],input[value="Apply"],input[name="cmdEditShare"],input[name="cmdUserEdit"]').not('input.lock').prop('disabled',false);
    form.find('input[value="<?=_("Done")?>"],input[value="Done"]').not('input.lock').val("<?=_('Reset')?>").prop('onclick',null).off('click').click(function(){formHasUnsavedChanges=false;refresh(form.offset().top);});
  });});
  // add leave confirmation when form has changed without applying (opt-in function)
  $('form.js-confirm-leave').on('change',function(e){formHasUnsavedChanges=true;}).on('submit',function(e){formHasUnsavedChanges=false;});
  $(window).on('beforeunload',function(e){if (formHasUnsavedChanges) return '';}); // note: the browser creates its own popup window and warning message
  // form parser: add escapeQuotes protection
  $('form').each(function(){
    var action = $(this).prop('action').actionName();
    if (action=='update.htm' || action=='update.php') {
      var onsubmit = $(this).attr('onsubmit')||'';
      $(this).attr('onsubmit','clearTimeout(timers.flashReport);escapeQuotes(this);'+onsubmit);
    }
  });

  var top = ($.cookie('top')||0) - $('.tabs').offset().top - 75;
  if (top>0) {$('html,body').scrollTop(top);}
  $.removeCookie('top',{path:'/'});
<?if ($safemode):?>
  showNotice("<?=_('System running in')?> <b><?=('safe mode')?></b>");
<?else:?>
<?$readme = @file_get_contents("$docroot/plugins/unRAIDServer/README.md",false,null,0,20);?>
<?if (strpos($readme,'REBOOT REQUIRED')!==false):?>
  showUpgrade("<b><?=_('Reboot Now')?></b> <?=_('to upgrade Unraid OS')?>",true);
<?elseif (strpos($readme,'DOWNGRADE')!==false):?>
  showUpgrade("<b><?=_('Reboot Now')?></b> <?=_('to downgrade Unraid OS')?>",true);
<?elseif ($version = plugin_update_available('unRAIDServer',true)):?>
  showUpgrade("Unraid OS v<?=$version?> <?=_('is available')?>. <a><?=_('Update Now')?></a>");
<?endif;?>
<?if (!$notify['system']):?>
  addBannerWarning("<?=_('System notifications are')?> <b><?=_('disabled')?></b>. <?=_('Click')?> <a href='/Settings/Notifications' style='cursor:pointer'><?=_('here')?></a> <?=_('to change notification settings')?>.",true,true);
<?endif;?>
<?endif;?>
<?if ($notify['display']):?>
  var opts = [];
  context.init({preventDoubleContext:false,left:true,above:false});
  opts.push({text:"<?=_('View')?>",icon:'fa-folder-open-o',action:function(e){e.preventDefault();openNotifier('alert');}});
  opts.push({divider:true});
  opts.push({text:"<?=_('History')?>",icon:'fa-file-text-o',action:function(e){e.preventDefault();viewHistory('alert');}});
  opts.push({divider:true});
  opts.push({text:"<?=_('Acknowledge')?>",icon:'fa-check-square-o',action:function(e){e.preventDefault();closeNotifier('alert');}});
  context.attach('#nav-tub1',opts);

  var opts = [];
  context.init({preventDoubleContext:false,left:true,above:false});
  opts.push({text:"<?=_('View')?>",icon:'fa-folder-open-o',action:function(e){e.preventDefault();openNotifier('warning');}});
  opts.push({divider:true});
  opts.push({text:"<?=_('History')?>",icon:'fa-file-text-o',action:function(e){e.preventDefault();viewHistory('warning');}});
  opts.push({divider:true});
  opts.push({text:"<?=_('Acknowledge')?>",icon:'fa-check-square-o',action:function(e){e.preventDefault();closeNotifier('warning');}});
  context.attach('#nav-tub2',opts);

  var opts = [];
  context.init({preventDoubleContext:false,left:true,above:false});
  opts.push({text:"<?=_('View')?>",icon:'fa-folder-open-o',action:function(e){e.preventDefault();openNotifier('normal');}});
  opts.push({divider:true});
  opts.push({text:"<?=_('History')?>",icon:'fa-file-text-o',action:function(e){e.preventDefault();viewHistory('normal');}});
  opts.push({divider:true});
  opts.push({text:"<?=_('Acknowledge')?>",icon:'fa-check-square-o',action:function(e){e.preventDefault();closeNotifier('normal');}});
  context.attach('#nav-tub3',opts);
<?endif;?>
  if (location.pathname.search(/\/(AddVM|UpdateVM|AddContainer|UpdateContainer)/)==-1) {
    $('blockquote.inline_help').each(function(i) {
      $(this).attr('id','helpinfo'+i);
      var pin = $(this).prev();
      if (!pin.prop('nodeName')) pin = $(this).parent().prev();
      while (pin.prop('nodeName') && pin.prop('nodeName').search(/(table|dl)/i)==-1) pin = pin.prev();
      pin.find('tr:first,dt:last').each(function() {
        var node = $(this);
        var name = node.prop('nodeName').toLowerCase();
        if (name=='dt') {
          while (!node.html() || node.html().search(/(<input|<select|nbsp;)/i)>=0 || name!='dt') {
            if (name=='dt' && node.is(':first-of-type')) break;
            node = node.prev();
            name = node.prop('nodeName').toLowerCase();
          }
          node.css('cursor','help').click(function(){$('#helpinfo'+i).toggle('slow');});
        } else {
          if (node.html() && (name!='tr' || node.children('td:first').html())) node.css('cursor','help').click(function(){$('#helpinfo'+i).toggle('slow');});
        }
      });
    });
  }
  $('form').append($('<input>').attr({type:'hidden', name:'csrf_token', value:csrf_token}));
});
</script>
</body>
</html>
