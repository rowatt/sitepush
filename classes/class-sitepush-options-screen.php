<?php

class SitePush_Options_Screen extends SitePush_Screen
{


	public function __construct( $plugin, $options )
	{
		parent::__construct( $plugin, $options );
	}

	// output HTML for the SitePush options screen
	function display_screen()
	{
		?>
		<div class='wrap'>
			<?php screen_icon( 'options-general' ); ?>
			<h2>SitePush Options</h2>
			
			<?php if( !empty($this->options['notices']) ) echo $this->settings_notices(); ?>
			
			<p>@todo Help text will go here…</p>
			
			<form action='options.php' method='post'>
			<?php
				settings_fields('mra_sitepush_options');
				do_settings_sections('mra_sitepush_section_config');
				do_settings_sections('mra_sitepush_section_capabilities');
				do_settings_sections('mra_sitepush_section_cache');	
				do_settings_sections('mra_sitepush_section_plugins');	
				do_settings_sections('sitepush_options');	
			?>
			<input name="Submit" type='submit' value='Save Changes' class='button-primary' />
			</form>
		</div>
		<?php
	}
	
	function settings_notices()
	{
		if( empty( $this->options['notices'] ) ) return FALSE; //nothing to display
		
		$output = '';
		
		foreach( $this->options['notices'] as $type )
		{
			foreach( $type as $field=>$msg )
			{
				$class = 'error'==$type ? 'error settings-error' : 'updated settings-error';
				$output .= "<div id='mra_sitepush_options_{$type}' class='{$class}'>";
				$output .= "<p>{$msg}</p>";
				$output .= "</div>";
			}
		}
		return $output;
	}
	
	
	/* -------------------------------------------------------------- */	/* Options page sections help texts */
	
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
		echo '<p class="description">With certain cache plugins, SitePush can can clear the cache immediately after a push.</p>';
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
	
	function field_cache()
	{
		$caches = array(
				  'w3tc' => 'W3 Total Cache'
				, 'none' => 'None <span class="description">(select this if you have a cache installed but it is not listed above)</span>'
		);
		echo $this->input_radio('cache', $caches);
	}
	function field_cache_key()
	{
		$extra_text = empty( $this->options['cache_key'] ) ? "<br />A random string you could use: " .  md5( microtime() ) : '';
	
		echo $this->input_text('cache_key', "A hard to guess secret key. This ensures that the cache is only cleared on a destination site when you want it to. This key must be the same on all sites which you push to from this site.{$extra_text}");
	}
	
	function field_plugin_activates()
	{
		$activates = '';
	
		foreach( $this->options['plugins']['activate'] as $name=>$path )
		{
			$activates .= "{$name}\n";
		}

		echo $this->input_textarea('plugin_activates','Plugins which are to be automatically activated for any site which is classed as live, and deactivated on all others. One plugin per line, use the full path to the plugin from your plugins directory, e.g. "myplugin/myplugin.php"',max(3,2+count($this->options['plugins']['activate'])) );
	}
		
	function field_plugin_deactivates()
	{
		$deactivates = '';

		foreach( $this->options['plugins']['deactivate'] as $name=>$path )
		{
			$deactivates .= "{$name}\n";
		}

		echo $this->input_textarea('plugin_deactivates','Plugins which are to be automatically deactivated for any site which is not classed as live, and activated on all others. One plugin per line, use the full path to the plugin from your plugins directory, e.g. "myplugin/myplugin.php"',max(3,2+count($this->options['plugins']['deactivate'])) );
	}
	
	// actual HTML creator
	function input_text( $field, $description='', $class='regular-text' )
	{
		if( $class ) $class=" class='{$class}'";

		$value = empty( $this->options[$field] ) ? '' : $this->options[$field];
		$output = "<input id='mra_sitepush_field_{$field}' name='mra_sitepush_options[{$field}]' type='text' value='{$value}'{$class} />";
		if( $description )
			$output .= "<span class='description' style='display:block;'>{$description}</span>";
		return $output;
	}

	function input_textarea( $field, $description='', $rows='', $class='large-text' )
	{
		if( $class ) $class = " class='{$class}'";
		if( $rows ) $rows = " rows='{$rows}'";

		$value = empty( $this->options[$field] ) ? '' : $this->options[$field];
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
			$output .= "<label><input name='mra_sitepush_options[{$field}]' type='radio' value='{$radio_option}'" . checked($radio_option, $this->options[$field], FALSE) . " /> {$label}</label><br />\n";
		}
			
		if( $description )
			$output .= "<span class='description' style='display:block;'>{$description}</span>";
		
		return $output;
	}
	
	//gets list of all installed plugins which are not managed by SitePush
	function get_other_plugins()
	{
		//get all installed plugins
		$plugins = get_plugins();
		
		$other_plugins = array();
		
		//gather plugins we are already managing or can't manage
		$managed_plugins = array_merge($this->options['plugins']['activate'],$this->options['plugins']['deactivate'],$this->options['plugins']['never_manage']);
		if( !empty( $this->options['plugins']['cache'] ) )
			$managed_plugins[] = $this->options['plugins']['cache'];
		$managed_plugins[] = 'sitepush/sitepush.php';
		
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