<?php

class SitePushOptions
{

	//set to true once we have enough options set OK
	public $OK = FALSE;

	public $errors = array();
	public $sites = array();
	public $dbs = array();
	private $current_site = ''; //access through get_current_site method
	public $current_site_conf = array();
	public $all_domains = array();
	public $sites_conf = '';
	public $dbs_conf = '';
	private $source_params = array();
	private $dest_params = array();

	//default capabilities required to use SitePush
	public static $default_capability = 'manage_options';
	public static $default_admin_capability = 'manage_options';
	public static $fallback_capability = 'manage_options'; //user with this capability will always be able to access options

	//options which need keeping when user updates options
	private $keep_options = array( 'accept', 'last_update' );
	//parameters which get initialised and get whitespace trimmed
	private $trim_params = array('plugin_activates', 'plugin_deactivates', 'sites_conf', 'dbs_conf', 'backup_path', 'backup_keep_time', 'rsync_path', 'dont_sync', 'capability', 'admin_capability', 'cache_key', 'timezone');
	//parameters which just get initialised
	private $no_trim_params = array('accept', 'make_relative_urls');
	private $init_params; //set in __construct

	//options - these come from WP option mra_sitepush_options
	public $accept;
	public $capability;
	public $admin_capability;
	public $backup_path;
	public $cache_key;
	public $plugins;
	public $make_relative_urls; //@todo to add to WP options
	public $rsync_path;
	public $dont_sync;
	public $backup_keep_time;
	public $timezone;

	function __construct()
	{
		$this->init_params = array_merge( $this->trim_params, $this->no_trim_params );

		$options = get_option( 'mra_sitepush_options' );

		//make sure all options set & initialise WP options if necessary
		if( !$options || !is_array($options) )
		{
			$options = $this->options_init();
			$this->update( $options );
		}

		$options = $this->options_clean( $options );

		//set object properties according to options
		foreach( $options as $option=>$value)
		{
			$this->$option = $value;
		}

		if( !$this->options_validate( $options ) ) return FALSE;

		//initialise & validate db configs
		$dbs_conf = $this->get_conf( $this->dbs_conf, 'DB ' );
		$this->dbs = $this->dbs_init( $dbs_conf );
		if( $this->errors ) return FALSE;
	
		//initialise & validate site configs
		$sites_conf = $this->get_conf( $this->sites_conf, 'Sites ' );
		$this->sites = $this->sites_init( $sites_conf );
		if( $this->errors ) return FALSE;
		
		//set current site
		$this->current_site_init();
		if( $this->errors ) return FALSE;

		//no errors so everything appears to be OK
		$this->OK = TRUE;
		return TRUE;
	}
	
	/**
	 * update
	 * 
	 * Updates plugin options in WP DB.
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
		
		foreach( $this->init_params as $param )
		{
			$update_options[ $param ] = $options[ $param ];
		}

		if( !empty($options['plugins']['activate']) )
			$update_option['plugin_activates'] = implode( "\n", $options['plugins']['activate'] );
		if( !empty($options['plugins']['deactivate']) )
			$update_option['plugin_deactivates'] = implode( "\n", $options['plugins']['deactivate'] );

		update_option( 'mra_sitepush_options', $update_options );
	}
	
	//xxxxx check this!!!
	
/* --------------------------------------------------------------
/* !INITIALISE & VALIDATE OPTIONS
/* -------------------------------------------------------------- */
		
	/**
	 * options_init
	 * 
	 * Initialise options so that all array keys present.
	 * Will not overwrite any options which already exist.
	 *
	 * @param array $options
	 * @return array $options initialised options
	 */
	private function options_init( $options=array() )
	{
		//plugin defaults
		if( !array_key_exists('plugins',          $options) )            $options['plugins']                     = array();
		if( !array_key_exists('activate',         $options['plugins']) ) $options['plugins']['activate']         = array();
		if( !array_key_exists('deactivate',       $options['plugins']) ) $options['plugins']['deactivate']       = array();
		if( !array_key_exists('never_manage',     $options['plugins']) ) $options['plugins']['never_manage']     = array();

		//other defaults
		if( !array_key_exists('backup_keep_time', $options) || ''==$options['backup_keep_time'] ) $options['backup_keep_time'] = 10;
		if( empty($options['capability']) ) $options['capability'] = self::$default_capability;
		if( empty($options['admin_capability']) ) $options['admin_capability'] = self::$default_admin_capability;

		//defaults for what not to sync
		if( !array_key_exists('dont_sync', $options) )
			$options['dont_sync'] = '.git, .svn, .htaccess, tmp/, wp-config.php';

		if( empty($options['rsync_path']) )
			$options['rsync_path'] = $this->guess_rsync_path();

		//make sure any other parameters exist
		$options = $this->init_params( $options, $this->init_params );

		return $options;
	}

