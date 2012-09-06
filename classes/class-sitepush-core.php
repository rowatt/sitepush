<?php
/**
 * SitePushCore class
 * 
 */
class SitePushCore
{
	//main parameters
	public $sites = array(); //holds all sites parameters
	public $source;
	public $dest;
	public $theme; //name of specific theme to push
	public $push_plugins = FALSE; //push plugins directory
	public $push_mu_plugins = FALSE; //push mu_plugins directory
	public $push_uploads = FALSE; //push uploads directory
	public $push_themes = FALSE; //push themes directory (ie all themes)
	public $push_wp_files = FALSE; //push WordPress files (ie everything in wp directory except wp-content)
	public $do_backup = TRUE;

	//not used - will be used when undo implemented
	//public $undo;

	/**
	 * @var string holds db_prefix for databases
	 */
	private $db_prefix = '';
	
	/**
	 * @var bool don't actually do anything if dry_run is TRUE
	 */
	public $dry_run = FALSE;
	
	/**
	 * @var array holds any results. Get results with get_results method
	 */
	private $results = array();

	//timestamp for backup files
	protected $timestamp;
	
	//source/dest params - array created during __construct
	public $source_params;
	public $dest_params;
	
	//the following options could be set from web interface
	//where we look for info about the sites we are pushing
	public $sites_conf_path; //full path to sites.conf file **required**
	public $dbs_conf_path; //full path to sites.conf file **required**
	public $domain_map_conf_path; //full path to domain_map.conf file **required if multisite**

	//do we want to save undo files
	public $save_undo = TRUE;

	//where to store backups
	public $source_backup_path; //undo files etc **required**
	public $dest_backup_path; //file archives, db_dumps etc **required**

	//mysqldump options
	private $dump_options = "--opt --verbose";
	
	//rsync/ssh options - used for pushing to remote site
	public $remote_user; //user account on remote destination //???
	public $ssh_key_dir; //where ssh key is on local server for remote push (key must be named same as the remote server) //???
	//private $remote_shell; //set up by __construct //???

	//are we in wordpress maintenance mode or not
	private $maintenance_mode = 'off';
	
	//seconds before a push will timeout. If you have a lot of files or a large DB, you may need to increase this.
	public $push_time_limit = 6000;

	//should push output be echoed in realtime?
	public $echo_output = TRUE;

	//passwords will be replaced with **** in any log output
	public $hide_passwords = TRUE;
	
	/**
	 * @var SitePushOptions object holding options
	 */
	private $options;

	/**
	 * @var bool if set to TRUE, fix_multisite_domains method will be run when pushing DB
	 */
	private $fix_domains = FALSE;

	/**
	 * @var string $source name of push source
	 * @var string $dest name of push dest
	 */
	function __construct( $source, $dest )
	{
		//set PHP script timelimit so push has plenty of time to complete
		set_time_limit( $this->push_time_limit );

		$this->options = SitePushOptions::get_instance();
		$this->check_requirements();

		$this->source = $source;
		$this->dest = $dest;

		//WP sets this to UTC somewhere which confuses things...
		if( $this->options->timezone )
			date_default_timezone_set( $this->options->timezone );

		//get params for source and dest
		$this->source_params = $this->options->get_site_params( $this->source );
		if( !$this->source_params )
		{
			SitePushErrors::add_error( "Unknown site config '{$this->source}'", 'fatal-error' );
			return;
		}

		$this->dest_params = $this->options->get_site_params( $this->dest );
		if( !$this->dest_params )
		{
			SitePushErrors::add_error( "Unknown site config '{$this->dest}'", 'fatal-error' );
			return;
		}

		//create single timestamp for all backups
		$this->timestamp = date('Ymd-His');

		$this->db_prefix = $this->options->db_prefix;
	}
	
	function __destruct()
	{
		//make sure maintenance mode is off
		if( $this->maintenance_mode=='on' ) $this->set_maintenance_mode('off');
	}

	/* -------------------------------------------------------------- */
	/* !METHODS USED TO SET THINGS UP */
	/* -------------------------------------------------------------- */

	/**
	 * Check that various required things are present.
	 *
	 * Currently all checks have fallbacks, so check always passes.
	 *
	 * @return bool
	 */
	public function check_requirements()
	{
		//if we can't find rsync/mysql/mysqldump at defined path, try without any path
		if( $this->options->rsync_path && !file_exists( $this->options->rsync_path ) )
		{
			$this->add_result("rsync not found or not readable at {$this->options->rsync_path}, using PHP instead",3);
			$this->options->rsync_path = '';
		}
		if( !file_exists( $this->options->mysql_path ) )
		{
			if( !$this->options->mysql_path )
				$this->add_result("mysql path not set, using 'mysql'",3);
			else
				$this->add_result("mysql not found or not readable at {$this->options->mysql_path}, using 'mysql' instead and hoping system path is set correctly",3);
			$this->options->mysql_path = 'mysql';
		}
		if( !file_exists( $this->options->mysqldump_path ) )
		{
			if( !$this->options->mysqldump_path )
				$this->add_result("mysqldump path not set, using 'mysqldump'",3);
			else
				$this->add_result("mysqldump not found or not readable at {$this->options->mysqldump_path}, using 'mysqldump' instead and hoping system path is set correctly",3);
			$this->options->mysqldump_path = 'mysqldump';
		}

		return TRUE;
	}

