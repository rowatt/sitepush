<?php

class SitePush_Options_Screen extends SitePush_Screen
{

	public $notices = array();

	public function __construct( $plugin )
	{
		parent::__construct( $plugin );
	}

	// output HTML for the SitePush options screen
	function display_screen()
	{
	?>
		<div class='wrap'>
			<?php screen_icon( 'options-general' ); ?>
			<h2>SitePush Options</h2>

			<?php
			//show errors/notices but only if we are updating, unless it's a fatal error
			if( ! empty($_GET['settings-updated']) )
				SitePushErrors::errors();
			else
				SitePushErrors::errors('fatal-error');

			if( $this->plugin->abort )
				return FALSE;

			?>
			<p>You are using SitePush version <?php echo $this->options->get_plugin_version(); ?>

			<form action='options.php' method='post'>
			<?php
				settings_fields('sitepush_options');
				do_settings_sections('sitepush_options');
			?>
			<input name="Submit" type='submit' value='Save Changes' class='button-primary' />
			</form>
		</div>
	<?php
		return TRUE;
	}


	/* -------------------------------------------------------------- */
	/* Options page sections help texts */

	function section_warning_text()
	{
		?>
			<p><b>This plugin</b> moves files and database tables between different WordPress installations and if something goes wrong, it <b>could break your site</b>. It has been successfully used on a number of sites without problem, but your server may be different.</p>
			<p>When you first use SitePush do it on a test site, and/or have a complete backup of your files and database. Once you have confirmed things work for your setup it's less likely to do any serious damage, but it's still possible.</p>
		<?php
	}

	function section_config_text()
	{
		echo '<p>Configuration and backup files should not be placed anywhere which is web readable. If possible, place these outside your web document root.<br />For this site, the document root is at <i>'.$_SERVER['DOCUMENT_ROOT'].'</i></p>';
	}

	function section_capabilities_text()
	{
		echo '<p class="description">Define which capabilities are required for normal admins to use SitePush, and for master admins to configure. Anyone with the <i>delete_users</i> capability will always be able to use and configure SitePush.</p>';
	}

	function section_cache_text()
	{
		echo '<p class="description">If the destination site uses <a href="http://wordpress.org/extend/plugins/w3-total-cache/" target="_blank">W3 Total Cache</a> or <a href="http://wordpress.org/extend/plugins/wp-super-cache/" target="_blank">WP Super Cache</a>, SitePush can can clear the cache immediately after a push.<br />To enable this, you must set the cache secret key below, and also set the cache parameter to <i>yes</i> in your sites config file for at least one site.</p>';
	}

	function section_rsync_text()
	{
		echo '<p class="description">Files are copied between sites using rsync. These options can normally be left as they are.</p>';
	}

	function section_mysql_text()
	{
		echo '<p class="description">Database tables are copied between sites using mysql and mysqldump. These options can normally be left as they are.</p>';
	}

	function section_backup_text()
	{
		echo '<p class="description">Destination files and database will be backed up before being overwritten. Files and database dumps are saved in the directory defined below. Currently SitePush cannot automatically restore - if you need to restore files or database you will need to do this manually.</p>';
	}

	function section_plugins_text()
	{
		$others = '';

		echo '<p class="description">SitePush can force certain plugins to be on or off on different versions of the site. This is useful, for example to ensure that a cache plugin is only active on your live site, or to ensure that a Google Analytics plugin is never turned on for a development site.</p>';

		foreach( $this->get_other_plugins() as $plugin )
		{
			$others .= "<li class='description'>{$plugin}</li>";
		}

		if( $others )
		{
			echo "<table class='form-table otherpluginslist'><tr><th></th><td>";
			echo "<p class='description'>The following plugins are installed and could be managed by SitePush:</p>";
			echo "<ul>{$others}</ul>";
			echo "<p class='description'>Copy any of the plugins below to the activate or deactivate fields below if you wish SitePush to control activation of that plugin.</p>";
			echo "</td></tr></table>";
		}

	}


	/* -------------------------------------------------------------- */
	/* Options page settings fields */

	function field_accept()
	{
		echo $this->input_checkbox('accept',' I have read the instructions, backed up my site and accept the risks.' );
	}

	function field_sites_conf()
	{
		echo $this->input_text('sites_conf','','large-text');
	}

	function field_dbs_conf()
	{
		echo $this->input_text('dbs_conf','','large-text');
	}

