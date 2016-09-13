<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_macro';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.30';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'http://stefdawson.com/';
$plugin['description'] = 'Define custom macros/virtual Textpattern tags for your site';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '1';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '2';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@smd_macro
smd_macro_attributes => Attributes
smd_macro_att_clone => [+]
smd_macro_att_clone_help => Add an attribute
smd_macro_cannot_import => Unable to import macro. Please check folder permissions/settings on your host
smd_macro_choose => Select macro to edit
smd_macro_clone => Clone
smd_macro_created => Macro created
smd_macro_deleted => Macro deleted
smd_macro_exists => Macro name already exists or clashes
smd_macro_export => Export
smd_macro_file_size => Macro file too large
smd_macro_import => Import
smd_macro_imported => Imported: 
smd_macro_invalid => Macro name not valid
smd_macro_invalid_ini => Macro file not in expected format
smd_macro_not_imported => Not imported: 
smd_macro_overwrite => Force overwrite
smd_macro_repname => Replacement name
smd_macro_saved => Macro saved
smd_macro_skipped => Skipped: 
smd_macro_tab_name => Macros
smd_macro_tag_definition => Macro definition
smd_macro_tbl_installed => Table installed
smd_macro_tbl_not_installed => Table not installed
smd_macro_tbl_not_removed => Table not removed
smd_macro_tbl_removed => Table removed
smd_macro_upload => Macro file
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_macro
 *
 * A Textpattern CMS plugin for creating new tags:
 *  -> Define new <txp:tags> to do any task you like -- lightbox effect, comment scheme...
 *  -> Add arbitrary numbers of attributes and set defaults if you wish
 *  -> Import / export your creations to share new tags with the community
 *
 * @author Stef Dawson
 * @link   http://stefdawson.com/
 * @todo   Allow apostrophes / tags-in-tags in container code
 */

if(@txpinterface == 'admin') {
	global $smd_macro_event;
	$smd_macro_event = 'smd_macro';

	add_privs($smd_macro_event,'1,2');
	register_tab('content', $smd_macro_event, gTxt('smd_macro_tab_name'));
	register_callback('smd_macro_dispatcher', 'smd_macro');
	register_callback('smd_macro_upload_form', $smd_macro_event.'_ui', 'upload_form');
	register_callback('smd_macro_welcome', 'plugin_lifecycle.smd_macro');
}

if (!defined('SMD_MACRO')) {
	define("SMD_MACRO", 'smd_macro');
}

// Bootstrap the function insertion
register_callback('smd_macro_boot', 'pretext');

// ********************
// ADMIN SIDE INTERFACE
// ********************

// ------------------------
function smd_macro_dispatcher($evt, $stp) {
	global $smd_macro_event;

	$available_steps = array(
		'smd_macro_table_install' => true,
		'smd_macro_table_remove'  => true,
		'smd_macro_prefsave'      => true,
		'smd_macro_save'          => true,
		'smd_macro_delete'        => true,
	);

	if (!$stp or !bouncer($stp, $available_steps)) {
		$stp = $smd_macro_event;
	}
	$stp();
}

// ------------------------
function smd_macro_welcome($evt, $stp) {
	$msg = '';
	switch ($stp) {
		case 'installed':
			smd_macro_table_install(0);
			$msg = 'Go go gadget macro!';
			break;
		case 'deleted':
			smd_macro_table_remove(0);
			break;
	}
	return $msg;
}