	/* -------------------------------------------------------------- */
	/* !PUBLIC METHODS */
	/* -------------------------------------------------------------- */

	/**
	 * Push files as per parameters.
	 *
	 * Parameters are set as class properties and need to be set before pushing.
	 */
	public function push_files()
	{
		if( $this->push_plugins )
		{
			$backup_file = $this->file_backup( $this->dest_params['web_path'] . $this->dest_params['wp_plugin_dir'] );
			$this->copy_files( $this->source_params['web_path'] . $this->source_params['wp_plugin_dir'], $this->dest_params['web_path'] . $this->dest_params['wp_plugin_dir'], $backup_file, 'plugins', TRUE );
		}

		if( $this->push_mu_plugins )
		{
			$backup_file = $this->file_backup( $this->dest_params['web_path'] . $this->dest_params['wpmu_plugin_dir'] );
			$this->copy_files( $this->source_params['web_path'] . $this->source_params['wpmu_plugin_dir'], $this->dest_params['web_path'] . $this->dest_params['wpmu_plugin_dir'], $backup_file, 'mu_plugins', TRUE );
		}

		if( $this->push_uploads )
		{
			$backup_file = $this->file_backup( $this->dest_params['web_path'] . $this->dest_params['wp_uploads_dir'] );
			$this->copy_files( $this->source_params['web_path'] . $this->source_params['wp_uploads_dir'], $this->dest_params['web_path'] . $this->dest_params['wp_uploads_dir'], $backup_file, 'uploads', TRUE );
		}

		if( $this->push_themes )
		{
			$backup_file = $this->file_backup( $this->dest_params['web_path'] . $this->dest_params['wp_themes_dir'] );
			$this->copy_files( $this->source_params['web_path'] . $this->source_params['wp_themes_dir'], $this->dest_params['web_path'] . $this->dest_params['wp_themes_dir'], $backup_file, 'themes', TRUE );
		}
		
		if( $this->theme )
		{
			$backup_file = $this->file_backup( $this->dest_params['web_path'] . $this->dest_params['wp_themes_dir'] . '/' . $this->theme );
			$this->copy_files( $this->source_params['web_path'] . $this->source_params['wp_themes_dir'] . '/' . $this->theme, $this->dest_params['web_path'] . $this->dest_params['wp_themes_dir'] . '/' . $this->theme, $backup_file, $this->theme, TRUE );
		}

	}
	
	/**
	 * Copy source DB to destintation, with backup
	 * 
	 * @param array $table_groups which groups of tables to push
	 * @access public
	 * @return bool result of push command
	 */
	public function push_db( $table_groups=array() )
	{
		$db_source = $this->options->get_db_params( $this->source );
		$db_dest = $this->options->get_db_params( $this->dest );

		//last minute error checking
		if( $db_source['name'] == $db_dest['name'] )
			SitePushErrors::add_error( 'Database not pushed. Source and destination databases cannot be the same.', 'fatal-error' );
		if( ! @shell_exec("{$this->options->mysql_path} --version") )
			SitePushErrors::add_error( 'mysql not found, not configured properly or PHP safe mode is preventing it from being run.' );
		if( ! @shell_exec("{$this->options->mysqldump_path} --version") )
			SitePushErrors::add_error( 'mysqldump not found, not configured properly or PHP safe mode is preventing it from being run.' );
		if( SitePushErrors::is_error() ) return FALSE;

		//work out which table(s) to push
		$tables = '';
		if( $table_groups )
		{
			foreach( $table_groups as $table_group )
			{
				//if table group is an array, then it is an array of custom table groups
				if( is_array($table_group) )
					$tables .= ' ' . $this->get_custom_tables( $table_group );
				else
					$tables .= ' ' . $this->get_tables( $table_group );
			}
		}
		$tables = trim($tables);
		if( $tables ) 
			$tables = "--tables {$tables}"; //tables parameter isn't strictly speaking necessary, but we'll use it just to be safe
		elseif( is_multisite() )
			$this->fix_domains = TRUE; //we are pushing all tables, and in multisite, so we need to fix domains after push

		//backup database
		$backup_file = $this->database_backup($db_dest);

		//create mysql command
		$source_host = !empty($db_source['host']) ? " --host={$db_source['host']}" : '';
		$dest_host = !empty($db_dest['host']) ? " --host={$db_dest['host']}" : '';
		$dump_command = "{$this->options->mysqldump_path} {$this->dump_options}{$source_host} -u {$db_source['user']} -p'{$db_source['pw']}' {$db_source['name']} {$tables}";
		$mysql_command = "{$this->options->mysql_path} -D {$db_dest['name']} -u {$db_dest['user']}{$dest_host} -p'{$db_dest['pw']}'";
		$command = "{$dump_command} | " . $this->make_remote($mysql_command);

		//write file which will undo the push
		if( $this->save_undo && $backup_file )
		{
			$undo['type'] = 'mysql';
			$undo['original'] = $command;
			//$undo['remote'] = $this->remote_shell; //@later make remote
			$undo['undo'] = "'{$this->options->mysql_path}' -u {$db_dest['user']} -p'{$db_dest['pw']}'{$dest_host} -D {$db_dest['name']} < '{$backup_file}'";
			$this->write_undo_file( $undo );
		}
		
		//turn maintenance mode on
		$this->set_maintenance_mode('on');
		
		if( $tables )
			$this->add_result("Pushing database tables from {$db_source['label']} to {$db_dest['label']}: {$tables}");
		else
			$this->add_result("Pushing whole database",1);		
		$this->add_result("Database source: {$db_source['label']} ({$db_source['name']}) on {$this->source_params['domain']}",2);
		$this->add_result("Database dest: {$db_dest['label']} ({$db_dest['name']}) on {$this->dest_params['domain']}",2);

		//run the command
		$result = $this->my_exec($command);

		//fix multisite domains if required
		if( $this->fix_domains ) $this->fix_multisite_domains();

		//turn maintenance mode off
		$this->set_maintenance_mode('off');

		return $result;
	}
	