	function field_fix_site_urls()
	{
		echo $this->input_checkbox('fix_site_urls', ' Convert site URLs to link to current site', 'Make sure that any URLs to any of your sites domains link to the current site. <br />For example http://dev.mysite.com/mypage would be converted to http://www.mysite.com/mypage when viewing www.mysite.com.<br />This helps to make sure that URLs work across different versions of your sites.<br />If a site has more than one domain defined, URLs will be converted to the first domain given for that site in your sites config file.');
	}

	function field_timezone()
	{
		echo $this->input_text('timezone','Your default timezone is  <i>' . date_default_timezone_get() . '</i>. If that is not correct, enter your timezone here to make sure that logs and reporting are in your correct local time. See <a href="http://php.net/manual/en/timezones.php" target="_blank">list of supported timezones</a> for valid values.');
	}

	function field_debug_output_level()
	{
		echo $this->input_text('debug_output_level','How much debug output is shown. Enter a number from 0 (no debug output) to 3 (detailed output).<br />Debug output is only ever shown to people with SitePush admin capability.','small-text');
	}

	function field_capability()
	{
		echo $this->input_text('capability');
	}

	function field_admin_capability()
	{
		echo $this->input_text('admin_capability');
	}

	function field_only_admins_login_to_live()
	{
		echo $this->input_checkbox('only_admins_login_to_live', ' Only admins can login to live sites', 'Prevent login to any site labelled as <i>live</i> (in site config file) by any user who does not have the SitePush admin capability.');
	}

	function field_cache_key()
	{
		$extra_text = empty( $this->options->cache_key ) ? "<br />A random string you could use: " .  md5( microtime() ) : '';

		echo $this->input_text('cache_key', "A hard to guess secret key. This ensures that the cache is only cleared on a destination site when you want it to.<br />This key must be the same on all sites which you push to from this site.{$extra_text}");
	}

	function field_plugin_activates()
	{
		echo $this->input_textarea(	array(
		                                    'field' => 'plugin_activates'
		                                    , 'value' => implode( "\n", $this->options->plugins['activate'] )
		                                    , 'description' => 'Plugins which are to be automatically activated for any site which is classed as live, and deactivated on all others. One plugin per line, use the full path to the plugin from your plugins directory, e.g. "myplugin/myplugin.php"'
		                                    , 'rows' => max( 3, 2+count($this->options->plugins['activate']) )
		                               ));
	}

	function field_plugin_deactivates()
	{
		echo $this->input_textarea(	array(
		                                    'field' => 'plugin_deactivates'
		                                    , 'value' => implode( "\n", $this->options->plugins['deactivate'] )
		                                    , 'description' => 'Plugins which are to be automatically deactivated for any site which is not classed as live, and activated on all others. One plugin per line, use the full path to the plugin from your plugins directory, e.g. "myplugin/myplugin.php"'
		                                    , 'rows' => max( 3, 2+count($this->options->plugins['deactivate']) )
		                               ));
	}

	function field_backup_path()
	{
		echo $this->input_text('backup_path','If you leave this blank, destination site will not be backed up before a push.','large-text');
	}

	function field_backup_keep_time()
	{
		echo $this->input_text('backup_keep_time','SitePush backups will be deleted after they are this many days old. Backups will never be deleted if set to 0.','small-text');
	}

	function field_rsync_path()
	{
		$rsync_help = 'Path to rsync on this server. ';
		if( $this->options->rsync_path && file_exists($this->options->rsync_path) )
			$rsync_help .= 'The current path appears to be OK.';
		elseif( $this->options->rsync_path && ! file_exists($this->options->rsync_path) )
			$rsync_help .= '<b>rsync was not found!</b> Please make sure you enter the correct path, e.g. /usr/bin/rsync, or leave blank.';
		else
			$rsync_help .= ' Please enter a path to rsync, e.g. /usr/bin/rsync, or leave blank.';

		echo $this->input_text('rsync_path',$rsync_help,'large-text');
	}

	function field_dont_sync()
	{
		echo $this->input_text('dont_sync','Comma separated list of files or directories that will never be synced. You probably don\'t need or want to change this.','large-text');
	}

	function field_mysql_path()
	{
		$help = 'Path to mysql on this server. ';
		if( $this->options->mysql_path && file_exists($this->options->mysql_path) )
			$help .= 'The current path appears to be OK.';
		elseif( $this->options->mysql_path && ! file_exists($this->options->mysql_path) )
			$help .= '<b>mysql was not found!</b> Please make sure you enter the correct path, e.g. /usr/bin/mysql, or leave blank.';
		else
			$help .= ' Please enter a path to mysql, e.g. /usr/bin/mysql, or leave blank.';

		echo $this->input_text('mysql_path',$help,'large-text');
	}

