<?php

class SitePushPlugin
{

	//major errors in initialisation will stop even options screen showing
	public $abort = FALSE;
	
	//holds any errors from push
	public $errors = array();
	public $notices = array();
	
	//holds SitePushOptions object
	public $options;
	
	private $min_wp_version = '3.3';
	
	public function __construct()
	{
		//check we have correct versions of WP, PHP etc.
		$this->check_requirements();

		/* --------------------------------------------------------------
		/* !SETUP HOOKS
		/* -------------------------------------------------------------- */

		//initialise plugin
		add_action('init',array( &$this, 'plugin_init'));

		//register scripts, styles & menus
		add_action('admin_init', array( __CLASS__, 'admin_init') );
		add_action('admin_menu', array( &$this, 'register_options_menu_help') );
		add_action('admin_head', array( &$this, 'add_plugin_js') );

		add_action('admin_notices',array( &$this, 'show_warnings'));

		//uninstall
		register_uninstall_hook(__FILE__, array( __CLASS__, 'uninstall') );
	}
	/**
	 * plugin_init
	 *
	 * sets up plugin options and adds some hooks
	 * run by init hook
	 */
	public function plugin_init()
	{
		//include required files & instantiate classes
		require_once('class-sitepush-options.php');
		$this->options = new SitePushOptions;

		if( $this->options->OK & ! $this->abort )
		{
			//makes sure correct plugins activated/deactivated for site
			$this->activate_plugins_for_site();

			//clears cache if proper $_GET params set, otherwise does nothing
			$this->clear_cache();

			//add settings to plugin listing page
			add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_links'), 10, 2 );
			add_filter( 'plugin_action_links', array( &$this, 'plugin_admin_override'), 10, 2 );

			//content filters
			add_filter('the_content', array( &$this, 'relative_urls') );
		}
	}
	//run during construct
	public function check_requirements()
	{
		if( version_compare( get_bloginfo( 'version' ), $this->min_wp_version, '<') )
			$this->errors[] = "SitePush requires at least WordPress version {$this->min_wp_version}";

		if( (defined('WP_ALLOW_MULTISITE') && WP_ALLOW_MULTISITE) && ! (defined('MRA_SITEPUSH_ALLOW_MULTISITE') && MRA_SITEPUSH_ALLOW_MULTISITE) )
			$this->errors[] = "SitePush does not support WordPress multisite installs. If you wish to use SitePush on a multisite install, add define('MRA_SITEPUSH_ALLOW_MULTISITE',TRUE) to your wp-config.php file and proceed with caution!";

		if( !empty($this->errors) )
			$this->abort = TRUE;	
	}
	
	//delete options entry when plugin is deleted
	static public function uninstall()
	{
		delete_option('mra_sitepush_options');
		
		//@todo delete user options
	}


	//add settings to plugin listing page
	//called by plugin_action_links filter
	static public function plugin_links( $links, $file )
	{
		if ( $file == MRA_SITEPUSH_BASENAME )
		{
			$add_link = '<a href="'.get_admin_url().'admin.php?page=mra_sitepush_options">'.__('Settings').'</a>';
			array_unshift( $links, $add_link );
		}
		return $links;
	}
	
	//@todo collate notices better
	private function check_query_vars()
	{
		if( isset($_GET['settings-updated']) && $_GET['settings-updated'] )
			$this->notices[] = 'Options updated.';
	}
	
	/* -------------------------------------------------------------- */
	/* !INITIALISATION FUNCTIONS */
	/* -------------------------------------------------------------- */
	