	/**
	 * options_clean
	 * 
	 * Clean options, and make sure some keys are set,
	 * convert string options (from user options) to arrays
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

		$plugin_activates = array();
		$plugin_deactivates = array();

		//make sure plugin options arrays exist
		if( empty($options['plugins']) )
			$options['plugins'] = array();
		if( empty($options['plugins']['activate']) )
			$options['plugins']['activate'] = array();
		if( empty($options['plugins']['deactivate']) )
			$options['plugins']['deactivate'] = array();
		if( empty($options['plugins']['never_manage']) )
			$options['plugins']['never_manage'] = array();

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
			unset( $options['plugin_activates'] );		
			$options['plugins']['activate'] = $plugin_activates;
		}

		//set options for plugins to deactivate on live sites
		if( !empty($options['plugin_deactivates']) )
		{
			foreach( explode("\n",$options['plugin_deactivates']) as $plugin )
			{
				$plugin = trim( $plugin );
				if( !$plugin || in_array($plugin, $plugin_deactivates) || in_array($plugin, $plugin_activates) || in_array($plugin, $options['plugins']['never_manage']) ) continue; //empty line or duplicate
				$plugin_deactivates[] = $plugin;
			}
			asort($plugin_deactivates);
			unset( $options['plugin_deactivates'] );		
			$options['plugins']['deactivate'] = $plugin_deactivates;
		}

		if( empty($options['rsync_path']) )
			$options['rsync_path'] = $this->guess_rsync_path();

		return $options;
	}

	/**
	 * guess_rsync_path
	 *
	 * Tries to determine where rsync is on this system.
	 *
	 * @return string best guess for rsync patgh
	 */
	private function guess_rsync_path()
	{
		$whereis_path = trim( str_ireplace('rsync:', '', `whereis -b rsync`) );
		$rsync_paths = array($whereis_path, '/usr/local/bin/rsync', '/usr/bin/rsync' );
		$rsync_path = '';
		foreach( $rsync_paths as $rsync_path )
		{
			if( file_exists($rsync_path) ) break;
		}

		return $rsync_path;
	}


	/**
	 * options_validate
	 * 
	 * Validate config options, setting errors as appropriate. This is called when options are updated
	 * from settings screen.
	 *
	 * @param array $options options to validated
	 * @sets array $this->errors adds any error messages to array
	 * @return bool TRUE if options OK, FALSE otherwise
	 */
	function options_validate( &$options=array() )
	{
		//if nothing is configured we don't validate, but no error generated
		if( empty( $options ) )
			return FALSE;

		$errors = array();

		if( empty($options['accept']) )
			$errors['accept'] = 'You must accept the warning before using SitePush.';

		if( empty( $options['sites_conf'] ) || !file_exists( $options['sites_conf'] ) )
			$errors['sites_conf'] = 'Path not valid - sites config file not found.';

		if( empty( $options['dbs_conf'] ) ||  !file_exists( $options['dbs_conf'] ) )
			$errors['dbs_conf'] = 'Path not valid - DB config file not found.';
		
		if( !empty($options['sites_conf']) && !empty($options['dbs_conf']) && $options['dbs_conf'] == $options['sites_conf'] )
			$errors['dbs_conf'] = 'Sites and DBs config files cannot be the same file.';
	
		if( !empty($options['backup_path']) && !file_exists( $options['backup_path'] ) )
			$errors['backup_path'] = 'Path not valid - backup directory not found.';

		if( empty($options['rsync_path']) || !file_exists( $options['rsync_path'] ) )
			$errors['rsync_path'] = 'Path not valid - rsync not found.';

		if( !empty( $options['timezone'] ) )
		{
			@$tz=timezone_open( $options['timezone'] );
			if( FALSE===$tz )
			{
				$errors['timezone'] = "{$options['timezone']} is not a valid timezone. See <a href='http://php.net/manual/en/timezones.php' target='_blank'>list of supported timezones</a> for valid values.";
			}
		}

		//Make sure current admin has whatever capabilities are required for SitePush
		if( !current_user_can( $options['capability']) )
		{
			$errors['capability'] = "SitePush capability ({$options['capability']}) cannot be a capability which you do not have.";
			$options['capability'] = self::$default_capability;
		}
		if( !current_user_can( $options['admin_capability']) )
		{
			$errors['admin_capability'] = "SitePush admin capability ({$options['admin_capability']}) cannot be a capability which you do not have.";
			$options['admin_capability'] = self::$default_capability;
		}

		$this->errors = $errors;

		return ! (bool) $errors;
	}