	/**
	 * Clear caches on destination. Will attempt to clear W3TC and SuperCache,
	 * and empty any directories defined in sites.ini.php 'caches' parameter.
	 * 
	 * @return bool TRUE if any cache cleared, FALSE otherwise
	 */
	public function clear_cache()
	{
		$return = FALSE;

		//clear any cache directories defined by site parameters
		if( !empty($this->dest_params['caches']) )
		{
			if( !is_array($this->dest_params['caches']) )
				$this->dest_params['caches'] = (array) $this->dest_params['caches'];
			foreach( $this->dest_params['caches'] as $cache )
			{
				$cache_path = $this->trailing_slashit($this->dest_params['web_path']) . ltrim($this->trailing_slashit($cache),'/');
				if( file_exists($cache_path) )
				{
					$command = $this->make_remote("rm -rf {$cache_path}*");
					$this->add_result( "Clearing file cache ({$cache}) on {$this->dest_params['label']}", 1 );
					$result = $this->my_exec($command);
					if( $result )
						$this->add_result( "Result: {$result}", 3 );
					$return = TRUE;
				}
			}
		}
		
		//cache not active on destination, so don't try to clear it
		if( empty($this->dest_params['cache']) )
		{
			if( !$return )
			{
				$error = "Cache not cleared: WordPress cache is not activated on {$this->dest_params['label']}";
				$this->add_result( $error , 1 );
				SitePushErrors::add_error( $error, 'warning' );
			}
		}
		else
		{
			//clear WP cache on destination site
			$cache_key = urlencode( $this->options->cache_key );
			$url = "{$this->trailing_slashit($this->dest_params['domain'])}?sitepush_cmd=clear_cache&sitepush_key={$cache_key}";
			
			$cc_result = $this->callResource($url, 'GET', $data = null);

			//Get explanation returned by destination. First 4 chars are error code, so exclude them
			$result_text = empty( $cc_result['data'] ) ? '' : substr( $cc_result['data'], 4 ).' ';

			if( $cc_result['code']==200 )
			{
				//sucess
				$this->add_result( "Cache: {$result_text}", 1 );
				$return = TRUE;
			}
			elseif(  $cc_result['code']==401 )
			{
				$error = "Cache: could not access destination site to clear cache because authorisation failed (check in your .htaccess that this server can access the destination) [{$cc_result['code']}]";
				$this->add_result( $error, 1 );
				SitePushErrors::add_error( $error );
			}
			else
			{	
				$error = "Error clearing cache: {$result_text}[{$cc_result['code']}]";
				$this->add_result( $error, 1 );
				SitePushErrors::add_error( $error );
			}
		}

		return $return;
	}


/* -------------------------------------------------------------- */
/* !PRIVATE METHODS */
/* -------------------------------------------------------------- */
	
	/**
	 * Backup a directory before we push files
	 *
	 * @param string $path path of dir to backup
	 * @param string $backup_name label for the backup file (defaults to last directory of $path)
	 * @return string|bool path to backup file or FALSE if no backup
	 */
	private function file_backup( $path , $backup_name='' )
	{
		//no backup dir set
		if( !$path ) return FALSE;
	
		//don't backup if directory is really a symlink
		if( is_link($path) )
		{
			$this->add_result("{$path} not backed up, it is a symlink.",1);
			return FALSE;
		}
		
		$last_pos =  strrpos($path, '/') + 1;
		$dir = substr( $path, $last_pos );

		$this->clear_old_backups();
		
		if( !$backup_name ) $backup_name = $dir;
	
		if( file_exists($path) && $this->do_backup )
		{
			$this->add_result("Backing up {$path}",1);

			//where do we backup to
			$backup_file = "{$this->dest_backup_path}/{$this->dest}-{$this->timestamp}-file-{$backup_name}.tgz";

			//create the backup command
			$command = $this->make_remote("cd '{$path}'; cd ..; tar -czf '{$backup_file}' '{$dir}'; chmod 400 '{$backup_file}'");
			
			//run the backup command
			$this->my_exec($command);
						
			//add the backup file to the backups array so we know what's been done for user reporting etc
			$this->add_result("Backup file is at {$backup_file}",1);

			//return the backup file name/path so we can undo		
			return $backup_file;
		}
		elseif( !$this->do_backup )
		{
			$this->add_result("File backup off",2);
			return FALSE;
		}
		else
		{
			//we didn't backup, so return FALSE
			$this->add_result("{$path} not backed up, because it was not found.",1);
			return FALSE;
		}
	}
	