	function field_mysqldump_path()
	{
		$help = 'Path to mysqldump on this server. ';
		if( $this->options->mysqldump_path && file_exists($this->options->mysqldump_path) )
			$help .= 'The current path appears to be OK.';
		elseif( $this->options->mysqldump_path && ! file_exists($this->options->mysqldump_path) )
			$help .= '<b>mysqldump was not found!</b> Please make sure you enter the correct path, e.g. /usr/bin/mysqldump, or leave blank.';
		else
			$help .= ' Please enter a path to mysqldump, e.g. /usr/bin/mysqldump, or leave blank.';

		echo $this->input_text('mysqldump_path',$help,'large-text');
	}

	/* --------------------------------------------------------------
	/* ! Generate HTML fields
	/* -------------------------------------------------------------- */

	/**
	 * Generate a text field
	 *
	 * @param $field
	 * @param string $description
	 * @param string $class CSS class
	 * @return string HTML output
	 */
	function input_text( $field, $description='', $class='regular-text' )
	{
		if( $class ) $class=" class='{$class}'";

		$value = isset($this->options->$field) ? $this->options->$field : '';
		$output = "<input id='sitepush_field_{$field}' name='sitepush_options[{$field}]' type='text' value='{$value}'{$class} />";
		if( $description )
			$output .= "<span class='description' style='display:block;'>{$description}</span>";
		return $output;
	}

	/**
	 * Generate a textarea field
	 *
	 * @param array $vars
	 * @return string HTML output
	 */
	function input_textarea( $vars=array() )
	{
		/** @var string $field */
		/** @var string $description */
		/** @var string $rows */
		/** @var string $class CSS class to add */
		/** @var string $value */
		$defaults = array(
			    'field'	=>	''
			  , 'description' => ''
			  , 'rows' => ''
			  , 'class' => 'large-text'
			  , 'value' => NULL
		);
		extract( wp_parse_args( $vars , $defaults ) );

		if( $class ) $class = " class='{$class}'";
		if( $rows ) $rows = " rows='{$rows}'";

		if( is_null($value) )
			$value = $this->options->$field ? $this->options->$field : '';

		$output = "<textarea id='sitepush_field_{$field}' name='sitepush_options[{$field}]' type='text'{$class}{$rows}>{$value}</textarea>";
		if( $description )
			$output .= "<span class='description' style='display:block;'>{$description}</span>";
		return $output;
	}

	/**
	 * Generate radio button group
	 *
	 * @param $field
	 * @param $radio_options
	 * @param string $description
	 * @return string HTML output
	 */
	function input_radio( $field, $radio_options, $description='' )
	{
		$output = '';

		foreach( $radio_options as $radio_option=>$label )
		{
			$output .= "<label><input name='sitepush_options[{$field}]' type='radio' value='{$radio_option}'" . checked($radio_option, $this->options->$field, FALSE) . " /> {$label}</label><br />\n";
		}

		if( $description )
			$output .= "<span class='description' style='display:block;'>{$description}</span>";

		return $output;
	}

	/**
	 * Generate checkbox field
	 *
	 * @param $field
	 * @param $description
	 * @param string $class
	 * @return string HTML output
	 */
	function input_checkbox( $field, $description, $help='', $class='' )
	{
		if( $class ) $class=" class='{$class}'";

		$checked = empty( $this->options->$field ) ? '' : ' checked="checked"';
		$output = "<label for='sitepush_field_{$field}'{$class}>";
		$output .= "<input id='sitepush_field_{$field}' name='sitepush_options[{$field}]' type='checkbox'{$checked} />";
		$output .= "{$description}</label>";
		if( $help )
			$output .= "<span class='description' style='display:block;'>{$help}</span>";
		return $output;
	}

	/**
	 * Get list of all installed plugins which are not managed by SitePush
	 *
	 * @return array plugins
	 */
	function get_other_plugins()
	{
		$other_plugins = array();

		//gather plugins we are already managing or can't manage
		$managed_plugins = array_merge($this->options->plugins['activate'],$this->options->plugins['deactivate'],$this->options->plugins['never_manage']);
		$managed_plugins[] = SITEPUSH_BASENAME;
		
		//create list of plugins we could manage
		foreach( get_plugins() as $plugin=>$info )
		{
			if( ! in_array(trim($plugin), $managed_plugins) )
				$other_plugins[] = $plugin;
		}
		
		return $other_plugins;

	}
	
}
/* EOF */