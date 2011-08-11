<?php

/* -------------------------------------------------------------- *//* !WP PUSH OPTIONS PAGE *//* -------------------------------------------------------------- */

//register all the settings
function mra_wp_push_register_settings()
{
	register_setting('mra_wp_push_options', 'mra_wp_push_options', 'mra_wpp_validate_options');

	/* General settings fields */
	add_settings_section(
		'mra_wpp_section_config',
		'General Configuration',
		'mra_wpp_section_config_text',
		'wp_push_options'	
	);
	
	add_settings_field(
		'mra_wpp_field_sites_conf',
		'Full path to sites.conf file',
		'mra_wpp_field_sites_conf',
		'wp_push_options',
		'mra_wpp_section_config'
	);
	
	add_settings_field(
		'mra_wpp_field_dbs_conf',
		'Full path to dbs.conf file',
		'mra_wpp_field_dbs_conf',
		'wp_push_options',
		'mra_wpp_section_config'
	);	

	add_settings_field(
		'mra_wpp_field_backup_path',
		'Path to backups directory',
		'mra_wpp_field_backup_path',
		'wp_push_options',
		'mra_wpp_section_config'
	);	

	add_settings_field(
		'mra_wpp_field_timezone',
		'Timezone',
		'mra_wpp_field_timezone',
		'wp_push_options',
		'mra_wpp_section_config'
	);	

	/*Capability fields */
	add_settings_section(
		'mra_wpp_section_capabilities',
		'WP Push Capabilities',
		'mra_wpp_section_capabilities_text',
		'wp_push_options'	
	);

	add_settings_field(
		'mra_wpp_field_capability',
		'WP Push capability',
		'mra_wpp_field_capability',
		'wp_push_options',
		'mra_wpp_section_capabilities'
	);
	
	add_settings_field(
		'mra_wpp_field_admin_capability',
		'WP Push admin capability',
		'mra_wpp_field_admin_capability',
		'wp_push_options',
		'mra_wpp_section_capabilities'
	);

	/* Cache option fields */
	add_settings_section(
		'mra_wpp_section_cache',
		'Cache management',
		'mra_wpp_section_cache_text',
		'wp_push_options'	
	);
	add_settings_field(
		'mra_wpp_field_cache',
		'Cache plugin',
		'mra_wpp_field_cache',
		'wp_push_options',
		'mra_wpp_section_cache'
	);
	add_settings_field(
		'mra_wpp_field_cache_key',
		'Cache secret key',
		'mra_wpp_field_cache_key',
		'wp_push_options',
		'mra_wpp_section_cache'
	);


	/* Plugin option fields */
	add_settings_section(
		'mra_wpp_section_plugins',
		'Plugin management',
		'mra_wpp_section_plugins_text',
		'wp_push_options'	
	);

	add_settings_field(
		'mra_wpp_field_plugin_management',
		'Plugin management',
		'mra_wpp_field_plugin_management',
		'wp_push_options',
		'mra_wpp_section_plugins'
	);

}

// output HTML for the WP Push options screen
function mra_wpp_options_html()
{
	global $mra_wpp_options;
	?>
	<div class='wrap'>
		<?php screen_icon( 'options-general' ); ?>
		<h2>WP Push Options</h2>
		
		<?php if( !empty($mra_wpp_options['notices']) ) echo mra_wpp_settings_notices(); ?>
		
		<p>Help text will go here…</p>
		
		<form action='options.php' method='post'>
		<?php
			settings_fields('mra_wp_push_options');
			do_settings_sections('mra_wpp_section_config');
			do_settings_sections('mra_wpp_section_capabilities');
			do_settings_sections('mra_wpp_section_cache');	
			do_settings_sections('mra_wpp_section_plugins');	
			do_settings_sections('wp_push_options');	
		?>
		<input name="Submit" type='submit' value='Save Changes' class='button-primary' />
		</form>
	</div>
	<?php
}


/* -------------------------------------------------------------- *//* Options page sections help texts */

function mra_wpp_section_config_text()
{
	echo '<p>Configuration and backup files should not be placed anywhere which is web readable. If possible, place these outside your web document root. For this site, the document root is at <br /><i>'.$_SERVER['DOCUMENT_ROOT'].'</i></p>';
}

function mra_wpp_section_capabilities_text()
{
	echo '<p class="description">Define which capabilities are required for normal admins to use WP Push, and for master admins to configure. Anyone with the <i>delete_users</i> capability will always be able to use and configure WP Push.</p>';
}

function mra_wpp_section_cache_text()
{
	echo '<p class="description">With certain cache plugins, WP Push can can clear the cache immediately after a push.</p>';
}

function mra_wpp_section_plugins_text()
{
	global $mra_wpp_options;
	
	$activates = $deactivates = '';

	foreach( $mra_wpp_options['plugins']['activate'] as $name=>$path )
	{
		$name = is_numeric( $name ) ? $path : $name;
		$activates .= "<li>{$name}</li>";
	}
	
	foreach( $mra_wpp_options['plugins']['deactivate'] as $name=>$path )
	{
		$name = is_numeric( $name ) ? $path : $name;
		$deactivates .= "<li>{$name}</li>";
	}

	$output = <<<EOD
 <div class="description"><p>WP Push can force certain plugins to be on or off on different versions of the site. This is useful, for example to ensure that a cache plugin is only active on your live site, or to ensure that a Google Analytics plugin is never turned on for a development site. Currently managed plugins which are hard wired, in the future they will be user definable.</p>
<p>The following plugins are automatically activated for any site which is classed as live:</p>
<ul>
{$activates}
</ul>
<p>The following plugins are automatically deactivated for any site which is classed as live:</p>
<ul>
{$deactivates}
</ul>
</div>
EOD;

	echo $output;
}


