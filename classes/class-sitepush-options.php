<?php

class SitePushOptions
{
	private static $instance = NULL;

	//set to true once we have enough options set OK
	public $OK = FALSE;

	public $sites = array();
	public $dbs = array();
	public $db_prefix = ''; //prefix for tables in all DBs
	private $current_site = ''; //access through get_current_site method
	public $current_site_conf = array();
	public $all_domains = array();
	public $use_cache = FALSE; //remember if any site uses cache


	//default capabilities required to use SitePush
	public static $default_capability = 'install_plugins';
	public static $default_admin_capability = 'install_plugins';
	public static $fallback_capability = 'manage_sitepush_options'; //user with this capability will always be able to access options

	//options which need keeping when user updates options
	private $keep_options = array( 'accept', 'last_update' );
	//parameters which get initialised and get whitespace trimmed
	private $trim_params = array('sites_conf', 'dbs_conf', 'timezone', 'debug_output_level', 'capability', 'admin_capability', 'cache_key', 'plugin_activates', 'plugin_deactivates', 'backup_path', 'backup_keep_time', 'rsync_path', 'dont_sync', 'mysql_path', 'mysqldump_path');
	//parameters which just get initialised
	private $no_trim_params = array('accept', 'fix_site_urls', 'only_admins_login_to_live');
	private $site_params = array( 'label', 'name', 'web_path', 'db', 'live', 'default', 'cache', 'caches', 'domain', 'domains', 'wp_dir' );
	private $all_params; //set in __construct

	//options - these come from WordPress option sitepush_options
	public $accept;
	public $sites_conf = '';
	public $dbs_conf = '';
	public $timezone;
	public $debug_output_level;

	public $capability;
	public $admin_capability;
	public $only_admins_login_to_live; //if TRUE only users with admin_capability can log into a site flagges as 'live'

	public $cache_key;

	public $plugins;

	public $backup_path;
	public $backup_keep_time;

	public $rsync_path;
	public $dont_sync;

	public $mysql_path;
	public $mysqldump_path;

	public $fix_site_urls;

	//Internal options - can only be changed here
	public $mask_passwords = TRUE; //mask passwords from results log

	/**
	 * Singleton instantiator
	 * @static
	 * @return SitePushOptions
	 */
	public static function get_instance()
	{
		if( !self::$instance instanceof SitePushOptions )
		{
			self::$instance = new SitePushOptions();
		}

		return self::$instance;
	}

	private function __construct()
	{
		$this->all_params = array_merge( $this->trim_params, $this->no_trim_params );

		$options = get_option( 'sitepush_options' );

		//make sure all options set & initialise WP options if necessary
		if( !$options || !is_array($options) )
		{
			$options = $this->options_init();
			$this->update( $options );
		}
		else
		{
			$options = $this->options_init( $options );
		}

		//set object properties according to options
		foreach( $options as $option=>$value)
		{
			$this->$option = $value;
		}

		if( !$this->options_validate( $options ) ) return FALSE;

		//initialise & validate db configs
		$dbs_conf = $this->get_conf( $this->dbs_conf, 'DB ' );
		$sites_conf = $this->get_conf( $this->sites_conf, 'Sites ' );
		if( SitePushErrors::is_error() ) return FALSE;
		$this->dbs = $this->dbs_init( $dbs_conf );
		$this->sites = $this->sites_init( $sites_conf );
		if( SitePushErrors::is_error() ) return FALSE;
	
		//set current site
		$this->current_site_init();
		if( SitePushErrors::is_error() ) return FALSE;

		//final validation once everything setup
		if( !$this->final_validate() ) return FALSE;

		//no errors so everything appears to be OK
		$this->OK = TRUE;
		return TRUE;
	}
	