	/**
	 * Backup a database before we push
	 *
	 * @param array $db DB config array
	 * @return string|bool path to backup file or FALSE if no backup
	 */
	private function database_backup( $db )
	{
		if( $this->do_backup )
		{
			$this->clear_old_backups();

			//where do we backup to
			$destination = "{$this->dest_backup_path}/{$this->dest}-{$this->timestamp}-db-{$db['name']}.sql";
			
			//DB host parameter if needed
			$dest_host = !empty($db['host']) ? " --host={$db['host']}" : '';
			
			//create the backup command
			$command = $this->make_remote("mysqldump {$this->dump_options} -r {$destination}{$dest_host} -u {$db['user']} -p'{$db['pw']}' {$db['name']}; chmod 400 {$destination}");

			//add the backup file to the backups array so we know what's been done for user reporting etc
			$this->add_result("Backing up {$this->dest} DB",1);
			$this->add_result("Backup file is at {$destination}",2);
			
			//run the backup command
			$this->my_exec($command);

			//return the backup file name/path so we can undo			
			return $destination;
		}
		else
		{
			//we didn't backup, so return FALSE
			$this->add_result("Database backup off",2);
			return FALSE;
		}
	}
	
	/**
	 * Delete old backups. Backups older than the backup_keep_time option will be deleted.
	 * It runs whenever a new backup is made.
	 *
	 * @later will need to be updated for remote site backups
	 *
	 * @return bool TRUE if any backups deleted
	 */
	private function clear_old_backups()
	{
		static $already_cleared_backups;
		if( $already_cleared_backups )
		{
			$this->add_result("Skipping backup clear because we've already run it.",3);
			return FALSE;
		}
		else
		{
			$already_cleared_backups = TRUE;
		}

		if( empty($this->dest_backup_path) )
		{
			$this->add_result("Skipping backup clear because backup directory not set.",3);
			return FALSE;
		}

		if( !$this->options->backup_keep_time )
		{
			$this->add_result("Not clearing backups.",3);
			return FALSE;
		}

		$this->add_result("Checking for old backups to clear at {$this->dest_backup_path}",2);

		$have_deleted = FALSE;
		if( !$this->options->backup_keep_time ) return FALSE;
		
		//anything older than this is too old
		$too_old = time() - $this->options->backup_keep_time*24*60*60;
		
		foreach( scandir($this->dest_backup_path) as $backup )
		{
		
			//skip if it doesn't look like a sitepush backup file
			if( !in_array( substr($backup, -4), array( '.sql', '.tgz', 'undo') ) ) continue;
			$this->add_result("Checking {$backup}",4);
		
			if( filemtime("{$this->dest_backup_path}/{$backup}") < $too_old )
			{
				$this->add_result("Deleting old backup at {$this->dest_backup_path}/{$backup}");
				unlink("{$this->dest_backup_path}/{$backup}");
				$have_deleted = TRUE;
			}
		}
		
		if( ! $have_deleted )
			$this->add_result('No old backups found to delete', 2);
		
		return $have_deleted;
	}

	/**
	 * Get tables for any given push group.
	 *
	 * @param string $group name of a table group
	 * @return string list of tables for group
	 */
	private function get_tables( $group )
	{
		switch( $group )
		{
			case 'options':
				$tables = '%prefix%options';
				if( is_multisite() ) $this->fix_domains = TRUE;
				break;
			case 'comments':
				$tables = '%prefix%commentmeta %prefix%comments';
				break;
			case 'content':
				$tables = '%prefix%links %prefix%postmeta %prefix%posts %prefix%term_relationships %prefix%term_taxonomy %prefix%terms';
				break;
			case 'users':
				$tables = '%prefix%usermeta %prefix%users';
				break;
			case 'multisite':
				global $wpdb;
				//get all MS tables from $wpdb and add base prefix
				foreach( $wpdb->ms_global_tables as $ms_table )
				{
					$ms_tables[]  = $wpdb->base_prefix . $ms_table;
				}
				$tables = implode(' ', $ms_tables);
				$this->fix_domains = TRUE;
				break;
			case 'all_tables':
				$tables = '';
				if( is_multisite() ) $this->fix_domains = TRUE;
				break;
			default:
				die("Unknown or no db-type option.\n");
		}
		
		//add correct DB prefix to all tables (except multisite tables - already done above)
		if( $tables )
			$tables = str_replace('%prefix%',$this->db_prefix,$tables);

		return $tables;
	}

