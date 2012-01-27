<?php
/**
 * SitePushCore class
 * 
 */
class SitePushCore
{
	//main parameters
	public $source;
	public $dest;
	public $db_source;
	public $db_dest;
	public $theme;
	public $push_plugins = FALSE; //push plugins directory
	public $push_uploads = FALSE; //push uploads directory
	public $push_themes = FALSE; //push themes directory (ie all themes)
	public $push_wp_files = FALSE; //push WordPress files (ie everything in wp directory except wp-content)
	public $do_backup = TRUE;
	public $undo;
	public $db_prefix = ''; //custom prefix for database security
	
	//don't actually do anything if dry_run is TRUE
	public $dry_run = FALSE;
	
	//hold any results
	public $results = array();
	
	//CACHE_KEY - security key required to run commands remotely
	//this should be supplied URL encoded
	public $cache_key = '';
	
	//type of cache
	public $cache_type = '';

	//array of file patterns to exclude from all pushes
	public $excludes = array( '.git', '.svn', '.htaccess', 'tmp/', 'wp-config.php' );
	
	//timestamp for backup files
	protected $timestamp;
	
	//source/dest params - array created during __construct
	private $source_params;
	private $dest_params;
	
	//the following options could be set from web interface
	//where we look for info about the sites we are pushing
	public $sites_conf_path; //full path to sites.conf file **required**
	public $dbs_conf_path; //full path to sites.conf file **required**
	
	//do we want to save undo files
	public $save_undo = TRUE;
	private $undo_file; //read from get_undo_file method
	
	//top level source/dest directories
	//normally this would be path to httpdocs for site
	public $source_path;
	public $dest_path;

	//name of site - defines dir where backups are stored
	//this is either set explicitly or inferred from CLI path
	public $site_name = '';
	
	//where to store backups
	public $source_backup_path; //undo files etc **required**
	public $dest_backup_path; //file archives, db_dumps etc **required**
	//site_name (if set) and yyyy-mm gets appended to backup_path in set_all_params, so all backups for a site/timeframe are grouped together
	private $source_backup_dir;
	private $dest_backup_dir;
	
	//mysqldump options
	//add  --events --routines to options if we need to backup triggers & stored procedures too (shouldn't be necessary for wp)
	private $dump_options = "--opt";
	
	//rsync/ssh options - used for pushing to remote site
	public $remote_user; //user account on remote destination
	public $ssh_key_dir; //where ssh key is on local server for remote push (key must be named same as the remote server)
	private $remote_shell; //set up by __construct
	public $rsync_cmd = '/usr/bin/rsync'; //full path to rsync

	//are we in wordpress maintenance mode or not
	private $maintenance_mode = 'off';
	
	//seconds before a push will timeout. If you have a lot of files or a large DB, you may need to increase this.
	public $push_time_limit = 6000;

	//should push output be echoed in realtime?
	public $echo_output = TRUE;
	
	//how much info to output (normal,backups=1 or detail=2, very_detail=3)
	public $output_level = 1;
	
	//holds any errors for later output
	public $errors = array();
	
	//passwords will be replaced with **** in any log output
	public $hide_passwords = TRUE;
	
	//set to TRUE for additional debug output
	public $debug = FALSE;
	
	function __construct( $vars=array() )
	{
		$this->check_requirements();

		$defaults = array(
			  'timezone'	=>	''
		);
		extract( wp_parse_args( $vars , $defaults ) );
		
		//WP sets this to UTC somewhere which confuses things...
		if( $timezone )
			date_default_timezone_set( $time_zone );
		
		//create single timestamp for all backups
		$this->timestamp = date('Ymd-His');
	}
	
	function __destruct()
	{
		//make sure maintenance mode is off
		if( $this->maintenance_mode=='on' ) $this->set_maintenance_mode('off');
	}

/* -------------------------------------------------------------- */
/* !METHODS USED TO SET THINGS UP */
/* -------------------------------------------------------------- */

	//get site config info from sites config file
	private function get_sites()
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
	
	//get params for a specific site
	protected function get_site_params( $site )
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