/* -------------------------------------------------------------- *//* Options page settings fields */

function mra_wpp_field_sites_conf()
{
	echo mra_get_wpp_input_text('sites_conf','','large-text');
}

function mra_wpp_field_dbs_conf()
{
	echo mra_get_wpp_input_text('dbs_conf','','large-text');
}

function mra_wpp_field_backup_path()
{
	echo mra_get_wpp_input_text('backup_path','If you leave this blank, destination site will not be backed up before a push.','large-text');
}

function mra_wpp_field_timezone()
{
	echo mra_get_wpp_input_text('timezone','Your default timezone is  <i>' . date_default_timezone_get() . '</i>. If that is not correct, enter your timezone here to make sure that logs and reporting are in your correct local time. See <a href="http://php.net/manual/en/timezones.php" target="_blank">list of supported timezones</a> for valid values.');
}

function mra_wpp_field_capability()
{
	echo mra_get_wpp_input_text('capability');
}

function mra_wpp_field_admin_capability()
{
	echo mra_get_wpp_input_text('admin_capability');
}

function mra_wpp_field_cache()
{
	$caches = array(
			  'w3tc' => 'W3 Total Cache'
			, 'none' => 'None <span class="description">(select this if you have a cache installed but it is not listed above)</span>'
	);
	echo mra_get_wpp_input_radio('cache', $caches);
}
function mra_wpp_field_cache_key()
{
	global $mra_wpp_options;
	
	$extra_text = empty( $mra_wpp_options['cache_key'] ) ? "<br />A random string you could use: " .  md5( microtime() ) : '';

	echo mra_get_wpp_input_text('cache_key', "A hard to guess secret key. This ensures that the cache is only cleared on a destination site when you want it to. This key must be the same on all sites which you push to from this site.{$extra_text}");
}

function mra_wpp_field_plugin_management()
{
	//echo mra_get_wpp_input_text('admin_capability');
}

// actual HTML creator
function mra_get_wpp_input_text( $field, $description='', $class='regular-text' )
{
	global $mra_wpp_options;
	$value = empty( $mra_wpp_options[$field] ) ? '' : $mra_wpp_options[$field];
	$output = "<input id='mra_wpp_field_{$field}' name='mra_wp_push_options[{$field}]' type='text' value='{$value}' class='{$class}' />";
	if( $description )
		$output .= "<span class='description' style='display:block;'>{$description}</span>";
	return $output;
}

function mra_get_wpp_input_radio( $field, $radio_options, $description='' )
{
	global $mra_wpp_options;
	
	$output = '';
	
	foreach( $radio_options as $radio_option=>$label )
	{
		$output .= "<label><input name='mra_wp_push_options[{$field}]' type='radio' value='{$radio_option}'" . checked($radio_option, $mra_wpp_options[$field], FALSE) . " /> {$label}</label><br />\n";
	}
		
	if( $description )
		$output .= "<span class='description' style='display:block;'>{$description}</span>";
	
	return $output;
}


/* -------------------------------------------------------------- *//* WP Push options field validation */

//@todo fix duplicated errors/errors not showing when options in WP Push menu
function mra_wpp_validate_options( $options )
{
	$notices = array();
	
	if( empty( $options ) )
	{
		//no options have been set, so this is a fresh config
		$options['ok'] = FALSE;
		$options['notices']['<b>Please configure WP Push</b>'] = 'error';
		return $options;
	}
	
	if( array_key_exists('sites_conf', $options) ) $options['sites_conf'] = trim( $options['sites_conf'] );
	if( empty( $options['sites_conf'] ) || !file_exists( $options['sites_conf'] ) )
		$notices['Path not valid - sites config file not found.'] = 'error';
		
	if( array_key_exists('dbs_conf', $options) ) $options['dbs_conf'] = trim( $options['dbs_conf'] );
	if( empty( $options['dbs_conf'] ) ||  !file_exists( $options['dbs_conf'] ) )
		$notices['Path not valid - DB config file not found.'] = 'error';
	
	if( array_key_exists('sites_conf', $options) && array_key_exists('dbs_conf', $options) && $options['dbs_conf'] == $options['sites_conf'] )
		$notices['Sites and DBs config files cannot be the same file!'] = 'error';

	if( array_key_exists('backup_path', $options) ) $options['backup_path'] = trim( $options['backup_path'] );
	if( !empty($options['backup_path']) && !file_exists( $options['backup_path'] ) )
		$notices['Path not valid - backup directory not found.'] = 'error';

	
	if( !empty( $options['timezone'] ) )
	{
		@$tz=timezone_open( $options['timezone'] );
		if( FALSE===$tz )
		{
			$notices["{$options['timezone']} is not a valid timezone. See <a href='http://php.net/manual/en/timezones.php' target='_blank'>list of supported timezones</a> for valid values."] = 'error';
		}
	}


	if( empty($options['capability']) )
		$options['capability'] = MRA_WPP_BASE_CAPABILITY;

	if( empty($options['admin_capability']) )
		$options['admin_capability'] = MRA_WPP_BASE_CAPABILITY;


	if( empty($options['cache']) )
		$options['cache'] = 'none';

	if( empty($options['cache_key']) )
		$options['cache_key'] = '';

	
	if( $notices )
	{
		$options['ok'] = FALSE;
		$options['notices'] = $notices;
	}
	else
	{
		$options['ok'] = TRUE;
	}

	return $options;
}

/* EOF */