	/**
	 * Get tables for custom table groups
	 *
	 * @param array $groups custom table groups. key is equal to number for group in order added in options screen.
	 *
	 * @return string list of tables
	 */
	private function get_custom_tables( $groups )
	{
		$tables = array();
		foreach( $groups as $group )
		{
			$group_array = $this->options->db_custom_table_groups_array[ $group ];
			$tables = array_merge( $tables, $group_array['tables']);
		}

		//add db_prefix to each table and return
		return $this->db_prefix . implode( ' ' . $this->db_prefix, $tables );
	}

	/**
	 * In multisite mode WordPress uses site domain to work out which blog to show. Domains are stored in the database.
	 * However, domains will be different for different SitePush versions of a site, so this won't work unless
	 * we make sure the database has the right domains for the right sites.
	 *
	 * This method makes sure the database has the correct domains in place.
	 *
	 * @requires domain map file (path set in options)
	 */
	private function fix_multisite_domains()
	{
		$sitepush_domain_map = parse_ini_file( $this->options->domain_map_conf, TRUE );

		if( $this->dry_run )
		{
			$this->add_result("Fixing destination domains for multisite (not done because this is a dry run)",2);
			return FALSE;
		}
		else
		{
			$this->add_result("Fixing destination domains for multisite",2);
		}

		//create a new WPDB object for the database we are pushing to
		$db_dest = $this->options->get_db_params( $this->dest );
		$dest_host = empty($db_dest['host']) ? DB_HOST : $db_dest['host'];
		$spdb = new wpdb( $db_dest['user'], $db_dest['pw'], $db_dest['name'], $dest_host );

		//set up $spdb properties
		global $table_prefix;
		$spdb->set_prefix( $table_prefix );

		$sitepush_replace_urls = $sitepush_domain_map[ $this->dest ];
		unset( $sitepush_domain_map[ $this->dest ] );
		$sitepush_search_sites = array_keys( $sitepush_domain_map );

		$blogs = $spdb->get_results( $spdb->prepare("SELECT blog_id FROM $spdb->blogs"), ARRAY_A );

		//cycle through each domain for this site
		foreach( $sitepush_replace_urls as $site_id=>$sitepush_replace_url )
		{
			//cycle through each sitepush site
			foreach( $sitepush_search_sites as $sitepush_search_site )
			{
				$sitepush_search_url = empty($sitepush_domain_map[ $sitepush_search_site ][ $site_id ]) ? '' : $sitepush_domain_map[ $sitepush_search_site ][ $site_id ] ;
				if( !$sitepush_search_url ) continue; //domain wasn't specified for that site

				//update domain in wp_site
				$spdb->query( $spdb->prepare(
					"UPDATE {$spdb->site} SET domain = %s WHERE domain = %s",
					array( $sitepush_replace_url, $sitepush_search_url )
				));

				//update domain in wp_sitemeta
				$spdb->query( $spdb->prepare(
					"UPDATE {$spdb->sitemeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key = 'siteurl'",
					array( $sitepush_search_url, $sitepush_replace_url )
				));

				//update domain in wp_blogs
				$spdb->query( $spdb->prepare(
					"UPDATE {$spdb->blogs} SET domain = %s WHERE domain = %s",
					array( $sitepush_replace_url, $sitepush_search_url )
				));

				//update domain in main wp_options
				$spdb->query( $spdb->prepare(
					"UPDATE {$spdb->base_prefix}options SET option_value = REPLACE(option_value, %s, %s) WHERE option_name = 'siteurl' OR option_name = 'home' OR option_name = 'fileupload_url' ",
					array( $sitepush_search_url, $sitepush_replace_url )
				));

				//update domain in wp_options for each site
				foreach( $blogs as $blog_id )
				{
					$spdb->query( $spdb->prepare(
						"UPDATE {$spdb->base_prefix}{$blog_id['blog_id']}_options SET option_value = REPLACE(option_value, %s, %s) WHERE option_name = 'siteurl' OR option_name = 'home' OR option_name = 'fileupload_url' ",
						array( $sitepush_search_url, $sitepush_replace_url )
					));
				}

			}
		}
	}