	public function check_requirements()
	{
		$errors = array();
		
		//get php version
		if (!defined('PHP_VERSION_ID'))
		{
			$php_version = explode('.', PHP_VERSION);
			define('PHP_VERSION_ID', ($php_version[0] * 10000 + $php_version[1] * 100 + $php_version[2]));
		}
		if( PHP_VERSION_ID < 50200 ) $errors[] = 'we need PHP 5.2';
		
		//if we can't find rsync at defined path, try without any path
		if( !file_exists( $this->rsync_cmd ) )
			$this->rsync_cmd = 'rsync';
		
		$result = shell_exec("{$this->rsync_cmd} --version");
			if( !$result ) $errors[]='Rsync not found or not configured properly.';
		
		$this->errors = array_merge($this->errors, $errors);
		
		return ! (bool) $errors;			
	}

/* -------------------------------------------------------------- */
/* !PUBLIC METHODS */
/* -------------------------------------------------------------- */

	//returns text listing values of all key params
	//primarily for debugging
	public function show_params( $set_params=TRUE )
	{
		if( $set_params ) $this->set_all_params();
		
		$params = array(
				  'source'				=>	'string'
				, 'dest'				=>	'string'
				, 'db_source'			=>	'string'
				, 'db_dest'				=>	'string'
				, 'theme'				=>	'string'
				, 'db_prefix'			=>	'string'
				, 'undo'				=>	'string'
				
				, 'push_plugins'		=>	'bool'
				, 'push_uploads'		=>	'bool'
				, 'push_themes'			=>	'bool'
				, 'push_wp_files'		=>	'bool'
				, 'do_backup'			=>	'bool'
				
				, 'dry_run'				=>	'bool'
				, 'debug'				=>	'bool'
				
				, 'source_params'		=>	'array'
				, 'dest_params'			=>	'array'
				
				, 'sites_conf_path'		=>	'string'
				, 'dbs_conf_path'		=>	'string'
				, 'save_undo'			=>	'bool'
				, 'undo_file'			=>	'string'
				
				, 'source_path'			=>	'string'
				, 'dest_path'			=>	'string'
				
				, 'site_name'			=>	'string'
				
				, 'backup_path'			=>	'string'
				, 'source_backup_path'	=>	'string'
				, 'dest_backup_path'	=>	'string'
		);
		
		$output = "PARAMETER DUMP:\n";
		foreach( $params as $param_name=>$type )
		{
			switch( $type )
			{
				case 'string':
					$param = (string) $this->$param_name;
					break;
				
				case 'array':
					$param = print_r($this->$param_name, TRUE);
					break;
				
				case 'bool':
					$param = $this->$param_name ? 'TRUE' : 'FALSE';
					break;
			}               
			$output .= "{$param_name} ({$type}): {$param}\n";
		}
		return $output;
	}

	//push everything as per parameters
	public function push_files()
	{
		$this->set_all_params();
		
		if( $this->debug ) echo $this->show_params( FALSE );
		
		$source = $this->source_params;
		$dest = $this->dest_params;
	
		if( $this->push_plugins )
		{
			$backup_file = $this->file_backup( $this->dest_path . $dest['wp_plugins_dir'] );
			$this->copy_files( $this->source_path . $source['wp_plugins_dir'], $this->dest_path . $dest['wp_plugins_dir'], $backup_file, 'plugins', TRUE );
		}
		
		if( $this->push_uploads )
		{
			$backup_file = $this->file_backup( $this->dest_path . $dest['wp_uploads_dir'] );
			$this->copy_files( $this->source_path . $source['wp_uploads_dir'], $this->dest_path . $dest['wp_uploads_dir'], $backup_file, 'uploads', TRUE );
		}

		if( $this->push_themes )
		{
			$backup_file = $this->file_backup( $this->dest_path . $dest['wp_themes_dir'] );
			$this->copy_files( $this->source_path . $source['wp_themes_dir'], $this->dest_path . $dest['wp_themes_dir'], $backup_file, 'themes', TRUE );
		}
		
		if( $this->theme )
		{
			$backup_file = $this->file_backup( $this->dest_path . $dest['wp_themes_dir'] . '/' . $this->theme );
			$this->copy_files( $this->source_path . $source['wp_themes_dir'] . '/' . $this->theme, $this->dest_path . $dest['wp_themes_dir'] . '/' . $this->theme, $backup_file, $this->theme, TRUE );
		}

	}
	