// ------------------------
function smd_macro($msg='') {
	global $smd_macro_event;

	if (!smd_macro_table_exist(1)) {
		smd_macro_table_install(0);
	}

	$export_list = gps('smd_macro_export');
	$max_file_size = get_pref('smd_macro_import_filesize', 15 * 1024);

	if (gps('step') == 'smd_macro_export' && $export_list) {
		// Generate an associative array that is translated to an ini file
		$out = array();
		$rows = safe_rows('*', SMD_MACRO, "macro_name IN ('".join("','", doSlash($export_list))."')");
		foreach ($rows as $row) {
			$atts = unserialize($row['attributes']);
			$outatts = array();
			foreach ($atts as $att => $vals) {
				$outatts[] = utf8_decode(join('|', array($att, $vals['default'], $vals['rep'])));
			}
			$out[$row['macro_name']] = array(
				'description' => $row['description'],
				'attributes' => $outatts,
				'definition' => $row['definition'],
			);
		}

		$content = smd_macro_create_ini($out, array('definition' => 'base64_encode'));
		header("Content-Type: text/plain; charset=utf-8");
		header("Content-Disposition: attachment; filename=\"smd_macros.ini\"");
		header("Content-Length: " . mb_strlen($content));
		header("Content-Transfer-Encoding: binary");
		header("Cache-Control: no-cache, must-revalidate, max-age=60");
		header("Expires: Sat, 01 Jan 2000 12:00:00 GMT");
		print($content);
		exit;

	} else if (gps('step') == 'smd_macro_import') {
		$overwrite = gps('smd_macro_import_overwrite');

		$file = @get_uploaded_file($_FILES['thefile']['tmp_name'], get_pref('tempdir').DS.basename($_FILES['thefile']['tmp_name']));
		if ($file === false) {
			$msg = array(gTxt('smd_macro_cannot_import'), E_WARNING);
		} else {
			$size = filesize($file);
			if ($max_file_size < $size) {
				$msg = array(gTxt('smd_macro_file_size'), E_WARNING);
			} else {
				$ini = @parse_ini_file($file, true);
				if ($ini) {
					$done = array('ok' => array(), 'nok' => array(), 'skip' => array());
					foreach ($ini as $key => $val) {
						$atts = array();
						$def = $desc = '';
						if (isset($val['attributes'])) {
							foreach ($val['attributes'] as $attlist) {
								list($nam, $dflt, $rep) = explode('|', utf8_encode($attlist));
								$atts[strtolower(str_replace('-', '_', sanitizeForUrl($nam)))] = array(
									'default' => $dflt,
									'rep' => $rep
								);
							}
						}
						if (isset($val['description'])) {
							$desc = $val['description'];
						}
						if (isset($val['definition'])) {
							$def = base64_decode($val['definition']);
						}

						$result = (string)smd_macro_save_direct($key, $desc, $atts, $def, $overwrite);
						switch ($result) {
							case 'SMD_MACRO_BAD_NAME':
							case 'SMD_MACRO_CLASH':
								$done['nok'][] = $key;
								break;
							case 'SMD_MACRO_SKIP':
								$done['skip'][] = $key;
								break;
							default:
								$done['ok'][] = $key;
								break;
						}
					}

					$msg = (($done['ok']) ? gTxt('smd_macro_imported') . join(', ', $done['ok']).br : '')
						. (($done['skip']) ? gTxt('smd_macro_skipped') . join(', ', $done['skip']).br : '')
						. (($done['nok']) ? gTxt('smd_macro_not_imported') . join(', ', $done['nok']) : '');
				} else {
					$msg = array(gTxt('smd_macro_invalid_ini'), E_WARNING);
				}
			}
			unlink($file);
		}
	} else if (gps('step') == 'smd_macro_clone') {
		$source = safe_row('*', SMD_MACRO, "macro_name='".doSlash(gps('smd_macro_name'))."'");
		$name = gps('smd_macro_new_name');
		$desc = $source['description'];
		$def = $source['definition'];
		$atts = $source['attributes'];

		$result = (string)smd_macro_save_direct($name, $desc, $atts, $def, false);
		switch($result) {
			case 'SMD_MACRO_BAD_NAME':
				$msg = array(gTxt('smd_macro_invalid'), E_WARNING);
				break;
			case 'SMD_MACRO_CLASH':
			case 'SMD_MACRO_SKIP':
				$msg = array(gTxt('smd_macro_exists'), E_WARNING);
				break;
			default:
				$msg = gTxt('smd_macro_created');
				$_POST['smd_macro_name'] = $name; // Inject the new macro into the post stream so it's selected by default
				break;
		}
	}

	pagetop(gTxt('smd_macro_tab_name'), $msg);

	$macros = safe_rows('*', SMD_MACRO, '1=1 ORDER BY macro_name');
	$macro_name = $macro_new_name = gps('smd_macro_name');
	$macro_description = $macro_def = $macro_code = '';
	$macro_atts = array();

	// Build the select list if possible
	$macrolist = array();
	foreach ($macros as $idx => $macro) {
		$macrolist[$macro['macro_name']] = $macro['macro_name'];
		if ($macro['macro_name'] == $macro_name) {
			$macro_new_name = $macro['macro_name'];
			$macro_description = $macro['description'];
			$macro_atts = $macro['attributes'] ? unserialize($macro['attributes']) : array();
			$macro_def = $macro['definition'];
		}
	}
	$macroSelector = ($macrolist) ? selectInput('smd_macro_name', $macrolist, $macro_name, true, 1) : '';
	$multi_macsel = '';
	if ($macrolist) {
		$multi_macsel = '<select name="smd_macro_export[]" id="smd_macro_export_list" size="8" class="list" multiple="multiple">'.n;
		foreach ($macrolist as $key => $val) {
			$multi_macsel .= '<option value="'.$key.'"' . (($macro_name==$key) ? ' selected="selected"' : '') .'>'.$val.'</option>'.n;
		}
		$multi_macsel .= '</select>';
	}

	$editcell = ($macrolist) ? '<label>'.gTxt('smd_macro_choose').'</label>'.n.$macroSelector : '';

	// Edit form
	echo n, '<h1 class="txp-heading">', gTxt('smd_macro_tab_name'), '</h1>',
		n, '<div id="smd_macro_control" class="txp-control-panel">',
		n, '<form id="smd_macro_select" action="index.php" method="post">',
		n, '<p id="smd_macro_select">',
		n, $editcell,
		n, eInput($smd_macro_event),
		n, '</form>',

		// Import / export form
		n, '<a id="smd_macro_import" href="#">'.gTxt('smd_macro_import').'</a>',
		n, (($multi_macsel)
			? ' / ' . '<a id="smd_macro_export" href="#">'.gTxt('smd_macro_export').'</a>'
				. (($macro_name) ? ' / ' . '<a id="smd_macro_clone" href="#">'.gTxt('smd_macro_clone').'</a>' : '')
			: ''),
		n, '<div id="smd_macro_import_holder" class="smd_hidden">',
		n, upload_form(gTxt('smd_macro_upload'), '', 'smd_macro_import', $smd_macro_event, ''),
		n, '</div>',
		n, (($multi_macsel)
				? '<div id="smd_macro_export_holder" class="smd_hidden"><form id="smd_macro_export_form" action="index.php" method="post">'
					. $multi_macsel . br
					. fInput('submit', 'smd_macro_export_go', gTxt('go'), '', '', 'smd_macro_export_close();')
					. eInput($smd_macro_event)
					. sInput('smd_macro_export')
					. '</form></div>'
					. '<div id="smd_macro_clone_holder" class="smd_hidden"><form id="smd_macro_clone_form" action="index.php" method="post">'
					. hInput('smd_macro_name', $macro_name)
					. fInput('text', 'smd_macro_new_name', '')
					. fInput('submit', 'smd_macro_clone_go', gTxt('go'))
					. eInput($smd_macro_event)
					. sInput('smd_macro_clone')
					. tInput()
					. '</form></div>'
				: ''
			),
		n, '</div>';

	echo n, '<div class="txp-container">',
		n, '<form id="smd_macro_form" action="index.php" method="post">',
		n, startTable(),
		n, tr(
			fLabelCell('name', '', 'smd_macro_new_name')
			.n.td(
				fInput('text', 'smd_macro_new_name', $macro_new_name).fInput('hidden', 'smd_macro_name', $macro_name)
				.(($macro_name == '') ? '' : sp.'<a href="?event='.$smd_macro_event. a.'step=smd_macro_delete' . a . 'smd_macro_name='.urlencode($macro_name). a . '_txp_token='.form_token() . '" onclick="return confirm(\''.gTxt('confirm_delete_popup').'\');">[x]</a>')
			)
		),
		n, tr(
			fLabelCell('description', '', 'smd_macro_description')
			.n.td(
				fInput('text', 'smd_macro_description', $macro_description, '', '', '', '66')
			)
		);

	$attlist = array('<table id="smd_macro_attlist"><thead><tr><th>'.gTxt('name').'</th><th>'.gTxt('default').'</th><th>'.gTxt('smd_macro_repname').'</th></tr></thead><tbody>');
	$macro_atts = empty($macro_atts) ? array('' => array('default' => '', 'rep' => '')) : $macro_atts;

	foreach ($macro_atts as $attname => $att) {
		$attlist[] = '<tr class="smd_macro_att">'
			.'<td>'.fInput('text', 'smd_macro_attname[]', $attname). '</td>'
			.'<td>'.fInput('text', 'smd_macro_attdflt[]', $att['default']). '</td>'
			.'<td>'.fInput('text', 'smd_macro_attrep[]', $att['rep']). '</td>'
			.'</tr>';
	}
	$attlist[] = '</tbody></table>';

	$atts = join(n, $attlist);
	echo n, tr(
		tda('<label>'.gTxt('smd_macro_attributes').'</label>'.n.'<a href="#" id="smd_macro_att_add" title="'.gTxt('smd_macro_att_clone_help').'">'.gTxt('smd_macro_att_clone').'</a>')
		.tda($atts, ' id="smd_macro_att"')
		),
		n, tr(
			tda('<label>'.gTxt('smd_macro_tag_definition').'</label>')
			.n.tda(
				text_area('smd_macro_definition', 250, 700, $macro_def)
			)
		),
		n, tr(td('&nbsp;').eInput($smd_macro_event).sInput('smd_macro_save').td(fInput('submit', 'save', gTxt('save')))),
		n, tInput(),
		n, endTable(),
		n, '</form>',
		n, '</div>',
		n, script_js(<<<EOJS
jQuery(function() {
	jQuery('#smd_macro_att_add').click(function(ev) {
		var obj = jQuery('#smd_macro_attlist tbody');
		var elems = jQuery('.smd_macro_att');

		// Add the row, empty it and focus. Can't do this in any fewer jQuery() calls for some reason
		obj.append(obj.children().eq(0).clone());
		obj.children().last().find('input:text').val('');
		obj.children().last().find('input:text').first().focus();
		ev.preventDefault();
	});

	// Import link
	jQuery('#smd_macro_import').click(function(ev) {
		jQuery('#smd_macro_export_holder').hide('normal');
		jQuery('#smd_macro_clone_holder').hide('normal');
		jQuery('#smd_macro_import_holder').toggle('normal');
	});

	// Export link
	jQuery('#smd_macro_export').click(function(ev) {
		jQuery('#smd_macro_import_holder').hide('normal');
		jQuery('#smd_macro_clone_holder').hide('normal');
		jQuery('#smd_macro_export_holder').toggle('normal');
	});

	// Clone link
	jQuery('#smd_macro_clone').click(function(ev) {
		jQuery('#smd_macro_import_holder').hide('normal');
		jQuery('#smd_macro_export_holder').hide('normal');
		jQuery('#smd_macro_clone_holder').toggle('normal').find("input[name='smd_macro_new_name']").focus();
	});
});
function smd_macro_export_close() {
	jQuery("#smd_macro_export").click();
}
EOJS
		),
		<<<EOCSS
<style type="text/css">
.smd_hidden {
	display:none;
}
</style>
EOCSS;
}