	/**
	 * Update plugin options in WP DB.
	 *
	 * @param array $options
	 * @return void
	 */
	public function update( $options=array() )
	{
		if( is_object($options) )
			$options = (array) $options;
	
		//microtime ensures that options are written and don't use cached value
		$update_options['last_update'] = microtime(TRUE);
		
		foreach( $this->all_params as $param )
		{
			$update_options[ $param ] = empty($options[ $param ]) ? NULL : $options[ $param ];
		}

		if( !empty($options['plugins']['activate']) )
			$update_option['plugin_activates'] = implode( "\n", $options['plugins']['activate'] );
		if( !empty($options['plugins']['deactivate']) )
			$update_option['plugin_deactivates'] = implode( "\n", $options['plugins']['deactivate'] );

		unset( $options['plugins'] );

		update_option( 'sitepush_options', $update_options );
	}


	/* --------------------------------------------------------------
	/* !INITIALISE & VALIDATE OPTIONS
	/* -------------------------------------------------------------- */
		
	/**
	 * Initialise options so that all array keys present,
	 * make sure various arrays set up. If an option isn't present
	 * then set it to default.
	 *
	 * @param array $options
	 * @return array $options initialised options
	 */
	private function options_init( $options=array() )
	{
		//accept risks
		if( !array_key_exists( 'accept', $options ) ) $options['accept'] = FALSE;

		//General parameters
		if( !array_key_exists( 'sites_conf', $options ) ) $options['sites_conf'] = '';
		if( !array_key_exists( 'dbs_conf', $options ) ) $options['dbs_conf'] = '';
		if( !array_key_exists( 'timezone', $options ) ) $options['timezone'] = '';
		if( !array_key_exists( 'debug_output_level', $options ) ) $options['debug_output_level'] = 0;

		//checkbox params - can only initialise to FALSE or else they are always set to TRUE whatever user wants
		if( !array_key_exists( 'fix_site_urls', $options ) ) $options['fix_site_urls'] = FALSE;
		if( !array_key_exists( 'only_admins_login_to_live', $options ) ) $options['only_admins_login_to_live'] = FALSE;

		//Capabilities
		if( empty($options['capability']) ) $options['capability'] = self::$default_capability;
		if( empty($options['admin_capability']) ) $options['admin_capability'] = self::$default_admin_capability;

		//Cache management
		if( !array_key_exists( 'cache_key', $options ) ) $options['cache_key'] = '';

		//Plugin management
		if( !array_key_exists('plugins',          $options) )            $options['plugins']                     = array();
		if( !array_key_exists('never_manage',     $options['plugins']) ) $options['plugins']['never_manage']     = array(); //only used internally, not user settable

		$plugin_activates = array();

		//set options for plugins to activate on live sites
		if( !empty($options['plugin_activates']) )
		{
			foreach( explode("\n",$options['plugin_activates']) as $plugin )
			{
				$plugin = trim( $plugin );
				if( !$plugin || in_array($plugin, $plugin_activates) || in_array($plugin, $options['plugins']['never_manage']) ) continue; //empty line or duplicate
				$plugin_activates[] = $plugin;
			}
			asort($plugin_activates);
			$options['plugins']['activate'] = $plugin_activates;
		}
		else
		{
			$options['plugins']['activate'] = array();
		}

		//set options for plugins to deactivate on live sites
		if( !empty($options['plugin_deactivates']) )
		{
			$plugin_deactivates = array();
			foreach( explode("\n",$options['plugin_deactivates']) as $plugin )
			{
				$plugin = trim( $plugin );
				if( !$plugin || in_array($plugin, $plugin_deactivates) || in_array($plugin, $plugin_activates) || in_array($plugin, $options['plugins']['never_manage']) ) continue; //empty line or duplicate
				$plugin_deactivates[] = $plugin;
			}
			asort($plugin_deactivates);
			$options['plugins']['deactivate'] = $plugin_deactivates;
		}
		else
		{
			$options['plugins']['deactivate'] = array();
		}

		//Backup Options
		if( !array_key_exists( 'backup_path', $options ) ) $options['backup_path'] = '';
		if( !array_key_exists( 'backup_keep_time', $options ) || ''==$options['backup_keep_time'] ) $options['backup_keep_time'] = 10;

		//rsync options
		if( !array_key_exists( 'rsync_path', $options ) )
			$options['rsync_path'] = $this->guess_path( 'rsync' );
		if( !array_key_exists('dont_sync', $options) )
			$options['dont_sync'] = '.git, .svn, .htaccess, tmp/, wp-config.php';

		//mysql options
		if( !array_key_exists( 'mysql_path', $options ) )
			$options['mysql_path'] = $this->guess_path( 'mysql' );
		if( !array_key_exists( 'mysqldump_path', $options ) )
			$options['mysqldump_path'] = $this->guess_path( 'mysqldump' );

		//other non-user settable options
		if( empty($options['sitepush_version']) )
			$options['sitepush_version'] = $this->get_plugin_version();

		return $options;
	}

