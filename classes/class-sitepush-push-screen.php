<?php

class SitePush_Push_Screen extends SitePush_Screen
{
	//holds the last source/dest for current user
	private $user_last_source = '';
	private $user_last_dest = '';

	public function __construct( &$plugin )
	{
		parent::__construct( $plugin );
	}

	//display the admin options/SitePush page
	public function display_screen()
	{
		//check that the user has the required capability 
		if( !$this->plugin->can_use() )
			wp_die( __('You do not have sufficient permissions to access this page.') );
		?>
		<div class="wrap">
			<h2>SitePush</h2>
		<?php
		//initialise options from form data
		$push_options['db_all_tables'] =  SitePushPlugin::get_query_var('sitepush_push_db_all_tables') ? TRUE : FALSE;
		$push_options['db_post_content'] =  SitePushPlugin::get_query_var('sitepush_push_db_post_content') ? TRUE : FALSE;
		$push_options['db_comments'] = SitePushPlugin::get_query_var('sitepush_push_db_comments') ? TRUE : FALSE;
		$push_options['db_users'] = SitePushPlugin::get_query_var('sitepush_push_db_users') ? TRUE : FALSE;
		$push_options['db_options'] = SitePushPlugin::get_query_var('sitepush_push_db_options') ? TRUE : FALSE;
		
		$push_options['push_uploads'] = SitePushPlugin::get_query_var('sitepush_push_uploads') ? TRUE : FALSE;
		$push_options['push_theme'] = SitePushPlugin::get_query_var('sitepush_push_theme') ? TRUE : FALSE;
		$push_options['push_themes'] = SitePushPlugin::get_query_var('sitepush_push_themes') ? TRUE : FALSE;
		$push_options['push_plugins'] = SitePushPlugin::get_query_var('sitepush_push_plugins') ? TRUE : FALSE;
		$push_options['push_mu_plugins'] =  SitePushPlugin::get_query_var('sitepush_push_mu_plugins') ? TRUE : FALSE;
		$push_options['push_wp_core'] = SitePushPlugin::get_query_var('sitepush_push_wp_core') ? TRUE : FALSE;
		
		$push_options['clear_cache'] = SitePushPlugin::get_query_var('clear_cache') ? TRUE : FALSE;
		$push_options['dry_run'] = SitePushPlugin::get_query_var('sitepush_dry_run') ? TRUE : FALSE;
		$push_options['do_backup'] = SitePushPlugin::get_query_var('sitepush_push_backup') ? TRUE : FALSE;

		$db_custom_table_groups = SitePushPlugin::get_query_var('sitepush_db_custom_table_groups');
		if( $db_custom_table_groups )
		{
			foreach( $db_custom_table_groups as $key=>$group )
			{
				$push_options['db_custom_table_groups'][] = $key;
			}
		}
		else
		{
			$push_options['db_custom_table_groups'] = array();
		}

		$push_options['source'] = SitePushPlugin::get_query_var('sitepush_source') ? SitePushPlugin::get_query_var('sitepush_source') : '';
		$push_options['dest'] = SitePushPlugin::get_query_var('sitepush_dest') ? SitePushPlugin::get_query_var('sitepush_dest') : '';

		//multisite specific options
		$push_options['db_multisite_tables'] =  SitePushPlugin::get_query_var('sitepush_push_db_multisite_tables') ? TRUE : FALSE;

		$user_options = get_user_option('sitepush_options');
		$this->user_last_source = empty($user_options['last_source']) ? '' : $user_options['last_source'];
		$this->user_last_dest = empty($user_options['last_dest']) ? '' : $user_options['last_dest'];
	
		SitePushErrors::errors( 'all', 'sitepush' );

		if( $push_options['dest'] )
		{
			//output directly to screen... doesn't work on all browsers.
			ob_implicit_flush();

			//save source/dest to user options
			$user_options = get_user_option('sitepush_options');
			$user_options['last_source'] = $push_options['source'];
			$user_options['last_dest'] = $push_options['dest'];
			update_user_option( get_current_user_id(), 'sitepush_options', $user_options );

			// do the push!
			if( $this->plugin->can_admin() && $this->options->debug_output_level )
			{
				$hide_html = '';
			}
			else
			{
				$hide_html = ' style="display: none;"';
				echo "<div id='running'></div>";
			}

			echo "<script>
				var scrollIntervalID = window.setInterval(function(){
					jQuery('#sitepush-results').scrollTop( jQuery('#sitepush-results').prop('scrollHeight') );
				}, 100);
			</script>";

			echo "<h3{$hide_html} class='sitepush-results'>Push results</h3>";
			echo "<pre id='sitepush-results' class='sitepush-results' {$hide_html}>";

			//Do the push!
			$obj_sitepushcore = new SitePushCore( $push_options['source'], $push_options['dest'] );
			if( !SitePushErrors::count_errors('all-errors') )
				$push_result = $this->plugin->do_the_push( $obj_sitepushcore, $push_options );
			else
				$push_result = FALSE;

			echo "</pre>";
			echo "<script>
				jQuery('#running').hide();
				if( ! jQuery('#sitepush-results').text() ) jQuery('.sitepush-results').hide();
				clearInterval( scrollIntervalID );
				jQuery('#sitepush-results').scrollTop( jQuery('#sitepush-results').prop('scrollHeight') );
			</script>";

			if( $push_result )
			{
				if( $push_options['dry_run'] )
					SitePushErrors::add_error( "Dry run complete. Nothing was actually pushed, but you can see what would have been done from the output above.", 'notice' );
				elseif( SitePushErrors::count_errors('warning') )
					SitePushErrors::add_error( "Push complete (with warnings).", 'notice' );
				else
					SitePushErrors::add_error( "Push complete.", 'notice' );

				//bit of a hack... do one page load for destination site to make sure SitePush has activated plugins etc
				//before any user accesses the site
				wp_remote_get( $obj_sitepushcore->dest_params['domain'] );
			}
			else
			{
				if( !SitePushErrors::is_error() )
					SitePushErrors::add_error( "Nothing selected to push" );
			}

			SitePushErrors::errors();
		}

		
		//set up what menu options are selected by default
		if( !empty($_REQUEST['sitepush-nonce']) )
		{
			//already done a push, so redo what we had before
			$default_source = empty( $_REQUEST['sitepush_source'] ) ? '' :  $_REQUEST['sitepush_source'];
			$default_dest = empty( $_REQUEST['sitepush_dest'] ) ? '' :  $_REQUEST['sitepush_dest'];
		}
		if( empty($default_source) )
			$default_source = $this->user_last_source ? $this->user_last_source : $this->options->get_current_site();
		if( empty($default_dest) )
			$default_dest = $this->user_last_dest ? $this->user_last_dest : '';

	?>
	
			<form method="post" action="">
				<?php wp_nonce_field('sitepush-dopush','sitepush-nonce'); ?>
				<table class="form-table">
					<?php if( SITEPUSH_SHOW_MULTISITE ) : ?>
					<tr>
						<th scope="row"><label for="sitepush_source">Site</label></th>
						<td>
							<?php bloginfo('name');?>
						</td>
					</tr>
					<?php endif; ?>

					<tr>
						<th scope="row"><label for="sitepush_source">Source</label></th>
						<td>
							<select name="sitepush_source" id="sitepush_source" class="site-selector">
							<?php
								foreach( $this->plugin->get_sites('source') as $site )
								{
									echo "<option value='{$site}'";
									if( $default_source == $site ) echo " selected='selected'";
									echo ">{$this->options->sites[$site]['label']}</option>";
								}
							?>
							</select>
						</td>
					</tr>
	
					<tr>
						<th scope="row"><label for="sitepush_dest">Destination</label></th>
						<td>
							<select name="sitepush_dest" id="sitepush_dest" class="site-selector">
							<?php
								foreach( $this->plugin->get_sites('destination') as $site )
								{
									$use_cache = $this->options->sites[$site]['use_cache'] ? 'yes' : 'no';
									echo "<option value='{$site}' data-cache='{$use_cache}'";
									if( $default_dest == $site ) echo " selected='selected'";
									echo ">{$this->options->sites[$site]['label']}</option>";
								}
							?>
							</select>
							<span id='sitepush_dest-warning'><span>
						</td>
					</tr>
					<?php $ms_message = SITEPUSH_SHOW_MULTISITE ? "<br /><i>current site only</i>" : ''; ?>
					<tr>
						<th scope="row">Database content<?php echo $ms_message; ?></th>
						<td>
							<?php
								if( !SITEPUSH_SHOW_MULTISITE )
									echo $this->option_html('sitepush_push_db_all_tables','Entire database <i>(this will overwrite all content and settings)</i>','admin_only');
							?>
							<?php echo $this->option_html('sitepush_push_db_post_content','All post content <i>(pages, posts, media, links, custom post types, post meta, categories, tags &amp; custom taxonomies)</i>', 'user');?>
							<?php if( $this->plugin->can_admin() || !$this->options->non_admin_exclude_comments ) echo $this->option_html('sitepush_push_db_comments','Comments','user');?>
							<?php if( !SITEPUSH_SHOW_MULTISITE ) echo $this->option_html('sitepush_push_db_users','Users &amp; user meta','admin_only');?>
							<?php if( $this->plugin->can_admin() || !$this->options->non_admin_exclude_options ) echo $this->option_html('sitepush_push_db_options','WordPress options','user');?>
							<?php
								foreach( $this->options->db_custom_table_groups_array as $key=>$table_group )
								{
									//if label is preceded by $$$ then field only shows to admins
									if( strpos( $table_group['label'], '$$$' )===0 )
									{
										$admin_only = TRUE;
										$table_group['label'] = substr( $table_group['label'], 3 );
									}
									else
									{
										$admin_only = FALSE;
									}

									echo $this->option_html( array('sitepush_db_custom_table_groups',$key ), $table_group['label'], $admin_only );
								}
							?>
						</td>
					</tr>

					<?php
						$files_output = '';
						if( !SITEPUSH_SHOW_MULTISITE ) $files_output .= $this->option_html('sitepush_push_theme', 'Current theme <i>('._deprecated_get_current_theme().')</i>','admin_only');
						if( !SITEPUSH_SHOW_MULTISITE ) $files_output .= $this->option_html('sitepush_push_themes','All themes','admin_only');
						if( !SITEPUSH_SHOW_MULTISITE ) $files_output .= $this->option_html('sitepush_push_plugins','WordPress plugins','admin_only');
						if( !SITEPUSH_SHOW_MULTISITE && file_exists($this->options->current_site_conf['web_path'] . $this->options->current_site_conf['wpmu_plugin_dir']) ) $files_output .= $this->option_html('sitepush_push_mu_plugins','WordPress must-use plugins','admin_only');
						if( 'ERROR' <> $this->options->current_site_conf['wp_uploads_dir'] )
							$files_output .= $this->option_html('sitepush_push_uploads','WordPress media uploads', 'user');
						elseif( $this->plugin->can_admin() )
							$files_output .= "Uploads directory could not be determined, so uploaded media files cannot be pushed.<br />";

						if( $files_output )
							echo "<tr><th scope='row'>Files{$ms_message}</th><td>{$files_output}</td></tr>";
					?>

					<?php if( SITEPUSH_SHOW_MULTISITE && $this->plugin->can_admin() ) : ?>
					<tr>
						<th scope="row">Multisite database content<br /><i>affects all sites</i></th>
						<td>
							<?php echo $this->option_html('sitepush_push_db_users','Users &amp; user meta','admin_only');?>
							<?php echo $this->option_html('sitepush_push_db_multisite_tables','Multisite tables <i>(blogs, blog_versions, registration_log, signups, site, sitemeta, sitecategories)</i>','admin_only'); ?>
							<?php echo $this->option_html('sitepush_push_db_all_tables','Entire database for all sites <i>(Caution! This will overwrite all content and settings for all sites in this network installation)</i>','admin_only'); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Multisite files<br /><i>affects all sites</i></th>
						<td>
							<?php echo $this->option_html('sitepush_push_theme', 'Current theme <i>('._deprecated_get_current_theme().')</i>','admin_only');?>
							<?php echo $this->option_html('sitepush_push_themes','All themes','admin_only');?>
							<?php echo $this->option_html('sitepush_push_plugins','WordPress plugins','admin_only');?>
							<?php if( file_exists($this->options->current_site_conf['web_path'] . $this->options->current_site_conf['wpmu_plugin_dir']) ) echo $this->option_html('sitepush_push_mu_plugins','WordPress must-use plugins','admin_only');?>
						</td>
					</tr>
					<?php endif; ?>

					<?php
						$output = '';

						if( !empty($this->options->cache_key) && $this->options->use_cache )
							$output .= $this->option_html('clear_cache','Clear cache on destination','user','checked');

						if( $this->options->backup_path )
							$output .= $this->option_html('sitepush_push_backup','Backup push <i>(note - restoring from a backup is currently a manual process and ideally requires command line access)</i>','user','checked');

						if( $this->options->debug_output_level >= 3 )
							$output .= $this->option_html('sitepush_dry_run','Dry run <i>(show what actions would be performed by push, but don\'t actually do anything)</i>','admin_only');


					/* No undo till we get it working properly!
					<br /><label title="undo"><input type="radio" name="push_type" value="undo"<?php echo $push_type=='undo'?' checked="checked"':'';?> /> Undo the last push (<?php echo date( "D j F, Y \a\t H:i:s e O T",$obj_sitepushcore->get_last_undo_time() );?>)</label>
					*/
					
						if( $output )
							echo "<tr valign='top'><th scope='row'>Push options</th><td>{$output}</td></tr>";
					?>
	
				<?php if( ! $this->plugin->can_admin() ) : ?>
					<tr>
						<th scope="row">&nbsp;</th>
						<td>
							<br /><span class="description">To push plugins, themes or settings, please ask an administrator.</span>
						</td>
					</tr>
				<?php endif; ?>
	
				</table>
				<p class="submit">
			   	<input type="submit" class="button-primary" value="Push Content" id="push-button" />
				</p>
			</form>
		</div>
	<?php 
	}
	
	//output HTML for push option
	private function option_html($_option, $label, $admin_only='admin_only', $checked='not_checked' )
	{
		//if $_option is array, then we are dealing with a $_REQUEST array type option so configure accordingly
		if( is_array( $_option) )
		{
			$option = "{$_option[0]}[{$_option[1]}]";
			$request_empty = empty( $_REQUEST[ $_option[0] ][ $_option[1] ] );
		}
		else
		{
			$option = $_option;
			$request_empty = empty($_REQUEST[ $option ]);
		}

		if( in_array( str_replace( 'sitepush_', '', $option ), $this->options->hide_push_options_array ) )
			return '';

		if( 'admin_only'==$admin_only && ! $this->plugin->can_admin() )
			return '';

		//set checked either to default, or to last run if we have just done a push
		if( !empty($_REQUEST['sitepush-nonce']) )
			$checked_html = $request_empty ? '' : ' checked="checked"';
		else
			$checked_html = 'checked'==$checked ? ' checked="checked"' : '';
	
		return "<label title='{$option}'><input type='checkbox' name='{$option}' value='{$option}'{$checked_html} /> {$label}</label><br />\n";
	}

}

/* EOF */