	/**
	 * options sanitize
	 *
	 * called by register_setting when options are updated
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

		//add any errors to WP settings errors
		//the function hasn't loaded when plugin initialises, so only add if function exists
		if( $this->errors )
		{
			//$this->errors = array_merge( $this->errors, $errors );
			foreach( $this->errors as $field=>$error )
			{
				add_settings_error('mra-sitepush', "{$field}-error", $error, 'error');
			}

			$this->errors = array();
		}

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
	 * get_conf
	 * 
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
			//add_settings_error( 'mra-sitepush', '{$type}-config-error', "{$type} config file not found at {$conf_file}\n");
			$this->OK = FALSE;
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
	 * sites_init
	 * 
	 * Initialise configs for all sites
	 *
	 * @param array $sites
	 * @return array initialised & validated $sites
	 */	
	private function sites_init( $sites=array() )
	{
		if( !$sites )
		{
			//add_settings_error( 'mra-sitepush', 'sites-config-error', "No sites defined.\n");
			$this->OK = FALSE;
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
	 * site_init
	 * 
	 * Initialise params & set defaults for a single site
	 *
	 * @param array $params params for site
	 * @return array params with defaults set
	 */
	public function site_init( $params=array() )
	{
		//make sure all params initialised, and non-params removed
		$params = $this->init_params( $params, array( 'label', 'name', 'web_path', 'db', 'live', 'default', 'cache', 'domain', 'domains', 'wp_dir' ) );

		if( array_key_exists('domains',$params) && empty($params['domain']) )
			$params['domain'] = $params['domains'][0];

		//save all domains in array
		$this->all_domains = array_merge( $this->all_domains, $params['domains'], (array) $params['domain'] );

		//make sure certain optional params are set correctly
		if( !$params['label'] ) $params['label'] = $params['name'];
		if( empty($params['wp_content_dir']) ) $params['wp_content_dir'] = '/wp-content';
		if( empty($params['wp_plugins_dir']) ) $params['wp_plugins_dir'] = $params['wp_content_dir'] . '/plugins';
		if( empty($params['wp_uploads_dir']) ) $params['wp_uploads_dir'] = $params['wp_content_dir'] . '/uploads';
		if( empty($params['wp_themes_dir']) ) $params['wp_themes_dir'] = $params['wp_content_dir'] . '/themes';
		
		return $params;	
	}
	


	/**
	 * current_site_init
	 * 
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
			//add_settings_error( 'mra-sitepush', 'sites-config-error', "No sites defined.\n");
			$this->OK = FALSE;
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
					$this->errors[] = "The sites {$default} and {$site} cannot both be set as the default site.";
				$default = $site;
			}
			
			if( in_array( $_SERVER['SERVER_NAME'], $site_conf['domains'] ) )
			{
				if( $current_site )
					$this->errors[] = "The sites {$current_site} and {$site} both have the same domains.";
				$current_site = $site;
			}
		}
		
		//we didn't recognise the URL, so assume we are in the default site
		if( !$current_site )
			$current_site = $default;
		
		if( !$current_site )
			$this->errors[]="This site ({$_SERVER['SERVER_NAME']}) is not recognised and you have not set a default in your sites config. Please make sure the domain or domains[] parameters are set to {$_SERVER['SERVER_NAME']} for one site, or that one site is set as the default site.";

		$this->current_site = $current_site;

		$this->current_site_conf = $this->sites[ $current_site ];

		return (bool) $current_site;
	}

	/**
	 * get_current_site
	 * 
	 * Gets the name of the current site
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
	 * sites_validate
	 * 
	 * Validates configs for sites
	 *
	 * @param array $sites
	 * @return bool TRUE if validated
	 */	
	private function sites_validate( $sites=array() )
	{
		if( !$sites )
		{
			//add_settings_error( 'mra-sitepush', 'sites-config-error', "No sites defined.\n");
			$this->OK = FALSE;
			return array();
		}
		$validated = TRUE;
	
		foreach( $sites as $site )
		{
			$validated = $validated && $this->site_validate( $site );
		}
		
		if( count( $sites ) < 2 )
		{
			$this->errors[] = "You must have at least 2 sites defined in your sites config file.";
			$validated = FALSE;
		}
	
		return $validated;	
	}

	/**
	 * site_validate
	 * 
	 * Validates config for single site
	 *
	 * @param array $params site parameters
	 * @return bool TRUE if validated
	 */	
	private function site_validate( $params=array() )
	{
		$errors = array();
		
		if( empty($params['web_path']) )
			$errors[] = "Required parameter web_path is missing from config for site {$params['name']}.";

		//@later this will need changing when we add remote sites
		if( !file_exists($params['web_path']) )
			$errors[] = "The web path for site {$params['name']} ({$params['web_path']}) does not exist or is not accessible.";

		if( empty($params['db']) )
			$errors[] = "Required parameter db is missing from config for site {$params['name']}.";
		
		if( !array_key_exists($params['db'], $this->dbs) )
				$errors[] = "Database {$params['db']} in config for {$params['name']} is not defined in database config file.";
		
		if( $errors )
			$this->errors = array_merge($this->errors, $errors);
		
		return (bool) $errors;
	}

/* --------------------------------------------------------------
/* !INITIALISE & VALIDATE DB CONFIGS
/* -------------------------------------------------------------- */

	/**
	 * dbs_init
	 * 
	 * Initialise & validate DB configs
	 *
	 * @param array $dbs
	 * @return array initialised & validated $dbs array
	 */	
	private function dbs_init( $dbs=array() )
	{
		if( !$dbs )
		{
			//add_settings_error( 'mra-sitepush', 'dbs-config-error', "No databases defined.\n");
			$this->OK = FALSE;
			return array();
		};

		//make sure db options set correctly
		foreach( $dbs as $db=>$params )
		{
			$dbs[$db] = $this->db_init( $params, $db );
		}

		return $dbs;
	}

	/**
	 * db_init
	 * 
	 * Initialise & validate a single DB config
	 *
	 * @param array $params
	 * @param string $name label of DB for error reporting
	 * @return array initialised & validated $params array
	 */	
	private function db_init( $params, $name='' )
	{
		$this->db_validate( $params, $name );
		return $params;
	}
	
	/**
	 * db_validate
	 * 
	 * Validate a single DB config
	 *
	 * @param array $params
	 * @param string $name label of DB for error reporting
	 * @sets $this->errors if any errors encountered
	 * @return bool TRUE if validated
	 */
	private function db_validate( $params, $name='' )
	{
		$errors = array();
		
		$requireds = array( 'name', 'user', 'pw', 'prefix' );
		
		foreach( $requireds as $required )
		{
			if( empty($params[$required]) )
				$errors[] = "Required parameter {$required} is missing from config for database {$name}.";
		}

		if( $errors )
			$this->errors = array_merge( $this->errors, $errors );

		return (bool) $errors;
	}

	/**
	 * get_db_params_for_site
	 * 
	 * Gets database params for a specific site
	 *
	 * @param string $site
	 * @return array DB config for site
	 */	
	public function get_db_params_for_site( $site )
	{
		if( !array_key_exists($site, $this->sites) )
			wp_die('ERROR: Tried to get DB params for site {$site}, but site does not exist.');
		
		return $this->dbs[ $this->sites[$site]['db'] ];
	}

	
	/**
	 * init_params
	 * 
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
	
/* -------------------------------------------------------------- */
/* !	ORIG METHOS */
/* -------------------------------------------------------------- */

	//@todo check this works
	//figure out which of our sites is currently running
/*	function get_current_site()
	{
		$this_site = '';
		$default = '';
		
		foreach( $this->options['sites'] as $site=>$site_conf )
		{
			if( !empty( $site_conf['domain'] ) )
				$site_conf['domains'][] = $site_conf['domain'];
			
			//check if this site is the default and remember if it is
			if( $site_conf['default'] )
				$default = $site;
		
			if( in_array( $_SERVER['SERVER_NAME'], $site_conf['domains'] ) )
			{
				//we found a match so we know what site we are on
				$this_site = $site;
				break;
			}
		}
		
		//we didn't recognise the URL, so assume we are in the default site
		if( !$this_site )
			$this_site = $default;
		
		if( $this_site )
		{
			return $this_site;
		}
		else
		{
			$this->errors[]="<div id='mra_sitepush_site_error' class='error settings-error'>This site ({$_SERVER['SERVER_NAME']}) is not recognised and you have not set a default in sites.conf. Please configure sites.conf with the domain of this site, or set a default.</div>";
			$this->options['ok'] = FALSE;
		}
	}
*/
	//make sure we have all options set and valid
/*	function xxxoptions_init()
	{
		//get options from DB
		$this->options = array_merge( (array) get_option( 'mra_sitepush_options' ), $this->options );
	
		//activate/deactivate plugin options for live site(s)
		//for non-live sites plugins are switched to the opposite state
		if( !empty($this->options['plugin_activates']) )
			$this->options['plugins']['activate'] = explode("\n",trim($this->options['plugin_activates']));
		else
			$this->options['plugins']['activate'] = array();

		if( !empty($this->options['plugin_deactivates']) )
			$this->options['plugins']['deactivate'] = explode("\n",trim($this->options['plugin_deactivates']));
		else
			$this->options['plugins']['deactivate'] = array();			
		
		//never manage these plugins
		$this->options['plugins']['never_manage'] = array();
		
		
		//get options from WP_DB & validate all user set params
		$this->options = $this->validate_options( $this->options );
		$this->validate_conf_files();
		if( !empty( $this->options['notices']['errors'] ) )
		{
			//one or more options not OK, so stop here and leave SitePush inactive
			$this->options['ok'] = FALSE;
			return FALSE;
		}
	
		//make sure certain sites options set correctly
		foreach( $this->options['sites'] as $site=>$params )
		{
			$this->options['sites'][ $site ]['label'] = empty( $params['label'] ) ? $site : $params['label'];
			$this->options['sites'][ $site ]['default'] = empty( $this->options['default_push'] ) ? $params['default'] : $this->options['default_push'];
			$this->options['sites'][ $site ]['admin_only'] =  empty( $params['sitepush_admin_only'] ) ? FALSE : $params['sitepush_admin_only'];
			$this->options['sites'][ $site ]['name'] =  $site;
		}
	
		$this->options['current_site'] = $this->options['sites'][ $this->get_current_site() ];
	
		//all options OK, so plugin can do its stuff!
		$this->options['ok'] = TRUE;
		
		return $this->options;
	}
*/	
	/* -------------------------------------------------------------- */
	/* SitePush options field validation */
/*	function validate_options( $options )
	{
		$errors = array();
		
		if( empty( $options ) )
		{
			//no options have been set, so this is a fresh config
			$options['ok'] = FALSE;
			$options['notices']['errors'][] = '<b>Please configure SitePush</b>';
			return $options;
		}

		if( ! array_key_exists('accept', $options) )
			$errors['accept'] = 'You must accept the warning before using SitePush.';
		
		if( array_key_exists('sites_conf', $options) ) $options['sites_conf'] = trim( $options['sites_conf'] );
		if( empty( $options['sites_conf'] ) || !file_exists( $options['sites_conf'] ) )
			$errors['sites_conf'] = 'Path not valid - sites config file not found.';
			
		if( array_key_exists('dbs_conf', $options) ) $options['dbs_conf'] = trim( $options['dbs_conf'] );
		if( empty( $options['dbs_conf'] ) ||  !file_exists( $options['dbs_conf'] ) )
			$errors['dbs_conf'] = 'Path not valid - DB config file not found.';
		
		if( !empty($options['sites_conf']) && !empty($options['dbs_conf']) && $options['dbs_conf'] == $options['sites_conf'] )
			$errors['dbs_conf'] = 'Sites and DBs config files cannot be the same file!';
	
		if( array_key_exists('backup_path', $options) ) $options['backup_path'] = trim( $options['backup_path'] );
		if( !empty($options['backup_path']) && !file_exists( $options['backup_path'] ) )
			$errors['backup_path'] = 'Path not valid - backup directory not found.';

		if( array_key_exists('backup_keep_time', $options) ) $options['backup_keep_time'] = trim( $options['backup_keep_time'] );
		if( array_key_exists('backup_keep_time', $options) && ''==$options['backup_keep_time'] )
			$options['backup_keep_time'] = 10;

		if( array_key_exists('rsync_path', $options) ) $options['rsync_path'] = trim( $options['rsync_path'] );
		if( !empty($options['rsync_path']) && !file_exists( $options['rsync_path'] ) )
			$errors['rsync_path'] = 'Path not valid - rsync not found.';
		if( empty($options['rsync_path']) )
		{
			$whereis_path = trim( str_ireplace('rsync:', '', `whereis -b rsync`) );
			$rsync_paths = array($whereis_path, '/usr/bin/rsync', '/usr/local/bin/rsync' );
			foreach( $rsync_paths as $rsync_path )
			{
				if( file_exists($rsync_path) )
				{
					$options['rsync_path'] = $rsync_path;
					break;
				}
			}
		}
		
		if( array_key_exists('dont_sync', $options) ) $options['dont_sync'] = trim( $options['dont_sync'] );
		if( empty($options['dont_sync']) )
			$options['dont_sync'] = '.git, .svn, .htaccess, tmp/, wp-config.php';
		
		if( !empty( $options['timezone'] ) )
		{
			@$tz=timezone_open( $options['timezone'] );
			if( FALSE===$tz )
			{
				$errors['timezone'] = "{$options['timezone']} is not a valid timezone. See <a href='http://php.net/manual/en/timezones.php' target='_blank'>list of supported timezones</a> for valid values.";
			}
		}
	
		if( !empty($options['plugin_activates']) )
		{
			$plugin_activates = array();
			foreach( explode("\n",$options['plugin_activates']) as $plugin )
			{
				$plugin = trim( $plugin );
				if( !$plugin || in_array($plugin, $plugin_activates) || in_array($plugin, $this->options['plugins']['never_manage']) ) continue; //empty line or duplicate
				$plugin_activates[] = $plugin;
			}
			asort($plugin_activates);
			$options['plugin_activates'] = implode("\n", $plugin_activates);		
		}

		if( !empty($options['plugin_deactivates']) )
		{
			$plugin_deactivates = array();
			foreach( explode("\n",$options['plugin_deactivates']) as $plugin )
			{
				$plugin = trim( $plugin );
				if( !$plugin || in_array($plugin, $plugin_deactivates) || in_array($plugin, $plugin_activates) || in_array($plugin, $this->options['plugins']['never_manage']) ) continue; //empty line or duplicate
				$plugin_deactivates[] = $plugin;
			}
			asort($plugin_deactivates);
			$options['plugin_deactivates'] = implode("\n", $plugin_deactivates);		
		}
	
	
		if( empty($options['capability']) )
			$options['capability'] = SitePushPlugin::$default_capability;
	
		if( empty($options['admin_capability']) )
			$options['admin_capability'] = SitePushPlugin::$default_admin_capability;

	
		if( empty($options['cache_key']) )
			$options['cache_key'] = '';
	
		
		if( $errors )
		{
			$options['ok'] = FALSE;
			$options['notices']['errors'] = $errors;
		}
		else
		{
			$options['ok'] = TRUE;
			$options['notices']['errors'] = array();
		}
	
		return $options;
	}
*/
	/*
	 * validate_conf_files
	 * 
	 * Validate config files. Should be run after options have been validated.
	 *
	 * @sets array $this->options['notices']['errors'] with any errors
	 * @return bool TRUE if valid, FALSE otherwise.
	 */
/*	private function validate_conf_files()
	{
		$errors = array();
		
		$required_site_params = array( 'db', 'web_path' );
		$required_db_params = array( 'name', 'user', 'pw' );
		
		//get site info from the sites.conf file
		$sites_conf = parse_ini_file($this->options['sites_conf'],TRUE);
		$dbs_conf = parse_ini_file($this->options['dbs_conf'],TRUE);
		
		//check if conf file has 'all' section and if so merge that config with config for each site	
		if( !empty( $sites_conf['all'] ) )
		{
			$sites_conf_all = $sites_conf['all'];
			unset( $sites_conf['all'] );
			
			foreach( $sites_conf  as $site=>$site_conf )
			{
				$sites_conf[$site] = array_merge( $sites_conf_all, $sites_conf[$site] );
			}
		}

		if( count( $sites_conf ) < 2 )
			$errors[] = "You must have at least 2 sites defined in your sites config file.";
		
		foreach( $sites_conf as $site=>$site_conf )
		{
			foreach( $required_site_params as $req )
			{
				if( empty( $site_conf[$req] ) )
					$errors[] = "Required parameter {$req} missing in site config for {$site}";
			}
				
			if( !array_key_exists($site_conf['db'], $dbs_conf) )
				$errors[] = "Database {$site_conf['db']} in config for {$site} is not defined in database config file.";
			
		
		
		}

		foreach( $dbs_conf as $db=>$db_conf )
		{
			foreach( $required_db_params as $req )
			{
				if( empty( $db_conf[$req] ) )
					$errors[] = "Required parameter {$req} missing in database config for {$db}";
			}
		}
		
		if( $errors )
		{
			$this->options['notices']['errors'] = array_merge( $this->options['notices']['errors'], $errors );
			return FALSE;
		}
		$this->options['sites'] = $sites_conf;
		$this->options['dbs'] = $dbs_conf;
		return TRUE;
	}
*/

/* -------------------------------------------------------------- */
/* !MOVED FROM SITEPUSH CORE */
/* -------------------------------------------------------------- */

	//get site config info from sites config file
/*	private function get_sites()
	{
		if( !file_exists($this->sites_conf_path) )
			die("Sites config file not found at {$this->sites_conf_path}\n");

		//get site info from the sites.conf file
		$sites_conf = parse_ini_file($this->sites_conf_path,TRUE);
		
		//check if conf file has 'all' section and if so merge that config with config for each site	
		if( !empty( $sites_conf['all'] ) )
		{
			$sites_conf_all = $sites_conf['all'];
			unset( $sites_conf['all'] );
			
			foreach( $sites_conf  as $site=>$site_conf )
			{
				$sites_conf[$site] = array_merge( $sites_conf_all, $sites_conf[$site] );
			}
	
		}

		$this->sites = $sites_conf;
		return $this->sites;
	}

	//get db config info from sites config file
	private function get_dbs()
	{
		if( !file_exists($this->dbs_conf_path) )
			die("DB config file not found at {$this->dbs_conf_path}\n");
		$this->dbs = parse_ini_file($this->dbs_conf_path,TRUE);
		return $this->dbs;
	}
*/	
	//get params for a specific site
/*	protected function get_site_params( $site )
	{
		if( array_key_exists($site,$this->sites) )
		{
			$params = $this->sites[$site];
			if( array_key_exists('domains',$params) )
				$params['domain'] = $params['domains'][0];
				
			//make sure certain optional params are set correctly
			if( empty($params['wp_dir']) ) $params['wp_dir'] = ''; //make sure it is set
			if( empty($params['wp_content_dir']) ) $params['wp_content_dir'] = '/wp-content';
			if( empty($params['wp_plugins_dir']) ) $params['wp_plugins_dir'] = $params['wp_content_dir'] . '/plugins';
			if( empty($params['wp_uploads_dir']) ) $params['wp_uploads_dir'] = $params['wp_content_dir'] . '/uploads';
			if( empty($params['wp_themes_dir']) ) $params['wp_themes_dir'] = $params['wp_content_dir'] . '/themes';
			
			//stop if certain required params not present
			if( empty( $params['web_path'] ) || empty( $params['db'] ) )
				die( "ERROR: required parameter, or web_path, or db is missing from config for {$site} in sites.conf\n" );

			return $params;
		}
		else
		{
			return FALSE;
		}
	}
*/	
	//get params for a specific db
	protected function get_db_params( $db='', $db_type='' )
	{
		
		if( array_key_exists($db,$this->dbs) )
		{
			$result = $this->dbs[$db];
			$result['label'] = $db;
		}
		else
		{
			switch( $db_type )
			{
				case 'source':
					$result = $this->dbs[ $this->source_params['db'] ];
					$result['label'] = $this->source_params['db'];
					break;
				case 'dest':
					$result =  $this->dbs[ $this->dest_params['db'] ];
					$result['label'] = $this->dest_params['db'];
					break;
				default:
					$result =  FALSE;
			}
		}

		//stop if certain required params not present
		if( empty( $result['name'] ) || empty( $result['user'] ) || empty( $result['pw'] ) || empty( $result['prefix'] ) )
			die( "ERROR: required parameter name, or user, or pw, or prefix is missing from config for {$result['label']} in dbs.conf\n" );

		return $result;
	}


	//make sure all variables are set properly from config files etc
/*	private function set_all_params()
	{
		//read site & db config files
		$this->get_sites();
		$this->get_dbs();
		
		//get params for source and dest
		$this->source_params = $this->get_site_params( $this->source );
		if( !$this->source_params ) die("Unknown site config '{$this->source}'.\n");
		
		$this->dest_params = $this->get_site_params( $this->dest );
		if( !$this->dest_params ) die("Unknown site config '{$this->dest}'.\n");

		//set $source_path & $dest_path from config file parameter
		$this->source_path = $this->source_params['web_path'];
		$this->dest_path = $this->dest_params['web_path'];

		//make sure dest['remote'] is set, and force remote dest to server specified by remote param
		if( isset($this->dest_params['remote']) && $this->dest_params['remote'] )
		{
			$this->dest_params['domain'] = $this->dest_params['remote'];
			$this->dest_params['remote'] = TRUE;
		}
		else
		{
			$this->dest_params['remote'] = FALSE;
		}

		if( isset($this->source_params['remote']) && $this->source_params['remote'] )
		{
			die("Remote source isn't currently supported.\n");
		}
		
		//set up remote shell command
		$this->remote_shell = $this->dest_params['remote'] ? "ssh -i {$this->ssh_key_dir}{$this->dest_params['domain']} {$this->remote_user}@{$this->dest_params['domain']} " : '';

		//set source/dest backup path from backup_path parameter if explicit source/dest paths not set
		if( empty($this->source_backup_path) ) $this->source_backup_path = $this->backup_path;
		if( empty($this->dest_backup_path) ) $this->dest_backup_path = $this->backup_path;

		if( $this->source_backup_path )
		{
			$this->source_backup_path = $this->trailing_slashit($this->source_backup_path);
			$this->source_backup_dir = $this->source_backup_path;
			if( ! file_exists($this->source_backup_dir) ) mkdir($this->source_backup_dir,0700,TRUE);
		}

		if( $this->dest_backup_path )
		{
			$this->dest_backup_path = $this->trailing_slashit($this->dest_backup_path);
			$this->dest_backup_dir = $this->dest_backup_path;
			if( $this->dest_params['remote'] )
			{
				$command = $this->make_remote("mkdir -p {$this->dest_backup_dir}");
				$this->my_exec($command);
			}
			else
			{
				if( ! file_exists($this->dest_backup_dir) ) mkdir($this->dest_backup_dir,0700,TRUE);
			}
		}
	}

*/


}

/* EOF */
