<?php

class SitePush_Push_Screen extends SitePush_Screen
{
	//holds the last source/dest for current user
	private $user_last_source = '';
	private $user_last_dest = '';

	public function __construct( $plugin )
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
		$push_options['push_wp_core'] = SitePushPlugin::get_query_var('sitepush_push_wp_core') ? TRUE : FALSE;
		
		$push_options['clear_cache'] = SitePushPlugin::get_query_var('clear_cache') ? TRUE : FALSE;
		$push_options['dry_run'] = SitePushPlugin::get_query_var('sitepush_dry_run') ? TRUE : FALSE;
		$push_options['do_backup'] = SitePushPlugin::get_query_var('sitepush_push_backup') ? TRUE : FALSE;
		
		$push_options['source'] = SitePushPlugin::get_query_var('sitepush_source') ? SitePushPlugin::get_query_var('sitepush_source') : '';
		$push_options['dest'] = SitePushPlugin::get_query_var('sitepush_dest') ? SitePushPlugin::get_query_var('sitepush_dest') : '';
	
		$user_options = get_user_option('sitepush_options');
		$this->user_last_source = empty($user_options['last_source']) ? '' : $user_options['last_source'];
		$this->user_last_dest = empty($user_options['last_dest']) ? '' : $user_options['last_dest'];
	
		SitePushErrors::errors();

		if( $push_options['dest'] )
		{
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
				
			echo "<h3{$hide_html}>Push results</h3>";
			echo "<pre id='sitepush-results'{$hide_html}>";

			//Do the push!
			$obj_sitepushcore = new SitePushCore( $push_options['source'], $push_options['dest'] );
			if( !SitePushErrors::count_errors('all-errors') )
				$push_result = $this->plugin->do_the_push( $obj_sitepushcore, $push_options );
			else
				$push_result = FALSE;

			echo "</pre>";
			echo "<script>jQuery('#running').hide();</script>";

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
				echo "<iframe src='http://{$obj_sitepushcore->dest_params['domain']}' class='hidden-iframe'></iframe>";
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
					<tr>
						<th scope="row"><label for="sitepush_source">Source</label></th>
						<td>
							<select name="sitepush_source" id="sitepush_source" class="site-selector">
							<?php
								foreach( $this->plugin->get_sites() as $site )
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
								foreach( $this->plugin->get_sites() as $site )
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
	
					<tr>
						<th scope="row">Database content</th>
						<td>
							<?php echo $this->option_html('sitepush_push_db_all_tables','Entire database (this will overwrite all content and settings)','admin_only');?>
							<?php echo $this->option_html('sitepush_push_db_post_content','All post content (pages, posts, media, links, custom post types, post meta, categories, tags &amp; custom taxonomies)', 'user');?>
							<?php echo $this->option_html('sitepush_push_db_comments','Comments','user');?>
							<?php echo $this->option_html('sitepush_push_db_users','Users &amp; user meta','admin_only');?>
							<?php echo $this->option_html('sitepush_push_db_options','WordPress options','admin_only');?>
						</td>
					</tr>
	
					<tr>
						<th scope="row">Files</th>
						<td>
							<?php echo $this->option_html('sitepush_push_theme', 'Current theme ('.get_current_theme().')','admin_only');?>
							<?php echo $this->option_html('sitepush_push_themes','All themes','admin_only');?>
							<?php echo $this->option_html('sitepush_push_plugins','WordPress plugins','admin_only');?>
							<?php echo $this->option_html('sitepush_push_uploads','WordPress media uploads', 'user');?>
						</td>
					</tr>				
	
					<?php
						$output = '';

						if( !empty($this->options->cache_key) && $this->options->use_cache )
							$output .= $this->option_html('clear_cache','Clear cache on destination','user','checked');

						if( $this->options->backup_path )
							$output .= $this->option_html('sitepush_push_backup','Backup push (note - restoring from a backup is currently a manual process and requires command line access)','user','checked');

						if( $this->options->debug_output_level >= 3 )
							$output .= $this->option_html('sitepush_dry_run','Dry run (show what actions would be performed by push, but don\'t actually do anything)','admin_only');


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
							<br /><span class="description">To push plugins, theme code, users or site settings, please ask an administrator.</span>
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
	private function option_html($option, $label, $admin_only='admin_only', $checked='not_checked' )
	{
		//set checked either to default, or to last run if we have just done a push
		if( !empty($_REQUEST['sitepush-nonce']) )
			$checked_html = empty($_REQUEST[ $option ]) ? '' : ' checked="checked"';
		else
			$checked_html = 'checked'==$checked ? ' checked="checked"' : '';
	
		if( 'admin_only'==$admin_only && ! $this->plugin->can_admin() )
			return '';
		else
			return "<label title='{$option}'><input type='checkbox' name='{$option}' value='{$option}'{$checked_html} /> {$label}</label><br />\n";
	}

}

/* EOF */