// ------------------------
// Add an overwrite checkbox to the upload form
function smd_macro_upload_form($evt, $stp, $data, $args) {
	$ret = str_replace(
		'</div>'
		,  checkbox('smd_macro_import_overwrite', '1', 0) . gTxt('smd_macro_overwrite') . '</div>'
		, $data
	);
	return $ret;
}

// ------------------------
// Takes function params; assumes attribute sanitization done already
function smd_macro_save_direct($name='', $desc='', $atts=array(), $def='', $overwrite = false) {
	global $smd_macro_event;

	$smd_macro_new_name = sanitizeForUrl($name);
	$smd_macro_description = doSlash($desc);
	$smd_macro_definition = $def;

	$ret = '';

	if (smd_macro_valid($smd_macro_new_name)) {
		$code = doSlash(smd_macro_build($smd_macro_new_name, $atts, $smd_macro_definition));

		$smd_macro_definition = doSlash($smd_macro_definition);
		$smd_macro_atts = is_array($atts) ? serialize($atts) : $atts;

		if (smd_macro_table_exist()) {
			// Check if this macro name clashes with a built-in PHP/Txp function
			$exists = smd_macro_exists($smd_macro_new_name, false);
			if ($exists === false) {
				$indb = safe_field('macro_name', SMD_MACRO, "macro_name='$smd_macro_new_name'");

				if ($indb) {
					if ($overwrite) {
						// Update
						$ret = safe_update(SMD_MACRO, "macro_name='$smd_macro_new_name', description='$smd_macro_description', attributes='$smd_macro_atts', definition='$smd_macro_definition', code='$code'", "macro_name='$smd_macro_new_name'");
					} else {
						$ret = 'SMD_MACRO_SKIP';
					}
				} else {
					// Insert
					$ret = safe_insert(SMD_MACRO, "macro_name='$smd_macro_new_name', description='$smd_macro_description', attributes='$smd_macro_atts', definition='$smd_macro_definition', code='$code'");
				}
			} else {
				$ret = 'SMD_MACRO_CLASH';
			}
		}
	} else {
		$ret = 'SMD_MACRO_BAD_NAME';
	}

	// $ret may be empty only if macro table not installed
	return $ret;
}

