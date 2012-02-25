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
	public $source_params;
	public $dest_params;
	
	//the following options could be set from web interface
	//where we look for info about the sites we are pushing
	public $sites_conf_path; //full path to sites.conf file **required**
	public $dbs_conf_path; //full path to sites.conf file **required**
	
	//do we want to save undo files
	public $save_undo = TRUE;
	private $undo_file; //read from get_undo_file method
	
	//where to store backups
	public $source_backup_path; //undo files etc **required**
	public $dest_backup_path; //file archives, db_dumps etc **required**
	public $backup_keep_time; //how many days to keep backups

	//mysqldump options
	private $dump_options = "--opt";
	
	//rsync/ssh options - used for pushing to remote site
	public $remote_user; //user account on remote destination
	public $ssh_key_dir; //where ssh key is on local server for remote push (key must be named same as the remote server)
	//private $remote_shell; //set up by __construct

	//are we in wordpress maintenance mode or not
	private $maintenance_mode = 'off';
	
	//seconds before a push will timeout. If you have a lot of files or a large DB, you may need to increase this.
	public $push_time_limit = 6000;

	//should push output be echoed in realtime?
	public $echo_output = TRUE;

	//holds any errors for later output
	public $errors = array();
	
	//passwords will be replaced with **** in any log output
	public $hide_passwords = TRUE;
	
	//set to TRUE for additional debug output
	public $debug = FALSE;

	/**
	 * @var SitePushOptions object holding options
	 */
	private $options;

	/**
	 * @var string $source name of push source
	 * @var string $dest name of push dest	 *
	 * @var SitePushOptions object holding options
	 */
	function __construct( $source, $dest, $options )
	{
		//give push plenty of time to complete
		set_time_limit( 6000 );

		$this->options = $options;
		$this->check_requirements();

		$this->source = $source;
		$this->dest = $dest;

		//WP sets this to UTC somewhere which confuses things...
		if( $this->options->timezone )
			date_default_timezone_set( $this->options->timezone );

		//get params for source and dest
		$this->source_params = $this->options->get_site_params( $this->source );
		if( !$this->source_params ) die("Unknown site config '{$this->source}'.\n"); //@todo better error handling

		$this->dest_params = $this->options->get_site_params( $this->dest );
		if( !$this->dest_params ) die("Unknown site config '{$this->dest}'.\n");

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
		
		//if we can't find rsync/mysql/mysqldump at defined path, try without any path
		if( !file_exists( $this->options->rsync_path ) )
		{
			$this->options->rsync_path = 'rsync';
			if( !$this->options->rsync_path )
				$this->add_result("rsync path not set, using 'rsync'",3);
			else
				$this->add_result("rsync not found at {$this->options->rsync_path}, using 'rsync' instead and hoping system path is set correctly",3);
		}
		if( !file_exists( $this->options->mysql_path ) )
		{
			$this->options->mysql_path = 'mysql';
			if( !$this->options->mysql_path )
				$this->add_result("mysql path not set, using 'mysql'",3);
			else
				$this->add_result("mysql not found at {$this->options->mysql_path}, using 'mysql' instead and hoping system path is set correctly",3);
		}
		if( !file_exists( $this->options->mysqldump_path ) )
		{
			$this->options->mysqldump_path = 'mysqldump';
			if( !$this->options->mysqldump_path )
				$this->add_result("mysqldump path not set, using 'mysqldump'",3);
			else
				$this->add_result("mysqldump not found at {$this->options->mysqldump_path}, using 'mysqldump' instead and hoping system path is set correctly",3);
		}

		$this->errors = array_merge($this->errors, $errors);
		
		return ! (bool) $errors;			
	}

