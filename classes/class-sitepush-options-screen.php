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
				if( $this->notices ) echo $this->settings_notices(); 
			
				if( $this->plugin->abort )
					{
						foreach( $this->plugin->errors as $error )
						{
							echo "<div class='error'><p>{$error}</p></div>";
						}
						return FALSE;
					}			
			?>
			<p>You are using SitePush version <?php $pd=get_plugin_data( WP_PLUGIN_DIR .'/' . MRA_SITEPUSH_BASENAME ); echo $pd['Version']; ?>
			
			<form action='options.php' method='post'>
			<?php
				settings_fields('mra_sitepush_options');
				do_settings_sections('sitepush_options');	
			?>
			<input name="Submit" type='submit' value='Save Changes' class='button-primary' />
			</form>
		</div>
		<?php
	}
	
	function settings_notices()
	{
		//don't display notices if we haven't submitted options form
		if( empty( $_REQUEST['settings-updated'] ) ) return FALSE;

		if( $this->notices ) return FALSE; //nothing to display
		
		$output = '';
		
		foreach( $this->notices as $type=>$notices )
		{
			foreach( $notices as $field=>$msg )
			{
				$class = 'errors'==$type ? 'error settings-error' : 'updated settings-error';
				$output .= "<div id='mra_sitepush_options_{$type}' class='{$class}'>";
				$output .= "<p>{$msg}</p>";
				$output .= "</div>";
			}
		}
		return $output;
	}
	
	
	/* -------------------------------------------------------------- */	/* Options page sections help texts */
	
	function section_warning_text()
	{
		?>
			<p>This plugin does a lot of things which, if they go wrong, could break your site. It has been successfully used on a number of sites without problem, but your server may be different.</p>
			<p>It is strongly suggested that when you first use SitePush you do it on a non live site, and/or have a complete backup of your files and database. Once you have confirmed things work for your setup it's less likely to do any serious damage, but it's still possible.</p>
		<?php
	}
	
	function section_config_text()
	{
		echo '<p>Configuration and backup files should not be placed anywhere which is web readable. If possible, place these outside your web document root. For this site, the document root is at <br /><i>'.$_SERVER['DOCUMENT_ROOT'].'</i></p>';
	}
	
	function section_capabilities_text()
	{
		echo '<p class="description">Define which capabilities are required for normal admins to use SitePush, and for master admins to configure. Anyone with the <i>delete_users</i> capability will always be able to use and configure SitePush.</p>';
	}
	
	function section_cache_text()
	{
		echo '<p class="description">If the destination site uses <a href="http://wordpress.org/extend/plugins/w3-total-cache/" target="_blank">W3 Total Cache</a> or <a href="http://wordpress.org/extend/plugins/wp-super-cache/" target="_blank">WP Super Cache</a>, SitePush can can clear the cache immediately after a push. To enable this, you must first set the cache secret key below.</p>';
	}

	function section_rsync_text()
	{
		echo '<p class="description">Files are copied between sites using rsync. These options can normally be left as they are.</p>';
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
	
	
	/* -------------------------------------------------------------- */	/* Options page settings fields */
	
	function field_accept()
	{
		echo $this->input_checkbox('accept',' I have read the instructions, backed up my site and accept the risks.', empty($this->options->accept) ? '' : 'hide-field' );
	}
	
	function field_sites_conf()
	{
		echo $this->input_text('sites_conf','','large-text');
	}
	
	function field_dbs_conf()
	{
		echo $this->input_text('dbs_conf','','large-text');
	}
	
	function field_backup_path()
	{
		echo $this->input_text('backup_path','If you leave this blank, destination site will not be backed up before a push.','large-text');
	}

	function field_backup_keep_time()
	{
		echo $this->input_text('backup_keep_time','SitePush backups will be deleted after they are this many days old.');
	}

	
	function field_rsync_path()
	{
		$rsync_help = 'Path to rsync on this server. ';
		if( $this->options->rsync_path && file_exists($this->options->rsync_path) )
			$rsync_help .= 'The current path appears to be OK.';
		elseif( $this->options->rsync_path && ! file_exists($this->options->rsync_path) )
			$rsync_help .= '<b>Rsync was not found!</b> Please make sure you enter the correct path, e.g. /usr/bin/rsync.';
		else
			$rsync_help .= ' Please enter a path to rsync, e.g. /usr/bin/rsync';

		echo $this->input_text('rsync_path',$rsync_help,'large-text');
	}

	function field_dont_sync()
	{
		echo $this->input_text('dont_sync','Comma separated list of files or directories that will never be synced. You probably don\'t need or want to change this.','large-text');
	}

	
	function field_timezone()
	{
		echo $this->input_text('timezone','Your default timezone is  <i>' . date_default_timezone_get() . '</i>. If that is not correct, enter your timezone here to make sure that logs and reporting are in your correct local time. See <a href="http://php.net/manual/en/timezones.php" target="_blank">list of supported timezones</a> for valid values.');
	}

	function field_capability()
	{
		echo $this->input_text('capability');
	}
	
	function field_admin_capability()
	{
		echo $this->input_text('admin_capability');
	}
	
	function field_cache_key()
	{
		$extra_text = empty( $this->options->cache_key ) ? "<br />A random string you could use: " .  md5( microtime() ) : '';
	
		echo $this->input_text('cache_key', "A hard to guess secret key. This ensures that the cache is only cleared on a destination site when you want it to. This key must be the same on all sites which you push to from this site.{$extra_text}");
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
	
	// actual HTML creator
	function input_text( $field, $description='', $class='regular-text' )
	{
		if( $class ) $class=" class='{$class}'";

		$value = $this->options->$field ? $this->options->$field : '';
		$output = "<input id='mra_sitepush_field_{$field}' name='mra_sitepush_options[{$field}]' type='text' value='{$value}'{$class} />";
		if( $description )
			$output .= "<span class='description' style='display:block;'>{$description}</span>";
		return $output;
	}

	function input_textarea( $vars=array() )
	{
		$defaults = array(
			    'field'	=>	''
			  , 'description' => ''
			  , 'rows' => ''
			  , 'class' => 'large-text'
			  , 'value' => ''
		);
		extract( wp_parse_args( $vars , $defaults ) );

		if( $class ) $class = " class='{$class}'";
		if( $rows ) $rows = " rows='{$rows}'";

		if( !$value )
			$value = $this->options->$field ? $this->options->$field : '';
		
		$output = "<textarea id='mra_sitepush_field_{$field}' name='mra_sitepush_options[{$field}]' type='text'{$class}{$rows}>{$value}</textarea>";
		if( $description )
			$output .= "<span class='description' style='display:block;'>{$description}</span>";
		return $output;
	}

	
	function input_radio( $field, $radio_options, $description='' )
	{
		$output = '';
		
		foreach( $radio_options as $radio_option=>$label )
		{
			$output .= "<label><input name='mra_sitepush_options[{$field}]' type='radio' value='{$radio_option}'" . checked($radio_option, $this->options->$field, FALSE) . " /> {$label}</label><br />\n";
		}
			
		if( $description )
			$output .= "<span class='description' style='display:block;'>{$description}</span>";
		
		return $output;
	}
	
	function input_checkbox( $field, $description, $class='' )
	{
		if( $class ) $class=" class='{$class}'";

		$checked = empty( $this->options->$field ) ? '' : ' checked="checked"';
		$output = "<label for='mra_sitepush_field_{$field}'{$class}>";
		$output .= "<input id='mra_sitepush_field_{$field}' name='mra_sitepush_options[{$field}]' type='checkbox'{$checked} />";
		$output .= "{$description}</label>";
		return $output;
	}
	
	//gets list of all installed plugins which are not managed by SitePush
	function get_other_plugins()
	{
		//get all installed plugins
		$plugins = get_plugins();
		
		$other_plugins = array();

		//gather plugins we are already managing or can't manage
		$managed_plugins = array_merge($this->options->plugins['activate'],$this->options->plugins['deactivate'],$this->options->plugins['never_manage']);
		$managed_plugins[] = MRA_SITEPUSH_BASENAME;
		
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