// ------------------------
// Takes URL params; sanitizes attributes locally
function smd_macro_save() {
	global $smd_macro_event;

	extract(doSlash(gpsa(array(
		'smd_macro_name',
		'smd_macro_new_name',
		'smd_macro_description',
	))));

	$msg = '';
	$smd_macro_new_name = sanitizeForUrl($smd_macro_new_name);

	if (smd_macro_valid($smd_macro_new_name)) {
		$att_name = gps('smd_macro_attname');
		$att_dflt = gps('smd_macro_attdflt');
		$att_rep = gps('smd_macro_attrep');

		// Don't doSlash() this yet
		$smd_macro_definition = gps('smd_macro_definition');

		$atts = array();
		foreach($att_name as $idx => $att) {
			if ($att == '') continue;
			$atts[strtolower(str_replace('-', '_', sanitizeForUrl($att)))] = array(
				'default' => $att_dflt[$idx],
				'rep' => $att_rep[$idx]
			);
		}
		
		$code = doSlash(smd_macro_build($smd_macro_new_name, $atts, $smd_macro_definition));
		$smd_macro_definition = doSlash($smd_macro_definition);
		$smd_macro_atts = serialize($atts);

		if (smd_macro_table_exist()) {
			$exists = ($smd_macro_name != $smd_macro_new_name) && smd_macro_exists($smd_macro_new_name);
			if ($exists === false) {
				// Inject the new name so it's selected in the dropdown if renamed
				$_POST['smd_macro_name'] = $smd_macro_new_name;
				
				if ($smd_macro_name == '') {
					// Insert
					$ret = safe_insert(SMD_MACRO, "macro_name='$smd_macro_new_name', description='$smd_macro_description', attributes='$smd_macro_atts', definition='$smd_macro_definition', code='$code'");
					$msg = gTxt('smd_macro_created');
				} else {
					// Update
					$ret = safe_update(SMD_MACRO, "macro_name='$smd_macro_new_name', description='$smd_macro_description', attributes='$smd_macro_atts', definition='$smd_macro_definition', code='$code'", "macro_name='$smd_macro_name'");
					$msg = gTxt('smd_macro_saved');
				}
			} else {
				$msg = array(gTxt('smd_macro_exists'), E_WARNING);
			}
		}
	} else {
		$msg = array(gTxt('smd_macro_invalid'), E_WARNING);
	}
	smd_macro($msg);
}

