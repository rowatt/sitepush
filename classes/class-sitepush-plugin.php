<?php

class SitePushPlugin
{

	private static $instance = NULL;

	//major errors in initialisation will stop even options screen showing
	public $abort = FALSE;

	/**
	 * @var SitePushOptions
	 */
	public $options;
	
	private $min_wp_version = '3.3';
	private $min_php_version = '50200';

	/**
	 * Singleton instantiator
	 * @static
	 * @return SitePushPlugin
	 */
	public static function get_instance()
	{
		if( !self::$instance instanceof SitePushPlugin )
			self::$instance = new SitePushPlugin();

		return self::$instance;
	}

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

		//block login to certain sites by certain users
		add_filter('wp_authenticate_user', array( &$this, 'block_login') );
	}
	/**
	 * sets up plugin options and adds some hooks
	 * run by init hook
	 */
	public function plugin_init()
	{
		//include required files & instantiate classes
		require_once('class-sitepush-options.php');
		$this->options = SitePushOptions::get_instance();

		//add settings to plugin listing page
		if( ! $this->abort )
			add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_links'), 10, 2 );

		if( $this->options->OK & ! $this->abort )
		{
			//makes sure correct plugins activated/deactivated for site
			$this->activate_plugins_for_site();

			//clears cache if proper $_GET params set, otherwise does nothing
			$this->clear_cache();

			//override plugin activate/deactivate for plugins we are managing
			add_filter( 'plugin_action_links', array( &$this, 'plugin_admin_override'), 10, 2 );

			//content filters
			add_filter('the_content', array( &$this, 'fix_site_urls') );
		}

		if( SITEPUSH_DEBUG)
			SitePushErrors::add_error( "Warning: SitePush debug mode is enabled.", 'important' );

		//constant to show if we show multisite features
		//in future we may allow for not showing multisite features even if running in multisite mode
		define( 'SITEPUSH_SHOW_MULTISITE', is_multisite() );
	}

	/**
	 * Check core requirements met. Run during __construct.
	 */
	private function check_requirements()
	{
		if( version_compare( get_bloginfo( 'version' ), $this->min_wp_version, '<') )
			SitePushErrors::add_error( "SitePush requires at least WordPress version {$this->min_wp_version}", 'error' );

		if( is_multisite() && ! (defined('SITEPUSH_ALLOW_MULTISITE') && SITEPUSH_ALLOW_MULTISITE) )
			SitePushErrors::add_error( "Support for WordPress multisite installs is experimental.<br />If you wish to use SitePush on a multisite install, add define('SITEPUSH_ALLOW_MULTISITE',TRUE) to your wp-config.php file and proceed with caution!", 'fatal-error' );

		//get php version
		if (!defined('PHP_VERSION_ID'))
		{
			$php_version = explode('.', PHP_VERSION);
			define('PHP_VERSION_ID', ($php_version[0] * 10000 + $php_version[1] * 100 + $php_version[2]));
		}

		if( PHP_VERSION_ID < $this->min_php_version )
		{
			$major_v = intval($this->min_php_version/10000);
			$minor_v = intval($this->min_php_version/100) - $major_v*100;
			$release_v = $this->min_php_version - $major_v*10000 - $minor_v*100;
			SitePushErrors::add_error( "SitePush requires PHP version {$major_v}.{$minor_v}.{$release_v} or greater.", 'fatal-error' );
		}

		if( SitePushErrors::is_error() )
			$this->abort = TRUE;	
	}
	
	/**
	 * Delete SitePush and SitePush user options entry when plugin is deleted
	 *
	 * @static
	 */
	static public function uninstall()
	{
		global $wpdb;

		delete_option('sitepush_options');

		foreach( get_users( "meta_key={$wpdb->prefix}sitepush_options&fields=ID" ) as $user_id )
		{
			delete_user_option( $user_id, 'sitepush_options');
		}
	}

	/**
	 * Add settings to plugin listing page
	 * Called by plugin_action_links filter
	 *
	 * @static
	 * @param $links
	 * @param $file
	 * @return array
	 */
	static public function plugin_links( $links, $file )
	{
		if ( $file == SITEPUSH_BASENAME )
		{
			$add_link = '<a href="'.get_admin_url().'admin.php?page=sitepush_options">'.__('Settings').'</a>';
			array_unshift( $links, $add_link );
		}
		return $links;
	}
	
	/* -------------------------------------------------------------- */
	/* !INITIALISATION FUNCTIONS */
	/* -------------------------------------------------------------- */
	
	/**
	 * Set up the plugin options, plugin menus and help screens
	 * called by admin_menu action
	 *
	 * @return void
	 */
	public function register_options_menu_help()
	{
		//instantiate menu classes
		$push_screen = new SitePush_Push_Screen( $this );
		$options_screen = new SitePush_Options_Screen( $this );
		
		//register the settings
		$this->register_options( $options_screen );

		//if options aren't OK and user doesn't have admin capability don't add SitePush menus
		if( ! $this->can_admin() && ! $this->options->OK ) return;

		//make sure menus will always show for admin
		if( ! current_user_can( $this->options->capability ) && $this->can_admin() )
			$capability = $this->options->admin_capability;
		else
			$capability = $this->options->capability;
		
		if( ! current_user_can( $this->options->admin_capability ) && current_user_can( SitePushOptions::$fallback_capability ) )
			$admin_capability = SitePushOptions::$fallback_capability;
		else
			$admin_capability = $this->options->admin_capability;
		
		//add menu(s) - only options page is shown if not configured properly
		$page_title = 'SitePush';
		$menu_title = 'SitePush';
		$menu_slug = ($this->options->OK && ! $this->abort) ? 'sitepush' : 'sitepush_options';
		$function = ($this->options->OK && ! $this->abort) ? array( $push_screen, 'display_screen') : array( $options_screen, 'display_screen');
		$icon_url = SITEPUSH_PLUGIN_DIR_URL . '/img/icon-16.png';
		$position = 3;
		add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
	
		$parent_slug = $menu_slug;
		
		//add SitePush if options are OK
		if( $this->options->OK && !$this->abort)
		{	
			$page = add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function);
			add_action('admin_print_styles-' . $page, array( __CLASS__, 'admin_styles' ) ); //add custom stylesheet
			add_action('load-' . $page, array( $this, 'push_help' ) ); //add contextual help for main push screen
		}

		if( $this->can_admin() || $this->abort )
		{
			//add options page if we have admin capability
			$page_title = 'SitePush Options';
			$menu_title = 'Options';
			$menu_slug = 'sitepush_options';
			$function = array( $options_screen, 'display_screen');
			
			$page = add_submenu_page( $parent_slug, $page_title, $menu_title, $admin_capability, $menu_slug, $function);

			add_action('admin_print_styles-' . $page, array( __CLASS__, 'admin_styles' ) ); //add custom stylesheet
			add_action('load-' . $page, array( __CLASS__, 'options_help' ) ); //add contextual help for options screen
		}
	}

	/**
	 * Initialise things we need in admin
	 * Called by admin_init hook
	 *
	 * @static
	 */
	static public function admin_init()
	{
		wp_register_style( 'sitepush-styles', SITEPUSH_PLUGIN_DIR_URL.'/styles.css' );
	}
	
	/**
	 * Called by admin_print_styles-$page hook
	 * @static
	 */
	static public function admin_styles()
	{
		wp_enqueue_style( 'sitepush-styles' );
	}

	/**
	 * Add JS to page head in admin
	 * Called by admin_head hook
	 */
	public function add_plugin_js()
	{
		echo "<script type='text/javascript'>\n";
		echo "			jQuery(function($) {\n";
		$this->jq_update_source_dest();
		echo "			});\n";
		echo "</script>\n";
	}

	/**
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
			if( $.inArray( $("#sitepush_dest").find("option:selected").val(), liveSites ) > -1 )
				warnText = 'Caution - live site!';

		<?php //change button from Push<->Pull depending on destination ?>
			if( $("#sitepush_dest").find("option:selected").val() == "<?php echo $this->options->get_current_site(); ?>" )
				$('#push-button').val('Pull Content');
			else
				$('#push-button').val('Push Content');

		<?php //hide submit button if source/dest are same ?>
			if( $("#sitepush_dest").find("option:selected").val() == $("#sitepush_source").find("option:selected").val() )
			{
				$('#push-button').hide();
				warnText = 'Source and destination sites cannot be the same!';
			}
			else
			{
				$('#push-button').show();
			}

			$('#sitepush_dest-warning').text(warnText);
		};

		function update_cache_option() {
			if( $('#sitepush_dest').children('option:selected').attr('data-cache') == 'no' )
			{
				$('label[title=clear_cache]').addClass('disabled');
				$('input[name=clear_cache]').attr("disabled", "disabled");
				$('input[name=clear_cache]').removeAttr("checked");
			}
			else
			{
				$('input[name=clear_cache]').removeAttr("disabled");
				$('label[title=clear_cache]').removeClass('disabled');
			}
		}

		updateSourceDest();
		$(".site-selector").change(function() {
			updateSourceDest();
		});

		update_cache_option();
		$("#sitepush_dest").change(function() {
			update_cache_option();
		});
	<?php
	}

	
	/* -------------------------------------------------------------- */
	/* !CONTENT FILTERS */
	/* -------------------------------------------------------------- */
	
	/**
	 * Make sure that URLs to any defined site's domains link to the current site, so that links still work across versions of a site
	 *
	 * Called by the_content filter
	 *
	 * @param string
	 * @return string
	 */
	function fix_site_urls( $content='' )
	{
		if( !$this->options->fix_site_urls ) return $content;
		
		foreach( $this->options->all_domains as $domain )
		{
			$search = array( "http://{$domain}", "https://{$domain}" );
			$replace = array( "http://{$this->options->current_site_conf['domains'][0]}", "https://{$this->options->current_site_conf['domains'][0]}" );
			$content = str_ireplace( $search, $replace, $content );
		}
		
		return $content;
	}
	
	/* -------------------------------------------------------------- */
	/* !HELP FUNCTIONS */
	/* -------------------------------------------------------------- */

	/**
	 * Help for options screen
	 * Called by load-$page hook
	 *
	 * @static
	 */
	static public function options_help()
	{
		/**
		 * @var WP_Screen
		 */
		$screen = get_current_screen();

		$screen->add_help_tab( array(
		                            'id'      => 'sitepush-options-help-overview',
		                            'title'   => 'Overview',
		                            'content' => file_get_contents( SITEPUSH_PLUGIN_DIR.'/help/options.overview.html')
		                       ) );

		$screen->set_help_sidebar( "<p>More help and information is available from the <a href='http://wordpress.org/extend/plugins/sitepush/' target='_blank'>SitePush plugin page</a>.</p>" );
	}

	/**
	 * Help for push screen
	 * Called by load-$page hook
	 *
	 * @static
	 */
	public function push_help()
	{
		$screen = get_current_screen();

		if( $this->can_admin() )
		{
			$screen->add_help_tab( array(
			                            'id'      => 'sitepush-push-help',
			                            'title'   => 'Overview',
			                            'content' => file_get_contents( SITEPUSH_PLUGIN_DIR.'/help/sitepush.overview.admin.html'),
			                       ) );

			$screen->set_help_sidebar( "<p>More help and information is available from the <a href='http://wordpress.org/extend/plugins/sitepush/' target='_blank'>SitePush plugin page</a>.</p>" );
		}
		else
		{
			$screen->add_help_tab( array(
			                            'id'      => 'sitepush-push-help',
			                            'title'   => 'Overview',
			                            'content' => file_get_contents( SITEPUSH_PLUGIN_DIR.'/help/sitepush.overview.non-admin.html'),
			                       ) );
		}
	}

	/* -------------------------------------------------------------- */
	/* !SITEPUSH FUNCTIONS */
	/* -------------------------------------------------------------- */
	
	/**
	 * Is the current user a SitePush admin
	 *
	 * User is admin if, they have defined admin capability, a catchall 'fallback' capability
	 * User is not admin if we are in multisite mode and user does not have certain superadmin capabilities
	 *
	 * @return bool TRUE if user is SitePush admin, FALSE otherwise
	 */
	public function can_admin()
	{
		if( is_multisite() && ! ( is_super_admin() || current_user_can('manage_network') || current_user_can('manage_sites') || current_user_can('manage_network_options') ) )
			return FALSE;
		elseif( !empty($this->options->admin_capability) && current_user_can( $this->options->admin_capability ) )
			return TRUE;
		else
			return  current_user_can( SitePushOptions::$default_admin_capability );
	}
	
	/**
	 * Can the current user use SitePush
	 *
	 * @return bool TRUE if user can use SitePush, FALSE otherwise
	 */
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
	 * Run the push.
	 *
	 * This is where all options for SitePushCore are set, and the relevant pushes are run.
	 *
	 * @param SitePushCore $my_push
	 * @param array $push_options options for this push from $_REQUEST
	 * @return bool TRUE if push completed without errors, FALSE otherwise
	 */
	public function do_the_push( $my_push, $push_options )
	{
		//if we are going to do a push, check that we were referred from options page as expected
		check_admin_referer('sitepush-dopush','sitepush-nonce');

		//final check everything is OK
		$this->final_check( $my_push );

		if( SitePushErrors::count_errors('all-errors') )
			return FALSE;

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

		//initialise some parameters
		$push_files = FALSE;
		$results = array(); //should be empty at end if everything worked as expected
		$db_types = array();
		

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
			$my_push->theme = _deprecated_get_theme_stylesheet();
		}
		
		if( $push_options['push_plugins'] )
		{
			$push_files = TRUE;
			$my_push->push_plugins = TRUE;
		}

		if( $push_options['push_mu_plugins'] )
		{
			$push_files = TRUE;
			$my_push->push_mu_plugins = TRUE;
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
			$db_types[] = 'all_tables';
			$db_push = TRUE;
		}
		else
		{
			//we only check other options if we're not pushing whole DB
			if( $push_options['db_post_content'] ) $db_types[] = 'content';
			if( $push_options['db_comments'] ) $db_types[] = 'comments';
			if( $push_options['db_users'] ) $db_types[] = 'users';
			if( $push_options['db_options'] ) $db_types[] = 'options';
			if( $push_options['db_multisite_tables'] ) $db_types[] = 'multisite';
			if( $push_options['db_custom_table_groups'] ) $db_types[] = $push_options['db_custom_table_groups'];
		
			if( $db_types ) $db_push = TRUE;
		}

		$restore_options = FALSE;
		if( $db_push )
		{
			//save various options which we don't want overwritten if we are doing a pull
			$restore_options = ( $this->options->get_current_site() == $push_options['dest'] ) ;
			if( $restore_options )
			{
				$current_options = get_option('sitepush_options');
				$current_user_options = $this->get_all_user_options();
				$current_active_plugins = get_option('active_plugins');

				//if we don't delete the options before DB push then WP won't restore options properly if
				//option wasn't present in DB we are pulling from
				delete_option('sitepush_options');
				$this->delete_all_user_options();
			}

			//push DB
			$results[] = $my_push->push_db( $db_types );
			$done_push = TRUE;
		}

	/* -------------------------------------------------------------- */
	/* !Clear Cache */
	/* -------------------------------------------------------------- */
		if( $push_options['clear_cache'] && $this->options->cache_key )
			$my_push->clear_cache();
		elseif( $push_options['clear_cache'] && ! $this->options->cache_key )
			SitePushErrors::add_error( "Could not clear the destination cache because the cache secret key is not set.", 'warning' );

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
		if( $restore_options )
		{
			$this->options->update( $current_options );
			$this->save_all_user_options( $current_user_options );

			//deactivating sitepush ensures that when we update option cached value isn't used
			//we reactivate again after this if clause just to make sure it's active
			deactivate_plugins(SITEPUSH_BASENAME);
			update_option( 'active_plugins', $current_active_plugins );
		}
		
		//make sure sitepush is still activated and save our options to DB so if we have pulled DB from elsewhere we don't overwrite sitepush options
		activate_plugin(SITEPUSH_BASENAME);

		return SitePushErrors::is_error() ? FALSE : $done_push;
	}

	/**
	 * Run last minute final checks immediately before doing a push
	 *
	 * @param SitePushCore $my_push
	 * @return void
	 */
	private function final_check( $my_push )
	{
		//check if source and dest DB are actually the same DB
		if( $this->options->dbs[ $my_push->source_params['db'] ]['name'] == $this->options->dbs[ $my_push->dest_params['db'] ]['name'] )
			SitePushErrors::add_error( "Unable to push - both sites use the same database ({$this->options->dbs[ $my_push->dest_params['db'] ]['name']})", 'error' );
	}

	/**
	 * Get SitePush user options for all users who have SitePush user meta options set
	 *
	 * @return array array of SitePush user option arrays
	 */
	private function get_all_user_options()
	{
		global $wpdb;
		
		$results = array();
		
		foreach( get_users( "meta_key={$wpdb->prefix}sitepush_options&fields=ID" ) as $user_id )
		{
			$results[$user_id] = get_user_option( 'sitepush_options', $user_id );
		}

		return $results;
	}

	/**
	 * Delete SitePush user options for all users who have SitePush user meta options set
	 *
	 * @return array array of SitePush user option arrays
	 */
	private function delete_all_user_options()
	{
		global $wpdb;

		$results = array();

		foreach( get_users( "meta_key={$wpdb->prefix}sitepush_options&fields=ID" ) as $user_id )
		{
			$results[$user_id] = delete_user_option( 'sitepush_options', $user_id );
		}

		return $results;
	}

	/**
	 * Set SitePush user options for many users
	 *
	 * @param array $user_opts array of SitePush user option arrays
	 */
	private function save_all_user_options( $user_opts=array() )
	{
		foreach( $user_opts as $user_id=>$options )
		{
			if( !$options ) continue;
			$options['last_update'] = microtime(TRUE); //make sure we aren't caching
			update_user_option( $user_id, 'sitepush_options', $options );
		}
	}
	
	/**
	 * Clear cache(s) based on HTTP GET parameters. Allows another site to tell this site to clear its cache.
	 * Will only run if GET params include correct secret key, which is defined in SitePush options
	 *
	 * @return mixed result code echoed to screen, or FALSE if command/key not set
	 */
	private function clear_cache()
	{

		//check $_GET to see if someone has asked us to clear the cache
		//for example a push from another server to this one
		$cmd = isset($_GET['sitepush_cmd']) ? $_GET['sitepush_cmd'] : FALSE;
		$key = isset($_GET['sitepush_key']) ? $_GET['sitepush_key'] : FALSE;

		//no command and/or key so return to normal WP initialisation
		if( !$cmd || !$key ) return FALSE;

		//do nothing if the secret key isn't correct
		$options = get_option('sitepush_options');
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
	 * Is a plugin a cache plugin?
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
	 * Make sure correct plugins are activated/deactivated for the site we are viewing.
	 * Will make sure cache plugin is always deactivated if WP_CACHE is not TRUE
	 *
	 * @return bool FALSE if options not set properly, otherwise TRUE
	 */
	private function activate_plugins_for_site()
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

	/**
	 * Remove activate/deactivate links for plugins controlled by sitepush
	 * Called by plugin_action_links hook
	 *
	 * @param $links
	 * @param $file
	 * @return array
	 */
	public function plugin_admin_override( $links, $file )
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

	/**
	 * Get all sites which are valid given current capability
	 *
	 * @param string $context only return sites in this context, source or destination
	 * @param string $exclude exclude certain sites. current=exclude current site we are on
	 *
	 * @return array
	 */
	public function get_sites( $context='', $exclude='' )
	{
		$sites_list = array();
		$exclude_current = ('current'==$exclude) ? $this->options->get_current_site() : '';
	
		foreach( $this->options->sites as $site=>$site_conf )
		{
			//exclude current site if required
			if( $site==$exclude_current ) continue;

			//if not admin, exclude sites limited to only source/dest context
			if( !$this->can_admin() )
			{
				if( 'destination'==$context && !empty($site_conf['source_only']) ) continue;
				if( 'source'==$context && !empty($site_conf['destination_only']) ) continue;
			}
			$sites_list[] = $site;
		}
		return $sites_list;
	}

	/**
	 * Equivalent to WP function get_query_var, but works in admin
	 *
	 * @static
	 * @param $var
	 * @return mixed (string) value for query var, or FALSE if query var not present
	 */
	static public function get_query_var( $var )
	{
		return empty( $_REQUEST[ $var ] ) ? FALSE : $_REQUEST[ $var ];
	}
	
	/**
	 * Register all the settings used by SitePush
	 *
	 * @param $options_screen WP_Screen object for screen these settings are on
	 */
	private function register_options( $options_screen )
	{
		register_setting('sitepush_options', 'sitepush_options', array( &$this->options, 'options_sanitize') );

		if( empty($this->options->OK) )
		{
			/* General settings fields */
			add_settings_section(
				'sitepush_section_warning',
				'Caution!',
				array( $options_screen, 'section_warning_text' ),
				'sitepush_options'
			);

			add_settings_field(
				'sitepush_field_accept',
				'',
				array( $options_screen, 'field_accept' ),
				'sitepush_options',
				'sitepush_section_warning'
			);
		}
	
		/* General settings fields */
		add_settings_section(
			'sitepush_section_config',
			'General Configuration',
			array( $options_screen, 'section_config_text' ),
			'sitepush_options'	
		);
		
		add_settings_field(
			'sitepush_field_sites_conf',
			'Full path to sites config file',
			array( $options_screen, 'field_sites_conf' ),
			'sitepush_options',
			'sitepush_section_config'
		);

		add_settings_field(
			'sitepush_field_dbs_conf',
			'Full path to dbs config file',
			array( $options_screen, 'field_dbs_conf' ),
			'sitepush_options',
			'sitepush_section_config'
		);

		if( SITEPUSH_SHOW_MULTISITE )
			add_settings_field(
				'sitepush_field_domain_map_conf',
				'Full path to domain map file',
				array( $options_screen, 'field_domain_map_conf' ),
				'sitepush_options',
				'sitepush_section_config'
			);

		add_settings_field(
			'sitepush_field_fix_site_urls',
			'Fix site URLs',
			array( $options_screen, 'field_fix_site_urls' ),
			'sitepush_options',
			'sitepush_section_config'
		);

		add_settings_field(
			'sitepush_field_timezone',
			'Timezone',
			array( $options_screen, 'field_timezone' ),
			'sitepush_options',
			'sitepush_section_config'
		);

		add_settings_field(
			'sitepush_field_debug_output_level',
			'Debug output level',
			array( $options_screen, 'field_debug_output_level' ),
			'sitepush_options',
			'sitepush_section_config'
		);

		/*Capability fields */
		add_settings_section(
			'sitepush_section_capabilities',
			'SitePush Capabilities',
			array( $options_screen, 'section_capabilities_text' ),
			'sitepush_options'	
		);
	
		add_settings_field(
			'sitepush_field_capability',
			'SitePush capability',
			array( $options_screen, 'field_capability' ),
			'sitepush_options',
			'sitepush_section_capabilities'
		);
		
		add_settings_field(
			'sitepush_field_admin_capability',
			'SitePush admin capability',
			array( $options_screen, 'field_admin_capability' ),
			'sitepush_options',
			'sitepush_section_capabilities'
		);

		add_settings_field(
			'sitepush_field_only_admins_login_to_live',
			'Live site login',
			array( $options_screen, 'field_only_admins_login_to_live' ),
			'sitepush_options',
			'sitepush_section_capabilities'
		);

		add_settings_field(
			'sitepush_field_non_admin_exclude_comments',
			'Restrict non-admin capabilities',
			array( $options_screen, 'field_non_admin_exclude_comments' ),
			'sitepush_options',
			'sitepush_section_capabilities'
		);

		add_settings_field(
			'sitepush_field_non_admin_exclude_options',
			'',
			array( $options_screen, 'field_non_admin_exclude_options' ),
			'sitepush_options',
			'sitepush_section_capabilities'
		);

		/* Cache option fields */
		add_settings_section(
			'sitepush_section_cache',
			'Cache management',
			array( $options_screen, 'section_cache_text' ),
			'sitepush_options'	
		);
		add_settings_field(
			'sitepush_field_cache_key',
			'Cache secret key',
			array( $options_screen, 'field_cache_key' ),
			'sitepush_options',
			'sitepush_section_cache'
		);
	
	
		/* Plugin option fields */
		add_settings_section(
			'sitepush_section_plugins',
			'Plugin management',
			array( $options_screen, 'section_plugins_text' ),
			'sitepush_options'	
		);
	
		add_settings_field(
			'sitepush_field_plugins_activate',
			'Activate Plugins',
			array( $options_screen, 'field_plugin_activates' ),
			'sitepush_options',
			'sitepush_section_plugins'
		);

		add_settings_field(
			'sitepush_field_plugins_deactivate',
			'Deactivate Plugins',
			array( $options_screen, 'field_plugin_deactivates' ),
			'sitepush_options',
			'sitepush_section_plugins'
		);
		
		/* Backup options */
		add_settings_section(
			'sitepush_section_backup',
			'Backup options',
			array( $options_screen, 'section_backup_text' ),
			'sitepush_options'	
		);
		
		add_settings_field(
			'sitepush_field_backup_path',
			'Path to backups directory',
			array( $options_screen, 'field_backup_path' ),
			'sitepush_options',
			'sitepush_section_backup'
		);	
		
		add_settings_field(
			'sitepush_field_backup_keep_time',
			'Days before backups deleted',
			array( $options_screen, 'field_backup_keep_time' ),
			'sitepush_options',
			'sitepush_section_backup'
		);	

		/* Custom DB tables option fields */
		add_settings_section(
			'sitepush_section_db_custom_table_groups',
			'Custom DB table groups',
			array( $options_screen, 'section_db_custom_table_groups_text' ),
			'sitepush_options'
		);
		add_settings_field(
			'sitepush_field_db_custom_table_groups',
			'Custom DB table groups',
			array( $options_screen, 'field_db_custom_table_groups' ),
			'sitepush_options',
			'sitepush_section_db_custom_table_groups'
		);

		/* rsync options */
		add_settings_section(
			'sitepush_section_rsync',
			'Sync options',
			array( $options_screen, 'section_rsync_text' ),
			'sitepush_options'	
		);


		add_settings_field(
			'sitepush_field_dont_sync',
			'Exclude from sync',
			array( $options_screen, 'field_dont_sync' ),
			'sitepush_options',
			'sitepush_section_rsync'
		);

		add_settings_field(
			'sitepush_field_rsync_path',
			'Path to rsync',
			array( $options_screen, 'field_rsync_path' ),
			'sitepush_options',
			'sitepush_section_rsync'
		);	

		/* mysql options */
		add_settings_section(
			'sitepush_section_mysql',
			'mysql options',
			array( $options_screen, 'section_mysql_text' ),
			'sitepush_options'
		);

		add_settings_field(
			'sitepush_field_mysql_path',
			'Path to mysql',
			array( $options_screen, 'field_mysql_path' ),
			'sitepush_options',
			'sitepush_section_mysql'
		);

		add_settings_field(
			'sitepush_field_mysqldump_path',
			'Path to mysqldump',
			array( $options_screen, 'field_mysqldump_path' ),
			'sitepush_options',
			'sitepush_section_mysql'
		);

		/* Debug stuff */
		if( SITEPUSH_DEBUG )
		{
			add_settings_section(
				'sitepush_section_debug',
				'Debug',
				array( $options_screen, 'section_debug_text' ),
				'sitepush_options'
			);
			add_settings_field(
				'sitepush_field_debug_custom_code',
				'Custom debug code',
				array( $options_screen, 'field_debug_custom_code' ),
				'sitepush_options',
				'sitepush_section_debug'
			);
		}
	}
	
	/**
	 * Alert user to any SitePush related config errors.
	 * These errors are displayed anywhere in admin, to any SitePush admin user.
	 *
	 * @return void
	 */
	public function show_warnings()
	{
		//don't show warnings if user can't admin SitePush
		if( ! current_user_can( $this->options->admin_capability ) ) return;

		$error = $this->check_wp_config();

		if( $error )
		    echo "<div id='sitepush-error' class='error'><p>{$error}</p></div>";
	}

	/**
	 * Check that current WP config is OK for SitePush
	 *
	 * Currently only checks that WP_Cache is set correctly according to config for current site
	 *
	 * @return string any errors found
	 */
	private function check_wp_config()
	{
		$error = '';
		if( empty($this->options->current_site_conf['cache']) && ( defined('WP_CACHE') && WP_CACHE ) )
			$error = "<b>SitePush Warning</b> - caching is turned off in your config file for this site, but WP_CACHE is defined as TRUE in your wp-config.php file. You should either change the setting in your config file ({$this->options->sites_conf}), or update wp-config.php.";
		elseif( !empty($this->options->current_site_conf['cache']) && ( !defined('WP_CACHE') || !WP_CACHE ) )
			$error = "<b>SitePush Warning</b> - caching is turned on in your config file for this site, but WP_CACHE is defined as FALSE or not defined in your wp-config.php file. You should either change the setting in your config file ({$this->options->sites_conf}), or update wp-config.php.";

		return $error;
	}

	/**
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


	/**
	 * Block login to a live site for non admin users, if option is set to do that.
	 *
	 * When in multisite mode, this blocks login from all users apart from Super Admins.
	 *
	 * @param $userdata
	 * @return WP_Error
	 */
	public function block_login( $userdata )
	{
		//no config for current site, so allow login
		if( empty($this->options->current_site_conf) )
			return $userdata;

		//we're not blocking logins to live sites
		if( ! $this->options->only_admins_login_to_live )
			return $userdata;

		//it's not a live site, so never block login
		if( !$this->options->current_site_conf['live'] )
			return $userdata;

		//multisite super admins can always login
		if( is_multisite() && is_super_admin( $userdata->ID ) )
			return $userdata;

		//not multisite & user has admin capability, so allow login
		if( !is_multisite() &&	user_can( $userdata->ID, $this->options->admin_capability ) )
			return $userdata;

		//not allowed to login, so throw error and block login
		return new WP_Error('login_blocked', __('You cannot login to this site. Please contact the site admin for more information.'));
	}
}

/* EOF */