	/**
	 * Clean options by trimming whitespace
	 *
	 * @param array $options
	 * @return array $options cleaned options
	 */
	private function options_clean( $options=array() )
	{
		foreach( $this->trim_params as $trim_opt )
		{
			$options[$trim_opt] = trim( $options[$trim_opt] );
		}

		return $options;
	}

	/**
	 * Try to determine where rsync/mysql/mysqldump is on this system.
	 *
	 * @param string $type rsync, mysql or mysqld
	 * @return string best guess for rsync patgh
	 */
	private function guess_path( $type )
	{
		$paths = array();
		switch( $type )
		{
			case 'rsync':
				if( preg_match( '|(/[^ ]*)|', `whereis rsync`, $matches ) )
					$paths[] = $matches[1];
				$paths = array_merge( $paths, array('/usr/local/bin/rsync', '/usr/bin/rsync' ) );
				break;

			case 'mysql':
				if( preg_match( '|(/[^ ]*)|', `whereis mysql`, $matches ) )
					$paths[] = $matches[1];
				$paths = array_merge( $paths, array( '/Applications/MAMP/Library/bin/mysql', '/usr/local/bin/mysql', '/usr/bin/mysql' ) );
				break;

			case 'mysqldump':
				if( preg_match( '|(/[^ ]*)|', `whereis mysqldump`, $matches ) )
					$paths[] = $matches[1];
				$paths = array_merge( $paths, array( '/Applications/MAMP/Library/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/bin/mysqldump' ) );
				break;
		}

		$path = '';
		foreach( $paths as $path )
		{
			if( file_exists($path) ) break;
		}

		return $path;
	}