/* -------------------------------------------------------------- */
/* !PUBLIC METHODS */
/* -------------------------------------------------------------- */

	//push everything as per parameters
	public function push_files()
	{
		if( $this->push_plugins )
		{
			$backup_file = $this->file_backup( $this->dest_params['web_path'] . $this->dest_params['wp_plugins_dir'] );
			$this->copy_files( $this->source_params['web_path'] . $this->source_params['wp_plugins_dir'], $this->dest_params['web_path'] . $this->dest_params['wp_plugins_dir'], $backup_file, 'plugins', TRUE );
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
	 * push_db function.
	 * 
	 * copy source db to destintation, with backup
	 * 
	 * @param mixed $table_groups which groups of tables to push, either array of groups, or a single group as string
	 * @access public
	 * @return bool result of push command
	 */
	public function push_db( $table_groups=array() )
	{
		// $this->set_all_params(); //@cleanup - should be ok to remove

		$db_source = $this->options->get_db_params( $this->source );
		$db_dest = $this->options->get_db_params( $this->dest );

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

		return $this->copy_db($db_source, $db_dest, $tables, TRUE);
	}
		

	//backs up and copies a database
	private function copy_db($db_source, $db_dest, $tables='', $maint_mode=FALSE )
	{
		//check that mysql/mysqldump are present
		$errors = array();
		$result = shell_exec("{$this->options->mysql_path} --version");
		if( !$result ) $errors[]='mysql not found or not configured properly.';
		$result = shell_exec("{$this->options->mysqldump_path} --version");
		if( !$result ) $errors[]='mysqldump not found or not configured properly.';
		if( $errors )
		{
			$this->errors = array_merge($this->errors, $errors);
			return FALSE;
		}


		$backup_file = $this->database_backup($db_dest);
		
		//set PHP script timelimit
		set_time_limit( $this->push_time_limit );
		
		$source_host = !empty($db_source['host']) ? " --host={$db_source['host']}" : '';
		$dest_host = !empty($db_dest['host']) ? " --host={$db_dest['host']}" : '';
		
		$test = '';
		//$test = " --ignore-table={$db_source['name']}.wp_posts";
		
		$dump_command = "{$this->options->mysqldump_path} {$this->dump_options}{$source_host}{$test} -u {$db_source['user']} -p'{$db_source['pw']}' {$db_source['name']} {$tables}";
		
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
		if( $maint_mode ) $this->set_maintenance_mode('on');
		
		if( $tables )
			$this->add_result("Pushing database tables from {$db_source['label']} to {$db_dest['label']}: {$tables}");
		else
			$this->add_result("Pushing whole database",1);		
		$this->add_result("Database source: {$db_source['label']} ({$db_source['name']}) on {$this->source_params['domain']}",2);
		$this->add_result("Database dest: {$db_dest['label']} ({$db_dest['name']}) on {$this->dest_params['domain']}",2);

		//@todo $this->dest_params etc is null, also lots of other $this-> properties null???

		$result = $this->my_exec($command);
		$this->add_result('--');
		
		//turn maintenance mode off
		if( $maint_mode ) $this->set_maintenance_mode('off');
		
		return $result;
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
		// $this->set_all_params(); //@cleanup - should be ok to remove
		$result = '';
		$return = FALSE;

		//clear any cache directories defined by site parameters
		if( array_key_exists('caches', $this->sites[$this->dest]) && is_array($this->sites[$this->dest]['caches']) )
		{
			foreach( $this->sites[$this->dest]['caches'] as $cache )
			{
				$cache_path = $this->trailing_slashit($this->dest_params['web_path']) . ltrim($this->trailing_slashit($cache),'/') . '*';
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

			//Get explanation returned by destination. First 4 chars are error code, so exclude them
			$result_text = empty( $cc_result['data'] ) ? '' : substr( $cc_result['data'], 4 ).' ';

			if( $cc_result['code']==200 )
			{
				//sucess
				$result .= "Cache: {$result_text}";
				$return = TRUE;
			}
			elseif(  $cc_result['code']==401 )
			{
				$error = "Cache: could not access destination site to clear cache because authorisation failed (check in your .htaccess that this server can access the destination) [{$cc_result['code']}]";
				$result .= $error;
				$result .= "\n{$url}";
				$this->errors[] = $error;
			}
			else
			{	
				$error = "Error clearing cache: {$result_text}[{$cc_result['code']}]";
				$result .= $error;
				$result .= "\n{$url}";
				$this->errors[] = $error;
			}
		}
			
		$this->add_result($result);
		$this->add_result('--');
		
		return $return;
	}


/* -------------------------------------------------------------- */
/* !PRIVATE METHODS */
/* -------------------------------------------------------------- */
	
	/**
	 * file_backup
	 * 
	 * backup a directory before we push files
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
			$this->add_result('--');

			//return the backup file name/path so we can undo		
			return $backup_file;
		}
		elseif( !$this->do_backup )
		{
			$this->add_result("File backup off",2);
			$this->add_result('--',2);
			return FALSE;
		}
		else
		{
			//we didn't backup, so return FALSE
			$this->add_result("{$path} not backed up, because it was not found.",1);
			$this->add_result('--',1);		
			return FALSE;
		}
	}
	
	//backup the database before we push
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

			$this->add_result('--');
		
			//return the backup file name/path so we can undo			
			return $destination;
		}
		else
		{
			//we didn't backup, so return FALSE
			$this->add_result("Database backup off",2);
			$this->add_result('--',2);
			return FALSE;
		}
	}
	
	/**
	 * clear_old_backups
	 * 
	 * Deletes old backups. Backups older than the backup_keep_time option will be deleted.
	 * It runs whenever a new backup is made.
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
			$this->add_result('--',3);
			return FALSE;
		}
		else
		{
			$already_cleared_backups = TRUE;
		}

		if( empty($this->dest_backup_path) )
		{
			$this->add_result("Skipping backup clear because backup directory not set.",3);
			$this->add_result('--',3);
			return FALSE;
		}

		if( !$this->options->backup_keep_time )
		{
			$this->add_result("Not clearing backups.",3);
			$this->add_result('--',3);
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
		
		$this->add_result('--', $have_deleted ? 1 : 2);
		return $have_deleted;
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
				die("Unknown or no db-type option.\n");
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
	
	//
	//
	//
	/**
	 * copy_files
	 *
	 * Copies files from source to dest deleting anything in dest not in source.
	 * Aborts if either source or dest is a symlink.
	 *
	 * @param string $source_path
	 * @param string $dest_path
	 * @param string $backup_file
	 * @param string $dir
	 * @param bool $maint_mode
	 * @return bool TRUE if copy was run, FALSE if not
	 */
	private function copy_files($source_path,$dest_path,$backup_file='',$dir='',$maint_mode=FALSE)
	{
		//check that rsync is present
		if( !shell_exec("{$this->options->rsync_path} --version") )
		{
			$this->errors[]='rsync not found or not configured properly.';
			return FALSE;
		}

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
		if( !empty($this->dest_params['remote']) )
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
		$excludes = is_array($this->options->dont_sync) ? $this->options->dont_sync : explode( ',', $this->options->dont_sync );
		foreach( $excludes as $exclude )
		{
			$exclude = trim($exclude);
			$rsync_options .= " --exclude='{$exclude}'";
		}
		
		//create the command
		$command = "{$this->options->rsync_path} {$rsync_options} '{$source_path}' '{$remote_site}{$dest_path}'";
		
		//write file which will undo the push
		if( $this->source_backup_path && $this->save_undo && $dir && $backup_file )
		{
			$undo_dir = "{$this->dest}-{$this->timestamp}-undo_files";
			$undo['type'] = 'rsync';
			$undo['original'] = $command;
			//$undo['remote'] = $this->remote_shell; //@todo add remote
			$undo['undo'][] = "cd '{$this->dest_backup_path}'; mkdir '{$undo_dir}'; cd '{$undo_dir}'; tar -zpxf '{$backup_file}'"; //prep
			$undo['undo'][] = "'{$this->options->rsync_path}' {$rsync_options} '{$this->dest_backup_path}/{$undo_dir}/{$dir}/' '{$dest_path}'"; //sync
			$this->write_undo_file( $undo );
		}
		
		//turn maintenance mode on, if required
		if( $maint_mode ) $this->set_maintenance_mode('on');
		
		//add to the results log so we know what has been done
		$this->add_result("Pushing files from {$this->source} to {$this->dest}",1);
		$this->add_result("Files source path: {$source_path}",2);
		$this->add_result("Files dest path: {$dest_path}",2);

		//run the command
		$this->my_exec($command);
		$this->add_result('--');
		
		//turn maintenance mode off
		if( $maint_mode ) $this->set_maintenance_mode('off');

		return TRUE;
	}

	//if destination is remote, run command through remote shell
	//@later - add remote capabilities
	private function make_remote( $command, $remote='not_set' )
	{
		/*
		if( $remote=='not_set') $remote = $this->dest_params['remote'];
		if( $remote )
		{
			$command = "{$this->remote_shell} '{$command}'";
		}
		*/
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
	

	/**
	 * write_undo_file
	 *
	 * Writes undo actions to undo file.
	 * Undo file is always written to source, so it may be in different place to the backups if we are pushing to a remote server.
	 *
	 * @param array $undos undo actions to be written to file
	 * @return bool TRUE if written, FALSE if not
	 */
	private function write_undo_file( $undos=array() )
	{
		if( !$this->source_backup_path ) return FALSE;
	
		//define the undo file
		$this->undo_file = "{$this->source_backup_path}/{$this->dest}-{$this->timestamp}.undo";
		
		//write file so we know what the last timestamp for backups was
		file_put_contents($this->source_backup_path . '/last', $this->undo_file);

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
		
		if( file_exists($this->undo_file) ) chmod($this->undo_file, 0600);
		$result = file_put_contents($this->undo_file, $undo_text, FILE_APPEND);
		chmod($this->undo_file, 0400);

		return (bool) $result;
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

		$db_source = $this->options->get_db_params( $this->source );
		$db_dest = $this->options->get_db_params( $this->dest );

		if( !$db_source || !$db_dest ) return $command;
		
		return str_replace(array($db_source['pw'], $db_dest['pw']), array('*****', '*****'), $command);
	}
	
	//config add params for echo or not
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
	 * get_results
	 * 
	 * returns results up to defined log level
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
		$this->add_result('--');

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


*/

/* EOF */