	/**
	 * Copy files from source to dest deleting anything in dest not in source.
	 * Aborts if either source or dest is a symlink.
	 *
	 * @param string $source_path
	 * @param string $dest_path
	 * @param string $backup_file path to backup file - for undo logging
	 * @param string $dir name of directory backed up - for undo logging
	 * @param bool $maint_mode if TRUE, turn on maintenance mode when running
	 * @return bool TRUE if copy was run, FALSE if not
	 */
	private function copy_files( $source_path, $dest_path, $backup_file='', $dir='', $maint_mode=FALSE )
	{
		//don't copy if source or dest are symlinks
		if( is_link( rtrim($source_path,'/') ) )
		{
			SitePushErrors::add_error( "Could not push from {$source_path} because it is a symlink and not a real directory.", 'warning' );
			return FALSE;
		}
		elseif( is_link( rtrim($dest_path,'/') ) )
		{
			SitePushErrors::add_error( "Could not push to {$dest_path} because it is a symlink and not a real directory.", 'warning' );
			return FALSE;
		}
		
		//make sure dest dir exists
		//note - mkdir gives warning if dir or parent already exists
		if( ! file_exists($dest_path) ) @mkdir($dest_path,0755,TRUE);

		//turn maintenance mode on, if required
		if( $maint_mode ) $this->set_maintenance_mode('on');
		
		//add to the results log so we know what has been done
		$this->add_result("Pushing files from {$this->source} to {$this->dest}",1);
		$this->add_result("Files source path: {$source_path}",2);
		$this->add_result("Files dest path: {$dest_path}",2);

		//copy the files
		if( $this->options->rsync_path )
			$result = $this->linux_rsync( $source_path, $dest_path, $backup_file, $dir );
		else
			$result = $this->php_rsync( $source_path, $dest_path, $backup_file, $dir );

		//turn maintenance mode off
		if( $maint_mode ) $this->set_maintenance_mode('off');

		//check we have copied OK
		if( !$result )
		{
			SitePushErrors::add_error( "One or more files failed to copy correctly. Please make sure that the destination files and directories have the correct file permissions.", 'error' );
		}
		else
		{
			$source_path = rtrim( $source_path, '/' );
			$dest_path = rtrim( $dest_path, '/' );
			$this->add_result("Checking file push...",3);
			if( ! ( $this->validate_copy( $source_path, $dest_path, $this->options->get_dont_syncs() ) ) )
				SitePushErrors::add_error( "Files do not appear to have copied properly. Please make sure that the destination files and directories have the correct file permissions.", 'error' );
			else
				$this->add_result("Files pushed OK",3);
		}

		return $result;
	}

	/**
	 * Copy files using linux rsync
	 *
	 * @param string $source_path
	 * @param string $dest_path
	 * @param string $backup_file path to backup file - for undo logging
	 * @param string $dir name of directory backed up - for undo logging
	 *
	 * @return bool TRUE if sync done, FALSE otherwise
	 */
	private function linux_rsync( $source_path, $dest_path, $backup_file='', $dir='' )
	{
		//for rsync we need trailing slashes on directories
		$source_path = $this->trailing_slashit( $source_path );
		$dest_path = $this->trailing_slashit( $dest_path );

		//check that rsync is present
		if( ! @shell_exec("{$this->options->rsync_path} --version") )
		{
			SitePushErrors::add_error( 'rsync not found, not configured properly or PHP safe mode is preventing it from being run', 'error' );
			return FALSE;
		}

		//rsync option parameters
		$rsync_options = "-avz --delete";

		//add the excludes to the options
		foreach( $this->options->get_dont_syncs() as $exclude )
		{
			$exclude = trim($exclude);
			$rsync_options .= " --exclude='{$exclude}'";
		}

		//create the command
		$command = "{$this->options->rsync_path} {$rsync_options} '{$source_path}' '{$dest_path}'";

		//write file which will undo the push
		if( $this->source_backup_path && $this->save_undo && $dir && $backup_file )
		{
			$undo_dir = "{$this->dest}-{$this->timestamp}-undo_files";
			$undo['type'] = 'linux_rsync';
			$undo['original'] = $command;
			$undo['undo'][] = "cd '{$this->dest_backup_path}'; mkdir '{$undo_dir}'; cd '{$undo_dir}'; tar -zpxf '{$backup_file}'"; //prep
			$undo['undo'][] = "'{$this->options->rsync_path}' {$rsync_options} '{$this->dest_backup_path}/{$undo_dir}/{$dir}/' '{$dest_path}'"; //sync
			$this->write_undo_file( $undo );
		}

		//add push type to log
		$this->add_result("Sync type: linux_rsync",2);

		//run the command
		return (bool) $this->my_exec($command, 3, '/rsync error: /');
	}

	/**
	 * Copy files in an rsync like manner, but using PHP
	 *
	 * @param string $source_path
	 * @param string $dest_path
	 * @param string $backup_file path to backup file - for undo logging
	 * @param string $dir name of directory backed up - for undo logging
	 *
	 * @return bool TRUE if sync done, FALSE otherwise
	 */
	private function php_rsync( $source_path, $dest_path, $backup_file='', $dir='' )
	{
		//make sure we don't have trailing slashes
		$source_path = rtrim( $source_path, '/' );
		$dest_path = rtrim( $dest_path, '/' );

		//write file which will undo the push
		if( $this->source_backup_path && $this->save_undo && $dir && $backup_file )
		{
			//$undo_dir = "{$this->dest}-{$this->timestamp}-undo_files";
			$undo['type'] = 'php_rsync';
			$undo['undo'][] = '#undo not yet implemented for php_rsync';
			//$undo['original'] = $command;
			//$undo['undo'][] = "cd '{$this->dest_backup_path}'; mkdir '{$undo_dir}'; cd '{$undo_dir}'; tar -zpxf '{$backup_file}'"; //prep
			//$undo['undo'][] = "'{$this->options->rsync_path}' {$rsync_options} '{$this->dest_backup_path}/{$undo_dir}/{$dir}/' '{$dest_path}'"; //sync
			$this->write_undo_file( $undo );
		}

		//add push type to log
		$this->add_result("Sync type: php_rsync",2);

		return $this->php_rsync_core( $source_path, $dest_path, $this->options->get_dont_syncs() );
	}