	/**
	 * Validate config options, setting errors as appropriate.
	 *
	 * This is called when options are updated from settings screen, generating errors as appropriate,
	 * and when plugin is initialised, in which case errors not generated and capabilities not checked.
	 *
	 * @param array $options options to validated
	 * @param bool $update_check if FALSE, only validate, no error reporting and don't validate capabilities
	 * @return bool TRUE if options OK, FALSE otherwise
	 */
	private function options_validate( &$options=array(), $update_check = TRUE )
	{
		//if nothing is configured we don't validate, but no error generated
		if( empty( $options ) )
			return FALSE;

		$valid = TRUE;

		if( empty($options['accept']) )
		{
			if( $update_check ) SitePushErrors::add_error( 'You must accept the warning before using SitePush.', 'error', 'accept' );
			$valid = FALSE;
		}

		if( empty( $options['sites_conf'] ) || !file_exists( $options['sites_conf'] ) )
		{
			if( $update_check ) SitePushErrors::add_error( 'Path not valid - sites config file not found.', 'error', 'sites_conf' );
			$valid = FALSE;
		}

		if( empty( $options['dbs_conf'] ) ||  !file_exists( $options['dbs_conf'] ) )
		{
			if( $update_check ) SitePushErrors::add_error( 'Path not valid - DB config file not found.', 'error', 'dbs_conf' );
			$valid = FALSE;
		}

		if( !empty($options['sites_conf']) && !empty($options['dbs_conf']) && $options['dbs_conf'] == $options['sites_conf'] )
		{
			if( $update_check ) SitePushErrors::add_error( 'Sites and DBs config files cannot be the same file.', 'error', 'dbs_conf' );
			$valid = FALSE;
		}

		if( !empty($options['backup_path']) && !file_exists( $options['backup_path'] ) )
		{
			if( $update_check ) SitePushErrors::add_error( 'Path not valid - backup directory not found.', 'error', 'backup_path' );
			$valid = FALSE;
		}

		if( $options['rsync_path'] && !file_exists( $options['rsync_path'] ) )
		{
			if( $update_check ) SitePushErrors::add_error( 'Path not valid - rsync not found.', 'error', 'rsync_path' );
			$valid = FALSE;
		}

		if( $options['mysql_path'] && !file_exists( $options['mysql_path'] ) )
		{
			if( $update_check ) SitePushErrors::add_error( 'Path not valid - mysql not found.', 'error', 'mysql_path' );
			$valid = FALSE;
		}

		if( $options['mysqldump_path'] && !file_exists( $options['mysqldump_path'] ) )
		{
			if( $update_check ) SitePushErrors::add_error( 'Path not valid - mysqldump not found.', 'error', 'mysqldump_path' );
			$valid = FALSE;
		}

		if( !empty( $options['timezone'] ) )
		{
			@$tz=timezone_open( $options['timezone'] );
			if( FALSE===$tz )
			{
				if( $update_check ) SitePushErrors::add_error( "{$options['timezone']} is not a valid timezone. See <a href='http://php.net/manual/en/timezones.php' target='_blank'>list of supported timezones</a> for valid values.", 'error', 'timezone' );
				$valid = FALSE;
			}
		}

		//Make sure current admin has whatever capabilities are required for SitePush
		//we need to use WP settings_error API here, because error is fixed before SitePushErrors can report it
		if( $update_check )
		{
			if( !current_user_can( $options['capability']) )
			{
				$error = "SitePush capability ({$options['capability']}) cannot be a capability which you do not have. It has been reset to ".self::$default_capability.".";
				SitePushErrors::force_show_wp_errors();
				SitePushErrors::add_error( $error, 'error', 'capability' );
				if( function_exists('add_settings_error') )
					add_settings_error( 'sitepush', 'sitepush-capability-error', $error );
				$options['capability'] = self::$default_capability;
			}
			if( !current_user_can( $options['admin_capability']) )
			{
				$error = "SitePush admin capability ({$options['admin_capability']}) cannot be a capability which you do not have. It has been reset to ".self::$default_admin_capability.".";
				SitePushErrors::force_show_wp_errors();
				SitePushErrors::add_error( $error, 'error', 'admin-capability' );
				if( function_exists('add_settings_error') )
					add_settings_error( 'sitepush', 'sitepush-admin-capability-error', $error );
				$options['admin_capability'] = self::$default_capability;
			}
		}

		return $valid && !SitePushErrors::is_error();
	}

	/**
	 * Final validation after all params etc have been set, setting errors as appropriate.
	 *
	 * This is called when options are updated from settings screen, generating errors as appropriate, @todo
	 * and when plugin is initialised, in which case errors not generated and capabilities not checked.
	 *
	 * @return bool TRUE if options OK, FALSE otherwise
	 */
	private function final_validate()
	{
		//check wp_content dir
		$current_content_dir = $this->current_site_conf['web_path'].$this->current_site_conf['wp_content_dir'];
		if( WP_CONTENT_DIR <> $current_content_dir )
			SitePushErrors::add_error( "Warning - currently configured WordPress content directory (".WP_CONTENT_DIR.") is different from the configured uploads directory in your sites config file ($current_content_dir)", 'warning' );

		//check uploads dir
		$uld = wp_upload_dir();
		$current_uld = $this->current_site_conf['web_path'].$this->current_site_conf['wp_uploads_dir'];
		if( $uld['basedir'] <> $current_uld )
			SitePushErrors::add_error( "Warning - currently configured WordPress uploads directory ({$uld['basedir']}) is different from the configured uploads directory in your sites config file ($current_uld)", 'warning' );


		//check plugins dir
		$current_plugins_dir = $this->current_site_conf['web_path'].$this->current_site_conf['wp_plugin_dir'];
		if( WP_PLUGIN_DIR <> $current_plugins_dir )
			SitePushErrors::add_error( "Warning - currently configured WordPress plugins directory (".WP_PLUGIN_DIR.") is different from the configured plugins directory in your sites config file ($current_plugins_dir)", 'warning' );

		//check themes dir
		$current_themes_dir = $this->current_site_conf['web_path'].$this->current_site_conf['wp_themes_dir'];
		if( WP_CONTENT_DIR . '/themes' <> $current_themes_dir )
			SitePushErrors::add_error( "Warning - currently configured WordPress themes directory (".WP_CONTENT_DIR."/themes) is different from the configured themes directory in your sites config file ($current_themes_dir)", 'warning' );

		return ! SitePushErrors::is_error();
	}