	//set up the plugin options, plugin menus and help screens
	//called by admin_menu action
	function register_options_menu_help()
	{
		//instantiate menu classes
		$push_screen = new SitePush_Push_Screen( &$this );
		$options_screen = new SitePush_Options_Screen( &$this );
		
		//register the settings
		$this->register_options( $options_screen );

		//if options aren't OK and user doesn't have admin capability don't add SitePush menus
		if( ! $this->can_admin() && ! $this->options->OK ) return;

		//make sure menus show for right capabilities, but will always show for admin
		if( ! current_user_can( $this->options->capability ) && current_user_can( SitePushOptions::$fallback_capability ) )
			$capability = SitePushOptions::$fallback_capability;
		else
			$capability = $this->options->capability;
		
		if( ! current_user_can( $this->options->admin_capability ) && current_user_can( SitePushOptions::$fallback_capability ) )
			$admin_capability = SitePushOptions::$fallback_capability;
		else
			$admin_capability = $this->options->admin_capability;
		
		//add menu(s) - only options page is shown if not configured properly
		$page_title = 'SitePush';
		$menu_title = 'SitePush';
		$menu_slug = ($this->options->OK && ! $this->abort) ? 'mra_sitepush' : 'mra_sitepush_options';
		$function = ($this->options->OK && ! $this->abort) ? array( $push_screen, 'display_screen') : array( $options_screen, 'display_screen');
		$icon_url = '';
		$position = 3;
		add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
	
		$parent_slug = $menu_slug;
		
		//add SitePush if options are OK
		if( $this->options->OK && !$this->abort)
		{	
			$page = add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function);
			add_action('admin_print_styles-' . $page, array( __CLASS__, 'admin_styles' ) ); //add custom stylesheet
			add_action('load-' . $page, array( __CLASS__, 'push_help' ) ); //add contextual help for main push screen
		}