	/**
	 * Copy files from source to dest, and delete any files in dest which are not present in source
	 *
	 * @param string $source_path
	 * @param string $dest_path
	 * @param array  $excludes files to exclude from sync
	 *
	 * @return bool TRUE if all files copied successfully
	 */
	private function php_rsync_core( $source_path, $dest_path, $excludes=array() )
	{
		$result = TRUE;

		//copy all files, iterating through directories
		foreach( scandir( $source_path ) as $file )
		{
			if( '.'==$file || '..'==$file || in_array( $file, $excludes ) ) continue;
			$source_file_path = $source_path . '/' . $file;
			$dest_file_path = $dest_path . '/' . $file;

			if( is_dir( $source_file_path ) )
			{
				if( !file_exists( $dest_file_path) ) mkdir( $dest_file_path );
				$this->php_rsync_core( $source_file_path, $dest_file_path );
				continue;
			}

			if( file_exists( $dest_file_path ) && md5_file( $source_file_path ) ===  md5_file( $dest_file_path ) )
			{
				$this->add_result("php_rsyc: did not copy (files are the same)  {$source_file_path} -> {$dest_file_path}",5);
				continue;
			}

			$this->add_result("php_rsync: {$source_file_path} -> {$dest_file_path}",4);
			if( !copy( $source_file_path, $dest_file_path ) )
			{
				$result = FALSE;
				$this->add_result("php_rsync: failed to copy {$source_file_path} -> {$dest_file_path}",3);
			}
		}

		//iterate through dest directories to remove any files/dirs not present in source
		foreach( scandir( $dest_path ) as $file )
		{
			if( '.'==$file || '..'==$file || in_array( $file, $excludes ) ) continue;
			$source_file_path = $source_path . '/' . $file;
			$dest_file_path = $dest_path . '/' . $file;

			if( !file_exists($source_file_path) )
			{
				if( is_dir($dest_file_path) )
				{
					$it = new RecursiveDirectoryIterator( $dest_file_path );
					$del_files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST );
					foreach($del_files as $del_file)
					{
						if ($del_file->isDir())
						{
							$rp = $del_file->getRealPath();
							rmdir($rp);
							$this->add_result("php_rsync: removing empty directory {$rp}",4);
						}
						else
						{
							$rp = $del_file->getRealPath();
							unlink($rp);
							$this->add_result("php_rsync: deleting file {$rp}",5);
						}
					}

					$this->add_result("php_rsync: removing empty directory {$dest_file_path}",4);
					rmdir( $dest_file_path );
				}
				else
				{
					$this->add_result("php_rsync: deleting file {$dest_file_path}",5);
					unlink( $dest_file_path );
				}
			}
		}