	/**
	 * Called by register_setting when options are updated
	 *
	 * @param array $options
	 * @return array sanitized options
	 */
	public function options_sanitize( $options=array() )
	{
		$options = $this->options_keep( $options ); //keep options which would otherwise be lost when settings api updates options
		$options = $this->options_clean( $options );
		$options = $this->options_init( $options ); //makes sure certain array keys are set
		$this->options_validate( $options );

		$options['sitepush_version'] = $this->get_plugin_version();

		return $options;
	}

	/**
	 * Make sure any options we want kept aren't removed when options updated by settings API
	 * (i.e. when admin saves options)
	 *
	 * @param array $options
	 * @return array
	 */
	private function options_keep( $options=array() )
	{
		foreach( $this->keep_options as $option )
		{
			if( !array_key_exists( $option, $options ) && isset($this->$option) )
				$options[ $option ] = $this->$option;
		}
		return $options;
	}
	
	/* --------------------------------------------------------------
	/* !INITIALISE & VALIDATE SITE CONFIGS
	/* -------------------------------------------------------------- */
	
	/**
	 * Get a config from an ini file.
	 * If ini file has a section 'all' then those settings are applied to all other sections
	 *
	 * @param string $conf_file path to the conf file in php ini format
	 * @param string $type type of config file (for error messages)
	 * @return array settings from conf file
	 */
	private function get_conf( $conf_file='', $type='' )
	{
		if( !$conf_file ) return array();

		if( !file_exists($conf_file) )
		{
			SitePushErrors::add_error( "{$type} config file not found at {$conf_file}" );
			return array();
		}
		//get site info from the sites.conf file
		$configs = parse_ini_file($conf_file,TRUE);
		
		//check if conf file has 'all' section and if so merge that config with config for each other section	
		if( !empty( $configs['all'] ) )
		{
			$config_all = $configs['all'];
			unset( $configs['all'] );
			
			foreach( $configs  as $section=>$config )
			{
				$configs[$section] = array_merge( $config_all, $configs[$section] );
			}
	
		}

		return $configs;
	}

	/**
	 * Initialise configs for all sites
	 *
	 * @param array $sites
	 * @return array initialised & validated $sites
	 */	
	private function sites_init( $sites=array() )
	{
		if( !$sites )
		{
			SitePushErrors::add_error( "No sites defined in your sites config file." );
			return array();
		}

		//make sure certain sites options set correctly
		foreach( $sites as $site=>$params )
		{
			$params['name'] = $site;
			$sites[$site] = $this->site_init( $params );
		}

		$this->sites_validate( $sites );

		//make sure we only have one of each domain
		$this->all_domains = array_unique( $this->all_domains, SORT_STRING);

		return $sites;
	}

	/**
	 * Initialise params & set defaults for a single site
	 *
	 * @param array $options params for site
	 * @return array params with defaults set
	 */
	private function site_init( $options=array() )
	{
		//make sure all params initialised, and non-params removed
		$options = $this->init_params( $options, $this->site_params );

		if( array_key_exists('domains',$options) && empty($options['domain']) )
			$options['domain'] = $options['domains'][0];

		if( empty($options['domains']) )
			$options['domains'][0] = $options['domain'];

		//save all domains in array
		$this->all_domains = array_unique( array_merge( $this->all_domains, $options['domains'], (array) $options['domain'] ) );

		//make sure certain optional params are set correctly
		if( !$options['label'] ) $options['label'] = $options['name'];
		if( empty($options['admin_only']) ) $options['admin_only'] = FALSE;
		if( empty($options['wp_dir']) ) $options['wp_dir'] = '';
		if( empty($options['wp_content_dir']) ) $options['wp_content_dir'] = '/wp-content';
		if( empty($options['wp_plugin_dir']) ) $options['wp_plugin_dir'] = $options['wp_content_dir'] . '/plugins';
		if( empty($options['wp_uploads_dir']) ) $options['wp_uploads_dir'] = $options['wp_content_dir'] . '/uploads';
		if( empty($options['wp_themes_dir']) ) $options['wp_themes_dir'] = $options['wp_content_dir'] . '/themes';

		//remember if any site has caching turned on
		$options['use_cache'] = (bool) $options['cache'] || (bool) $options['caches'];
		$this->use_cache = $this->use_cache || $options['use_cache'];

		return $options;
	}
	