		if( $this->can_admin() || $this->abort )
		{
			//add options page if we have admin capability
			$page_title = 'SitePush Options';
			$menu_title = 'Options';
			$menu_slug = 'mra_sitepush_options';
			$function = array( $options_screen, 'display_screen');
			
			$page = add_submenu_page( $parent_slug, $page_title, $menu_title, $admin_capability, $menu_slug, $function);

			add_action('admin_print_styles-' . $page, array( __CLASS__, 'admin_styles' ) ); //add custom stylesheet
			add_action('load-' . $page, array( __CLASS__, 'options_help' ) ); //add contextual help for options screen
		}
	}
	
	static public function admin_init()
	{
		wp_register_style( 'mra-sitepush-styles', MRA_SITEPUSH_PLUGIN_DIR_URL.'/styles.css' );
	}
	
	// load css
	static public function admin_styles()
	{
		wp_enqueue_style( 'mra-sitepush-styles' );
	}
	
	public function add_plugin_js()
	{
		echo "<script type='text/javascript'>\n";
		echo "			jQuery(function($) {\n";
		$this->jq_update_source_dest();
		echo "			});\n";
		echo "</script>\n";
	}

	/**
	 * jq_update_source_dest
	 *
	 * Insert Javascript to update various things when user changes source/dest
	 *
	 * @return void
	 */
	private function jq_update_source_dest()
	{
		if( empty($this->options->sites) ) return;
		
		//create JS array of live sites for script below
		$live_sites = array();
		foreach( $this->options->sites as $site )
		{
			if( !empty($site['live']) ) $live_sites[] = "'{$site['name']}'";
		}
		$live_sites = implode(',', $live_sites);	
	?>
		var liveSites = [ <?php echo $live_sites; ?> ];
		function updateSourceDest() {
			var warnText = '';

		<?php //show/hide warning if pushing to live site ?>
			if( $.inArray( $("#mra_sitepush_dest").find("option:selected").val(), liveSites ) > -1 )
				warnText = 'Caution - live site!';

		<?php //change button from Push<->Pull depending on destination ?>
			if( $("#mra_sitepush_dest").find("option:selected").val() == "<?php echo $this->options->get_current_site(); ?>" )
				$('#push-button').val('Pull Content');
			else
				$('#push-button').val('Push Content');

		<?php //hide submit button if source/dest are same ?>
			if( $("#mra_sitepush_dest").find("option:selected").val() == $("#mra_sitepush_source").find("option:selected").val() )
			{
				$('#push-button').hide();
				warnText = 'Source and destination sites cannot be the same!';
			}
			else
			{
				$('#push-button').show();
			}

			$('#mra_sitepush_dest-warning').text(warnText);
		};
		
		updateSourceDest();
		$(".site-selector").change(function() {
			updateSourceDest();
		});
	<?php
	}

	
	/* -------------------------------------------------------------- */
	/* !CONTENT FILTERS */
	/* -------------------------------------------------------------- */
	
	//@todo add to options screen
	/**
	 * relative_urls
	 * 
	 * Removes domain names from URLs on site to make them relative, so that links still work across versions of a site
	 * Domains to remove is defined in SitePush options
	 *
	 * Called by the_content filter
	 *
	 * @param string
	 * @return string
	 */
	function relative_urls( $content='' )
	{
		if( !$this->options->make_relative_urls ) return $content;
		
		foreach( $this->options->all_domains as $domain )
		{
			$search = array( "http://{$domain}", "https://{$domain}" );
			$content = str_ireplace( $search, '', $content );	
		}
		
		return $content;
	}
	
	/* -------------------------------------------------------------- */
	/* !HELP FUNCTIONS */
	/* -------------------------------------------------------------- */
	
	//@todo
	
	static public function options_help()
	{
		/**
		 * @var WP_Screen
		 */
		$screen = get_current_screen();
		$screen->add_help_tab( array(
			'id'      => 'mra-sitepush-options-help',
			'title'   => 'Special Instructions',
			'content' => '<p>This is the content for the tab.</p>',
		) );
		
		$screen->set_help_sidebar( "<p>Help sidebar here...</p>" );
	}

	static public function push_help()
	{
		$screen = get_current_screen();
		$screen->add_help_tab( array(
			'id'      => 'mra-sitepush-push-help',
			'title'   => 'Special Instructions',
			'content' => '<p>This is the content for the tab.</p>',
		) );
		
		$screen->set_help_sidebar( "<p>Help sidebar here...</p>" );
	}
	
	/* -------------------------------------------------------------- */
	/* !SITEPUSH FUNCTIONS */
	/* -------------------------------------------------------------- */
	
	public function can_admin()
	{
		if( !empty($this->options->admin_capability) && current_user_can( $this->options->admin_capability ) )
			return TRUE;
		else
			return current_user_can( SitePushOptions::$fallback_capability )
					|| current_user_can( SitePushOptions::$default_admin_capability );
	}
	
	public function can_use()
	{
		if( $this->can_admin() )
			return TRUE;
		elseif( !empty($this->options->capability) && current_user_can( $this->options->capability ) )
			return TRUE;
		else
			return current_user_can( SitePushOptions::$default_capability );
	}

	/**
	 * @param SitePushCore $my_push
	 * @param array $push_options options for this push from $_REQUEST
	 * @return bool TRUE if push completed without errors, FALSE otherwise
	 */
	public function do_the_push( $my_push, $push_options )
	{
		//if we are going to do a push, check that we were referred from options page as expected
		check_admin_referer('sitepush-dopush','sitepush-nonce');
		
		if( $my_push->errors )
		{
			//if there are any errors before we start, then stop here!
			$this->errors = array_merge($this->errors, $my_push->errors);
			return FALSE;		
		}
		
		//track if we have actually tried to push anything
		$done_push = FALSE;
		
		$my_push->sites_conf_path = $this->options->sites_conf;
		$my_push->dbs_conf_path = $this->options->dbs_conf;
		
		$my_push->source = $push_options['source'];
		$my_push->dest = $push_options['dest'];
		
		$my_push->dry_run = $push_options['dry_run'] ? TRUE : FALSE;
		$my_push->do_backup = $push_options['do_backup'] ? TRUE : FALSE;
		$my_push->source_backup_path = $this->options->backup_path;
		$my_push->dest_backup_path = $this->options->backup_path;

		$my_push->echo_output = TRUE;
		$my_push->output_level = defined('MRA_SITEPUSH_OUTPUT_LEVEL') ? MRA_SITEPUSH_OUTPUT_LEVEL : 0;
		
		//initialise some parameters
		$push_files = FALSE;
		$results = array(); //should be empty at end if everything worked as expected
		$db_types = array();
		
		//save various options which we don't want overwritten if we are doing a pull
		$current_options = get_option('mra_sitepush_options');
		$current_user_options = $this->get_all_user_options();
		$current_active_plugins = get_option('active_plugins');
		
	/* -------------------------------------------------------------- */
	/* !Push WordPress Files */
	/* -------------------------------------------------------------- */
		if( $push_options['push_uploads'] )
		{
			$push_files = TRUE;
			$my_push->push_uploads = TRUE;
		}
		
		if( $push_options['push_themes'] )
		{
			$push_files = TRUE;
			$my_push->push_themes = TRUE;
		}
		
		if( $push_options['push_theme'] && ! $push_options['push_themes'])
		{
			//pushes current (child) theme
			$push_files = TRUE;
			$themes = get_themes();
			$my_push->theme = $themes[ get_current_theme() ]['Stylesheet'];
		}
		
		if( $push_options['push_plugins'] )
		{
			$push_files = TRUE;
			$my_push->push_plugins = TRUE;
		}
		
		if( $push_options['push_wp_core'] )
		{
			$push_files = TRUE;
			$my_push->push_wp_files = TRUE;
		}
	
		//do the push
		if( $push_files )
		{
			$results[] = $my_push->push_files();
			$done_push = TRUE;
		}
	/* -------------------------------------------------------------- */
	/* !Push WordPress Database */
	/* -------------------------------------------------------------- */
		$db_push = FALSE;
		if( $push_options['db_all_tables'] )
		{
			//with no params entire DB is pushed
			$results[] = $my_push->push_db();
			$done_push = TRUE;
			$db_push = TRUE;
		}
		else
		{
			if( $push_options['db_post_content'] ) $db_types[] = 'content';
			if( $push_options['db_comments'] ) $db_types[] = 'comments';
			if( $push_options['db_users'] ) $db_types[] = 'users';
			if( $push_options['db_options'] ) $db_types[] = 'options';
		
			//do the push
			if( $db_types )
			{
				$results[] = $my_push->push_db( $db_types );
				$done_push = TRUE;
				$db_push = TRUE;
			}
		}
	/* -------------------------------------------------------------- */
	/* !Clear Cache */
	/* -------------------------------------------------------------- */
	
		if( $push_options['clear_cache'] && $this->options->cache_key )
		{
			$my_push->cache_key = urlencode( $this->options->cache_key );
			$my_push->clear_cache();
		}
		elseif( $push_options['clear_cache'] && ! $this->options->cache_key )
		{
			if( !$this->errors )
				$this->errors[] = "Push complete, but you tried to clear the destination cache and the cache secret key is not set.";
		}
		
	/* -------------------------------------------------------------- */
	/* !Other things to do */
	/* -------------------------------------------------------------- */
		//normally result should be empty - results to display are captured in class and output separately
		//if anything is output here it probably means something went wrong
		//clean the array of empty elements
		$cleaned_results = array();
		foreach( $results as $result )
		{
			if( trim($result) ) $cleaned_results[] = $result;
		}
	
		//save current site & user options back to DB so options on site we are pulling from won't overwrite
		if( $db_push && $this->options->get_current_site() == $push_options['dest'] )
		{
			$this->options->update( $current_options);

			$this->save_all_user_options( $current_user_options );

			//deactivating sitepush ensures that when we update option cached value isn't used
			//we reactivate again after this if clause just to make sure it's active
			deactivate_plugins(MRA_SITEPUSH_BASENAME);
			update_option( 'active_plugins', $current_active_plugins );
		}
		
		//make sure sitepush is still activated and save our options to DB so if we have pulled DB from elsewhere we don't overwrite sitepush options
		activate_plugin(MRA_SITEPUSH_BASENAME);
		$this->errors = array_merge($this->errors, $my_push->errors, $cleaned_results);

		return $this->errors ? FALSE : $done_push;
	}
	
	private function get_all_user_options()
	{
		global $wpdb;
		
		$results = array();
		
		//get options for all users with mra_sitepush_options usermeta
		foreach( get_users( "meta_key={$wpdb->prefix}mra_sitepush_options&fields=ID" ) as $user_id )
		{
			$results[$user_id] = get_user_option( 'mra_sitepush_options', $user_id );
		}

		return $results;
	}
	
	private function save_all_user_options( $user_opts=array() )
	{
		foreach( $user_opts as $user_id=>$options )
		{
			if( !$options ) continue;
			$options['last_update'] = microtime(TRUE); //make sure we aren't caching
			update_user_option( $user_id, 'mra_sitepush_options', $options );
		}
	}
	
	//clear cache for this site
	/**
	 * clear_cache
	 * 
	 * Clear cache(s) based on HTTP GET parameters. Allows another site to tell this site to clear its cache.
	 * Will only run if GET params include correct secret key, which is defined in SitePush options
	 *
	 * @return mixed result code, or FALSE if command/key not set
	 */
	function clear_cache()
	{

		//check $_GET to see if someone has asked us to clear the cache
		//for example a push from another server to this one
		$cmd = isset($_GET['mra_sitepush_cmd']) ? $_GET['mra_sitepush_cmd'] : FALSE;
		$key = isset($_GET['mra_sitepush_key']) ? $_GET['mra_sitepush_key'] : FALSE;

		//no command and/or key so return to normal WP initialisation
		if( !$cmd || !$key ) return FALSE;

		//do nothing if the secret key isn't correct
		$options = get_option('mra_sitepush_options');
		$result = '';

		if( $key <> urlencode( $options['cache_key'] ) )
		{
			status_header('403'); //return an HTTP error so we know cache clear wasn't successful
			$result .= "[1] Unrecognized cache key\n";
			die( trim( $result ) );
		}
	
		switch( $cmd )
		{
			case 'clear_cache':
				// Purge the entire w3tc page cache:
				if( function_exists('w3tc_pgcache_flush') )
				{
					/** @noinspection PhpUndefinedFunctionInspection */
					w3tc_pgcache_flush();
					/** @noinspection PhpUndefinedFunctionInspection */
					w3tc_dbcache_flush();
					/** @noinspection PhpUndefinedFunctionInspection */
					w3tc_minify_flush();
					/** @noinspection PhpUndefinedFunctionInspection */
					w3tc_objectcache_flush();
					$result .= "[0] W3TC cache cleared\n";
				}

				// Purge the entire supercache page cache:
				if( function_exists('wp_cache_clear_cache') )
				{
					/** @noinspection PhpUndefinedFunctionInspection */
					wp_cache_clear_cache();
					$result .= "[0] Supercache cleared\n";
				}

				break;

			default:
				$result .= "[2] Unrecognised cache command\n";
				status_header('400'); //return an HTTP error so we know cache clear wasn't successful
				break;
		}
		
		if( !$result ) $result = "[3] No supported cache present\n";
		
		die( trim( $result ) );
	}
	
	/**
	 * is_cache_plugin
	 * 
	 * is a plugin a cache plugin?
	 *
	 * @param string $plugin to test
	 * @return bool TRUE if it is a cache plugin
	 */
	private function is_cache_plugin( $plugin='' )
	{
		$cache_plugins = array(
				  'w3-total-cache/w3-total-cache.php'
				, 'wp-super-cache/wp-cache.php'
		);
			
		return in_array( trim($plugin), $cache_plugins );
	}
	

	/**
	 * activate_plugins_for_site
	 * 
	 * Make sure correct plugins are activated/deactivated for the site we are viewing.
	 * Will make sure cache plugin is always deactivated if WP_CACHE is not TRUE
	 *
	 * @return bool FALSE if options not set properly, otherwise TRUE
	 */
	function activate_plugins_for_site()
	{

		//check if settings OK
		if( ! $this->options->OK ) return FALSE;
		
		//make sure WP plugin code is loaded
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		if( !empty($this->options->current_site_conf['live']) )
		{
			//site is live so activate/deactivate plugins for live site(s) as per options
			foreach( $this->options->plugins['activate'] as $plugin )
			{
				//deactivate if it's a cache plugin but caching is turned off for this site
				if( $this->is_cache_plugin( $plugin ) && empty($this->options->current_site_conf['cache']) )
				{
					if( is_plugin_active($plugin) ) deactivate_plugins($plugin);
					continue;
				}
				
				//activate if necessary
				if( !is_plugin_active($plugin) ) activate_plugin($plugin);
			}
	
			foreach( $this->options->plugins['deactivate'] as $plugin )
			{
				if( is_plugin_active($plugin) ) deactivate_plugins($plugin);
			}
		}
		else
		{
			//activate/deactivate plugins for non-live site(s) as per opposite of options for live site(s)
			foreach( $this->options->plugins['deactivate'] as $plugin )
			{
				//deactivate if it's a cache plugin but caching is turned off for this site
				if( $this->is_cache_plugin( $plugin ) && empty($this->options->current_site_conf['cache']) )
				{
					if( is_plugin_active($plugin) ) deactivate_plugins($plugin);
					continue;
				}
						
				//activate if necessary
				if( !is_plugin_active($plugin) ) activate_plugin($plugin);
			}
	
			foreach( $this->options->plugins['activate'] as $plugin )
			{
				if( is_plugin_active($plugin) ) deactivate_plugins($plugin);
			}
		}

		return TRUE;
	}
	
	//removes activate/deactivate links for plugins controlled by sitepush
	function plugin_admin_override( $links, $file )
	{
		//check if settings OK
		if( !$this->options->OK ) return $links;
		
		$plugins = array_merge( $this->options->plugins['activate'], $this->options->plugins['deactivate'] );
		
		foreach( $plugins as $plugin )
		{
			if ( $file == $plugin )
			{
				if( array_key_exists('activate', $links) )
					$links['activate'] = "Deactivated by SitePush";
				elseif( array_key_exists('deactivate', $links) )
					$links['deactivate'] = "Activated by SitePush";
	
				return $links;
			}
		}
	
		return $links;
	}
	
	//@todo check admin only works
	//get all sites which are valid given current capability
	function get_sites( $exclude_current='no' )
	{
		$sites_list = array();
		
		$exclude = ('exclude_current'==$exclude_current) ? $this->get_current_site() : '';
	
		foreach( $this->options->sites as $site=>$site_conf )
		{
			if( $site<>$exclude && ($this->can_admin() || !$site_conf['admin_only']) )
				$sites_list[] = $site;
		}
		return $sites_list;
	}
	
	//equivalent to WP function get_query_var, but works in admin
	static public function get_query_var( $var )
	{
		return empty( $_REQUEST[ $var ] ) ? FALSE : $_REQUEST[ $var ];
	}
	

	//register all the settings
	//must be passed object for screen these settings are on
	private function register_options( $options_screen )
	{
		register_setting('mra_sitepush_options', 'mra_sitepush_options', array( &$this->options, 'options_sanitize') );

		if( empty($this->options->OK) )
		{
			/* General settings fields */
			add_settings_section(
				'mra_sitepush_section_warning',
				'Caution!',
				array( $options_screen, 'section_warning_text' ),
				'sitepush_options'
			);

			add_settings_field(
				'mra_sitepush_field_accept',
				'',
				array( $options_screen, 'field_accept' ),
				'sitepush_options',
				'mra_sitepush_section_warning'
			);
		}
	
		/* General settings fields */
		add_settings_section(
			'mra_sitepush_section_config',
			'General Configuration',
			array( $options_screen, 'section_config_text' ),
			'sitepush_options'	
		);
		
		add_settings_field(
			'mra_sitepush_field_sites_conf',
			'Full path to sites config file',
			array( $options_screen, 'field_sites_conf' ),
			'sitepush_options',
			'mra_sitepush_section_config'
		);
		
		add_settings_field(
			'mra_sitepush_field_dbs_conf',
			'Full path to dbs config file',
			array( $options_screen, 'field_dbs_conf' ),
			'sitepush_options',
			'mra_sitepush_section_config'
		);	
	
		add_settings_field(
			'mra_sitepush_field_timezone',
			'Timezone',
			array( $options_screen, 'field_timezone' ),
			'sitepush_options',
			'mra_sitepush_section_config'
		);
	
		/*Capability fields */
		add_settings_section(
			'mra_sitepush_section_capabilities',
			'SitePush Capabilities',
			array( $options_screen, 'section_capabilities_text' ),
			'sitepush_options'	
		);
	
		add_settings_field(
			'mra_sitepush_field_capability',
			'SitePush capability',
			array( $options_screen, 'field_capability' ),
			'sitepush_options',
			'mra_sitepush_section_capabilities'
		);
		
		add_settings_field(
			'mra_sitepush_field_admin_capability',
			'SitePush admin capability',
			array( $options_screen, 'field_admin_capability' ),
			'sitepush_options',
			'mra_sitepush_section_capabilities'
		);
	
		/* Cache option fields */
		add_settings_section(
			'mra_sitepush_section_cache',
			'Cache management',
			array( $options_screen, 'section_cache_text' ),
			'sitepush_options'	
		);
		add_settings_field(
			'mra_sitepush_field_cache_key',
			'Cache secret key',
			array( $options_screen, 'field_cache_key' ),
			'sitepush_options',
			'mra_sitepush_section_cache'
		);
	
	
		/* Plugin option fields */
		add_settings_section(
			'mra_sitepush_section_plugins',
			'Plugin management',
			array( $options_screen, 'section_plugins_text' ),
			'sitepush_options'	
		);
	
		add_settings_field(
			'mra_sitepush_field_plugins_activate',
			'Activate Plugins',
			array( $options_screen, 'field_plugin_activates' ),
			'sitepush_options',
			'mra_sitepush_section_plugins'
		);

		add_settings_field(
			'mra_sitepush_field_plugins_deactivate',
			'Deactivate Plugins',
			array( $options_screen, 'field_plugin_deactivates' ),
			'sitepush_options',
			'mra_sitepush_section_plugins'
		);
		
		/* Backup options */
		add_settings_section(
			'mra_sitepush_section_backup',
			'Backup options',
			array( $options_screen, 'section_backup_text' ),
			'sitepush_options'	
		);
		
		add_settings_field(
			'mra_sitepush_field_backup_path',
			'Path to backups directory',
			array( $options_screen, 'field_backup_path' ),
			'sitepush_options',
			'mra_sitepush_section_backup'
		);	
		
		add_settings_field(
			'mra_sitepush_field_backup_keep_time',
			'Days before backups deleted',
			array( $options_screen, 'field_backup_keep_time' ),
			'sitepush_options',
			'mra_sitepush_section_backup'
		);	


		/* rsync options */
		add_settings_section(
			'mra_sitepush_section_rsync',
			'rsync options',
			array( $options_screen, 'section_rsync_text' ),
			'sitepush_options'	
		);
		
		add_settings_field(
			'mra_sitepush_field_rsync_path',
			'Path to rsync',
			array( $options_screen, 'field_rsync_path' ),
			'sitepush_options',
			'mra_sitepush_section_rsync'
		);	

		add_settings_field(
			'mra_sitepush_field_dont_sync',
			'Exclude from sync',
			array( $options_screen, 'field_dont_sync' ),
			'sitepush_options',
			'mra_sitepush_section_rsync'
		);

		/* mysql options */
		add_settings_section(
			'mra_sitepush_section_mysql',
			'mysql options',
			array( $options_screen, 'section_mysql_text' ),
			'sitepush_options'
		);

		add_settings_field(
			'mra_sitepush_field_mysql_path',
			'Path to mysql',
			array( $options_screen, 'field_mysql_path' ),
			'sitepush_options',
			'mra_sitepush_section_mysql'
		);

		add_settings_field(
			'mra_sitepush_field_mysqldump_path',
			'Path to mysqldump',
			array( $options_screen, 'field_mysqldump_path' ),
			'sitepush_options',
			'mra_sitepush_section_mysql'
		);
	}
	
	/**
	 * show_warnings
	 * 
	 * Alert user to any SitePush related config errors.
	 * These errors are displayed anywhere in admin, to any SitePush admin user.
	 *
	 * @return void
	 */
	public function show_warnings()
	{
		$errors = array();

		//don't show warnings if user can't admin SitePush
		if( ! current_user_can( $this->options->admin_capability ) ) return;

		if( $error = $this->check_wp_config() )
			$errors[] = $error;
		
		if( $errors )
		    echo "<div id='my-custom-warning' class='error'><p>".implode( '<br />', $errors )."</p></div>";
	}

	private function check_wp_config()
	{
		$error = FALSE;
		if( empty($this->options->current_site_conf['cache']) && ( defined('WP_CACHE') && WP_CACHE ) )
				$error = "<b>SitePush Warning</b> - caching is turned off in your config file for this site, but WP_CACHE is defined as TRUE in your wp-config.php file. You should either change the setting in your config file ({$this->options->sites_conf}), or update wp-config.php.";
		elseif( !empty($this->options->current_site_conf['cache']) && ( !defined('WP_CACHE') || !WP_CACHE ) )
			$error = "<b>SitePush Warning</b> - caching is turned on in your config file for this site, but WP_CACHE is defined as FALSE or not defined in your wp-config.php file. You should either change the setting in your config file ({$this->options->sites_conf}), or update wp-config.php.";

		return $error;
	}

	/**
	 * get_wp_config_path
	 * 
	 * Gets full path of the site's wp-config.php file
	 *
	 * @return mixed full path to wp-config.php, FALSE if not found
	 */
	private function get_wp_config_path()
	{
		if( file_exists( ABSPATH . 'wp-config.php') )
			return ABSPATH . 'wp-config.php';
		elseif( file_exists( dirname(ABSPATH) . '/wp-config.php' ) )
			return dirname(ABSPATH) . '/wp-config.php';
		else
			return FALSE;
	}








}

/* EOF */