		return $result;
	}

	private function validate_copy( $source_path, $dest_path, $excludes=array() )
	{
		return TRUE;


		foreach( scandir( $source_path ) as $file )
		{
			if( '.'==$file || '..'==$file || in_array( $file, $excludes ) ) continue;
			$source_file_path = $source_path . '/' . $file;
			$dest_file_path = $dest_path . '/' . $file;

			if( is_dir( $source_file_path ) && $this->validate_copy( $source_file_path, $dest_file_path ) )
				continue;
			elseif( ! is_dir( $source_file_path ) && file_exists( $dest_file_path ) && md5_file($source_file_path ) ===  md5_file( $dest_file_path ) )
				continue;

			//file match failed
			$this->add_result("sync_validate: file failed to copy {$source_file_path} -> {$dest_file_path}",3);
			return FALSE;
		}

		//everything matched OK
		return TRUE;
	}

	/**
	 * Make a command suitable for running on a remote system. If destination is remote, run command through remote shell
	 *
	 * @later not currently implemented
	 *
	 * @param $command
	 * @param string $remote
	 * @return mixed
	 */
	private function make_remote( $command, $remote=NULL )
	{
		/*
		if( is_null($remote) ) $remote = $this->dest_params['remote'];
		if( $remote )
		{
			$command = "{$this->remote_shell} '{$command}'";
		}
		*/
		return $command;
	}
	
	/**
	 * Write undo actions to undo file.
	 * Undo file is always written to source, so it may be in different place to the backups if we are pushing to a remote server.
	 *
	 * @param array $undos undo actions to be written to file
	 * @return bool TRUE if written, FALSE if not
	 */
	private function write_undo_file( $undos=array() )
	{
		if( !$this->source_backup_path ) return FALSE;
	
		//define the undo file
		$undo_file = "{$this->source_backup_path}/{$this->dest}-{$this->timestamp}.undo";
		
		//write file so we know what the last timestamp for backups was
		file_put_contents($this->source_backup_path . '/last', $undo_file);

		$undo_text = "#\n# start undo\n#\n";
		foreach( $undos as $key=>$undo )
		{
			switch( $key )
			{
				case 'undo':
					$undo_text .= "# undo command:\n";
					if( !is_array($undo) ) $undo = array( $undo );
					foreach( $undo as $undo_item )
					{
						$undo_text .= "{$undo_item}\n";
					}
					$undo_text .= "\n";
					break;
					
				case 'type':
					$undo_text .= "# type {$undo}\n";
					break;
										
				default:
					$undo_text .= "# {$key}:\n";
					$undo_text .= "## {$undo}\n";
					break;
			}

		}
		$undo_text .= "#\n# end undo\n#\n\n\n";
		
		if( file_exists($undo_file) ) chmod($undo_file, 0600);
		$result = file_put_contents($undo_file, $undo_text, FILE_APPEND);
		chmod($undo_file, 0400);

		return (bool) $result;
	}

	/**
	 * Make sure a path ends in a slash
	 *
	 * @param $path
	 * @return string
	 */
	private function trailing_slashit( $path )
	{
		return rtrim( $path , '/' ) . '/';
	}

	/**
	 * Wrapper for shell_exec etc which adds logging and does nothing if dry_run is set
	 *
	 * @param string $command
	 * @param int    $log_level
	 * @param string $error_regexp if result ever matches this regex, then function will return false to indicate an error
	 *
	 * @return bool|string FALSE if error, otherwise output from command
	 */
	private function my_exec($command,$log_level=3,$error_regexp='')
	{
		$log_command = htmlspecialchars($command);
		$error = FALSE;

		if(!$this->dry_run)
		{
			$this->add_result("RUN: {$log_command}",$log_level);
			
			if( $this->echo_output )
			{
				$result = '';
				if(!$fh = popen($command . ' 2>&1', "r"))
				{
					die ("Could not fork: $command");
				}
				while(!feof($fh))
				{
					$output = fgetc($fh);
					echo( $output );
					$result .= $output;

					if( $error_regexp )
					{
						if( preg_match( $error_regexp, $result ) ) $error = TRUE;
					}
				}
				pclose($fh);
				return $error ? FALSE : $result;
			}
			else
			{
				exec($command . ' 2>&1', $output, $result );
				return $result ? FALSE : implode( "\n", $output );
			}
		}
		else
		{
			$this->add_result("DRYRUN: {$log_command}",$log_level);
			return FALSE;
		}
	
	}

	/**
	 * Mask password for logs
	 *
	 * @param $command
	 * @return mixed
	 */
	private function sanitize_cmd($command)
	{
		if( !$this->hide_passwords ) return $command;

		$db_source = $this->options->get_db_params( $this->source );
		$db_dest = $this->options->get_db_params( $this->dest );

		if( !$db_source || !$db_dest ) return $command;

		if( $this->options->mask_passwords )
			$command = str_replace(array($db_source['pw'], $db_dest['pw']), array('*****', '*****'), $command);

		return $command;
	}
	
	/**
	 * Add a result to results array and optionally output
	 *
	 * @param string $result
	 * @param int $log_level log level for this result
	 */
	private function add_result( $result, $log_level=1 )
	{
		$this->results[] = array( 'level'=>$log_level, 'msg'=>trim($this->sanitize_cmd($result)) );
		
		if( $this->echo_output && $this->options->debug_output_level>=$log_level )
		{
			if( '--' == $result )
				echo "\n";
			else
				echo "[{$log_level}] " . trim($this->sanitize_cmd($result)) . "\n";
			@flush();
			@ob_flush();
		}
	}
	
	/**
	 * Return results up to defined log level
	 *
	 * @param mixed $max_level output up to log level $max_level
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
	
	/**
	 * Send http request and get results. Used for cache clearing.
	 *
	 * Based on code sample from iContact
	 *
	 * @param $url
	 * @param string $method
	 * @param mixed $data
	 * @param string $type
	 * @return array results from call
	 */
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

	/**
	 * Turn WP maintenance mode on/off
	 *
	 * @param bool $switch
	 * @return bool
	 */
	private function set_maintenance_mode( $switch=FALSE )
	{
		$maint_file = '<?php \$upgrading='.time().'; ?>';
		$maint_file_path = $this->dest_params['web_path'] . $this->trailing_slashit($this->dest_params['wp_dir']) . '.maintenance';
		
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

		//remember whether or not we are in maint mode
		$this->maintenance_mode = $switch;

		return TRUE;
	}
	
}


/*
METHODS FOR FUTURE DEVELOPMENT

	/**
	 * undo function.
	 * 
	 * undo either the last push, or a specific one if specified
	 * 
	 * @access public
	 * @return string :what happened
	 *
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
	 * Wrapper for file_put_contents which works for remote site as well
	 *
	 * @param $filename
	 * @param $data
	 * @param null $flags
	 * @param null $context
	 * @return bool|int|string
	 *
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


*/

/* EOF */