	/**
	 * Determine which site we are currently running on and set $this->current site accordingly
	 *
	 * @param array $sites configs for all sites. Defaults to $this->sites.
	 * @return bool TRUE if current site set OK, FALSE otherwise.
	 */
	private function current_site_init( $sites=array() )
	{
		if( !$sites )
			$sites = $this->sites;
		
		if( !$sites )
		{
			SitePushErrors::add_error( "No sites defined in your sites config file." );
			return array();
		}

		$current_site = '';
		$default = '';
		
		foreach( $sites as $site=>$site_conf )
		{
			if( !empty( $site_conf['domain'] ) )
				$site_conf['domains'][] = $site_conf['domain'];
			
			//check if this site is the default and remember if it is
			if( !empty($site_conf['default']) )
			{
				if( $default )
					SitePushErrors::add_error( "The sites {$default} and {$site} cannot both be set as the default site." );
				$default = $site;
			}
			
			if( in_array( $_SERVER['SERVER_NAME'], $site_conf['domains'] ) )
			{
				if( $current_site )
					SitePushErrors::add_error( "The sites {$current_site} and {$site} both have the same domains." );
				$current_site = $site;
			}
		}
		
		//we didn't recognise the URL, so assume we are in the default site
		if( !$current_site )
			$current_site = $default;
		
		if( !$current_site )
			SitePushErrors::add_error( "This site ({$_SERVER['SERVER_NAME']}) is not recognised and you have not set a default in your sites config. Please make sure the domain or domains[] parameters are set to {$_SERVER['SERVER_NAME']} for one site, or that one site is set as the default site." );

		$this->current_site = $current_site;

		$this->current_site_conf = $this->sites[ $current_site ];

		return (bool) $current_site;
	}

	/**
	 * Get all parameters for specific site
	 *
	 * @param string $site
	 * @return array parameters for site
	 */
	public function get_site_params( $site )
	{
		if( array_key_exists($site,$this->sites) )
			return $this->sites[$site];
		else
			return array();
	}

	/**
	 * Get the name of the current site
	 *
	 * @return string name of current site (empty if current site not determined)
	 */	
	public function get_current_site()
	{
		if( !$this->current_site )
			$this->current_site_init();
			
		return $this->current_site;
	}
	
	/**
	 * Validates configs for sites
	 *
	 * @param array $sites
	 * @return bool TRUE if validated
	 */	
	private function sites_validate( $sites=array() )
	{
		if( !$sites )
		{
			SitePushErrors::add_error( "No sites defined in your sites config file." );
			return array();
		}
		$validated = TRUE;
	
		foreach( $sites as $site )
		{
			$validated = $this->site_validate( $site ) && $validated;
		}
		
		if( count( $sites ) < 2 )
		{
			SitePushErrors::add_error( "You must have at least 2 sites defined in your sites config file." );
			$validated = FALSE;
		}
	
		return $validated;	
	}