// ------------------------
function smd_macro_delete() {
	global $smd_macro_event;

	$macro_name = doSlash(gps('smd_macro_name'));

	$ret = safe_delete(SMD_MACRO, "macro_name='$macro_name'");
	$msg = gTxt('smd_macro_deleted');

	$_GET['smd_macro_name'] = '';

	smd_macro($msg);
}

// ------------------------
// Check the macro doesn't exist and also that it doesn't clash with an internal PHP/Txp function
function smd_macro_exists($macro, $check_db = true) {
	$ret = ($check_db) ? safe_field('macro_name', SMD_MACRO, "macro_name='$macro'") : false;
	if ($ret === false) {
		$fns = get_defined_functions();
		foreach ($fns as $flist) {
			if ($ret === false) {
				$ret = in_array($macro, $flist);
			}
		}
	}
	return $ret;
}
// ------------------------
// Check the macro name is a valid PHP function name
function smd_macro_valid($macro) {
	return is_callable($macro, true) && preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $macro);
}

// ------------------------
function smd_macro_build($macro, $args, $def) {
	$arglist = $reps = array();
	$lAtts = '';

	// Validate macro name and bail if it'd cause problems
	// Build arg list and replacements array.
	// Replacements are prefixed with the name of the macro for clash minimisation
	$args = is_array($args) ? $args : unserialize($args);
	foreach ($args as $arg => $vals) {
		$arglist[] = "'$arg' => '".$vals['default']."'";
		$rep = empty($vals['rep']) ? $arg : $vals['rep'];
		$reps[] = '"{' . $rep . '}" => "$'.$macro.'_'.$arg.'"';
	}

	$reps[] = '"{smd_container}" => "$'.$macro.'_smd_container"';
	$arg_string = join(','.n, $arglist);
	$rep_string = join(','.n, $reps);
	$def = doSlash($def);

	// Only create lAtts if there's at least one attribute defined
	if ($arg_string) {
		$lAtts = <<<EOATT
extract(lAtts(array(
		{$arg_string}
	),\$atts), EXTR_PREFIX_ALL, '{$macro}');
EOATT;
	}

$full_macro = <<< EOFN
function {$macro}(\$atts, \$thing = NULL) {
	{$lAtts}
	\${$macro}_smd_container = (\$thing)? \$thing : (isset(\${$macro}_smd_container) ? \${$macro}_smd_container : '');
	\$out = '';
	\$out = strtr(stripslashes('{$def}'), array({$rep_string}));
	return parse(\$out);
}
EOFN;

	return $full_macro;
}