	/**
	 * push_db function.
	 * 
	 * copy source db to destintation, with backup
	 * 
	 * @param mixed table_groups :which groups of tables to push, either array of groups, or a single group as string
	 * @access public
	 * @return bool :result of push command
	 */
	public function push_db( $table_groups=FALSE )
	{
		$this->set_all_params();

		$db_source = $this->get_db_params( $this->db_source, 'source' );
		$db_dest = $this->get_db_params( $this->db_dest, 'dest' );

		if( empty( $db_source['prefix'] ) )
			$this->errors[] = "You must set a database prefix for each database in dbs.ini.php";
		if( $db_source['prefix'] <> $db_dest['prefix'] )
			$this->errors[] = "Source and destination DB prefix must be the same.";
		$this->db_prefix = $db_source['prefix'];

		if( !$db_source || !$db_dest )
			$this->errors[] = 'Unknown database source or destination';

		if( $db_source['name'] == $db_dest['name'] )
			$this->errors[] = 'Database not pushed. Source and destination databases are the same!';

		if( $this->errors )
			return FALSE;

		//work out which table(s) to push
		if( $table_groups )
		{
			$tables = '';
			if( is_array($table_groups) )
			{
				foreach( $table_groups as $table_group )
				{
					$tables .= ' ' . $this->get_tables( $table_group );
				}
			}
			else
			{
				$tables .= ' ' . $this->get_tables( $table_groups );
			}
		}
		else
		{
			$tables = '';
		}
		$tables = trim($tables);
		if( $tables ) 
			$tables = "--tables {$tables}"; //tables parameter isn't strictly speaking necessary, but we'll use it just to be safe
		$this->copy_db($db_source, $db_dest, $tables, TRUE);
	}
		

	//backs up and copies a database
	private function copy_db($db_source, $db_dest, $tables='', $maint_mode=FALSE )
	{
		$backup_file = $this->database_backup($db_dest);
		
		//set PHP script timelimit
		set_time_limit( $this->push_time_limit );
		
		$source_host = !empty($db_source['host']) ? " --host={$db_source['host']}" : '';
		$dest_host = !empty($db_dest['host']) ? " --host={$db_dest['host']}" : '';
		
		$test = '';
		//$test = " --ignore-table={$db_source['name']}.wp_posts";
		
		$dump_command = "mysqldump {$this->dump_options}{$source_host}{$test} -u {$db_source['user']} -p'{$db_source['pw']}' {$db_source['name']} {$tables}";
		
		$mysql_options = '';
		$mysql_command = "mysql -D {$db_dest['name']} -u {$db_dest['user']}{$dest_host} -p'{$db_dest['pw']}'";

		$command = "{$dump_command} | " . $this->make_remote($mysql_command);

		//write file which will undo the push
		if( $this->save_undo && $backup_file )
		{
			$undo_command = "mysql -u {$db_dest['user']} -p'{$db_dest['pw']}'{$dest_host} -D {$db_dest['name']} < {$backup_file}";
			$undo['original'] = $command;
			$undo['remote'] = $this->remote_shell;
			$undo['type'] = 'mysql';
			$undo['undo'] = $undo_command;
			$this->write_undo_file( $undo );
		}
		
		//turn maintenance mode on
		if( $maint_mode ) $this->set_maintenance_mode('on');
		
		if( $tables )
			$this->add_result("Pushing database tables from {$db_source['label']} to {$db_dest['label']}: {$tables}");
		else
			$this->add_result("Pushing whole database",1);		
		$this->add_result("Database source: {$db_source['label']} on {$this->source_params['domain']}",2);
		$this->add_result("Database dest: {$db_dest['label']} on {$this->dest_params['domain']}",2);

		$result = $this->my_exec($command);
		$this->add_result('--');
		
		//turn maintenance mode off
		if( $maint_mode ) $this->set_maintenance_mode('off');
		
		return $result;
	}
	
	/**
	 * undo function.
	 * 
	 * undo either the last push, or a specific one if specified
	 * 
	 * @access public
	 * @return string :what happened
	 */
	public function undo()
	{
		$this->set_all_params();

		$result = '';

		if( $this->undo && strlen($this->undo) <= 1 )
		{
			//get the last push undo file if no file was specified
			//this will have full path
			$this->undo = file_get_contents("{$this->source_backup_dir}last");
		}
		else
		{
			//don't expect user to enter full path, so add it
			$this->undo = "{$this->source_backup_dir}/{$this->undo}";
		}
		
		if( $this->undo )	
		{
			$result = "Running backup {$this->undo}:\n";
			$commands = file($this->undo);
			//run all commands in the undo file
			foreach( $commands as $command )
			{
				$result .= trim($command)."\n";
				if( $command ) $this->my_exec($command);
			}
		}
	return $result;
	}

