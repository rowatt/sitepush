<?php

//display the admin options/WP Push page
function mra_sitepush_html()
{
	global $mra_sitepush_options;
	
	//check that the user has the required capability 
	if( !mra_sitepush_can_use() )
		wp_die( __('You do not have sufficient permissions to access this page.') );

	//define sites which we can push to
	$sites = $mra_sitepush_options['sites'];

	//initialise options from form data
	$push_options['db_all_tables'] =  mra_sitepush_get_query_var('mra_sitepush_push_db_all_tables') ? TRUE : FALSE;
	$push_options['db_post_content'] =  mra_sitepush_get_query_var('mra_sitepush_push_db_post_content') ? TRUE : FALSE;
	$push_options['db_users'] = mra_sitepush_get_query_var('mra_sitepush_push_db_users') ? TRUE : FALSE;
	$push_options['db_options'] = mra_sitepush_get_query_var('mra_sitepush_push_db_options') ? TRUE : FALSE;
//	$push_options['db_gravity_options'] = mra_sitepush_get_query_var('gravity_options') ? TRUE : FALSE;
//	$push_options['db_gravity_data'] = mra_sitepush_get_query_var('gravity_data') ? TRUE : FALSE;
	
	$push_options['push_uploads'] = mra_sitepush_get_query_var('mra_sitepush_push_uploads') ? TRUE : FALSE;
	$push_options['push_theme'] = mra_sitepush_get_query_var('mra_sitepush_push_theme') ? TRUE : FALSE;
	$push_options['push_themes'] = mra_sitepush_get_query_var('mra_sitepush_push_themes') ? TRUE : FALSE;
	$push_options['push_plugins'] = mra_sitepush_get_query_var('mra_sitepush_push_plugins') ? TRUE : FALSE;
	$push_options['push_wp_core'] = mra_sitepush_get_query_var('mra_sitepush_push_wp_core') ? TRUE : FALSE;
	
	$push_options['clear_cache'] = mra_sitepush_get_query_var('clear_cache') ? TRUE : FALSE;
	$push_options['dry_run'] = mra_sitepush_get_query_var('mra_sitepush_dry_run') ? TRUE : FALSE;
	$push_options['do_backup'] = mra_sitepush_get_query_var('mra_sitepush_push_backup') ? TRUE : FALSE;
	
	$push_options['source'] = mra_sitepush_get_query_var('mra_sitepush_source') ? mra_sitepush_get_query_var('mra_sitepush_source') : '';
	$push_options['dest'] = mra_sitepush_get_query_var('mra_sitepush_dest') ? mra_sitepush_get_query_var('mra_sitepush_dest') : '';

	//instantiate a new push object
	$args = array(
			  'timezone' => $mra_sitepush_options['timezone']
			, 'sites_conf' => $mra_sitepush_options['sites_conf']
	);

	set_time_limit( 6000 );

	$my_sitepush = new SitePush( $mra_sitepush_options['sites_conf'] );

?>
	<div class="wrap">
		<h2>WordPress Push</h2>	
<?php

	if( $push_options['dest'] )
	{
		// do the push!
		echo "<h3>Push results</h3>";
		if( $push_options['dry_run'] )
			echo "<p style='color:red; font-weight:bold;'>Dry run only, nothing pushed</p>";
		
		echo "<pre id='mra-sitepush-results'>";
		$push_result = mra_sitepush_do_the_push( $my_sitepush, $push_options );
		echo "</pre>";

		if( ! $push_result )
			echo "Nothing selected to push<br />";
	}
	else
	{
		$last_push_result_file = $my_sitepush->get_last_undo_file();
		$last_push_results = $last_push_result_file ? file( $last_push_result_file ) : '';
		
		if( $last_push_results )
		{
			echo "<pre style='white-space: pre-wrap; margin: 20px 0; padding: 5px; border: 1px solid grey;'>";
			echo "Last push at ".date( 'D j F, Y \a\t H:i:s e O T',$my_sitepush->get_last_undo_time() )."\n";
			
			//show more detail if administator
			if( mra_sitepush_can_admin() )
			{
				foreach( $last_push_results as $result )
				{
					if( stripos($result, 'original')===0 )
					{
						$result = str_ireplace("original\t", '', $result);
						$result = preg_replace('/ -p[^ ]*/', ' -p*****', $result);
						echo $result;
					}
				}
			}
			echo "</pre>";
		}
	
	}
?>

		<form method="post" action="">
			<?php wp_nonce_field('sitepush','mra_sitepush'); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row">Source</th>
					<td>
						<select name="mra_sitepush_source" id="mra_sitepush_source">
						<?php
							foreach( mra_sitepush_get_sites() as $site )
							{
								echo "<option value='{$site}'";
								if( mra_sitepush_get_current_site() == $site ) echo " selected='selected'";
								echo ">{$mra_sitepush_options['sites'][$site]['label']}</option>";
							}
						?>
						</select>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row">Destination</th>
					<td>
						<select name="mra_sitepush_dest" id="mra_sitepush_dest">
						<?php
							foreach( mra_sitepush_get_sites() as $site )
							{
								echo "<option value='{$site}'";
								if( !empty( $mra_sitepush_options['default_site'] ) && $site == $mra_sitepush_options['default_site'] ) echo " selected='selected'";
								echo ">{$mra_sitepush_options['sites'][$site]['label']}</option>";
							}
						?>
						</select>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row">Database content</th>
					<td>
						<?php echo mra_sitepush_option_html('mra_sitepush_push_db_all_tables','Entire database (caution - this will overwrite all content and settings)','admin_only');?>
						<?php echo mra_sitepush_option_html('mra_sitepush_push_db_post_content','All WordPress post content (pages, posts, comments, etc)', 'user');?>
						<?php echo mra_sitepush_option_html('mra_sitepush_push_db_users','WordPress users','admin_only');?>
						<?php echo mra_sitepush_option_html('mra_sitepush_push_db_options','WordPress options','admin_only');?>
						<?php //echo mra_sitepush_option_html('gravity_options','Gravity Forms options','admin_only');?>
						<?php //echo mra_sitepush_option_html('gravity_data','Gravity Forms data','admin_only');?>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row">Files</th>
					<td>
						<?php echo mra_sitepush_option_html('mra_sitepush_push_uploads','WordPress media uploads', 'user');?>
						<?php echo mra_sitepush_option_html('mra_sitepush_push_theme',get_current_theme().' theme','admin_only');?>
						<?php echo mra_sitepush_option_html('mra_sitepush_push_themes','All themes','admin_only');?>
						<?php echo mra_sitepush_option_html('mra_sitepush_push_plugins','WordPress plugins','admin_only');?>
						<?php echo mra_sitepush_option_html('mra_sitepush_push_wp_core','All WordPress core files. Excludes content in wp-content, i.e. themes, plugins, uploads, etc.','admin_only');?>

					</td>
				</tr>				

				<tr valign="top">
					<th scope="row">Push options</th>
					<td>
						<?php
							if( 'none'<>$mra_sitepush_options['cache'] )
								echo mra_sitepush_option_html('clear_cache','Clear WordPress cache on destination','user','checked');
						?>
						<?php echo mra_sitepush_option_html('mra_sitepush_dry_run','Dry run (nothing will be pushed)','admin_only');?>
						<?php 
							if( !empty( $mra_sitepush_options['backup_path'] ) )
								echo mra_sitepush_option_html('mra_sitepush_push_backup','Backup push (caution - do not turn this off unless you are sure!)','user','checked');
						?>
					</td>
				</tr>				
				
						<?php /* No undo till we get it working properly!
						<br /><label title="undo"><input type="radio" name="push_type" value="undo"<?php echo $push_type=='undo'?' checked="checked"':'';?> /> Undo the last push (<?php echo date( "D j F, Y \a\t H:i:s e O T",$my_sitepush->get_last_undo_time() );?>)</label>
						*/ ?>

			<?php if( ! mra_sitepush_can_admin() ) : ?>
				<tr valign="top">
					<th scope="row">&nbsp;</th>
					<td>
						<br /><span class="description">To push Wordpress core, plugins, theme code, users or site settings, please ask an administrator.</span>
					</td>
				</tr>
			<?php endif; ?>

			</table>
			<p class="submit">
		   	<input type="submit" class="button-primary" value="Push Content" />
			</p>
		</form>
	</div>
<?php 
}


/* EOF */