// ------------------------
// Create ini file contents and return it.
// $special is an array of keynames and a function to apply to them (e.g. 'definition' => 'base64_encode')
function smd_macro_create_ini($arr, $special=array()) {
	$content = '';
	foreach ($arr as $key => $elem) {
		$content .= "[".$key."]\n";
		foreach ($elem as $subkey => $subelem) {
			$subelem = (array_key_exists($subkey, $special)) ? doArray($subelem, $special[$subkey]) : $subelem;
			if(is_array($subelem)) {
				$num = count($subelem);
				for($idx = 0; $idx < $num; $idx++) {
					$content .= $subkey . "[] = \"" . $subelem[$idx] . "\"\n";
				}
			} else if($subelem == "") {
				$content .= $subkey . " = \n";
			} else {
				$content .= $subkey . " = \"" . $subelem . "\"\n";
			}
		}
	}

	return $content;
}

// ------------------------
// Add macro table if not already installed
function smd_macro_table_install($showpane='1') {
	global $DB;

	$GLOBALS['txp_err_count'] = 0;
	$ret = '';
	$sql = array();
	$sql[] = "CREATE TABLE IF NOT EXISTS `".PFX.SMD_MACRO."` (
		`macro_name` varchar(32) NOT NULL default '',
		`description` varchar(255) NULL default '' COLLATE utf8_general_ci,
		`attributes` text NULL default '' COLLATE utf8_general_ci,
		`definition` mediumtext NULL default '' COLLATE utf8_general_ci,
		`code` mediumtext NULL default '',
		PRIMARY KEY (`macro_name`)
	) ENGINE=MyISAM, CHARACTER SET=utf8";

	if(gps('debug')) {
		dmp($sql);
	}
	foreach ($sql as $qry) {
		$ret = safe_query($qry);
		if ($ret===false) {
			$GLOBALS['txp_err_count']++;
			echo "<b>".$GLOBALS['txp_err_count'].".</b> ".mysql_error()."<br />\n";
			echo "<!--\n $qry \n-->\n";
		}
	}

	$flds = getThings('describe `'.PFX.SMD_MACRO.'`');
	if (!in_array('definition',$flds)) {
		safe_alter(SMD_MACRO, "add `definition` mediumtext NULL default '' after `attributes`");
	}

	// Upgrade table collation if necessary
	$ret = @safe_field("COLLATION_NAME", "INFORMATION_SCHEMA.COLUMNS", "table_name = '".PFX.SMD_MACRO."' AND table_schema = '" . $DB->db . "' AND column_name = 'description'");
	if ($ret != 'utf8_general_ci') {
		$ret = safe_alter(SMD_MACRO, 'CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci');
	}

	// Spit out results
	if ($GLOBALS['txp_err_count'] == 0) {
		if ($showpane) {
			$msg = gTxt('smd_macro_tbl_installed');
			smd_macro($msg);
		}
	} else {
		if ($showpane) {
			$msg = gTxt('smd_macro_tbl_not_installed');
			smd_macro($msg);
		}
	}
}

// ------------------------
// Drop table if in database
function smd_macro_table_remove() {
	$ret = '';
	$sql = array();
	$GLOBALS['txp_err_count'] = 0;
	if (smd_macro_table_exist()) {
		$sql[] = "DROP TABLE IF EXISTS " .PFX.SMD_MACRO. "; ";
		if(gps('debug')) {
			dmp($sql);
		}
		foreach ($sql as $qry) {
			$ret = safe_query($qry);
			if ($ret===false) {
				$GLOBALS['txp_err_count']++;
				echo "<b>".$GLOBALS['txp_err_count'].".</b> ".mysql_error()."<br />\n";
				echo "<!--\n $qry \n-->\n";
			}
		}
	}
	if ($GLOBALS['txp_err_count'] == 0) {
		$msg = gTxt('smd_macro_tbl_removed');
	} else {
		$msg = gTxt('smd_macro_tbl_not_removed');
		smd_macro($msg);
	}
}