	//return the undo file
	//reads from the 'last' file so will get last undo even on later instantiation
	public function get_last_undo_file()
	{
		return file_exists($this->source_backup_path . 'last') ? file_get_contents($this->source_backup_path . 'last') : FALSE;
	}

	//parses last undo filename and returns udate of last undo
	public function get_last_undo_time()
	{
		$undo_file = $this->get_last_undo_file();
		$last_time = substr( $undo_file, -11, 6);
		$last_date = substr( $undo_file, -20, 8);
		return strtotime("{$last_date}T{$last_time}");
	}
	

	/**
	 * clear_cache
	 * 
	 * Clears caches on destination. Will attempt to clear W3TC and SuperCache,
	 * and empty any directories defined in sites.ini.php 'caches' parameter.
	 * 
	 * @return bool TRUE if any cache cleared, FALSE otherwise
	 */
	public function clear_cache()
	{
		$this->set_all_params();
		$result = '';
		$return = FALSE;

		//clear any cache directories defined by site parameters
		if( array_key_exists('caches', $this->sites[$this->dest]) && is_array($this->sites[$this->dest]['caches']) )
		{
			foreach( $this->sites[$this->dest]['caches'] as $cache )
			{
				$cache_path = $this->trailing_slashit($this->dest_path) . ltrim($this->trailing_slashit($cache),'/') . '*';
				$command = $this->make_remote("rm -rf {$cache_path}");
				$result .= "Clearing cache {$cache} " . $this->my_exec($command) ."\n";
				$return = TRUE;
			}
		}
		
		//cache not active on destination, so don't try to clear it
		if( empty($this->sites[$this->dest]['cache']) )
		{
			$result .= "WordPress cache is not activated on destination site";
		}
		else
		{
			//clear WP cache on destination site
			$url = "{$this->trailing_slashit($this->dest_params['domain'])}?mra_sitepush_cmd=clear_cache&mra_sitepush_key={$this->cache_key}";
			
			$cc_result = $this->callResource($url, 'GET', $data = null);
	
			if( $cc_result['code']==200 )
			{
				//sucess
				$result .= "Cache: {$cc_result['data']}";
				$return = TRUE;
			}
			elseif(  $cc_result['code']==401 )
			{
				$result .= "Cache: could not access destination site to clear cache because authorisation failed (check in your .htaccess that this server can access the destination) [{$cc_result['code']}]";
				$result .= "\n{$url}";
			}
			else
			{	
				$result .= "Error clearing cache: status code [{$cc_result['code']}]";
				$result .= "\n{$url}";
			}
		}
			
		$this->add_result($result);
		$this->add_result('--');
		
		return $return;
	}


/* -------------------------------------------------------------- */
/* !PRIVATE METHODS */
/* -------------------------------------------------------------- */
	
	//make sure all variables are set properly from config files etc
	private function set_all_params()
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

		//set the actual backup dir for this site/month
		$backup_group_path = $this->site_name ? "{$this->site_name}/" . date('Y-m') : date('Y-m');

		//set source/dest backup path from backup_path parameter if explicit source/dest paths not set
		if( empty($this->source_backup_path) ) $this->source_backup_path = $this->backup_path;
		if( empty($this->dest_backup_path) ) $this->dest_backup_path = $this->backup_path;

		if( $this->source_backup_path )
		{
			$this->source_backup_path = $this->trailing_slashit($this->source_backup_path);
			$this->source_backup_dir = $this->source_backup_path . $backup_group_path . '/';
			if( ! file_exists($this->source_backup_dir) ) mkdir($this->source_backup_dir,0700,TRUE);
		}

