<?PHP
/* Copyright 2005-2023, Lime Technology
 * Copyright 2012-2023, Bergware International.
 * Copyright 2015-2021, Derek Macias, Eric Schultz, Jon Panozzo.
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
	require_once "$docroot/webGui/include/Custom.php";
	require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";

	// add translations
	if (substr($_SERVER['REQUEST_URI'],0,4) != '/VMs') {
		$_SERVER['REQUEST_URI'] = 'vms';
		require_once "$docroot/webGui/include/Translations.php";
	}

	$arrValidMachineTypes = getValidMachineTypes();
	$arrValidPCIDevices = getValidPCIDevices();
	$arrValidGPUDevices = getValidGPUDevices();
	$arrValidAudioDevices = getValidAudioDevices();
	$arrValidOtherDevices = getValidOtherDevices();
	$arrValidUSBDevices = getValidUSBDevices();
	$arrValidDiskDrivers = getValidDiskDrivers();
	$arrValidDiskBuses = getValidDiskBuses();
	$arrValidCdromBuses = getValidCdromBuses();
	$arrValidVNCModels = getValidVNCModels();
	$arrValidProtocols = getValidVMRCProtocols();
	$arrValidKeyMaps = getValidKeyMaps();
	$arrValidNetworks = getValidNetworks();
	$strCPUModel = getHostCPUModel();

	$templateslocation = "/boot/config/plugins/dynamix.vm.manager/savedtemplates.json";

	if (is_file($templateslocation)){
		$arrAllTemplates["User-templates"] = "";
		$ut = json_decode(file_get_contents($templateslocation),true);
		$arrAllTemplates = array_merge($arrAllTemplates, $ut);
	}

	$arrConfigDefaults = [
		'template' => [
			'name' => $strSelectedTemplate,
			'icon' => $arrAllTemplates[$strSelectedTemplate]['icon'],
			'os' => $arrAllTemplates[$strSelectedTemplate]['os'],
			'storage' => "default"
		],
		'domain' => [
			'name' => $strSelectedTemplate,
			'persistent' => 1,
			'uuid' => $lv->domain_generate_uuid(),
			'clock' => 'localtime',
			'arch' => 'x86_64',
			'machine' => 'pc-i440fx',
			'mem' => 1024 * 1024,
			'maxmem' => 1024 * 1024,
			'password' => '',
			'cpumode' => 'host-passthrough',
			'cpumigrate' => 'on',
			'vcpus' => 1,
			'vcpu' => [0],
			'hyperv' => 1,
			'ovmf' => 1,
			'usbmode' => 'usb2',
			'memoryBacking' => '{"nosharepages":{}}'
		],
		'media' => [
			'cdrom' => '',
			'cdrombus' => 'ide',
			'drivers' => is_file($domain_cfg['VIRTIOISO']) ? $domain_cfg['VIRTIOISO'] : '',
			'driversbus' => 'ide' ,
			'cdromboot' => 2
		],
		'disk' => [
			[
				'new' => '',
				'size' => '',
				'driver' => 'raw',
				'dev' => 'hda',
				'select' => $domain_cfg['VMSTORAGEMODE'],
				'bus' => 'virtio' ,
				'boot' => 1,
				'serial' => 'vdisk1'
			]
		],
		'gpu' => [
			[
				'id' => 'virtual',
				'protocol' => 'vnc',
				'autoport' => 'yes',
				'model' => 'qxl',
				'keymap' => 'none',
				'port' => -1 ,
				'wsport' => -1,
				'copypaste' => 'no'
			]
		],
		'audio' => [
			[
				'id' => ''
			]
		],
		'pci' => [],
		'nic' => [
			[
				'network' => $domain_bridge,
				'mac' => $lv->generate_random_mac_addr(),
				'model' => 'virtio-net'
			]
		],
		'usb' => [],
		'shares' => [
			[
				'source' => '',
				'target' => '',
				'mode' => ''
			]
		]
	];
	$hdrXML = "<?xml version='1.0' encoding='UTF-8'?>\n"; // XML encoding declaration

	// Merge in any default values from the VM template
	if ($arrAllTemplates[$strSelectedTemplate] && $arrAllTemplates[$strSelectedTemplate]['overrides']) {
		$arrConfigDefaults = array_replace_recursive($arrConfigDefaults, $arrAllTemplates[$strSelectedTemplate]['overrides']);
	}
	// create new VM
	if (isset($_POST['createvm'])) {
		if (isset($_POST['xmldesc'])) {
			// XML view
			$new = $lv->domain_define($_POST['xmldesc'], $_POST['domain']['xmlstartnow']==1);
			if ($new){
				$lv->domain_set_autostart($new, $_POST['domain']['autostart']==1);
				$reply = ['success' => true];
			} else {
				$reply = ['error' => $lv->get_last_error()];
			}
		} else {
			// form view
			if ($lv->domain_new($_POST)) {
				// Fire off the vnc/spice popup if available
				$dom = $lv->get_domain_by_name($_POST['domain']['name']);
				$vmrcport = $lv->domain_get_vnc_port($dom);
				$wsport = $lv->domain_get_ws_port($dom);
				$protocol = $lv->domain_get_vmrc_protocol($dom);
				$reply = ['success' => true];
				if ($vmrcport > 0) {
					$reply['vmrcurl']  = autov('/plugins/dynamix.vm.manager/'.$protocol.'.html',true).'&autoconnect=true&host=' . $_SERVER['HTTP_HOST'];
					if ($protocol == "spice") $reply['vmrcurl']  .= '&port=/wsproxy/'.$vmrcport.'/'; else $reply['vmrcurl'] .= '&port=&path=/wsproxy/' . $wsport . '/';
				}
			} else {
				$reply = ['error' => $lv->get_last_error()];
			}
		}
		echo json_encode($reply);
		exit;
	}

		// create new VM template
		if (isset($_POST['createvmtemplate'])) {
			$reply = addtemplatexml($_POST);
			echo json_encode($reply);
			exit;
		}

	// update existing VM
	if (isset($_POST['updatevm'])) {
		$uuid = $_POST['domain']['uuid'];
		$dom = $lv->domain_get_domain_by_uuid($uuid);
		$oldAutoStart = $lv->domain_get_autostart($dom)==1;
		$newAutoStart = $_POST['domain']['autostart']==1;
		$strXML = $lv->domain_get_xml($dom);

		if ($lv->domain_get_state($dom)=='running') {
			$arrErrors = [];
			$arrExistingConfig = domain_to_config($uuid);
			$arrNewUSBIDs = $_POST['usb'];

			// hot-attach any new usb devices
			foreach ($arrNewUSBIDs as $strNewUSBID) {
				if (strpos($strNewUSBID,"#remove")) continue;
				$remove = explode('#', $strNewUSBID);
				$strNewUSBID2 = $remove[0];
				foreach ($arrExistingConfig['usb'] as $arrExistingUSB) {
					if ($strNewUSBID2 == $arrExistingUSB['id']) continue 2;
				}
				[$strVendor,$strProduct] = my_explode(':', $strNewUSBID2);
				// hot-attach usb
				file_put_contents('/tmp/hotattach.tmp', "<hostdev mode='subsystem' type='usb'><source startupPolicy='optional'><vendor id='0x".$strVendor."'/><product id='0x".$strProduct."'/></source></hostdev>");
				exec("virsh attach-device ".escapeshellarg($uuid)." /tmp/hotattach.tmp --live 2>&1", $arrOutput, $intReturnCode);
				unlink('/tmp/hotattach.tmp');
				if ($intReturnCode != 0) {
					$arrErrors[] = implode(' ', $arrOutput);
				}
			}

			// hot-detach any old usb devices
			foreach ($arrExistingConfig['usb'] as $arrExistingUSB) {
				if (!in_array($arrExistingUSB['id'], $arrNewUSBIDs)) {
					[$strVendor, $strProduct] = my_explode(':', $arrExistingUSB['id']);
					file_put_contents('/tmp/hotdetach.tmp', "<hostdev mode='subsystem' type='usb'><source startupPolicy='optional'><vendor id='0x".$strVendor."'/><product id='0x".$strProduct."'/></source></hostdev>");
					exec("virsh detach-device ".escapeshellarg($uuid)." /tmp/hotdetach.tmp --live 2>&1", $arrOutput, $intReturnCode);
					unlink('/tmp/hotdetach.tmp');
					if ($intReturnCode != 0) $arrErrors[] = implode(' ',$arrOutput);
				}
			}
			$reply = !$arrErrors ? ['success' => true] : ['error' => implode(', ',$arrErrors)];
			echo json_encode($reply);
			exit;
		}

		// backup xml for existing domain in ram
		if ($dom && empty($_POST['xmldesc'])) {
			$oldName = $lv->domain_get_name($dom);
			$newName = $_POST['domain']['name'];
			$oldDir = $domain_cfg['DOMAINDIR'].$oldName;
			$newDir = $domain_cfg['DOMAINDIR'].$newName;
			if ($oldName && $newName && is_dir($oldDir) && !is_dir($newDir)) {
				// mv domain/vmname folder
				if (rename($oldDir, $newDir)) {
					// replace all disk paths in xml
					foreach ($_POST['disk'] as &$arrDisk) {
						if ($arrDisk['new']) $arrDisk['new'] = str_replace($oldDir, $newDir, $arrDisk['new']);
						if ($arrDisk['image']) $arrDisk['image'] = str_replace($oldDir, $newDir, $arrDisk['image']);
					}
				}
			}
		}
		$newuuid = $uuid;
		$olduuid = $uuid;
		// construct updated config
		if (isset($_POST['xmldesc'])) {
			// XML view
			$xml = $_POST['xmldesc'];
			$arrExistingConfig = custom::createArray('domain',$xml);
			$newuuid = $arrExistingConfig['uuid'];
			$xml = str_replace($olduuid,$newuuid,$xml);
		} else {
			// form view
			if ($error = create_vdisk($_POST) === false) {
				$arrExistingConfig = custom::createArray('domain',$strXML);
				$arrUpdatedConfig = custom::createArray('domain',$lv->config_to_xml($_POST));
				array_update_recursive($arrExistingConfig, $arrUpdatedConfig);
				$arrConfig = array_replace_recursive($arrExistingConfig, $arrUpdatedConfig);
				$xml = custom::createXML('domain',$arrConfig)->saveXML();
				$xml = $lv->appendqemucmdline($xml,$_POST["qemucmdline"]);
			} else {
				echo json_encode(['error' => $error]);
				exit;
			}
		}
		// delete and create the VM
		$lv->nvram_backup($uuid);
		$lv->domain_undefine($dom);
		$lv->nvram_restore($uuid);
		if ($newuuid != $olduuid) $lv->nvram_rename($olduuid,$newuuid);
		$new = $lv->domain_define($xml);
		if ($new) {
			$lv->domain_set_autostart($new, $newAutoStart);
			$reply = ['success' => true];
		} else {
			// Failure -- try to restore existing VM
			$reply = ['error' => $lv->get_last_error()];
			$old = $lv->domain_define($strXML);
			if ($old) $lv->domain_set_autostart($old, $oldAutoStart);
		}
		echo json_encode($reply);
		exit;
	}

	if (isset($_GET['uuid'])) {
		// edit an existing VM
		$uuid = unscript($_GET['uuid']);
		$dom = $lv->domain_get_domain_by_uuid($uuid);
		$boolRunning = $lv->domain_get_state($dom)=='running';
		$strXML = $lv->domain_get_xml($dom);
		$boolNew = false;
		$arrConfig = array_replace_recursive($arrConfigDefaults, domain_to_config($uuid));
		$arrVMUSBs = getVMUSBs($strXML);
	} else {
		// edit new VM
		$boolRunning = false;
		$strXML = '';
		$boolNew = true;
		$arrConfig = $arrConfigDefaults;
		$arrVMUSBs = getVMUSBs($strXML);
	}
	// Add any custom metadata field defaults (e.g. os)
	if (!$arrConfig['template']['os']) {
		$arrConfig['template']['os'] = ($arrConfig['domain']['clock']=='localtime' ? 'windows' : 'linux');
	}
	$os_type = ((empty($arrConfig['template']['os']) || stripos($arrConfig['template']['os'], 'windows') === false) ? 'other' : 'windows');
	if (isset($arrConfig['clocks'])) $arrClocks = json_decode($arrConfig['clocks'],true); else {
		if ($os_type == "windows") {
			if ($arrConfig['domain']['hyperv'] == 1) $arrClocks = $arrDefaultClocks['hyperv']; else $arrClocks = $arrDefaultClocks['windows'];
		} else $arrClocks = $arrDefaultClocks['other'];
	}

	if (strpos($arrConfig['template']['name'],"User-") !== false) {
		$arrConfig['template']['name'] = str_replace("User-","",$arrConfig['template']['name']);
		unset($arrConfig['domain']['uuid']);
	}
	if ($usertemplate == 1) unset($arrConfig['domain']['uuid']);
?>

<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/lib/codemirror.css')?>">
<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/show-hint.css')?>">
<style type="text/css">
	.CodeMirror { border: 1px solid #eee; cursor: text; margin-top: 15px; margin-bottom: 10px; }
	.CodeMirror pre.CodeMirror-placeholder { color: #999; }
</style>

<div class="formview">
<input type="hidden" name="template[os]" id="template_os" value="<?=htmlspecialchars($arrConfig['template']['os'])?>">
<input type="hidden" name="domain[persistent]" value="<?=htmlspecialchars($arrConfig['domain']['persistent'])?>">
<input type="hidden" name="domain[uuid]" value="<?=htmlspecialchars($arrConfig['domain']['uuid'])?>">
<input type="hidden" name="domain[arch]" value="<?=htmlspecialchars($arrConfig['domain']['arch'])?>">
<input type="hidden" name="domain[oldname]" id="domain_oldname" value="<?=htmlspecialchars($arrConfig['domain']['name'])?>">
<!--<input type="hidden" name="template[oldstorage]" id="storage_oldname" value="<?=htmlspecialchars($arrConfig['template']['storage'])?>"> -->
<input type="hidden" name="domain[memoryBacking]" id="domain_memorybacking" value="<?=htmlspecialchars($arrConfig['domain']['memoryBacking'])?>">

	<table>
		<tr>
			<td>_(Name)_:</td>
			<td><input type="text" name="domain[name]" id="domain_name" class="textTemplate" title="_(Name of virtual machine)_" placeholder="_(e.g.)_ _(My Workstation)_" value="<?=htmlspecialchars($arrConfig['domain']['name'])?>" required /></td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>Give the VM a name (e.g. Work, Gaming, Media Player, Firewall, Bitcoin Miner)</p>
	</blockquote>

	<table>
		<tr class="advanced">
			<td>_(Description)_:</td>
			<td><input type="text" name="domain[desc]" title="_(description of virtual machine)_" placeholder="_(description of virtual machine)_ (_(optional)_)" value="<?=htmlspecialchars($arrConfig['domain']['desc'])?>" /></td>
		</tr>
	</table>
	<div class="advanced">
		<blockquote class="inline_help">
			<p>Give the VM a brief description (optional field).</p>
		</blockquote>
	</div>

	<table>
		<tr>
			<?if (!$boolNew) $disablestorage = " disabled "; else $disablestorage = "";?>
			<td>_(Override Storage Location)_:</td><td>
			<select <?=$disablestorage?> name="template[storage]" class="disk_select narrow" id="storage_location" title="_(Location of virtual machine files)_">
			<?
			$default_storage=htmlspecialchars($arrConfig['template']['storage']);
			echo mk_option($default_storage, 'default', _('Default'));

			$strShareUserLocalInclude = '';
			$strShareUserLocalExclude = '';
			$strShareUserLocalUseCache = 'no';

			// Get the share name and its configuration
			$arrDomainDirParts = explode('/', $domain_cfg['DOMAINDIR']);
			$strShareName = $arrDomainDirParts[3];
			if (!empty($strShareName) && is_file('/boot/config/shares/'.$strShareName.'.cfg')) {
				$arrShareCfg = parse_ini_file('/boot/config/shares/'.$strShareName.'.cfg');
				if (!empty($arrShareCfg['shareInclude'])) {
					$strShareUserLocalInclude = $arrShareCfg['shareInclude'];
				}
				if (!empty($arrShareCfg['shareExclude'])) {
					$strShareUserLocalExclude = $arrShareCfg['shareExclude'];
				}
				if (!empty($arrShareCfg['shareUseCache'])) {
					$strShareUserLocalUseCache = $arrShareCfg['shareUseCache'];
				}
			}

			// Available cache pools
			foreach ($pools as $pool) {
				$strLabel = $pool.' - '.my_scale($disks[$pool]['fsFree']*1024, $strUnit).' '.$strUnit.' '._('free');
				echo mk_option($default_storage, $pool, $strLabel);
			}

			// Determine which disks from the array are available for this share:
			foreach ($disks as $name => $disk) {
				if ((strpos($name, 'disk') === 0) && (!empty($disk['device']))) {
					if ((!empty($strShareUserLocalInclude) && (strpos($strShareUserLocalInclude.',', $name.',') === false)) ||
						(!empty($strShareUserLocalExclude) && (strpos($strShareUserLocalExclude.',', $name.',') !== false)) ||
						(!empty($var['shareUserInclude']) && (strpos($var['shareUserInclude'].',', $name.',') === false)) ||
						(!empty($var['shareUserExclude']) && (strpos($var['shareUserExclude'].',', $name.',') !== false))) {
						// skip this disk based on local and global share settings
						continue;
					}
					$strLabel = _(my_disk($name),3).' - '.my_scale($disk['fsFree']*1024, $strUnit).' '.$strUnit.' '._('free');
					echo mk_option($default_storage, $name, $strLabel);
				}
			}
			?>
			</td></tr>
	</table>
	<blockquote class="inline_help">
		<p>Specify the overide storage pool for VM. This option allows you to specify the physical pool/disk used to store the disk images and snapshot data.
		   Default will follow standard processing and store images in the default location for the share defined in the settings.
		   A pool/disk(Volume) will be the location for images if the default is overridden.
		</p>
	</blockquote>

	<?
	$migratehidden =  "disabled hidden";
	if ($arrConfig['domain']['cpumode'] == 'host-passthrough') $migratehidden = "";
	?>

	<table>
		<tr class="advanced">
			<td><span class="advanced">_(CPU)_ </span>_(Mode)_:</td>
			<td>
				<select id="cpu" name="domain[cpumode]" class="cpu" title="_(define type of cpu presented to this vm)_">
				<?mk_dropdown_options(['host-passthrough' => _('Host Passthrough').' (' . $strCPUModel . ')', 'custom' => _('Emulated').' ('._('QEMU64').')'], $arrConfig['domain']['cpumode']);?>
				</select>
				<span class="advanced" id="domain_cpumigrate_text"<?=$migratehidden?>>_(Migratable)_:</span>

				<select name="domain[cpumigrate]" id="domain_cpumigrate"  <?=$migratehidden?> class="narrow" title="_(define if migratable)_">
				<?
				echo mk_option($arrConfig['domain']['cpumigrate'], 'on', 'On');
				echo mk_option($arrConfig['domain']['cpumigrate'], 'off', 'Off');
				?>
				</select>
			</td>
		</tr>
	</table>
	<div class="advanced">
		<blockquote class="inline_help">
			<p>There are two CPU modes available to choose:</p>
			<p>
				<b>Host Passthrough</b><br>
				With this mode, the CPU visible to the guest should be exactly the same as the host CPU even in the aspects that libvirt does not understand.  For the best possible performance, use this setting.
			</p>
			<p>
				<b>Emulated</b><br>
				If you are having difficulties with Host Passthrough mode, you can try the emulated mode which doesn't expose the guest to host-based CPU features.  This may impact the performance of your VM.
			</p>
			<p>
				<b>Migratable</b><br>
				Migratable attribute may be used to explicitly request such features to be removed from (on) or kept in (off) the virtual CPU. Off will not remove any host features when using Host Passthrough. Not support on emulated.
			</p>
		</blockquote>
	</div>

	<table>
		<tr>
			<td>_(Logical CPUs)_:</td>
			<td>
				<div class="textarea four">
				<?
				$cpus = cpu_list();
				foreach ($cpus as $pair) {
					unset($cpu1,$cpu2);
					[$cpu1, $cpu2] = my_preg_split('/[,-]/',$pair);
					$extra = ($arrConfig['domain']['vcpu'] && in_array($cpu1, $arrConfig['domain']['vcpu'])) ? ($arrConfig['domain']['vcpus'] > 1 ? 'checked' : 'checked disabled') : '';
					if (!$cpu2) {
						echo "<label for='vcpu$cpu1' class='checkbox'>cpu $cpu1<input type='checkbox' name='domain[vcpu][]' class='domain_vcpu' id='vcpu$cpu1' value='$cpu1' $extra><span class='checkmark'></span></label>";
					} else {
						echo "<label for='vcpu$cpu1' class='cpu1 checkbox'>cpu $cpu1 / $cpu2<input type='checkbox' name='domain[vcpu][]' class='domain_vcpu' id='vcpu$cpu1' value='$cpu1' $extra><span class='checkmark'></span></label>";
						$extra = ($arrConfig['domain']['vcpu'] && in_array($cpu2, $arrConfig['domain']['vcpu'])) ? ($arrConfig['domain']['vcpus'] > 1 ? 'checked' : 'checked disabled') : '';
						echo "<label for='vcpu$cpu2' class='cpu2 checkbox'><input type='checkbox' name='domain[vcpu][]' class='domain_vcpu' id='vcpu$cpu2' value='$cpu2' $extra><span class='checkmark'></span></label>";
					}
				}
				?>
				</div>
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>The number of logical CPUs in your system is determined by multiplying the number of CPU cores on your processor(s) by the number of threads.</p>
		<p>Select which logical CPUs you wish to allow your VM to use. (minimum 1).</p>
	</blockquote>

	<table>
		<tr>
			<td><span class="advanced">_(Initial)_ </span>_(Memory)_:</td>
			<td>
				<select name="domain[mem]" id="domain_mem" class="narrow" title="_(define the amount memory)_">
				<?
					echo mk_option($arrConfig['domain']['mem'], 128 * 1024, '128 MB');
					echo mk_option($arrConfig['domain']['mem'], 256 * 1024, '256 MB');
					for ($i = 1; $i <= ($maxmem*2); $i++) {
						$label = ($i * 512) . ' MB';
						$value = $i * 512 * 1024;
						echo mk_option($arrConfig['domain']['mem'], $value, $label);
					}
				?>
				</select>

			<span class="advanced">_(Max)_ _(Memory)_:</span>
				<select name="domain[maxmem]" id="domain_maxmem" class="narrow" title="_(define the maximum amount of memory)_">
				<?
					echo mk_option($arrConfig['domain']['maxmem'], 128 * 1024, '128 MB');
					echo mk_option($arrConfig['domain']['maxmem'], 256 * 1024, '256 MB');
					for ($i = 1; $i <= ($maxmem*2); $i++) {
						$label = ($i * 512) . ' MB';
						$value = $i * 512 * 1024;
						echo mk_option($arrConfig['domain']['maxmem'], $value, $label);
					}
				?>
				</select>
			</td>
		</tr>
	</table>
	<div class="basic">
		<blockquote class="inline_help">
			<p>Select how much memory to allocate to the VM at boot.</p>
		</blockquote>
	</div>
	<div class="advanced">
		<blockquote class="inline_help">
			<p>For VMs where no PCI devices are being passed through (GPUs, sound, etc.), you can set different values to initial and max memory to allow for memory ballooning.  If you are passing through a PCI device, only the initial memory value is used and the max memory value is ignored.  For more information on KVM memory ballooning, see <a href="http://www.linux-kvm.org/page/FAQ#Is_dynamic_memory_management_for_guests_supported.3F" target="_new">here</a>.</p>
		</blockquote>
	</div>

	<?
	if (!isset($arrValidMachineTypes[$arrConfig['domain']['machine']]))  {
		$arrConfig['domain']['machine'] = ValidateMachineType($arrConfig['domain']['machine']);
	}
	?>

	<table>
		<tr class="advanced">
			<td>_(Machine)_:</td>
			<td>
				<select name="domain[machine]" class="narrow" id="domain_machine" title="_(Select the machine model)_.  _(i440fx will work for most)_.  _(Q35 for a newer machine model with PCIE)_">
				<?mk_dropdown_options($arrValidMachineTypes, $arrConfig['domain']['machine']);?>
				</select>
			</td>
		</tr>
	</table>
	<div class="advanced">
		<blockquote class="inline_help">
			<p>The machine type option primarily affects the success some users may have with various hardware and GPU pass through.  For more information on the various QEMU machine types, see these links:</p>
			<a href="http://wiki.qemu.org/Documentation/Platforms/PC" target="_blank">http://wiki.qemu.org/Documentation/Platforms/PC</a><br>
			<a href="http://wiki.qemu.org/Features/Q35" target="_blank">http://wiki.qemu.org/Features/Q35</a><br>
			<p>As a rule of thumb, try to get your configuration working with i440fx first and if that fails, try adjusting to Q35 to see if that changes anything.</p>
		</blockquote>
	</div>

	<table>
		<tr class="advanced">
			<td>_(BIOS)_:</td>
			<td>
				<select name="domain[ovmf]" id="domain_ovmf" class="narrow" title="_(Select the BIOS)_.  _(SeaBIOS will work for most)_.  _(OVMF requires a UEFI-compatable OS)_ (_(e.g.)_ _(Windows 8/2012, newer Linux distros)_) _(and if using graphics device passthrough it too needs UEFI)_" onchange="BIOSChange(this)">
				<?
					echo mk_option($arrConfig['domain']['ovmf'], '0', _('SeaBIOS'));

					if (file_exists('/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd')) {
						echo mk_option($arrConfig['domain']['ovmf'], '1', _('OVMF'));
					} else {
						echo mk_option('', '0', _('OVMF').' ('._('Not Available').')', 'disabled');
					}
					if (file_exists('/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi-tpm.fd')) {
						echo mk_option($arrConfig['domain']['ovmf'], '2', _('OVMF TPM'));
					} else {
						echo mk_option('', '0', _('OVMF TPM').' ('._('Not Available').')', 'disabled');
					}
				?>
				</select>

			<?
				$usbboothidden = "hidden";
				if ($arrConfig['domain']['ovmf'] != '0') $usbboothidden = "";
				?>
				<span id="USBBoottext" class="advanced" <?=$usbboothidden?>>_(Enable USB boot)_:</span>

				<select name="domain[usbboot]" id="domain_usbboot" class="narrow" title="_(define OS boot options)_" <?=$usbboothidden?> onchange="USBBootChange(this)">
				<?
					echo mk_option($arrConfig['domain']['usbboot'], 'No', 'No');
					echo mk_option($arrConfig['domain']['usbboot'], 'Yes', 'Yes');
				?>
				</select>
				</td>
		</tr>
	</table>
	<div class="advanced">
		<blockquote class="inline_help">
			<p>
				<b>SeaBIOS</b><br>
				is the default virtual BIOS used to create virtual machines and is compatible with all guest operating systems (Windows, Linux, etc.).
			</p>
			<p>
				<b>OVMF</b><br>
				(Open Virtual Machine Firmware) adds support for booting VMs using UEFI, but virtual machine guests must also support UEFI.  Assigning graphics devices to a OVMF-based virtual machine requires that the graphics device also support UEFI.
			</p>
			<p>
				<b>OVMF TPM</b><br>
				(Open Virtual Machine Firmware) adds support for booting VMs using UEFI with TPM and Secure Boot available, but virtual machine guests must also support UEFI.  Assigning graphics devices to a OVMF-based virtual machine requires that the graphics device also support UEFI.<br>
			</p>
			<p>
				Once a VM is created this setting cannot be adjusted.
			</p>
			<p>
				<b>USB Boot</b><br>
				Adds support for booting from USB devices using UEFI. No device boot orders can be specified at the same time as this option.<br>
			</p>
		</blockquote>
	</div>

	<table class="domain_os windows">
		<tr class="advanced">
			<td>_(Hyper-V)_:</td>
			<td>
				<select name="domain[hyperv]" id="hyperv" class="narrow" title="_(Hyperv tweaks for Windows)_" 	<?if ($boolNew && $os_type == "windows"):?> onchange="HypervChgNew(this)" <?endif?>>
				<?mk_dropdown_options([_('No'), _('Yes')], $arrConfig['domain']['hyperv']);?>
				</select>
			</td>
		</tr>
	</table>
	<div class="domain_os windows">
		<div class="advanced">
			<blockquote class="inline_help">
				<p>Exposes the guest to hyper-v extensions for Microsoft operating systems.</p>
			</blockquote>
		</div>
	</div>

	<table>
		<tr class="advanced">
			<td>_(USB Controller)_:</td>
			<td>
				<select name="domain[usbmode]" id="usbmode" class="narrow" title="_(Select the USB Controller to emulate)_.  _(Some OSes won't support USB3)_ (_(e.g.)_ _(Windows 7/XP)_)">
				<?
					echo mk_option($arrConfig['domain']['usbmode'], 'usb2', _('2.0 (EHCI)'));
					echo mk_option($arrConfig['domain']['usbmode'], 'usb3', _('3.0 (nec XHCI)'));
					echo mk_option($arrConfig['domain']['usbmode'], 'usb3-qemu', _('3.0 (qemu XHCI)'));
				?>
				</select>
			</td>
		</tr>
	</table>
	<div class="advanced">
		<blockquote class="inline_help">
			<p>
				<b>USB Controller</b><br>
				Select the USB Controller to emulate.  Some OSes won't support USB3 (e.g. Windows 7).  Qemu XHCI is the same code base as Nec XHCI but without several hacks applied over the years.  Recommended to try qemu XHCI before resorting to nec XHCI.
			</p>
		</blockquote>
	</div>

	<table>
		<tr>
			<td>_(OS Install ISO)_:</td>
			<td>
				<input type="text" name="media[cdrom]" autocomplete="off" spellcheck="false" data-pickcloseonfile="true" data-pickfilter="iso" data-pickmatch="^[^.].*" data-pickroot="<?=htmlspecialchars($domain_cfg['MEDIADIR'])?>" class="cdrom" value="<?=htmlspecialchars($arrConfig['media']['cdrom'])?>" placeholder="_(Click and Select cdrom image to install operating system)_">
			</td>
		</tr>
		<tr class="advanced">
			<td>_(OS Install CDRom Bus)_:</td>
			<td>
				<select name="media[cdrombus]" class="cdrom_bus narrow">
				<?mk_dropdown_options($arrValidCdromBuses, $arrConfig['media']['cdrombus']);?>
				</select>
				_(Boot Order)_:
				<input type="number" size="5" maxlength="5" id="cdboot" class="narrow bootorder" style="width: 50px;" name="media[cdromboot]"   title="_(Boot order)_"  value="<?=$arrConfig['media']['cdromboot']?>" >
				</td>
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>Select the virtual CD-ROM (ISO) that contains the installation media for your operating system.  Clicking this field displays a list of ISOs found in the directory specified on the Settings page.</p>
		<p class="advanced">
			<b>CDRom Bus</b><br>
			Specify what interface this virtual cdrom uses to connect inside the VM.
		</p>
	</blockquote>

	<table class="domain_os windows">
		<tr class="advanced">
			<td>_(VirtIO Drivers ISO)_:</td>
			<td>
				<input type="text" name="media[drivers]" autocomplete="off" spellcheck="false" data-pickcloseonfile="true" data-pickfilter="iso" data-pickmatch="^[^.].*" data-pickroot="<?=htmlspecialchars($domain_cfg['MEDIADIR'])?>" class="cdrom" value="<?=htmlspecialchars($arrConfig['media']['drivers'])?>" placeholder="_(Download, Click and Select virtio drivers image)_">
			</td>
		</tr>
		<tr class="advanced">
			<td>_(VirtIO Drivers CDRom Bus)_:</td>
			<td>
				<select name="media[driversbus]" class="cdrom_bus narrow">
				<?mk_dropdown_options($arrValidCdromBuses, $arrConfig['media']['driversbus']);?>
				</select>
			</td>
		</tr>
	</table>
	<div class="domain_os windows">
		<div class="advanced">
			<blockquote class="inline_help">
				<p>Specify the virtual CD-ROM (ISO) that contains the VirtIO Windows drivers as provided by the Fedora Project.  Download the latest ISO from here: <a href="https://docs.fedoraproject.org/en-US/quick-docs/creating-windows-virtual-machines-using-virtio-drivers/index.html#virtio-win-direct-downloads" target="_blank">https://docs.fedoraproject.org/en-US/quick-docs/creating-windows-virtual-machines-using-virtio-drivers/index.html#virtio-win-direct-downloads</a></p>
				<p>When installing Windows, you will reach a step where no disk devices will be found.  There is an option to browse for drivers on that screen.  Click browse and locate the additional CD-ROM in the menu.  Inside there will be various folders for the different versions of Windows.  Open the folder for the version of Windows you are installing and then select the AMD64 subfolder inside (even if you are on an Intel system, select AMD64).  Three drivers will be found.  Select them all, click next, and the vDisks you have assigned will appear.</p>
				<p>
					<b>CDRom Bus</b><br>
					Specify what interface this virtual cdrom uses to connect inside the VM.
				</p>
			</blockquote>
		</div>
	</div>

	<?foreach ($arrConfig['disk'] as $i => $arrDisk) {
		$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : _('Primary');

		?>
		<table data-category="vDisk" data-multiple="true" data-minimum="1" data-maximum="24" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
			<tr>
				<td>_(vDisk Location)_:</td>
				<td>
					<select name="disk[<?=$i?>][select]" class="disk_select narrow">
					<?
						if ($i == 0) {
							echo '<option value="">'._('None').'</option>';
						}

						$default_option = $arrDisk['select'];

						if (!empty($domain_cfg['DOMAINDIR']) && file_exists($domain_cfg['DOMAINDIR'])) {

							$boolShowAllDisks = (strpos($domain_cfg['DOMAINDIR'], '/mnt/user/') === 0);

							if (!empty($arrDisk['new'])) {
								if (strpos($domain_cfg['DOMAINDIR'], dirname(dirname($arrDisk['new']))) === false ||
									basename(dirname($arrDisk['new'])) != $arrConfig['domain']['name'] || (
									basename($arrDisk['new']) != 'vdisk'.($i+1).'.img') && basename($arrDisk['new']) != 'vdisk'.($i+1).'.qcow2') {
									$default_option = 'manual';
								}
								if (file_exists(dirname(dirname($arrDisk['new'])).'/'.$arrConfig['domain']['name'].'/vdisk'.($i+1).'.img') || file_exists(dirname(dirname($arrDisk['new'])).'/'.$arrConfig['domain']['name'].'/vdisk'.($i+1).'.qcow2')) {
									// hide all the disks because the auto disk already has been created
									$boolShowAllDisks = false;
								}
							}

							echo mk_option($default_option, 'auto', _('Auto'));

							if ($boolShowAllDisks) {
								$strShareUserLocalInclude = '';
								$strShareUserLocalExclude = '';
								$strShareUserLocalUseCache = 'no';

								// Get the share name and its configuration
								$arrDomainDirParts = explode('/', $domain_cfg['DOMAINDIR']);
								$strShareName = $arrDomainDirParts[3];
								if (!empty($strShareName) && is_file('/boot/config/shares/'.$strShareName.'.cfg')) {
									$arrShareCfg = parse_ini_file('/boot/config/shares/'.$strShareName.'.cfg');
									if (!empty($arrShareCfg['shareInclude'])) {
										$strShareUserLocalInclude = $arrShareCfg['shareInclude'];
									}
									if (!empty($arrShareCfg['shareExclude'])) {
										$strShareUserLocalExclude = $arrShareCfg['shareExclude'];
									}
									if (!empty($arrShareCfg['shareUseCache'])) {
										$strShareUserLocalUseCache = $arrShareCfg['shareUseCache'];
									}
								}

								// Available cache pools
								foreach ($pools as $pool) {
									$strLabel = $pool.' - '.my_scale($disks[$pool]['fsFree']*1024, $strUnit).' '.$strUnit.' '._('free');
									echo mk_option($default_option, $pool, $strLabel);
								}

								// Determine which disks from the array are available for this share:
								foreach ($disks as $name => $disk) {
									if ((strpos($name, 'disk') === 0) && (!empty($disk['device']))) {
										if ((!empty($strShareUserLocalInclude) && (strpos($strShareUserLocalInclude.',', $name.',') === false)) ||
											(!empty($strShareUserLocalExclude) && (strpos($strShareUserLocalExclude.',', $name.',') !== false)) ||
											(!empty($var['shareUserInclude']) && (strpos($var['shareUserInclude'].',', $name.',') === false)) ||
											(!empty($var['shareUserExclude']) && (strpos($var['shareUserExclude'].',', $name.',') !== false))) {
											// skip this disk based on local and global share settings
											continue;
										}
										$strLabel = _(my_disk($name),3).' - '.my_scale($disk['fsFree']*1024, $strUnit).' '.$strUnit.' '._('free');
										echo mk_option($default_option, $name, $strLabel);
									}
								}
							}
						}
						echo mk_option($default_option, 'manual', _('Manual'));
					?>
					</select><input type="text" name="disk[<?=$i?>][new]" autocomplete="off" spellcheck="false" data-pickcloseonfile="true" data-pickfolders="true" data-pickfilter="img,qcow,qcow2" data-pickmatch="^[^.].*" data-pickroot="/mnt/" class="disk" id="disk_<?=$i?>" value="<?=htmlspecialchars($arrDisk['new'])?>" placeholder="_(Separate sub-folder and image will be created based on Name)_"><div class="disk_preview"></div>
				</td>
			</tr>

			<input type="hidden" name="disk[<?=$i?>][storage]" id="disk[<?=$i?>][storage]" value="<?=htmlspecialchars($arrConfig['template']['storage'])?>">

			<tr class="disk_file_options">
				<td>_(vDisk Size)_:</td>
				<td>
					<input type="text" name="disk[<?=$i?>][size]" value="<?=htmlspecialchars($arrDisk['size'])?>" class="narrow" placeholder="_(e.g.)_ 10M, 1G, 10G...">
				</td>
			</tr>

			<tr class="advanced disk_file_options">
				<td>_(vDisk Type)_:</td>
				<td>
					<select name="disk[<?=$i?>][driver]" class="disk_driver narrow" title="_(type of storage image)_">
					<?mk_dropdown_options($arrValidDiskDrivers, $arrDisk['driver']);?>
					</select>
				</td>
			</tr>

			<tr class="advanced disk_bus_options">
				<td>_(vDisk Bus)_:</td>
				<td>
					<select name="disk[<?=$i?>][bus]" class="disk_bus narrow" onchange="BusChange(this)">
					<?mk_dropdown_options($arrValidDiskBuses, $arrDisk['bus']);?>
					</select>
				_(Boot Order)_:
				<input type="number" size="5" maxlength="5" id="disk[<?=$i?>][boot]" class="narrow bootorder" style="width: 50px;" name="disk[<?=$i?>][boot]"   title="_(Boot order)_"  value="<?=$arrDisk['boot']?>" >
				<? if ($arrDisk['bus'] == "virtio" || $arrDisk['bus'] == "usb") $ssddisabled = "hidden "; else $ssddisabled = " ";?>
				<span id="disk[<?=$i?>][rotatetext]" <?=$ssddisabled?>>_(SSD)_:</span>
				<input type="checkbox"  id="disk[<?=$i?>][rotation]" class="narrow rotation" onchange="updateSSDCheck(this)"style="width: 50px;" name="disk[<?=$i?>][rotation]"  <?=$ssddisabled ?> <?=$arrDisk['rotation'] ? "checked ":"";?>  title="_(Set SDD flag)_"  value="<?=$arrDisk['rotation']?>" >
				</td>
			</tr>
			<tr class="advanced disk_bus_options">
				<td>_(Serial)_:</td>
				<td>
				<input type="text" size="20" maxlength="20" id="disk[<?=$i?>][serial]" class="narrow disk_serial" style="width: 200px;" name="disk[<?=$i?>][serial]"   title="_(Serial)_"  value="<?=$arrDisk['serial']?>" >
				</td>
			</tr>
		</table>
		<?if ($i == 0) {?>
		<blockquote class="inline_help">
			<p>
				<b>vDisk Location</b><br>
				Specify a path to a user share in which you wish to store the VM or specify an existing vDisk.  The primary vDisk will store the operating system for your VM.
			</p>

			<p>
				<b>NOTE</b>: Unraid will automatically "dereference" vdisk paths when starting a VM.
				That is, if a vdisk path is specified as being on a user share, we use the SYSTEM.LOCATION extended attribute to find out what physical disk the image exists on.
				We then pass this path when starting a VM via qemu.  This ensures that VM I/O bypasses shfs (FUSE user share file system) for better performance.
				It also means that a vdisk image file can be moved from one physical device to another without changing the VM XML file.
			</p>

			<p>
				Example: /mnt/user/domains/Windows/vdisk1.img will be dereferenced to /mnt/cache/domains/Windows/vdisk1.img (for vdisk1.img physically located in the "cache" volume).
			</p>

			<p>
				<b>vDisk Size</b><br>
				Specify a number followed by a letter.  M for megabytes, G for gigabytes.
			</p>

			<p class="advanced">
				<b>vDisk Type</b><br>
				Select RAW for best performance.  QCOW2 implementation is still in development.
			</p>

			<p class="advanced">
				<b>vDisk Bus</b><br>
				Select virtio for best performance.
			</p>

			<p class="advanced">
				<b>vDisk Boot Order</b><br>
				Specify the order the devices are used for booting.
			</p>

			<p class="advanced">
				<b>vDisk SSD Flag</b><br>
				Specify the vdisk shows as SSD within the guest, only supported on SCSI, SATA and IDE bus types.
			</p>

			<p class="advanced">
				<b>vDisk Serial</b><br>
				Set the device serial number presented to the VM.
			</p>

			<p>Additional devices can be added/removed by clicking the symbols to the left.</p>
		</blockquote>
		<?}?>
	<?}?>
	<script type="text/html" id="tmplvDisk">
		<table>
			<tr>
				<td>_(vDisk Location)_:</td>
				<td>
					<select name="disk[{{INDEX}}][select]" class="disk_select narrow">
					<?
						if (!empty($domain_cfg['DOMAINDIR']) && file_exists($domain_cfg['DOMAINDIR'])) {

							$default_option = $domain_cfg['VMSTORAGEMODE'];

							echo mk_option($default_option, 'auto', _('Auto'));

							if (strpos($domain_cfg['DOMAINDIR'], '/mnt/user/') === 0) {
								$strShareUserLocalInclude = '';
								$strShareUserLocalExclude = '';
								$strShareUserLocalUseCache = 'no';

								// Get the share name and its configuration
								$arrDomainDirParts = explode('/', $domain_cfg['DOMAINDIR']);
								$strShareName = $arrDomainDirParts[3];
								if (!empty($strShareName) && is_file('/boot/config/shares/'.$strShareName.'.cfg')) {
									$arrShareCfg = parse_ini_file('/boot/config/shares/'.$strShareName.'.cfg');
									if (!empty($arrShareCfg['shareInclude'])) {
										$strShareUserLocalInclude = $arrShareCfg['shareInclude'];
									}
									if (!empty($arrShareCfg['shareExclude'])) {
										$strShareUserLocalExclude = $arrShareCfg['shareExclude'];
									}
									if (!empty($arrShareCfg['shareUseCache'])) {
										$strShareUserLocalUseCache = $arrShareCfg['shareUseCache'];
									}
								}

								// Available cache pools
								foreach ($pools as $pool) {
									$strLabel = $pool.' - '.my_scale($disks[$pool]['fsFree']*1024, $strUnit).' '.$strUnit.' '._('free');
									echo mk_option($default_option, $pool, $strLabel);
								}

								// Determine which disks from the array are available for this share:
								foreach ($disks as $name => $disk) {
									if ((strpos($name, 'disk') === 0) && (!empty($disk['device']))) {
										if ((!empty($strShareUserLocalInclude) && (strpos($strShareUserLocalInclude.',', $name.',') === false)) ||
											(!empty($strShareUserLocalExclude) && (strpos($strShareUserLocalExclude.',', $name.',') !== false)) ||
											(!empty($var['shareUserInclude']) && (strpos($var['shareUserInclude'].',', $name.',') === false)) ||
											(!empty($var['shareUserExclude']) && (strpos($var['shareUserExclude'].',', $name.',') !== false))) {
											// skip this disk based on local and global share settings
											continue;
										}
										$strLabel = _(my_disk($name),3).' - '.my_scale($disk['fsFree']*1024, $strUnit).' '.$strUnit.' '._('free');
										echo mk_option($default_option, $name, $strLabel);
									}
								}
							}
						}
						echo mk_option('', 'manual', _('Manual'));
					?>
					</select><input type="text" name="disk[{{INDEX}}][new]" autocomplete="off" spellcheck="false" data-pickcloseonfile="true" data-pickfolders="true" data-pickfilter="img,qcow,qcow2" data-pickmatch="^[^.].*" data-pickroot="/mnt/" class="disk" id="disk_{{INDEX}}" value="" placeholder="_(Separate sub-folder and image will be created based on Name)_"><div class="disk_preview"></div>
				</td>
			</tr>
			<input type="hidden" name="disk[{{INDEX}}][storage]" id="disk[{{INDEX}}][storage]" value="<?=htmlspecialchars($arrConfig['template']['storage'])?>">
			<tr class="disk_file_options">
				<td>_(vDisk Size)_:</td>
				<td>
					<input type="text" name="disk[{{INDEX}}][size]" value="" class="narrow" placeholder="_(e.g.)_ 10M, 1G, 10G...">
				</td>
			</tr>

			<tr class="advanced disk_file_options">
				<td>_(vDisk Type)_:</td>
				<td>
					<select name="disk[{{INDEX}}][driver]" class="disk_driver narrow" title="_(type of storage image)_">
					<?mk_dropdown_options($arrValidDiskDrivers, '');?>
					</select>
				</td>
			</tr>

			<tr class="advanced disk_bus_options">
				<td>_(vDisk Bus)_:</td>
				<td>
					<select name="disk[{{INDEX}}][bus]" class="disk_bus narrow" onchange="BusChange(this)">
					<?mk_dropdown_options($arrValidDiskBuses, '');?>
					</select>

				_(Boot Order)_:
				<input type="number" size="5" maxlength="5" id="disk[{{INDEX}}][boot]" class="narrow bootorder" style="width: 50px;" name="disk[{{INDEX}}][boot]"   title="_(Boot order)_"  value="" >
				<span id="disk[{{INDEX}}][rotatetext]" hidden>_(SSD)_:</span>
				<input type="checkbox"  id="disk[{{INDEX}}][rotation]" class="narrow rotation" onchange="updateSSDCheck(this)"style="width: 50px;" name="disk[{{INDEX}}[rotation]" hidden title="_(Set SSD flag)_" value='0' >
				</td>
				<tr class="advanced disk_bus_options">
				<td>_(Serial)_:</td>
				<td>
				<input type="text" size="20" maxlength="20" id="disk[{{INDEX}}[serial]" class="narrow disk_serial" style="width: 200px;" name="disk[{{INDEX}}][serial]"   title="_(Serial)_"  value="" >
				</td>
			</tr>
			</tr>
		</table>
	</script>

	<?
	 $arrUnraidShares = getUnraidShares();
	 foreach ($arrConfig['shares'] as $i => $arrShare) {
		$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';

		?>
		<table  data-category="Share" data-multiple="true" data-minimum="1" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">

			<tr class="advanced">
				<td>_(Unraid Share Mode)_:</td>
				<td>
				<select name="shares[<?=$i?>][mode]" class="disk_bus narrow">
					<?if ($os_type != "windows") echo mk_option($arrShare['mode'], "9p", _('9p Mode'));;?>
					<?echo mk_option($arrShare['mode'], "virtiofs", _('Virtiofs Mode'));;?>
				</select>
				_(Unraid Share)_:
				<select name="shares[<?=$i?>][unraid]" class="disk_bus narrow"  onchange="ShareChange(this)" >
				    <?	$UnraidShareDisabled = ' disabled="disabled"';
					    $arrUnraidIndex = array_search("User:".$arrShare['target'],$arrUnraidShares);
					    if ($arrUnraidIndex != false && substr($arrShare['source'],0,10) != '/mnt/user/') $arrUnraidIndex = false;
					    if ($arrUnraidIndex == false) $arrUnraidIndex = array_search("Disk:".$arrShare['target'],$arrUnraidShares);
						if ($arrUnraidIndex == false) { $arrUnraidIndex = ''; $UnraidShareDisabled = "";}
						mk_dropdown_options($arrUnraidShares, $arrUnraidIndex);?>
				</select>
				</td>
				</tr>

			<tr class="advanced">
				<td>
					<text id="shares[<?=$i?>]sourcetext" > _(Unraid Source Path)_: </text>
				</td>
				<td>
					<input type="text" <?=$UnraidShareDisabled?> id="shares[<?=$i?>][source]" name="shares[<?=$i?>][source]" autocomplete="off" data-pickfolders="true" data-pickfilter="NO_FILES_FILTER" data-pickroot="/mnt/" value="<?=htmlspecialchars($arrShare['source'])?>" placeholder="_(e.g.)_ /mnt/user/..." title="_(path of Unraid share)_" />
				</td>
			</tr>

			<tr  class="advanced">
				<td><span id="shares[<?=$i?>][targettext]" >_(Unraid Mount Tag)_:</span></td>
				<td>
					<input type="text" <?=$UnraidShareDisabled?> name="shares[<?=$i?>][target]" id="shares[<?=$i?>][target]" value="<?=htmlspecialchars($arrShare['target'])?>" placeholder="_(e.g.)_ _(shares)_ (_(name of mount tag inside vm)_)" title="_(mount tag inside vm)_" />
				</td>
			</tr>
		</table>
		<?if ($i == 0) {?>
		<div>
			<div class="advanced">
				<blockquote class="inline_help">
					<p>
						<b>Unraid Share Mode</b><br>
						Used to create a VirtFS mapping to a Linux-based guest.  Specify the mode you want to use either 9p or Virtiofs.
					</p>


					<p>
						<b>Unraid Share</b><br>
						Set tag and path to match the selected Unraid share.
					</p>

					<p>
						<b>Unraid Source Path</b><br>
						Specify the path on the host here.
					</p>

					<p>
						<b>Unraid Mount tag</b><br>
						Specify the mount tag that you will use for mounting the VirtFS share inside the VM.  See this page for how to do this on a Linux-based guest: <a href="http://wiki.qemu.org/Documentation/9psetup" target="_blank">http://wiki.qemu.org/Documentation/9psetup</a>
					</p>
					<p>
						For Windows additional software needs to be installed: <a href="https://virtio-fs.gitlab.io/howto-windows.html" target="_blank">https://virtio-fs.gitlab.io/howto-windows.html</a>
					</p>

					<p>Additional devices can be added/removed by clicking the symbols to the left.</p>
				</blockquote>
			</div>
		</div>
		<?}?>
	<?}?>
	<script type="text/html" id="tmplShare">
		<table class="domain_os other">
		<tr class="advanced">
				<td>_(Unraid Share Mode)_:</td>
				<td>
				<select name="shares[{{INDEX}}][mode]" class="disk_bus narrow">
					<?if ($os_type != "windows") echo mk_option($arrShare['mode'], "9p", _('9p Mode'));;?>
					<?echo mk_option('', "virtiofs", _('Virtiofs Mode'));;?>
				</select>

				_(Unraid Share)_:

				<select name="shares[{{INDEX}}][unraid]" class="disk_bus narrow"  onchange="ShareChange(this)" >
					<?mk_dropdown_options($arrUnraidShares, '');?>
				</select>
				</td>
				</tr>

			<tr class="advanced">
				<td>_(Unraid Source Path)_:</td>
				<td>
					<input type="text" name="shares[{{INDEX}}][source]" id="shares[{{INDEX}}][source]" autocomplete="off" spellcheck="false" data-pickfolders="true" data-pickfilter="NO_FILES_FILTER" data-pickroot="/mnt/" value="" placeholder="_(e.g.)_ /mnt/user/..." title="_(path of Unraid share)_" />
				</td>
			</tr>

			<tr class="advanced">
				<td>_(Unraid Mount Tag)_:</td>
				<td>
					<input type="text" name="shares[{{INDEX}}][target]" id="shares[{{INDEX}}][target]" value="" placeholder="_(e.g.)_ _(shares)_ (_(name of mount tag inside vm)_)" title="_(mount tag inside vm)_" />
				</td>
			</tr>
		</table>
	</script>

	<?foreach ($arrConfig['gpu'] as $i => $arrGPU) {
		$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';

		?>
		<table data-category="Graphics_Card" data-multiple="true" data-minimum="1" data-maximum="<?=count($arrValidGPUDevices)+1?>" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
			<tr>
				<td>_(Graphics Card)_:</td>
				<td>
					<select name="gpu[<?=$i?>][id]" class="gpu narrow">
					<?
						if ($i == 0) {
							// Only the first video card can be VNC or SPICE
							echo mk_option($arrGPU['id'], 'virtual', _('Virtual'));

						} else {
							echo mk_option($arrGPU['id'], '', _('None'));
						}

						foreach($arrValidGPUDevices as $arrDev) {
							echo mk_option($arrGPU['id'], $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
						}
					?>
					</select>
					<?
					if ($arrGPU['id'] != 'virtual') $multifunction = ""; else $multifunction =  " disabled ";
					?>
					<span id="GPUMulti<?=$i?>" name="gpu[<?=$i?>][multi]" class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced gpumultiline<?=$i?>"   >_(Multifunction)_:</span>

					<select id="GPUMultiSel<?=$i?>" name="gpu[<?=$i?>][multi]" class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced narrow gpumultiselect<?=$i?>" title="_(define Multifunctiion Support)_" <?=$multifunction?> >
					<?
						echo mk_option($arrGPU['guest']['multi'], 'off', 'Off');
						echo mk_option($arrGPU['guest']['multi'], 'on', 'On');
					?>
					</select>
				</td>
			</tr>

			<?if ($i == 0) {
				$hiddenport = $hiddenwsport = "hidden";
				if ($arrGPU['autoport'] == "no"){
				if ($arrGPU['protocol'] == "vnc") $hiddenport = $hiddenwsport = "";
				if ($arrGPU['protocol'] == "spice") $hiddenport = "";
				}
				?>
				<tr class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced protocol">
				<td>_(VM Console Protocol)_:</td>
				<td>
					<select id="protocol" name="gpu[<?=$i?>][protocol]" class="narrow" title="_(protocol for virtual console)_" onchange="ProtocolChange(this)" >
					<?mk_dropdown_options($arrValidProtocols, $arrGPU['protocol']);?>
					</select>
				</td>
				</tr>
				<tr  id="copypasteline" name="copypaste" class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced copypaste">
					<td>_(VM Console enable Copy/paste)_:</td>
				<td>
					<select id="copypaste" name="gpu[<?=$i?>][copypaste]" class="narrow" >
						<?
						echo mk_option($arrGPU['copypaste'], 'no', _('No'));
						echo mk_option($arrGPU['copypaste'], 'yes', _('Yes'));
						?>
					</select>
			</tr>
				<tr  id="autoportline" name="autoportline" class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced autoportline">
					<td>_(VM Console AutoPort)_:</td>
				<td>
					<select id="autoport" name="gpu[<?=$i?>][autoport]" class="narrow" onchange="AutoportChange(this)">
						<?
						echo mk_option($arrGPU['autoport'], 'yes', _('Yes'));
						echo mk_option($arrGPU['autoport'], 'no', _('No'));
						?>
					</select>

					<span id="Porttext"  <?=$hiddenport?>>_(VM Console Port)_:</span>

					<input type="number" size="5" maxlength="5"  id="port" class="narrow" style="width: 50px;" name="gpu[<?=$i?>][port]"   title="_(port for virtual console)_"  value="<?=$arrGPU['port']?>"  <?=$hiddenport?> >

					<span id="WSPorttext" <?=$hiddenwsport?>>_(VM Console WS Port)_:</span>

					<input type="number" size="5" maxlength="5" id="wsport" class="narrow" style="width: 50px;" name="gpu[<?=$i?>][wsport]"   title="_(wsport for virtual console)_"  value="<?=$arrGPU['wsport']?>" <?=$hiddenwsport?> >
				</td>
			</tr>

			<tr class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced vncmodel">
				<td>_(VM Console Video Driver)_:</td>
				<td>
					<select id="vncmodel" name="gpu[<?=$i?>][model]" class="narrow" title="_(video for VM Console)_">
					<?mk_dropdown_options($arrValidVNCModels, $arrGPU['model']);?>
					</select>
				</td>
			</tr>

			<tr class="vncpassword">
				<td>_(VM Console Password)_:</td>
				<td><input type="password" name="domain[password]" autocomplete='new-password' value="<?=$arrGPU['password']?>" title="_(password for VM Console)_" placeholder="_(password for VM Console)_ (_(optional)_)" /></td>
			</tr>
			<tr class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced vnckeymap">
				<td>_(VM Console Keyboard)_:</td>
				<td>
					<select name="gpu[<?=$i?>][keymap]" title="_(keyboard for VM Console)_">
					<?mk_dropdown_options($arrValidKeyMaps, $arrGPU['keymap']);?>
					</select>
				</td>
			</tr>
			<?}?>
			<tr class="<?if ($arrGPU['id'] == 'virtual') echo 'was';?>advanced romfile">
				<td>_(Graphics ROM BIOS)_:</td>
				<td>
					<input type="text" name="gpu[<?=$i?>][rom]" autocomplete="off" spellcheck="false" data-pickcloseonfile="true" data-pickfilter="rom,bin" data-pickmatch="^[^.].*" data-pickroot="/mnt/" value="<?=htmlspecialchars($arrGPU['rom'])?>" placeholder="_(Path to ROM BIOS file)_ (_(optional)_)" title="_(Path to ROM BIOS file)_ (_(optional)_)" />
				</td>
			</tr>
		</table>
		<?if ($i == 0 || $i == 1) {?>
		<blockquote class="inline_help">
			<p>
				<b>Graphics Card</b><br>
				If you wish to assign a graphics card to the VM, select it from this list, otherwise leave it set to virtual.
			</p>

			<p class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced protocol">
				<b>Virtual video protocol VNC/SPICE</b><br>
				If you wish to assign a protocol type, specify one here.
			</p>

			<p class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced protocol">
				<b>Virtual enable copy paste for VNC/SPICE</b><br>
				If you enable copy paste you need to install additional software on the client in addition to the QEMU agent if that has been installed. <a href="https://www.spice-space.org/download.html"  target="_blank">https://www.spice-space.org/download.html </a>is the location for spice-vdagent for both window and linux. Note copy paste function will not work with web spice viewer you need to use virt-viewer.
			</p>

			<p class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced protocol">
				<b>Virtual auto port</b><br>
				Set it you want to specify a manual port for VNC or Spice. VNC needs two ports where Spice only requires one. Leave as auto yes for the system to set.
			</p>

			<p class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced vncmodel">
				<b>Virtual Video Driver</b><br>
				If you wish to assign a different video driver to use for a VM Console connection, specify one here.
			</p>

			<p class="vncpassword">
				<b>Virtual Password</b><br>
				If you wish to require a password to connect to the VM over a VM Console connection, specify one here.
			</p>

			<p class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced vnckeymap">
				<b>Virtual Keyboard</b><br>
				If you wish to assign a different keyboard layout to use for a VM Console connection, specify one here.
			</p>

			<p class="<?if ($arrGPU['id'] == 'virtual') echo 'was';?>advanced romfile">
				<b>Graphics ROM BIOS</b><br>
				If you wish to use a custom ROM BIOS for a Graphics card, specify one here.
			</p>

			<p>Additional devices can be added/removed by clicking the symbols to the left.</p>
		</blockquote>
		<?}?>
	<?}?>
	<script type="text/html" id="tmplGraphics_Card">
		<table>
			<tr>
				<td>_(Graphics Card)_:</td>
				<td>
					<select name="gpu[{{INDEX}}][id]" class="gpu narrow">
					<?
						echo mk_option('', '', _('None'));

						foreach($arrValidGPUDevices as $arrDev) {
							echo mk_option('', $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
						}
					?>
					</select>
					<span id="GPUMulti" name="gpu[{{INDEX}}][multi]"  >_(Multifunction)_:</span>
					<select name="gpu[{{INDEX}}][multi]" class="narrow" title="_(define Multifunctiion Support)_" >
					<?
						echo mk_option("off", 'off', 'Off');
						echo mk_option("off", 'on', 'On');
					?>
					</select>
				</td>
			</tr>
			<tr class="advanced romfile">
				<td>_(Graphics ROM BIOS)_:</td>
				<td>
					<input type="text" name="gpu[{{INDEX}}][rom]" autocomplete="off" spellcheck="false" data-pickcloseonfile="true" data-pickfilter="rom,bin" data-pickmatch="^[^.].*" data-pickroot="/mnt/" value="" placeholder="_(Path to ROM BIOS file)_ (_(optional)_)" title="_(Path to ROM BIOS file)_ (_(optional)_)" />
				</td>
			</tr>
		</table>
	</script>

	<?foreach ($arrConfig['audio'] as $i => $arrAudio) {
		$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';

		?>
		<table data-category="Sound_Card" data-multiple="true" data-minimum="1" data-maximum="<?=count($arrValidAudioDevices)?>" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
			<tr>
				<td>_(Sound Card)_:</td>
				<td>
					<select name="audio[<?=$i?>][id]" class="audio narrow">
					<?
						echo mk_option($arrAudio['id'], '', _('None'));

						foreach($arrValidAudioDevices as $arrDev) {
							echo mk_option($arrAudio['id'], $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
						}
					?>
					</select>
				</td>
			</tr>
		</table>
		<?if ($i == 0) {?>
		<blockquote class="inline_help">
			<p>Select a sound device to assign to your VM.  Most modern GPUs have a built-in audio device, but you can also select the on-board audio device(s) if present.</p>
			<p>Additional devices can be added/removed by clicking the symbols to the left.</p>
		</blockquote>
		<?}?>
	<?}?>
	<script type="text/html" id="tmplSound_Card">
		<table>
			<tr>
				<td>_(Sound Card)_:</td>
				<td>
					<select name="audio[{{INDEX}}][id]" class="audio narrow">
					<?
						foreach($arrValidAudioDevices as $arrDev) {
							echo mk_option('', $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
						}
					?>
					</select>
				</td>
			</tr>
		</table>
	</script>

	<?	if ( $arrConfig['nic'] == false) {
	  		$arrConfig['nic']['0'] =
			[
				'network' => $domain_bridge,
				'mac' => "",
				'model' => 'virtio-net'
			];
		}
	  	foreach ($arrConfig['nic'] as $i => $arrNic) {
		$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';

		?>
		<table data-category="Network" data-multiple="true" data-minimum="1" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
			<tr class="advanced">
				<td>_(Network MAC)_:</td>
				<td>
					<input type="text" name="nic[<?=$i?>][mac]" class="narrow" value="<?=htmlspecialchars($arrNic['mac'])?>" title="_(random mac, you can supply your own)_" /> <i class="fa fa-refresh mac_generate" title="_(re-generate random mac address)_"></i>
				</td>
			</tr>
			<tr class="advanced">
				<td>_(Network Source)_:</td>
				<td>
					<select name="nic[<?=$i?>][network]">
					<?
					foreach (array_keys($arrValidNetworks) as $key) {

						echo mk_option("", $key, "- "._($key)." -", "disabled");

						foreach ($arrValidNetworks[$key] as $strNetwork) {
							echo mk_option($arrNic['network'], $strNetwork, $strNetwork);
						}
					}
					?>
					</select>
				</td>
			</tr>
			<tr class="advanced">
				<td>_(Network Model)_:</td>
				<td>
					<select name="nic[<?=$i?>][model]">
					<?
					echo mk_option($arrNic['model'], 'virtio-net', 'virtio-net');
					echo mk_option($arrNic['model'], 'virtio', 'virtio');
					echo mk_option($arrNic['model'], 'e1000', 'e1000');
					echo mk_option($arrNic['model'], 'rtl8139', 'rtl8139');
					echo mk_option($arrNic['model'], 'vmxnet3', 'vmxnet3');
					?>
					</select>
				</td>
			</tr>
			<tr class="advanced">
				<td>_(Boot Order)_:</td>
				<td>
				<input type="number" size="5" maxlength="5" id="nic[<?=$i?>][boot]" class="narrow bootorder" <?=$bootdisable?>  style="width: 50px;" name="nic[<?=$i?>][boot]"   title="_(Boot order)_"  value="<?=$arrNic['boot']?>" >
				</td>
			</tr>
		</table>
		<?if ($i == 0) {?>
		<div class="advanced">
			<blockquote class="inline_help">
				<p>
					<b>Network MAC</b><br>
					By default, a random MAC address will be assigned here that conforms to the standards for virtual network interface controllers.  You can manually adjust this if desired.
				</p>

				<p>
					<b>Network Source</b><br>
					The default libvirt managed network bridge (virbr0) will be used, otherwise you may specify an alternative name for a private network to the host.
				</p>

				<p>
					<b>Network Model</b><br>
					Default and recommended is 'virtio-net', which gives improved stability. To improve performance 'virtio' can be selected, but this may lead to stability issues.
				</p>

				<p>Use boot order to set device as bootable and boot sequence.</p>

				<p>Additional devices can be added/removed by clicking the symbols to the left.</p>
			</blockquote>
		</div>
		<?}?>
	<?}?>
	<script type="text/html" id="tmplNetwork">
		<table>
			<tr class="advanced">
				<td>_(Network MAC)_:</td>
				<td>
					<input type="text" name="nic[{{INDEX}}][mac]" class="narrow" value="" title="_(random mac, you can supply your own)_" /> <i class="fa fa-refresh mac_generate" title="_(re-generate random mac address)_"></i>
				</td>
			</tr>
			<tr class="advanced">
				<td>_(Network Source)_:</td>
				<td>
					<select name="nic[{{INDEX}}][network]">
					<?
					foreach (array_keys($arrValidNetworks) as $key) {
						echo mk_option("", $key, "- "._($key)." -", "disabled");
						foreach ($arrValidNetworks[$key] as $strNetwork) {
							echo mk_option($domain_bridge, $strNetwork, $strNetwork);
						}
					}
					?>
					</select>
				</td>
			</tr>
			<tr class="advanced">
				<td>_(Network Model)_:</td>
				<td>
					<select name="nic[{{INDEX}}][model]">
					<?
					echo mk_option(1, 'virtio-net', 'virtio-net');
					echo mk_option(1, 'virtio', 'virtio');
					echo mk_option($arrNic['model'], 'e1000', 'e1000');
					echo mk_option($arrNic['model'], 'vmxnet3', 'vmxnet3');
					?>
					</select>
				</td>
			</tr>
			<tr class="advanced">
				<td>_(Boot Order)_:</td>
				<td>
				<input type="number" size="5" maxlength="5" id="nic[{{INDEX}}][boot]" class="narrow bootorder" <?=$bootdisable?>  style="width: 50px;" name="nic[{{INDEX}}][boot]"   title="_(Boot order)_"  value="" >
				</td>
			</tr>
		</table>
	</script>

	<table>
		<tr><td></td>
		<td>_(Select)_&nbsp&nbsp_(Optional)_&nbsp&nbsp_(Boot Order)_</td></tr></div>
		<tr>
			<td>_(USB Devices)_:</td>
			<td>
				<div class="textarea" style="width: 850px">
				<?
					if (!empty($arrVMUSBs)) {
						foreach($arrVMUSBs as $i => $arrDev) {
						?>
						<label for="usb<?=$i?>">&nbsp&nbsp&nbsp&nbsp<input type="checkbox" name="usb[]" id="usb<?=$i?>" value="<?=htmlspecialchars($arrDev['id'])?>" <?if (count(array_filter($arrConfig['usb'], function($arr) use ($arrDev) { return ($arr['id'] == $arrDev['id']); }))) echo 'checked="checked"';?>
						/> &nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp <input type="checkbox" name="usbopt[<?=htmlspecialchars($arrDev['id'])?>]" id="usbopt<?=$i?>" value="<?=htmlspecialchars($arrDev['id'])?>" <?if ($arrDev["startupPolicy"] =="optional") echo 'checked="checked"';?>/>&nbsp&nbsp&nbsp&nbsp&nbsp
						<input type="number" size="5" maxlength="5" id="usbboot<?=$i?>" class="narrow bootorder" <?=$bootdisable?>  style="width: 50px;" name="usbboot[<?=htmlspecialchars($arrDev['id'])?>]"   title="_(Boot order)_"  value="<?=$arrDev['usbboot']?>" >
						<?=htmlspecialchars(substr($arrDev['name'],0,100))?> (<?=htmlspecialchars($arrDev['id'])?>)</label><br/>
						<?
						}
					} else {
						echo "<i>"._('None available')."</i>";
					}
				?>
				</div>
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>If you wish to assign any USB devices to your guest, you can select them from this list.</p>
		<p>Use boot order to set device as bootable and boot sequence.</p>
		<p>Select optional if you want device to be ignored when VM starts if not present.</p>
	</blockquote>

	<table>
	<tr><td></td>
		<td>_(Select)_&nbsp&nbsp_(Boot Order)_</td></tr></div>
		<tr>
		<tr>
			<td>_(Other PCI Devices)_:</td>
			<td>
				<div class="textarea" style="width: 850px">
				<?
					$intAvailableOtherPCIDevices = 0;

					if (!empty($arrValidOtherDevices)) {
						foreach($arrValidOtherDevices as $i => $arrDev) {
							$bootdisable = $extra = $pciboot = '';
							if ($arrDev["typeid"] != "0108" && substr($arrDev["typeid"],0,2) != "02") $bootdisable = ' disabled="disabled"';
							if (count($pcidevice=array_filter($arrConfig['pci'], function($arr) use ($arrDev) { return ($arr['id'] == $arrDev['id']); }))) {
								$extra .= ' checked="checked"';
								foreach ($pcidevice as $pcikey => $pcidev)  $pciboot = $pcidev["boot"];
							} elseif (!in_array($arrDev['driver'], ['pci-stub', 'vfio-pci'])) {
								//$extra .= ' disabled="disabled"';
								continue;
							}
							$intAvailableOtherPCIDevices++;
					?>
						<label for="pci<?=$i?>">&nbsp&nbsp&nbsp&nbsp<input type="checkbox" name="pci[]" id="pci<?=$i?>" value="<?=htmlspecialchars($arrDev['id'])?>" <?=$extra?>/> &nbsp
						<input type="number" size="5" maxlength="5" id="pciboot<?=$i?>" class="narrow pcibootorder" <?=$bootdisable?>  style="width: 50px;" name="pciboot[<?=htmlspecialchars($arrDev['id'])?>]"   title="_(Boot order)_"  value="<?=$pciboot?>" >
						<?=htmlspecialchars($arrDev['name'])?> | <?=htmlspecialchars($arrDev['type'])?> (<?=htmlspecialchars($arrDev['id'])?>)</label><br/>
					<?
						}
					}
					if (empty($intAvailableOtherPCIDevices)) {
						echo "<i>"._('None available')."</i>";
					}
				?>
				</div>
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>If you wish to assign any other PCI devices to your guest, you can select them from this list.</p>
		<p>Use boot order to set device as bootable and boot sequence. Only NVMe and Network devices (PCI types 0108 and 02xx) supported for boot order.</p>
	</blockquote>

	<table>
		<tr>
			<td></td>
			<td>
			<?if (!$boolNew) {?>
				<input type="hidden" name="updatevm" value="1" />
				<input type="button" value="_(Update)_" busyvalue="_(Updating)_..." readyvalue="_(Update)_" id="btnSubmit" />
			<?} else {?>
				<label for="domain_start"><input type="checkbox" name="domain[startnow]" id="domain_start" value="1" checked="checked"/> _(Start VM after creation)_</label>
				<br>
				<input type="hidden" name="createvm" value="1" />
				<input type="button" value="_(Create)_" busyvalue="_(Creating)_..." readyvalue="_(Create)_" id="btnSubmit" />
			<?}?>
				<input type="button" value="_(Cancel)_" id="btnCancel" />
			</td>
		</tr>
	</table>
	<?if ($boolNew) {?>
	<blockquote class="inline_help">
		<p>Click Create to generate the vDisks and return to the Virtual Machines page where your new VM will be created.</p>
	</blockquote>
	<?}?>

	<table>
	<tr>
	<td></td>	<td>_(Advanced tuning options)_</td></tr>
		<tr>
			<td>_(QEMU Command Line)_:</td>
			<?
				if ($arrConfig['qemucmdline'] == "") $qemurows = 2; else $qemurows = 15;
				?>
			<td>
			<textarea id="qemucmdline" name="qemucmdline" rows=<?=$qemurows?> style="width: 850px" onchange="QEMUChgCmd(this)"><?=htmlspecialchars($arrConfig['qemucmdline'])?> </textarea></td></tr>
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>If you need to add QEMU arguments to the XML</p>
		Examples can be found on the Libvirt page => <a href="https://libvirt.org/kbase/qemu-passthrough-security.html "  target="_blank">https://libvirt.org/kbase/qemu-passthrough-security.html </a>
		</p>
	</blockquote>

	<table class="timers">
		<tr><td></td><td>_(Clocks)_</td></tr>
		<tr><td>_(Clocks Offset)_:</td>
		<td>
			<?$clockdisabled = "";?>
			<select name="domain[clock]" <?=$clockdisabled?> id="clockoffset" class="narrow" title="_(Clock Offset)_" <?=$arrConfig["domain"]['clock']?>>
				<?
					echo mk_option($arrConfig['domain']['clock'], 'localtime', 'Localtime');
					echo mk_option($arrConfig['domain']['clock'], 'utc', "UTC");
				?>
				</select>
			</td>
		</tr>
					<?$clockcount = 0;
					if (!empty($arrClocks)) {
						foreach($arrClocks as $i => $arrTimer) {
							if ($i =="offset") continue;
							if ($clockcount == 0)  $clocksourcetext = _("Timer Source").":"; else $clocksourcetext = "";
					?>
		<tr><td><?=$clocksourcetext?></td>
		<td>
						<span class="narrow" style="width: 50px"><?=ucfirst($i)?>:</span></td>
						<td class="present">
						<span>_(Present)_:</span>
						<select name="clock[<?=$i?>][present]" <?=$clockdisabled?>  id="clock[<?=$i?>][present]" class="narrow" title="_(Clock Offset)_" <?=$arrTimer["present"]?>>
						<?
							echo mk_option($arrTimer["present"], 'yes', 'Yes');
							echo mk_option($arrTimer["present"], 'no', "No");
						?>
						</select></td>
						<td>
						<span class="narrow" style="width: 50px">_(Tickpolicy)_:</span>
						<select name="clock[<?=$i?>][tickpolicy]" <?=$clockdisabled?>  id="clock[<?=$i?>][tickpolicy]" class="narrow" title="_(Clock Offset)_" <?=$arrTimer["tickpolicy"]?>>
						<?
							echo mk_option($arrTimer["tickpolicy"], 'delay', 'Delay');
							echo mk_option($arrTimer["tickpolicy"], 'catchup', 'Catchup');
							echo mk_option($arrTimer["tickpolicy"], 'merge', "Merge");
							echo mk_option($arrTimer["tickpolicy"], 'discard', "Discard");
						?>
						</select>
						</td></tr>
						<?
							$clockcount++;
						}
					}
					?>
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>Allows setting of timers and offset into the XML You should not need to modify these values.</p>
		<p>Windows tuning details can be found here <a href="https://forums.unraid.net/topic/134041-guide-optimizing-windows-vms-in-unraid/"  target="_blank">https://forums.unraid.net/topic/134041-guide-optimizing-windows-vms-in-unraid/ </a> </p>
			<p>Details can be found on the Libvirt XML page => <a href="https://libvirt.org/formatdomain.html#time-keeping"  target="_blank">https://libvirt.org/formatdomain.html#time-keeping</a></p>
			<p>Defaults are:	</p>
			<p>linux Hpet:no Hypervclock: no Pit yes rtc yes tickpolicy catchup.	</p>
			<p> Windows Hpet:no Hypervclock: yes Pit yes rtc yes tickpolicy catchup.	</p>
			<p>Windows and Hyperv Hpet:no Hypervclock: yes Pit no rtc no.	</p>
		</p>
	</blockquote>
	<?
	if (!isset($arrConfig['evdev'])) $arrConfig['evdev'][0] = ['dev'=>"",'grab'=>"",'repeat'=>"",'grabToggle'=>""];
	foreach ($arrConfig['evdev'] as $i => $arrEvdev) {
		$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';
		?>
		<table data-category="evdev" data-multiple="true" data-minimum="1" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
			<tr>
				<td>_(Evdev Device)_:</td>
				<td>
					<select name="evdev[<?=$i?>][dev]" class="dev narrow">
					<?
						echo mk_option($arrEvdev['dev'], '', _('None'));
						foreach(getValidevDev() as $line) echo mk_option($arrEvdev['dev'], $line , $line);
					?>
					</select>
				</td>

		<tr class="advanced disk_file_options">
			<td>_(Grab)_:</td>
			<td>
				<select name="evdev[<?=$i?>][grab]" class="evdev_grab"  title="_(grab options)_">
				<?echo mk_option($arrEvdev['grab'], '', _('None'));
				foreach(["all"] as $line) echo mk_option($arrEvdev['grab'],$line,ucfirst($line));?>
				</select>
			</td>
		</tr>

		<tr class="advanced disk_file_options">
			<td>_(Repeat)_:</td>
			<td>
				<select name="evdev[<?=$i?>][repeat]" class="evdev_repeat narrow" title="_(grab options)_">
				<?echo mk_option($arrEvdev['repeat'], '', _('None'));
				foreach(["on","off"] as $line) echo mk_option($arrEvdev['repeat'],$line,ucfirst($line));?>
				</select>
			</td>
		</tr>

		<tr class="advanced disk_file_options">
			<td>_(Grab Toggle)_:</td>
			<td>
				<select name="evdev[<?=$i?>][grabToggle]" class="evdev_grabtoggle narrow" title="_(grab options)_">
				<?echo mk_option($arrEvdev['grabToggle'], '', _('None'));
				foreach(["ctrl-ctrl", "alt-alt", "shift-shift", "meta-meta", "scrolllock" , "ctrl-scrolllock"] as $line) echo mk_option($arrEvdev['grabToggle'],$line,$line);?>
				</select>
			</td>
		</tr>

		</table>
		<?if ($i == 0) {?>
		<div class="advanced">
			<blockquote class="inline_help">
				<p>
					<b> Event Devices</b><br>
					Evdev is an input interface built into the Linux kernel. QEMU’s evdev passthrough support allows a user to redirect evdev events to a guest. These events can include mouse movements and key presses. By hitting both Ctrl keys at the same time, QEMU can toggle the input recipient. QEMU’s evdev passthrough also features almost no latency, making it perfect for gaming. The main downside to evdev passthrough is the lack of button rebinding – and in some cases, macro keys won’t even work at all.
					Optional items are normally only used for keyboards.
				</p>
				<p>
					<b>Device</b><br>
					Host device to passthrough to guest.
				</p>

				<p>
					<b>Grab</b><br>
					All grabs all input devices instead of just one
				</p>

				<p>
					<b>Repeat</b><br>
					Repeat with value 'on'/'off' to enable/disable auto-repeat events
				</p>

				<p>
					<b>GrabToggle</b><br>
					GrabToggle with values ctrl-ctrl, alt-alt, shift-shift, meta-meta, scrolllock or ctrl-scrolllock to change the grab key combination</p>

				<p>Additional devices can be added/removed by clicking the symbols to the left.</p>
			</blockquote>
		</div>
		<?}?>
	<?}?>
	<script type="text/html" id="tmplevdev">
	<table data-category="evdev" data-multiple="true" data-minimum="1"  data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
			<tr>
				<td>_(Evdev Device)_:</td>
				<td>
					<select name="evdev[{{INDEX}}][dev]" class="dev narrow">
					<?
						echo mk_option("", '', _('None'));
						foreach(getValidevDev() as $line) echo mk_option("", $line , $line);
					?>
					</select>
				</td>

			<tr class="advanced disk_file_options">
				<td>_(Grab)_:</td>
				<td>
					<select name="evdev[{{INDEX}}][grab]" class="evdev_grab"  title="_(grab options)_">
					<?echo mk_option(""	, '', _('None'));
					foreach(["all"] as $line) echo mk_option("",$line,ucfirst($line));?>
					</select>
				</td>
			</tr>

			<tr class="advanced disk_file_options">
				<td>_(Repeat)_:</td>
				<td>
					<select name="evdev[{{INDEX}}][repeat]" class="evdev_repeat narrow" title="_(grab options)_">
					<?echo mk_option("", '', _('None'));
					foreach(["on","off"] as $line) echo mk_option("",$line,ucfirst($line));?>
					</select>
				</td>
			</tr>

			<tr class="advanced disk_file_options">
				<td>_(Grab Toggle)_:</td>
				<td>
					<select name="evdev[{{INDEX}}][grabToggle]" class="evdev_grabtoggle narrow" title="_(grab options)_">
					<?echo mk_option("", '', _('None'));
					foreach(["ctrl-ctrl", "alt-alt", "shift-shift", "meta-meta", "scrolllock" , "ctrl-scrolllock"] as $line) echo mk_option("",$line,$line);?>
					</select>
				</td>
			</tr>
		</table>
	</script>

	<table>
		<tr>
			<td></td>
			<td>
			<?if (!$boolNew) {?>
				<input type="hidden" name="updatevm" value="1" />
				<input type="button" value="_(Update)_" busyvalue="_(Updating)_..." readyvalue="_(Update)_" id="btnSubmit" />
			<?} else {?>
				<input type="hidden" name="createvm" value="1" />
				<input type="button" value="_(Create)_" busyvalue="_(Creating)_..." readyvalue="_(Create)_" id="btnSubmit" />
			<?}?>
				<input type="button" value="_(Cancel)_" id="btnCancel" />
				<input type="button" value=" _(Create/Modify Template)_" busyvalue="_(Creating)_..." readyvalue="_(Create)_" id="btnTemplateSubmit" />
			</td>
		</tr>
	</table>
	<?if ($boolNew) {?>
	<blockquote class="inline_help">
		<p>Click Create to generate the vDisks and return to the Virtual Machines page where your new VM will be created.</p>
	</blockquote>
	<?}?>
</div>

<div class="xmlview">
	<textarea id="addcode" name="xmldesc" placeholder="_(Copy &amp; Paste Domain XML Configuration Here)_." autofocus><?=htmlspecialchars($hdrXML).htmlspecialchars($strXML)?></textarea>

	<table>
		<tr>
			<td></td>
			<td>
			<?if (!$boolRunning) {?>
				<?if ($strXML) {?>
					<input type="hidden" name="updatevm" value="1" />
					<input type="button" value="_(Update)_" busyvalue="_(Updating)_..." readyvalue="_(Update)_" id="btnSubmit" />
				<?} else {?>
					<label for="xmldomain_start"><input type="checkbox" name="domain[xmlstartnow]" id="xmldomain_start" value="1" checked="checked"/> _(Start VM after creation)_</label>
					<br>
					<input type="hidden" name="createvm" value="1" />
					<input type="button" value="_(Create)_" busyvalue="_(Creating)_..." readyvalue="_(Create)_" id="btnSubmit" />
				<?}?>
				<input type="button" value="_(Cancel)_" id="btnCancel" />
				<input type="button" value=" _(Create/Modify Template)_" busyvalue="_(Creating)_..." readyvalue="_(Create)_" id="btnTemplateSubmit" />
			<?} else {?>
				<input type="button" value="_(Back)_" id="btnCancel" />
			<?}?>
			</td>
		</tr>
	</table>
</div>

<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/lib/codemirror.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/display/placeholder.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/fold/foldcode.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/show-hint.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/xml-hint.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/libvirt-schema.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/mode/xml/xml.js')?>"></script>
<script type="text/javascript">

function ShareChange(share) {
	var value = share.value;
	var text = share.options[share.selectedIndex].text;
	var strArray = text.split(":");
	var index = share.name.indexOf("]") + 1;
	var name = share.name.substr(0,index);
	if (strArray[0] === "User") {
		var path = "/mnt/user/" + strArray[1];
	} else {
		var path = "/mnt/" + strArray[1];
	}
	if (strArray[0] != "Manual") {
	document.getElementById(name+"[target]").value = strArray[1];
	document.getElementById(name+"[source]").value = path;
	document.getElementById(name+"[target]").setAttribute("disabled","disabled");
	document.getElementById(name+"[source]").setAttribute("disabled","disabled");
	} else {
		document.getElementById(name+"[target]").removeAttribute("disabled");
		document.getElementById(name+"[source]").removeAttribute("disabled");
	}
}

function BusChange(bus) {
	var value = bus.value;
	var index = bus.name.indexOf("]") + 1;
	var name = bus.name.substr(0,index);
	if (value == "virtio" || value == "usb" ) {
	document.getElementById(name+"[rotatetext]").style.visibility="hidden";
	document.getElementById(name+"[rotation]").style.visibility="hidden";
	} else {
		document.getElementById(name+"[rotation]").style.display="inline";
		document.getElementById(name+"[rotation]").style.visibility="visible";
		document.getElementById(name+"[rotatetext]").style.display="inline";
		document.getElementById(name+"[rotatetext]").style.visibility="visible";
	}
}

function updateSSDCheck(ssd) {
	var value = ssd.value;
	var index = ssd.name.indexOf("]") + 1;
	var name = ssd.name.substr(0,index);
	if (document.getElementById(name+"[rotation]").checked) ssd.value = "1"; else ssd.value = "0";
}

function BIOSChange(bios) {
	var value = bios.value;
	if (value == "0") {
		document.getElementById("USBBoottext").style.visibility="hidden";
		document.getElementById("domain_usbboot").style.visibility="hidden";
	} else {
		document.getElementById("USBBoottext").style.display="inline";
		document.getElementById("USBBoottext").style.visibility="visible";
		document.getElementById("domain_usbboot").style.display="inline";
		document.getElementById("domain_usbboot").style.visibility="visible";
	}
}

function QEMUChgCmd(qemu) {
	var value = qemu.value;
	if (value != "") {
		document.getElementById("qemucmdline").setAttribute("rows","15");
	} else {
		document.getElementById("qemucmdline").setAttribute("rows","2");
	}
}

function HypervChgNew(hyperv) {
	var value = hyperv.value;
	if (value == "0") {
		var clockdefault = "windows";
		document.getElementById("clock[rtc][present]").value = "<?=$arrDefaultClocks['windows']['rtc']['present']?>";
		document.getElementById("clock[pit][present]").value = "<?=$arrDefaultClocks['windows']['pit']['present']?>";
	} else {
		var clockdefault = "hyperv";
		document.getElementById("clock[rtc][present]").value = "<?=$arrDefaultClocks['hyperv']['rtc']['present']?>";
		document.getElementById("clock[pit][present]").value = "<?=$arrDefaultClocks['hyperv']['pit']['present']?>";
	}
}

function SetBootorderfields(usbbootvalue) {
	var bootelements = document.getElementsByClassName("bootorder");
	for(var i = 0; i < bootelements.length; i++) {
		if (usbbootvalue == "Yes") {
		bootelements[i].value = "";
		bootelements[i].setAttribute("disabled","disabled");
		} else bootelements[i].removeAttribute("disabled");
	}
	var bootelements = document.getElementsByClassName("pcibootorder");
	const bootpcidevs = <?
		$devlist = [];
		foreach($arrValidOtherDevices as $i => $arrDev) {
			if ($arrDev["typeid"] != "0108" && substr($arrDev["typeid"],0,2) != "02") $devlist[$arrDev['id']] = "N"; else $devlist[$arrDev['id']] = "Y";
		}
		echo json_encode($devlist);
	?>

	for(var i = 0; i < bootelements.length; i++) {
		let bootpciid = bootelements[i].name.split('[');
		bootpciid= bootpciid[1].replace(']', '');

		if (usbbootvalue == "Yes") {
		bootelements[i].value = "";
		bootelements[i].setAttribute("disabled","disabled");
		} else {
			// Put check for PCI Type 0108 & 02xx and only remove disable if 0108 or 02xx.
			if (bootpcidevs[bootpciid] === "Y") 	bootelements[i].removeAttribute("disabled");
		}
	}
}

function USBBootChange(usbboot) {
	// Remove all boot orders if changed to Yes
	var value = usbboot.value;
	SetBootorderfields(value);
}

function AutoportChange(autoport) {
	if (autoport.value == "yes") {
		document.getElementById("port").style.visibility="hidden";
		document.getElementById("Porttext").style.visibility="hidden";
		document.getElementById("wsport").style.visibility="hidden";
		document.getElementById("WSPorttext").style.visibility="hidden";
	} else {
		var protocol = document.getElementById("protocol").value;
		document.getElementById("port").style.display="inline";
		document.getElementById("port").style.visibility="visible";
		document.getElementById("Porttext").style.display="inline";
		document.getElementById("Porttext").style.visibility="visible";
		if (protocol == "vnc") {
			document.getElementById("wsport").style.display="inline";
			document.getElementById("wsport").style.visibility="visible";
			document.getElementById("WSPorttext").style.display="inline";
			document.getElementById("WSPorttext").style.visibility="visible";
		} else {
			document.getElementById("wsport").style.visibility="hidden";
			document.getElementById("WSPorttext").style.visibility="hidden";
		}
	}
}

function ProtocolChange(protocol) {
	var autoport = document.getElementById("autoport").value;
	if (autoport == "yes") {
		document.getElementById("port").style.visibility="hidden";
		document.getElementById("Porttext").style.visibility="hidden";
		document.getElementById("wsport").style.visibility="hidden";
		document.getElementById("WSPorttext").style.visibility="hidden";
	} else {
		document.getElementById("port").style.display="inline";
		document.getElementById("port").style.visibility="visible";
		document.getElementById("Porttext").style.display="inline";
		document.getElementById("Porttext").style.visibility="visible";
		if (protocol.value == "vnc") {
			document.getElementById("wsport").style.display="inline";
			document.getElementById("wsport").style.visibility="visible";
			document.getElementById("WSPorttext").style.display="inline";
			document.getElementById("WSPorttext").style.visibility="visible";
		} else {
			document.getElementById("wsport").style.visibility="hidden";
			document.getElementById("WSPorttext").style.visibility="hidden";
		}
	}
}

$(function() {
	function completeAfter(cm, pred) {
		var cur = cm.getCursor();
		if (!pred || pred()) setTimeout(function() {
			if (!cm.state.completionActive)
				cm.showHint({completeSingle: false});
		}, 100);
		return CodeMirror.Pass;
	}

	function completeIfAfterLt(cm) {
		return completeAfter(cm, function() {
			var cur = cm.getCursor();
			return cm.getRange(CodeMirror.Pos(cur.line, cur.ch - 1), cur) == "<";
		});
	}

	function completeIfInTag(cm) {
		return completeAfter(cm, function() {
			var tok = cm.getTokenAt(cm.getCursor());
			if (tok.type == "string" && (!/['"]/.test(tok.string.charAt(tok.string.length - 1)) || tok.string.length == 1)) return false;
			var inner = CodeMirror.innerMode(cm.getMode(), tok.state).state;
			return inner.tagName;
		});
	}

	var editor = CodeMirror.fromTextArea(document.getElementById("addcode"), {
		mode: "xml",
		lineNumbers: true,
		foldGutter: true,
		gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"],
		extraKeys: {
			"'<'": completeAfter,
			"'/'": completeIfAfterLt,
			"' '": completeIfInTag,
			"'='": completeIfInTag,
			"Ctrl-Space": "autocomplete"
		},
		hintOptions: {schemaInfo: getLibvirtSchema()}
	});

	SetBootorderfields("<?=$arrConfig['domain']['usbboot']?>");

	function resetForm() {
		$("#vmform .domain_vcpu").change(); // restore the cpu checkbox disabled states
		<?if (!empty($arrConfig['domain']['state'])):?>
		<?=$arrConfig['domain']['ovmf']==0 ? "$('#vmform #domain_ovmf').prop('disabled',true);\n" : "$('#vmform #domain_ovmf option[value=0]').prop('disabled',true);\n"?>
		<?endif?>
		<?if ($boolRunning):?>
		$("#vmform").find('input[type!="button"],select,.mac_generate').prop('disabled', true);
		$("#vmform").find('input[name^="usb"]').prop('disabled', false);
		<?endif?>
	}

	$('.advancedview').change(function () {
		if ($(this).is(':checked')) {
			setTimeout(function() {
				var xmlPanelHeight = window.outerHeight;
				if (xmlPanelHeight > 1024) xmlPanelHeight = xmlPanelHeight-550;
				editor.setSize(null,xmlPanelHeight);
				editor.refresh();
			}, 100);
		}
	});

	var regenerateDiskPreview = function (disk_index) {
		var domaindir = '<?=$domain_cfg['DOMAINDIR']?>' + $('#domain_oldname').val();
		var tl_args = arguments.length;

		$("#vmform .disk").closest('table').each(function (index) {
			var $table = $(this);

			if (tl_args && disk_index != $table.data('index')) {
				return;
			}

			var disk_select = $table.find(".disk_select option:selected").val();
			var $disk_file_sections = $table.find('.disk_file_options');
			var $disk_bus_sections = $table.find('.disk_bus_options');
			var $disk_input = $table.find('.disk');
			var $disk_preview = $table.find('.disk_preview');
			var $disk_serial = $table.find('.disk_serial');
			var $disk_driver = $table.find('.disk_driver').val();
			var $disk_ext = "img";
			if ($disk_driver == "raw") $disk_ext = "img";
				else if(disk_select != 'manual') $disk_ext = $disk_driver;

			if (disk_select == 'manual') {

				// Manual disk
				$disk_preview.fadeOut('fast', function() {
					$disk_input.fadeIn('fast');
				});

				$disk_bus_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
				slideDownRows($disk_bus_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));

				$.getJSON("/plugins/dynamix.vm.manager/include/VMajax.php?action=file-info&file=" + encodeURIComponent($disk_input.val()), function( info ) {
					if (info.isfile || info.isblock) {
						slideUpRows($disk_file_sections);
						$disk_file_sections.filter('.advanced').removeClass('advanced').addClass('wasadvanced');
						$disk_input.attr('name', $disk_input.attr('name').replace('new', 'image'));
					} else {
						$disk_file_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
						slideDownRows($disk_file_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));
						$disk_input.attr('name', $disk_input.attr('name').replace('image', 'new'));
					}
				});
			} else if (disk_select !== '') {
				// Auto disk
				var auto_disk_path = domaindir + '/vdisk' + (index+1) + '.' + $disk_ext;
				$disk_preview.html(auto_disk_path);
				$disk_input.fadeOut('fast', function() {
					$disk_preview.fadeIn('fast');
				});

				$disk_bus_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
				slideDownRows($disk_bus_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));

				$.getJSON("/plugins/dynamix.vm.manager/include/VMajax.php?action=file-info&file=" + encodeURIComponent(auto_disk_path), function( info ) {
					if (info.isfile || info.isblock) {
						slideUpRows($disk_file_sections);
						$disk_file_sections.filter('.advanced').removeClass('advanced').addClass('wasadvanced');

						$disk_input.attr('name', $disk_input.attr('name').replace('new', 'image'));
					} else {
						$disk_file_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
						slideDownRows($disk_file_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));

						$disk_input.attr('name', $disk_input.attr('name').replace('image', 'new'));
					}
				});
			} else {
				// No disk
				var $hide_el = $table.find('.disk_bus_options,.disk_file_options,.disk_preview,.disk');
				$disk_preview.html('');
				slideUpRows($hide_el);
				$hide_el.filter('.advanced').removeClass('advanced').addClass('wasadvanced');
			}
		});
	};

	var setDiskserial = function (disk_index) {
		var domaindir = '<?=$domain_cfg['DOMAINDIR']?>' + $('#domain_oldname').val();
		var tl_args = arguments.length;

		$("#vmform .disk").closest('table').each(function (index) {
			var $table = $(this);

			if (tl_args && disk_index != $table.data('index')) {
				return;
			}

			var disk_select = $table.find(".disk_select option:selected").val();
			var $disk_serial = $table.find('.disk_serial');

			 if (disk_select !== '') {

				// Auto disk serial
				var auto_serial = 'vdisk' + (index+1);
				$disk_serial.val(auto_serial);
			}
		});
	};

	<?if ($boolNew):?>
	$("#vmform #domain_name").on("input change", function changeNameEvent() {
		$('#vmform #domain_oldname').val($(this).val());
		regenerateDiskPreview();
	});
	<?endif?>

	$("#vmform .domain_vcpu").change(function changeVCPUEvent() {
		var $cores = $("#vmform .domain_vcpu:checked");

		if ($cores.length == 1) {
			$cores.prop("disabled", true);
		} else {
			$("#vmform .domain_vcpu").prop("disabled", false);
		}
	});

	$("#vmform #domain_mem").change(function changeMemEvent() {
		$("#vmform #domain_maxmem").val($(this).val());
	});

	$("#vmform #domain_maxmem").change(function changeMaxMemEvent() {
		if (parseFloat($(this).val()) < parseFloat($("#vmform #domain_mem").val())) {
			$("#vmform #domain_mem").val($(this).val());
		}
	});

	$("#vmform #domain_machine").change(function changeMachineEvent() {
		// Cdrom Bus: select IDE for i440 and SATA for q35
		if ($(this).val().indexOf('i440fx') != -1) {
			$('#vmform .cdrom_bus').val('ide');
		} else {
			$('#vmform .cdrom_bus').val('sata');
		}
	});

	$("#vmform #domain_ovmf").change(function changeBIOSEvent() {
		// using OVMF - disable vmvga vnc option
		if ($(this).val() != '0' && $("#vmform #vncmodel").val() == 'vmvga') {
			$("#vmform #vncmodel").val('qxl');
		}
		$("#vmform #vncmodel option[value='vmvga']").prop('disabled', ($(this).val() != '0'));
	}).change(); // fire event now

	$("#vmform").on("spawn_section", function spawnSectionEvent(evt, section, sectiondata) {
		if (sectiondata.category == 'vDisk') {
			regenerateDiskPreview(sectiondata.index);
			setDiskserial(sectiondata.index);
		}
		if (sectiondata.category == 'Graphics_Card') {
			$(section).find(".gpu").change();
		}
	});

	$("#vmform").on("destroy_section", function destroySectionEvent(evt, section, sectiondata) {
		if (sectiondata.category == 'vDisk') {
			regenerateDiskPreview();
		}
	});

	$("#vmform").on("input change", ".cdrom", function changeCdromEvent() {
		if ($(this).val() == '') {
			slideUpRows($(this).closest('table').find('.cdrom_bus').closest('tr'));
		} else {
			slideDownRows($(this).closest('table').find('.cdrom_bus').closest('tr'));
		}
	});

	$("#vmform").on("change", ".disk_select", function changeDiskSelectEvent() {
		regenerateDiskPreview($(this).closest('table').data('index'));
	});

	$("#vmform").on("change", ".disk_driver", function changeDiskSelectEvent() {
		regenerateDiskPreview($(this).closest('table').data('index'));
	});

	$("#vmform").on("input change", ".disk", function changeDiskEvent() {
		var $input = $(this);
		var config = $input.data();

		if (config.hasOwnProperty('pickfilter')) {
			regenerateDiskPreview($input.closest('table').data('index'));
		}
	});

	$("#vmform").on("change", ".cpu", function changeCPUEvent() {
		var myvalue = $(this).val();
		var mylabel = $(this).children('option:selected').text();
		var cpumigrate = document.getElementById("domain_cpumigrate_text");
		var cpumigrate_text = document.getElementById("domain_cpumigrate");
		if (myvalue == "custom") {
			document.getElementById("domain_cpumigrate_text").style.visibility="hidden";
			document.getElementById("domain_cpumigrate").style.visibility="hidden";
		} else {
			document.getElementById("domain_cpumigrate_text").style.display="inline";
			document.getElementById("domain_cpumigrate_text").style.visibility="visible";
			document.getElementById("domain_cpumigrate").style.display="inline";
			document.getElementById("domain_cpumigrate").style.visibility="visible";
		}
	});

	$("#vmform").on("change", ".gpu", function changeGPUEvent() {
		var myvalue = $(this).val();
		var mylabel = $(this).children('option:selected').text();
		var myindex = $(this).closest('table').data('index');

		if (myindex == 0) {
			$vnc_sections = $('.autoportline,.protocol,.vncmodel,.vncpassword,.vnckeymap,.copypaste');
			if (myvalue == 'virtual') {
				$vnc_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
				slideDownRows($vnc_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));
				var MultiSel = document.getElementById("GPUMultiSel0");
				MultiSel.disabled = true;
			} else {
				slideUpRows($vnc_sections);
				$vnc_sections.filter('.advanced').removeClass('advanced').addClass('wasadvanced');
				var MultiSel = document.getElementById("GPUMultiSel0");
				MultiSel.disabled = false;
			}
		}

		$romfile = $(this).closest('table').find('.romfile');
		if (myvalue == 'virtual' || myvalue == '') {
			slideUpRows($romfile.not(isVMAdvancedMode() ? '.basic' : '.advanced'));
			$romfile.filter('.advanced').removeClass('advanced').addClass('wasadvanced');
		} else {
			$romfile.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
			slideDownRows($romfile.not(isVMAdvancedMode() ? '.basic' : '.advanced'));

			$("#vmform .gpu").not(this).each(function () {
				if (myvalue == $(this).val()) {
					$(this).prop("selectedIndex", 0).change();
				}
			});
		}
	});

	$("#vmform").on("click", ".mac_generate", function generateMac() {
		var $input = $(this).prev('input');

		$.getJSON("/plugins/dynamix.vm.manager/include/VMajax.php?action=generate-mac", function( data ) {
			if (data.mac) {
				$input.val(data.mac);
			}
		});
	});

	$("#vmform .formview #btnSubmit").click(function frmSubmit() {
		var $button = $(this);
		var $panel = $('.formview');
		var form = $button.closest('form');

		$("#vmform .disk_select option:selected").not("[value='manual']").closest('table').each(function () {
			var v = $(this).find('.disk_preview').html();
			$(this).find('.disk').val(v);
		});

		$panel.find('input').prop('disabled', false); // enable all inputs otherwise they wont post

		<?if (!$boolNew):?>
		// signal devices to be added or removed
		form.find('input[name="usb[]"],input[name="pci[]"],input[name="usbopt[]"]').each(function(){
			if (!$(this).prop('checked')) $(this).prop('checked',true).val($(this).val()+'#remove');
		});
		// remove unused graphic cards
		var gpus = [], i = 0;
		do {
			var gpu = form.find('select[name="gpu['+(i++)+'][id]"] option:selected').val();
			if (gpu) gpus.push(gpu);
		} while (gpu);
		form.find('select[name="gpu[0][id]"] option').each(function(){
			var gpu = $(this).val();
			if (gpu != 'virtual' && !gpus.includes(gpu)) form.append('<input type="hidden" name="pci[]" value="'+gpu+'#remove">');
		});
		// remove unused sound cards
		var sound = [], i = 0;
		do {
			var audio = form.find('select[name="audio['+(i++)+'][id]"] option:selected').val();
			if (audio) sound.push(audio);
		} while (audio);
		form.find('select[name="audio[0][id]"] option').each(function(){
			var audio = $(this).val();
			if (audio && !sound.includes(audio)) form.append('<input type="hidden" name="pci[]" value="'+audio+'#remove">');
		});
		<?endif?>
		var postdata = form.find('input,select,textarea[name="qemucmdline"]').serialize().replace(/'/g,"%27");
		<?if (!$boolNew):?>
		// keep checkbox visually unchecked
		form.find('input[name="usb[]"],input[name="usbopt[]"],input[name="pci[]"]').each(function(){
			if ($(this).val().indexOf('#remove')>0) $(this).prop('checked',false);
		});
		<?endif?>

		$panel.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));

		$.post("/plugins/dynamix.vm.manager/templates/Custom.form.php", postdata, function( data ) {
			if (data.success) {
				if (data.vmrcurl) {
					var vmrc_window=window.open(data.vmrcurl, '_blank', 'scrollbars=yes,resizable=yes');
					try {
						vmrc_window.focus();
					} catch (e) {
						swal({title:"_(Browser error)_",text:"_(Pop-up Blocker is enabled! Please add this site to your exception list)_",type:"warning",confirmButtonText:"_(Ok)_"},function(){ done() });
						return;
					}
				}
				done();
			}
			if (data.error) {
				swal({title:"_(VM creation error)_",text:data.error,type:"error",confirmButtonText:"_(Ok)_"});
				$panel.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
				resetForm();
			}
		}, "json");
	});

	$("#vmform .formview #btnTemplateSubmit").click(function frmSubmit() {
		var $button = $(this);
		var $panel = $('.formview');
		var form = $button.closest('form');
		form.append('<input type="hidden" name="createvmtemplate" value="1" />');
		var createVmInput = form.find('input[name="createvm"],input[name="updatevm"]');
		createVmInput.remove();

		$("#vmform .disk_select option:selected").not("[value='manual']").closest('table').each(function () {
			var v = $(this).find('.disk_preview').html();
			$(this).find('.disk').val(v);
		});

		$panel.find('input').prop('disabled', false); // enable all inputs otherwise they wont post

		<?if (!$boolNew):?>
		// signal devices to be added or removed
		form.find('input[name="usb[]"],input[name="pci[]"],input[name="usbopt[]"]').each(function(){
			if (!$(this).prop('checked')) $(this).prop('checked',true).val($(this).val()+'#remove');
		});
		// remove unused graphic cards
		var gpus = [], i = 0;
		do {
			var gpu = form.find('select[name="gpu['+(i++)+'][id]"] option:selected').val();
			if (gpu) gpus.push(gpu);
		} while (gpu);
		form.find('select[name="gpu[0][id]"] option').each(function(){
			var gpu = $(this).val();
			if (gpu != 'virtual' && !gpus.includes(gpu)) form.append('<input type="hidden" name="pci[]" value="'+gpu+'#remove">');
		});
		// remove unused sound cards
		var sound = [], i = 0;
		do {
			var audio = form.find('select[name="audio['+(i++)+'][id]"] option:selected').val();
			if (audio) sound.push(audio);
		} while (audio);
		form.find('select[name="audio[0][id]"] option').each(function(){
			var audio = $(this).val();
			if (audio && !sound.includes(audio)) form.append('<input type="hidden" name="pci[]" value="'+audio+'#remove">');
		});
		<?endif?>
		var postdata = form.find('input,select,textarea[name="qemucmdline"]').serialize().replace(/'/g,"%27");
		<?if (!$boolNew):?>
		// keep checkbox visually unchecked
		form.find('input[name="usb[]"],input[name="usbopt[]"],input[name="pci[]"]').each(function(){
			if ($(this).val().indexOf('#remove')>0) $(this).prop('checked',false);
		});
		<?endif?>

		$panel.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));

		swal({
			title: "_(Template Name)_",
			text: "_(Enter name)_:\n_(If name already exists it will be replaced)_.",
			type: "input",
			showCancelButton: true,
			closeOnConfirm: false,
			//animation: "slide-from-top",
			inputPlaceholder: "_(Leaving blank will use OS name)_."
		},
		function(inputValue){
			postdata=postdata+"&templatename="+inputValue;
			$.post("/plugins/dynamix.vm.manager/templates/Custom.form.php", postdata, function( data ) {
			if (data.success) {
				if (data.vmrcurl) {
					var vmrc_window=window.open(data.vmrcurl, '_blank', 'scrollbars=yes,resizable=yes');
					try {
						vmrc_window.focus();
					} catch (e) {
						swal({title:"_(Browser error)_",text:"_(Pop-up Blocker is enabled! Please add this site to your exception list)_",type:"warning",confirmButtonText:"_(Ok)_"},function(){ done() });
						return;
					}
				}
				done();
			}
			if (data.error) {
				swal({title:"_(VM creation error)_",text:data.error,type:"error",confirmButtonText:"_(Ok)_"});
				$panel.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
				resetForm();
			}
		}, "json");
	});
});

	$("#vmform .xmlview #btnSubmit").click(function frmSubmit() {
		var $button = $(this);
		var $panel = $('.xmlview');

		editor.save();

		$panel.find('input').prop('disabled', false); // enable all inputs otherwise they wont post

		var postdata = $panel.closest('form').serialize().replace(/'/g,"%27");

		$panel.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));

		$.post("/plugins/dynamix.vm.manager/templates/Custom.form.php", postdata, function( data ) {
			if (data.success) {
				done();
			}
			if (data.error) {
				swal({title:"_(VM creation error)_",text:data.error,type:"error",confirmButtonText:"_(Ok)_"});
				$panel.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
				resetForm();
			}
		}, "json");
	});

	$("#vmform .xmlview #btnTemplateSubmit").click(function frmSubmit() {
		var $button = $(this);
		var $panel = $('.xmlview');


		editor.save();

		$panel.find('input').prop('disabled', false); // enable all inputs otherwise they wont post
		var form = $button.closest('form');
		form.append('<input type="hidden" name="createvmtemplate" value="1" />');
		var createVmInput = form.find('input[name="createvm"],input[name="updatevm"]');
		createVmInput.remove();

		var postdata = $panel.closest('form').serialize().replace(/'/g,"%27");

		$panel.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));

		swal({
			title: "_(Template Name)_",
			text: "_(Enter name)_:\n_(If name already exists it will be replaced)_.",
			type: "input",
			showCancelButton: true,
			closeOnConfirm: false,
			//animation: "slide-from-top",
			inputPlaceholder: "_(Leaving blank will use OS name)_."
			},
			function(inputValue){

			postdata=postdata+"&templatename="+inputValue;

		$.post("/plugins/dynamix.vm.manager/templates/Custom.form.php", postdata, function( data ) {
			if (data.success) {
				done();
			}
			if (data.error) {
				swal({title:"_(VM creation error)_",text:data.error,type:"error",confirmButtonText:"_(Ok)_"});
				$panel.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
				resetForm();
			}
		}, "json");
	});
	});

	// Fire events below once upon showing page
	var os = $("#vmform #template_os").val() || 'linux';
	var os_casted = (os.indexOf('windows') == -1 ? 'other' : 'windows');

	$('#vmform .domain_os').not($('.' + os_casted)).hide();
	$('#vmform .domain_os.' + os_casted).not(isVMAdvancedMode() ? '.basic' : '.advanced').show();

	<?if ($boolNew):?>
	if (os_casted == 'windows') {
		$('#vmform #domain_clock').val('localtime');
		$("#vmform #domain_machine option").each(function(){
			if ($(this).val().indexOf('i440fx') != -1) {
				var usertemplate = <?=$usertemplate?>;
				if (usertemplate = 0) $('#vmform #domain_machine').val($(this).val()).change();
				return false;
			}
		});
	} else {
		$('#vmform #domain_clock').val('utc');
		$('#vmform #clockoffset').val('utc');
		$("#vmform #domain_machine option").each(function(){
			if ($(this).val().indexOf('q35') != -1) {
				var usertemplate = <?=$usertemplate?>;
				if (usertemplate = 0) $('#vmform #domain_machine').val($(this).val()).change();
				return false;
			}
		});
	}
	<?endif?>

	// disable usb3 option for windows7 / xp / server 2003 / server 2008
	var noUSB3 = (os == 'windows7' || os == 'windows2008' || os == 'windowsxp' || os == 'windows2003');
	if (noUSB3 && ($("#vmform #usbmode").val().indexOf('usb3')===0)) {
		$("#vmform #usbmode").val('usb2');
	}
	$("#vmform #usbmode option[value^='usb3']").prop('disabled', noUSB3);

	$("#vmform .gpu").change();

	$('#vmform .cdrom').change();

	regenerateDiskPreview();

	resetForm();
});
</script>