	/**
	 * Validates config for single site
	 *
	 * @param array $params site parameters
	 * @return bool TRUE if validated
	 */	
	private function site_validate( $params=array() )
	{
		$errors = FALSE;

		if( empty($params['web_path']) )
		{
			SitePushErrors::add_error( "Required parameter web_path is missing from config for site <i>{$params['label']}</i>." );
			$errors = TRUE;
		}
		elseif( !file_exists($params['web_path']) )
		{
			//@later this will need changing when we add remote sites
			SitePushErrors::add_error( "The web path for site <i>{$params['label']}</i> ({$params['web_path']}) does not exist or is not accessible." );
			$errors = TRUE;
		}

		if( empty($params['db']) )
		{
			SitePushErrors::add_error( "Required parameter db is missing from config for site <i>{$params['label']}</i>." );
			$errors = TRUE;
		}

		if( !array_key_exists($params['db'], $this->dbs) )
		{
			SitePushErrors::add_error( "Database <i>{$params['db']}</i> in config for site <i>{$params['label']}</i> is not defined in database config file." );
			$errors = TRUE;
		}

		return $errors;
	}

	/* --------------------------------------------------------------
	/* !INITIALISE & VALIDATE DB CONFIGS
	/* -------------------------------------------------------------- */

	/**
	 * Initialise & validate DB configs
	 *
	 * @param array $dbs
	 * @return array initialised & validated $dbs array
	 */	
	private function dbs_init( $dbs=array() )
	{
		if( !$dbs )
		{
			SitePushErrors::add_error( "No databases defined in dbs config file." );
			return array();
		}

		//make sure db options set correctly
		foreach( $dbs as $db=>$params )
		{
			$dbs[$db] = $this->db_init( $params, $db );
		}

		return $dbs;
	}

	/**
	 * Initialise & validate a single DB config
	 *
	 * @param array $params
	 * @param string $name label of DB for error reporting
	 * @return array initialised & validated $params array
	 */	
	private function db_init( $params, $name='' )
	{
		global $wpdb;

		//DB prefix is set directly from WP global setting
		$this->db_prefix = $wpdb->prefix;

		$this->db_validate( $params, $name );
		return $params;
	}
	
	/**
	 * Validate a single DB config
	 *
	 * @param array $params
	 * @param string $name label of DB for error reporting
	 * @sets $this->errors if any errors encountered
	 * @return bool TRUE if validated
	 */
	private function db_validate( $params, $name='' )
	{
		$errors = FALSE;
		
		$requireds = array( 'name', 'user', 'pw' );
		
		foreach( $requireds as $required )
		{
			if( empty($params[$required]) )
			{
				SitePushErrors::add_error( "Required parameter <i>{$required}</i> is missing from config for database <i>{$name}</i>." );
				$errors = TRUE;
			}
		}

		return $errors;
	}

	/**
	 * Gets database params for a specific site
	 *
	 * @param string $site
	 * @return array DB config for site
	 */	
	private function get_db_params_for_site( $site )
	{
		if( !array_key_exists($site, $this->sites) )
			SitePushErrors::add_error( 'Tried to get DB params for site {$site}, but site does not exist.', 'fatal-error' );
		
		return $this->dbs[ $this->sites[$site]['db'] ];
	}

	/**
	 * Get parameters for a specific database
	 *
	 * @param string $db name of database settings
	 * @return array
	 */
	public function get_db_params( $db )
	{
		if( array_key_exists($db,$this->dbs) )
		{
			$result = $this->dbs[$db];
			$result['label'] = $db;
		}
		else
		{
			$result = array();
		}

		return $result;
	}

	/* --------------------------------------------------------------
	/* ! Support methods
	/* -------------------------------------------------------------- */

	/**
	 * Initialises a parameter array, making sure required keys exist
	 *
	 * @param array $options the options array to initialise
	 * @param array $params list of required parameters
	 * @return array initialised parameters
	 */
	private function init_params( $options=array(), $params=array() )
	{
		foreach( $params as $param )
		{
			if( !array_key_exists($param, $options) )
				$options[ $param ] = '';
		}
		return $options;
	}

	/**
	 * Get version of this plugin
	 *
	 * @return string plugin version
	 */
	public function get_plugin_version()
	{
		if( function_exists( 'get_plugin_data' ) )
		{
			$pd=get_plugin_data( WP_PLUGIN_DIR .'/' . SITEPUSH_BASENAME );
			return $pd['Version'];
		}
		else
		{
			$options = get_option( 'sitepush_options' );
			return empty($options['sitepush_version']) ? '' : $options['sitepush_version'];
		}
	}

}

/* EOF */