// ------------------------
function smd_macro_table_exist($all='') {
	if ($all) {
		$tbls = array(SMD_MACRO => 5);
		$out = count($tbls);
		foreach ($tbls as $tbl => $cols) {
			if (gps('debug')) {
				echo "++ TABLE ".$tbl." HAS ".count(@safe_show('columns', $tbl))." COLUMNS; REQUIRES ".$cols." ++".br;
			}
			if (count(@safe_show('columns', $tbl)) == $cols) {
				$out--;
			}
		}
		return ($out===0) ? 1 : 0;
	} else {
		if (gps('debug')) {
			echo "++ TABLE ".SMD_MACRO." HAS ".count(@safe_show('columns', SMD_MACRO))." COLUMNS;";
		}
		return(@safe_show('columns', SMD_MACRO));
	}
}

// *********************
// PUBLIC SIDE INTERFACE
// *********************

// ------------------------
// Extracts all prebuilt macros (essentially, new tags) and injects them into the global scope so they can be called
function smd_macro_boot() {
	$full_macros = array();

	$rs = safe_rows('*', SMD_MACRO, '1=1');

	foreach ($rs as $row) {
		$full_macros[] = str_replace('\r\n','
',$row['code']); // yukky newline workaround
	}

	$macros = join(n, $full_macros);

	// Inject the virtual tags into the global scope
	eval($macros);
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1(#smd_top). smd_macro

Build your own virtual Textpattern tags from combinations of built-in tags and plugins -- even PHP. Thus you can ease your workflow or offer your clients simpler syntax for doing powerful things in their Pages, Forms, and Articles.

h2(#smd_feat). Features:

* Define macros -- custom, virtual Txp tags -- with any number of attributes
* Construct a definition block -- like a Txp Form -- that contains the tags / code that make up your macro
* Inject replacement values (read from your attributes) into macro definitions at runtime
* Import / export macros for archive / sharing with the community
* Macros can be single or containers

h2. Installation / uninstallation

p(information). Requires Textpattern 4.5.2+

Download the plugin from either "textpattern.org":http://textpattern.org/plugins/1213/smd_macro, or the "software page":http://stefdawson.com/sw, paste the code into the Txp _Admin -> Plugins_ pane, install and enable the plugin. To uninstall, delete from the _Admin -> Plugins_ page.

Visit the "forum thread":http://forum.textpattern.com/viewtopic.php?id=35772 for more info or to report on the success or otherwise of the plugin.

h2(#smd_usage). Usage

Visit the _Content -> Macros_ tab. When there are macros defined, the dropdown at the top-right of the panel contains a list of all macros that have been defined. Choose one to load it into the boxes below for editing.

The boxes are:

* %(atnm)Name% : name of your macro/virtual tag[1]. Click the adjacent [x] button to delete it.
* %(atnm)Description% : optional description for your own documentation -- not used anywhere else at present.
* %(atnm)Attributes% : specify up to three values for each attribute[2]. Click the [+] button to create more. Clear the _name_ box before Saving to delete that attribute. The three values are:
** %(atnm)Name% : the name of your attribute that clients will use in your tag. Must be a valid ASCII name.
** %(atnm)Default% : the default value that this attribute will take on if not supplied. Leave empty for @""@.
** %(atnm)Replacement% : the name of the @{replacement}@ that this attribute will occupy in your definitions. Specify it _without_ the @{}@ brackets. If this entry is omitted, the attribute's @Name@ will be used.
* %(atnm)Macro definition% : your tag code here. At any point you can insert the name of any of your defined attribute replacement values inside @{}@ marks to have the plugin inject the attribute into your markup when the client uses the tag. See "examples":#smd_examples for more.

fn1. IMPORTANT: your tag *MUST* adhere to the following conventions:

* an ASCII name -- no foreign characters, hyphens or anything other than alphanumeric characters and underscores. In addition it may _not_ begin with a number.
* double check before saving that your tag does not have the same name as an existing Txp or PHP function. If it does, your site WILL blow up with nasty errors. The best way to avoid this is to make sure you prefix your tags with a three-letter prefix, just as you would if this was your own plugin.

The plugin tries to shield you from invalid names as far as possible and will usually detect internal PHP/Txp function name clashes, admin-side plugin clashes as well as invalid/previously defined macro names. But it cannot legislate for functions that are going to become available on your public site, i.e. tags/functions added by public-side plugins. Please exercise caution when naming your macros.

fn2. Make sure you avoid the pipe symbol @|@ in your attribute names / defaults / replacements as this character is used internally by the plugin.

h3. Container macros

You may use your new macros as container tags. In your macro definition, specify the special replacement tag @{smd_container}@ where you want the contained content to go. If the container is empty, nothing is output _unless_ you define an attribute named @smd_container@ and give it some Default content. In that case, your @{smd_container}@ replacement tag will take on the default value assigned to that attribute. Note that the _Replacement name_ doesn't matter and can be omitted: the replacement is _always_ going to be @{smd_container}@.

Note this means that if you add an attribute @smd_container="some value"@ to your macro when it's used in your page, it does the same thing as using the container. This could be construed a feature.

h2(#smd_import). Importing macros

You can import macros that have been previously exported from the plugin. Click the _Import_ link and choose the @smd_macros.ini@ file that you have been given (the file name itself doesn't actually matter). If you want to overwrite any macros of the same name that already exist in your system, check the _Force overwrite_ box. Otherwise, any macros in the import file that already exist will be skipped.

Note that the plugin makes some checks on your system and will try and bail out gracefully if the necessary permissions are not set (it temporarily uploads files to Txp's temp directory, as defined in _Advanced Prefs_). It also imposes a soft limit of roughly 15KB on the size of the imported file, mostly for reasons of speed. If you find yourself requiring to import large numbers of macros in one file, you can increase this limit by creating the following global system preference (smd_prefalizer can help):

* %(atnm)Pref name%: smd_macro_import_filesize
* %(atnm)Value% : maximum file size (in bytes)
* %(atnm)Visibility% : Hidden
* %(atnm)Event% : smd_macro

Alternatively, split your macros into a few smaller files and import them separately.

h2(#smd_export). Exporting macros

At any time you can export one or more macros to a file for distribution or backup. Just click the _Export_ link and choose one or more macros from the list that appears. Click _Go_ and then save the resulting file to your filesystem. The current macro that is being edited (if any) is selected by default.

h2(#smd_clone). Cloning macros

While a macro is loaded into the edit boxes you can clone it. Click the _Clone_ button, supply a name for the clone and hit _Go_. Provided the name isn't invalid or in use, your new clone will be created and brought into the edit area for immediate editing.

Note that the clone will be created from the chosen macro as it was last saved to the database. If you have made unsaved edits to the macro in the edit boxes at the time you clone the macro, they will be lost.

h2(#smd_examples). Examples

h3(#smd_eg1). Example 1: image gallery

* %(atnm)Macro name% : my_gallery
* %(atnm)Attributes (default / replacement)% : category (empty / img_cat)

bc(block). <txp:images category="{img_cat}">
   <txp:image />
   <div class="img_info"><txp:image_info /> by <txp:image_author /></div>
</txp:images>

Thus, @<txp:my_gallery category="animals" />@ will render an image gallery from the given category, complete with caption and author info. Note that no default value is given so if the @category@ attribute is not supplied, no gallery will be produced.

h3(#smd_eg2). Example 2: interface to smd_query

* %(atnm)Macro name% : my_col_from_table
* %(atnm)Attributes (default / replacement)% : column (ID / col), table (textpattern)

bc(block). <p>The contents of the {col} column
from the {table} table is:</p>
<txp:smd_query column="{col}" table="{table}"
     wraptag="ul" break="li">
   {{col}}
</txp:smd_query>

Anybody using @<txp:my_col_from_table column="Title" />@ would see a list of all article titles. If they added @table="txp_file"@ they'd see all titles from all uploaded files. Things to note:

* No replacement variable specified for the @table@ attribute, therefore it takes on the name of the attribute itself.
* The double set of @{{}}@ in the smd_query container is required because you first want the macro to replace @{col}@ with the value from your macro's @column@ attribute, i.e. if you'd chosen @column="Posted"@ then @{{col}}@ becomes @{Posted}@. After that, smd_query searches for @{Posted}@ and replaces it with the contents of the 'Posted' column for each row in the table.

h2. Author / credits

Written by "Stef Dawson":http://stefdawson.com/contact. Spawned from an idea by jpdupont, with thanks. Also big props to the beta test team: primarily jpdupont, pieman, mrdale, maverick, maruchan and jakob.

h2(#smd_changelog). Changelog

16 Mar 2011 | 0.10 | Initial public release
27 Mar 2011 | 0.11 | Fixed nesting bug (thanks maverick). Resave your macros after upgrading
19 Feb 2012 | 0.20 | Fixed UTF-8 collation, forced lower case attribute names and only permitted valid ascii chars / underscores as names (all thanks uli) ; added container support
10 Oct 2012 | 0.30 | Textpattern 4.5.x compatible
# --- END PLUGIN HELP ---
-->
<?php
}
?>