		if( $this->dest_backup_path )
		{
			$this->dest_backup_path = $this->trailing_slashit($this->dest_backup_path);
			$this->dest_backup_dir = $this->dest_backup_path . $backup_group_path . '/';
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
	
	/**
	 * file_backup
	 * 
	 * backup a directory before we push files
	 *
	 * @param string $path path of dir to backup
	 * @param string $backup_name label for the backup file (defaults to last directory of $path)
	 * @return string/bool path to backup file or FALSE if no backup
	 */
	private function file_backup( $path , $backup_name='' )
	{
		//no backup dir set
		if( !$path ) return FALSE;
	
		//don't backup if directory is really a symlink
		if( is_link($path) ) return FALSE;
	
		$last_pos =  strrpos($path, '/') + 1;
		$dir = substr( $path, $last_pos );
		$newpath = substr( $path, 0, $last_pos );
		
		if( !$backup_name ) $backup_name = $dir;
	
		if( file_exists($path) && $this->do_backup )
		{
			$this->add_result("Backing up {$path}",1);

			//where do we backup to
			$backup_file = "{$this->dest_backup_dir}{$this->dest}-{$this->timestamp}-file-{$backup_name}.tgz";

			//create the backup command
			$command = $this->make_remote("cd {$path}; cd ..; tar -czf {$backup_file} {$dir}; chmod 400 {$backup_file}");
			
			//run the backup command
			$this->my_exec($command);
						
			//add the backup file to the backups array so we know what's been done for user reporting etc
			$this->add_result("Backup file is at {$backup_file}",1);
			$this->add_result('--');

			//return the backup file name/path so we can undo		
			return $backup_file;
		}
		else
		{
			//we didn't backup, so return FALSE
			$this->add_result("{$path} not backed up, because it was not found.",1);
			return FALSE;
		}
	}
	
	//backup the database before we push
	private function database_backup( $db )
	{
		if( $this->do_backup )
		{
			//where do we backup to
			$destination = "{$this->dest_backup_dir}{$this->dest}-{$this->timestamp}-db-{$db['name']}.sql";
			
			//DB host parameter if needed
			$dest_host = !empty($db['host']) ? " --host={$db['host']}" : '';
			
			//create the backup command
			$command = $this->make_remote("mysqldump {$this->dump_options} -r {$destination}{$dest_host} -u {$db['user']} -p'{$db['pw']}' {$db['name']}; chmod 400 {$destination}");

			//add the backup file to the backups array so we know what's been done for user reporting etc
			$this->add_result("Backing up {$this->dest} DB",1);
			$this->add_result("Backup file is at {$destination}",2);
			
			//run the backup command
			$this->my_exec($command);

			$this->add_result('--');
		
			//return the backup file name/path so we can undo			
			return $destination;
		}
		else
		{
			//we didn't backup, so return FALSE
			return FALSE;
		}
	}
	
	//get tables for any given push group
	private function get_tables( $group )
	{
	
	/*
		Table groups:-
		options = wp_options
		comments = wp_commentmeta, wp_comments
		content = wp_links, wp_postmeta, wp_posts, wp_term_relationships, wp_term_taxonomy, wp_terms
		users = wp_usermeta, wp_users
		forms = gravity forms
		form-data = data collected by gravity forms
		all-tables = the whole database - use with caution!
	*/
	
		switch( $group )
		{
			case 'options':
				$tables = 'wp_options';
				break;
			case 'comments':
				$tables = 'wp_commentmeta wp_comments';
				break;
			case 'content':
				$tables = 'wp_links wp_postmeta wp_posts wp_term_relationships wp_term_taxonomy wp_terms';
				break;
			case 'users':
				$tables = 'wp_usermeta wp_users';
				break;
			case 'all-tables':
				$tables = '';
				break;
			default:
				die("Unknown or no db-type option. Valid options are all-tables|options|comments|content|users|forms|form-data.\n");
		}
		
		//add correct DB prefix to all tables
		if( $tables )
		{
			$tables = " {$tables}";
			$tables = str_replace(' wp_',' '.$this->db_prefix,$tables);
			$tables = trim($tables);
		}

		return $tables;
	}
	
	//copies files from source to dest
	//deleting anything in dest not in source
	//aborts if either source or dest is a symlink
	private function copy_files($source_path,$dest_path,$backup_file='',$dir='',$maint_mode=FALSE)
	{
		//rsync option parameters
		$rsync_options = "-avz --delete";
		
		$source_path = $this->trailing_slashit( $source_path );
		$dest_path = $this->trailing_slashit( $dest_path );
		
		//don't copy if source or dest are symlinks
		if( is_link( rtrim($source_path,'/') ) )
		{
			$this->errors[] = "Could not push from {$source_path} because it is a symlink and not a real directory.";
			return FALSE;
		}
		elseif( is_link( rtrim($dest_path,'/') ) )
		{
			$this->errors[] = "Could not push to {$dest_path} because it is a symlink and not a real directory.";
			return FALSE;
		}
		
		//are we syncing to a remote server?
		$remote_site = '';
		if( $this->dest_params['remote'] )
		{
			$rsync_options .= " -e 'ssh -i {$this->ssh_key_dir}{$this->dest_params['domain']}'";
			$remote_site = "{$this->remote_user}@{$this->dest_params['domain']}:";
			
			//make sure remote dest dir exists
			$command = $this->make_remote("mkdir -p {$dest_path}");
			$this->my_exec($command);
		}
		else
		{
			//make sure dest dir exists
			shell_exec("mkdir -p {$dest_path}");
			//php mkdir gives warning if dir or parent already exists
			//if( ! file_exists($dest_path) ) mkdir($dest_path,0755,TRUE);
		}
		
		//add the excludes to the options
		if( !is_array($this->excludes) ) $this->excludes = explode( ',', $this->excludes );
		foreach( $this->excludes as $exclude )
		{
			$exclude = trim($exclude);
			$rsync_options .= " --exclude='{$exclude}'";
		}
		
		//create the command
		$command = "{$this->rsync_cmd} {$rsync_options} '{$source_path}' '{$remote_site}{$dest_path}'";
		
		//write file which will undo the push
		if( $this->source_backup_path && $this->save_undo && $dir && $backup_file )
		{
			$undo_dir = "{$this->dest}-{$this->timestamp}-undo_files";
			$undo_prep = "cd {$this->dest_backup_dir}; mkdir '{$undo_dir}'; cd '{$undo_dir}'; tar -zpxf {$backup_file}\n";
			$undo_sync = "{$this->rsync_cmd} {$rsync_options} '{$this->dest_backup_dir}/{$undo_dir}/{$dir}/' '{$dest_path}'\n\n";
			$undo['original'] = $command;
			$undo['remote'] = $this->remote_shell;
			$undo['type'] = 'rsync';
			$undo['undo'][] = $undo_prep;
			$undo['undo'][] = $undo_sync;
			$this->write_undo_file( $undo );
		}
		
		//turn maintenance mode on, if required
		if( $maint_mode ) $this->set_maintenance_mode('on');
		
		//add to the results log so we know what has been done
		$this->add_result("Pushing files from {$this->source} to {$this->dest}",1);
		$this->add_result("Files source path: {$source_path}",2);
		$this->add_result("Files dest path: {$dest_path}",2);

		//run the command
		$result = $this->my_exec($command);
		$this->add_result('--');
		
		//turn maintenance mode off
		if( $maint_mode ) $this->set_maintenance_mode('off');
	}

	//if destination is remote, run command through remote shell
	private function make_remote( $command, $remote='not_set' )
	{
		if( $remote=='not_set') $remote = $this->dest_params['remote'];
		if( $remote )
		{
			$command = "{$this->remote_shell} '{$command}'";
		}

		return $command;
	}
	
	//wrapper for file_put_contents which works for remote site as well
	private function dest_file_put_contents( $filename, $data , $flags=NULL, $context=NULL )
	{
		if( $this->dest_params['remote'] )
		{
			if( $flags & FILE_APPEND )
				$command = "echo \"{$data}\" >> $filename";
			else
				$command = "echo \'{$data}\' > $filename";
				
			$command = $this->make_remote($command);

			$result = $this->my_exec($command);
		}
		else
		{
			$result = file_put_contents($filename, $data, $flags, $context );
		}
		
		return $result;
	}
	
	//writes an array to undo file
	//undo file is always written to source, so may be in different place to db/file backups
	private function write_undo_file( $undos=array() )
	{
		if( !$this->source_backup_path ) return FALSE;
	
		//define the undo file
		$this->undo_file = "{$this->source_backup_dir}{$this->dest}-{$this->timestamp}.undo";
		
		//write file so we know what the last timestamp for backups was
		file_put_contents($this->source_backup_path . 'last', $this->undo_file);

		$undo_text = "start\tundo\n";
		foreach( $undos as $key=>$undo )
		{
			if( !is_array($undo) ) $undo = array( $undo );
			foreach( $undo as $undo_item )
				$undo_text .= "{$key}\t{$undo_item}\n";
		}
		$undo_text .= "end\tundo\n";
		
		if( file_exists($this->undo_file) ) chmod($this->undo_file, 0600);
		file_put_contents($this->undo_file, $undo_text, FILE_APPEND);
		chmod($this->undo_file, 0400);
	}
	
	private function trailing_slashit( $path )
	{
		return rtrim( $path , '/' ) . '/';
	}
	
	private function my_exec($command,$log_level=3)
	{
		$log_command = htmlspecialchars($command);

		if(!$this->dry_run)
		{
			$this->add_result("RUN: {$log_command}",$log_level);
			
			if( $this->echo_output )
				return system($command . ' 2>&1' );
			else
				return shell_exec($command . ' 2>&1' );

			$this->add_result('--',$log_level);
		}
		else
		{
			$this->add_result("DRYRUN: {$log_command}",$log_level);
			return FALSE;
		}
	
	}
	
	//masks password for logs
	private function sanitize_cmd($command)
	{
		if( !$this->hide_passwords ) return $command;

		$db_source = $this->get_db_params( $this->db_source, 'source' );
		$db_dest = $this->get_db_params( $this->db_dest, 'dest' );

		if( !$db_source || !$db_dest ) return $command;
		
		return str_replace(array($db_source['pw'], $db_dest['pw']), array('*****', '*****'), $command);
	}
	
	//config add params for echo or not
	private function add_result( $result, $log_level=1 )
	{
		$this->results[] = array( 'level'=>$log_level, 'msg'=>trim($this->sanitize_cmd($result)) );
		
		if( $this->echo_output && $this->output_level>=$log_level )
		{
			if( '--' == $result )
				echo "\n";
			else
				echo "[{$log_level}] " . trim($this->sanitize_cmd($result)) . "\n";
			flush();
			ob_flush();
		}
	}
	
	/**
	 * get_results
	 * 
	 * returns results up to defined log level
	 *
	 * @param int $max_level output up to log level $max_level
	 * @return string results
	 */
	public function get_results( $max_level='' )
	{
		$output = '';
		foreach( $this->results as $result )
		{
			$level = is_array( $result ) ? $result['level'] : 1;
			$result = is_array( $result ) ? $result['msg'] : $result;
			
			if( $max_level==='' || $max_level >= $level )
				$output .= "[{$level}] {$result}\n";
		}

		return $output;
	}
	
	//function for sending http request and getting results
	//based on code from iContact
	private function callResource($url, $method='GET', $data = null, $type='text')
	{
		$handle = curl_init();
		
		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
		);
		
		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($handle, CURLOPT_MAXREDIRS, 100);
		
		switch ($method) {
			case 'GET':
				curl_setopt($handle, CURLOPT_HTTPGET, true);
			break;
			case 'POST':
				curl_setopt($handle, CURLOPT_POST, true);
				curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($data));
			break;
			case 'PUT':
				curl_setopt($handle, CURLOPT_PUT, true);
				$file_handle = fopen($data, 'r');
				curl_setopt($handle, CURLOPT_INFILE, $file_handle);
			break;
			case 'DELETE':
				curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'DELETE');
			break;
		}
		
		$response = curl_exec($handle);
	
		switch( $type )
		{
			case 'json':
				$response = json_decode($response, true);
				break;
		}
			
		$code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
		
		curl_close($handle);
		
		return array(
			'code' => $code,
			'data' => $response,
		);
	}

	//turns WP maintenance mode on/off
	private function set_maintenance_mode( $switch=FALSE )
	{
		$maint_file = '<?php \$upgrading='.time().'; ?>';
		$maint_file_path = $this->dest_path . $this->trailing_slashit($this->dest_params['wp_dir']) . '.maintenance';
		
		if( $switch===TRUE || $switch===1 ) $switch = 'on';
		if( $switch===FALSE || $switch===0 ) $switch = 'off';
		
		switch( $switch )
		{
			case 'on':
				$command = $this->make_remote("echo \"{$maint_file}\" > {$maint_file_path}");
				$switch = 'on';
				break;
				
			case 'off':
			default:
				$command = $this->make_remote("if [ -f \"{$maint_file_path}\" ]; then rm \"{$maint_file_path}\"; fi");
				$switch = 'off';
				break;
		}

		$this->add_result( "Maintenance mode {$switch}" );
		$this->my_exec($command,3);
		$this->add_result('--');

		//remember whether or not we are in maint mode
		$this->maintenance_mode = $switch;

		return TRUE;
	}
	
}
?>