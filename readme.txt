=== SitePush ===

Contributors: markauk
Tags: migrate, migration, move, deployment, development, staging, production
Requires at least: 3.3.1
Tested up to: 3.5
Stable tag: 0.4.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Easily move content and code between WordPress sites. Pull your site's DB to a dev site, push new code to a staging site, etc.



== Description ==

SitePush is a WordPress plugin which allows you to have multiple versions of your WordPress site, so you can edit, develop, test without any risk to your main, live site. It's great for developers, designers and editors... anyone who wants to be able to test changes to a site before it is visible to the world. For example:-

1. you can **easily move content between sites**. For example, make extensive edits on a private staging site, and then push changes all at once to your live site. Or, easily pull copy of your live database into your development site so you are developing against the latest content.
2. **test new themes and plugins**, and only push them to your live site once they are configured and working as you want.
3. upgrade WordPress, themes and plugins on a private site so you can **test that nothing breaks before upgrading your live site**. Sure you take backups before any upgrades (right?), but it's a pain doing a full backup and an even bigger pain restoring from a backup.
4. easily make small (and big!) code changes on your development site, **test and easily push new code to a live site**. Great for dealing with clients who want "just one more thing".

Although SitePush installation is a bit more involved than a typical plugin, once set up it runs with minimal effort and can be easily used by non-tech authors & editors. Site admins can easily configure SitePush so that non-admins can only push content (i.e. posts/pages, comments and uploads) and to a restricted set of sites.


= Support =

SitePush is under active development and I will do my best to provide fixes to problems. The latest general releases are always available through the WordPress Plugins Directory. Development code is <a href="https://github.com/rowatt/sitepush/tree/develop">hosted on GitHub</a>, so you may find more frequent releases there.

For general questions, please post on the WordPress forums with the tag sitepush. For bug reports or if you wish to suggest patches or fixes, please go to the <a href="https://github.com/rowatt/sitepush">SitePush GitHub repository</a>.

If you have any problems with SitePush, it would be helpful if you could add

    define('SITEPUSH_DEBUG',TRUE);

to your wp-config.php file, and include the output which will now be displayed at the top of the SitePush options screen.

**Disclaimer** Although SitePush has been well tested and is used on production web sites, it moves files and database content between sites which could break things. Use of SitePush is at your own risk! Please make sure you have adequate backups and if you do find any problems please report them.


= Roadmap =

There are a number of areas which could be improved. Currently on the roadmap:-

* improve push undo
* add support for pushing between sites on different servers

Please let me know how you would like to see SitePush evolve.



== Installation ==

= Summary of installation steps =
1. check that your setup meets the requirements in the section below
2. set up different versions of your site on your server
1. download and unzip the plugin in your plugins directory and activate the plugin
3. create and upload the config files which hold details of your sites and databases. See *Config file* sections below for more details.
4. go to the SitePush settings page (immediately below Dashboard), and fill in settings as required. There is further help and explanation in the plugin.

Details for each step are covered in the sections below.

= Requirements =

SitePush is currently in active development. It has been extensively tested on single site installations on a limited range of servers, but has not been tested on many different setups. Because it uses shell commands to do some of its stuff, there is more chance of things going wrong than with most plugins.

It is currently well tested on:-

* Linux (Centos 5)
* MacOS X 10.7 (MAMP)

It may not work properly if your host has PHP safe mode enabled. I am currently investigating issues with SitePush on GoDaddy shared hosting - if you are using GoDaddy shared hosting and would like to use SitePush, please contact me.

It is completely untested and will not work:-

* where you wish to push between sites on different servers. Currently, SitePush must have filesystem access to all sites it pushes to.
* on Windows based systems (if you would like SitePush to work on Windows and are able to help, please let me know).

It has experimental support for Multisite installs:-
* it will not run if you have WP_ALLOW_MULTISITE or MULTISITE defined as TRUE in your wp-config file unless you also define SITEPUSH_ALLOW_MULTISITE to TRUE in your wp-config file.

In addition to WordPress (3.3 or greater), PHP (5.2.4 or greater) and mySQL (5.0 or greater) your server must have the following installed:-

* mysql and mysqldump command line utilities (tested on mysql version 5.5, it should work on versions above 5.0)
* tar (any version should be fine)

= Setup different versions of your site =

Different versions of your site must be on the same server (filesystem). You can setup different versions either in separate vhosts (normally recommended) or all in one vhost (won't work for multisite installs and may not work if you are using caching plugins).

For full details of how to do this, see the instructions for each method in the *Server Setup* section.

= Download and install SitePush plugin =

Download and install SitePush on one version of your site as per normal.

= Sites config file =
In addition to configuring SitePush's settings page, you will also need to create and some settings files as described in this and the following sections. If at all possible upload these files outside of your web root so they are not accessible from a web browser. If that is not possible, make sure the file names end in '.php' and that you include the first line from the sample files.

The sites config file contains information about all the sites you wish to push/pull between. It looks like this:-

	; <?php die('Forbidden'); ?> -*- conf -*-

	[all]
	wp_dir = /wp
	wp_content_dir = /wp/wp-content
	cache = no
	caches[] = /caches/timthumb
	caches[] = /caches/something_else

	[live]
	label = Live Site
	domains[] = live.example.com
	domains[] = live.example.co.uk
	web_path = /var/www/vhosts/mysite-live
	db = live
	live = yes

	[dev]
	label = Dev Site
	domain = dev.example.com
	web_path = /var/www/vhosts/mysite-dev
	db = dev
	live = no

Each section represents parameters for a web site, with the exception of *[all]* - parameters in this section apply to all sites. Required parameters are as follows:-

* **[sitename]** = a unique name for this site. It's only used internally (or as label if you don't supply the label parameter), and can be anything you like.
* **web_path** = the full filesystem path to the web root for the site (not the root of the WordPress install if you have WordPress in a subdirectory).
* **domain** = the domain this site is at. If the site uses more than one domain, use the domains[] parameter for each domain instead. Optional if domains[] parameters supplied.
* **domains[]** (optional if domain parameter supplied) = if your site can be accessed via multiple domains (e.g. example.com, example.co.uk) then list each domain with the domains[] parameter. Make sure you include the *[]*.
* **db** = the SitePush label of the database this site uses, as defined in your databases config file (see below).

The following parameters are optional:-

* **label** = label for your site used in menus, error messages etc. The label doesn't have to be unique, but it will be rather confusing if it's not.
* **wp_dir** = the path from your webroot to this sites WordPress install.  You shouldn't need to set this unless
* **wp_content_dir** = the path from your webroot to the site's wp-content directory. You shouldn't need to set this.
* **wp_plugin_dir**  = the path from your webroot to the site's plugins directory. You shouldn't need to set this.
* **live** = is this a live/production site (*yes*), or not (*no*). SitePush will show some warnings when you select a live site to push to, can prevent users logging into live sites and can activate/deactivate specific plugins on live sites. Defaults to no.
* **default** = if set to yes, SitePush will use settings for this site if it can't figure out what config to use for a site. This should be set at most for one site. Defaults to no.
* **cache** = is caching turned on for this site (yes or no). If you have set *WP_CACHE* to TRUE in your wp-config for this site, you should set this to yes. Defaults to no.
* **caches[]** = if your site has any cache directories which should be cleared when you update the site, enter the full filesystem path to those directories here, and SitePush will empty those directories whenever you push if the *Clear cache on destination* option is set when you push.
* **admin_only** = if only admins should be able to push to/pull from this site, then set to *yes*. Defaults to no.
* **source_only** = if set to yes, non-admins will not be able to push to this site. Defaults to no.
* **destination_only** = if set to yes, non-admins will not be able to push from this site. Defaults to no.

Don't include a trailing slash on any paths.

= Databases config file =

The databases config file contains information about your sites' databases.

	; <?php die('Forbidden'); ?> -*- conf -*-
	; Do not remove the above line, it is all that prevents this file from being downloaded.

	[all]
	prefix = wp_

	[live]
	name = live_database
	user = db_user
	pw = live_db_password

	[dev]
	name = dev_database
	user = db_user
	pw = dev_db_password

Each section represents parameters for a WordPress database, with the exception of *[all]* - parameters in this section apply to all sites. Required parameters are as follows:-

* **[dblabel]** = a unique label for this database. This label is used for the *db* parameter in the sites config file.
* **name** = the name of the database (same as *DB_NAME* in wp-config).
* **user** = the user name for accessing the database (same as *DB_USER* in wp-config).
* **pw** = the database password (same as *DB_PASSWORD* in wp-config).

The following parameters are optional:-

* **host** = the database host. Defaults to DB_HOST.

**NOTE** All databases you are pushing to/from must use the same prefix.

= Domain map config file =

If you are running a Multisite installation, you will also need to create a domain map file so that SitePush knows which domains apply to which sites. The file should have as many sections as you have SitePush sites defined in your sites config file, and each section should contain one entry for each blog in your multisite setup. If your multisite installation is set up as a subdomain install, then you should list the full domains for each site, for example:-

	; <?php die('Forbidden'); ?> -*- conf -*-
	; Do not remove the above line, it is all that prevents this file from being downloaded.

	[live]
	1 = site1.example.com
	2 = site2.example.com
	3 = site3.example.com

	[dev]
	1 = dev1.example.com
	2 = dev2.example.com
	3 = dev3.example.com

* **[sitename]** = the name you have given this site. It should be exactly the same as *[sitename]* in your sites config file.
* **blogid = domain** = define the primary domain for each blogid in your network. If you are using a sub-directory set up, then the domain would be the same for each blog, but you still need to enter it for each one.

If, on the other hand, your installation is set up as a subdirectory install, then the domains in each section will be the same, for example:-

	; <?php die('Forbidden'); ?> -*- conf -*-
	; Do not remove the above line, it is all that prevents this file from being downloaded.

	[live]
	1 = live.example.com
	2 = live.example.com
	3 = live.example.com

	[dev]
	1 = dev.example.com
	2 = dev.example.com
	3 = dev.example.com

** do not include the subdirectory path for each site **

If you do not configure this correctly, you will not be able to access blogs where you have pushed multisite tables (or if you pushed the whole database) and may have problems accessing individual blogs where you pushed options for that blog. If this does happen, you will need to manually edit the wp_blogs, wp_site, wp_sitemeta and options tables, or restore from a backup.

= Other Important Notes for Multisite Setups =

1. Support for Multisite is experimental and to enable it you will need to define SITEPUSH_ALLOW_MULTISITE as TRUE in wp-config. There may be some rough edges and note that SitePush has not been thoroughly tested on Multisite configurations, so make sure you have appropriate backups.
1. SitePush settings for each blog are independent, so you will need to configure each blog separately. Each blog will probably need its own sites config file (as domains and/or subdirectories will be different for each blog), but can and should share a common database config file. The domain map config file should also be common amongst all sites.
2. SitePush uses the term *site* to distinguish between different versions of a site - e.g. live, staging, development. A SitePush site is not the same as a Multisite site (which is actually a network of blogs). A standard Multisite setup normally only has one site but many blogs (so really it should be called a Multiblog setup...).
3. If you have a large Multisite network, you should probably avoid pushing all tables... if it takes too long the script may time out and you may be left with an incomplete database.
3. In Multisite setups, only Super Admins can administer SitePush.



== Server Setup ==

= How to setup SitePush in a multiple vhost environment =

You can run your separate versions of a site in a single vhost, or in separate vhosts. While running them all in a single vhost can be little easier to set up on some web hosts, it does not work well if different sites need any different configuration in your .htaccess file - for example if you are using a caching plugin.

If you are able to set up separate vhosts (or subdomains as some hosts call them) I recommend you do it that way.

Let's say you want to have three versions of your site - live, test, and dev.

Set up a vhost for each site. Where they all sit on your server will depend on your hosting setup, but let's say they are at:-

	/var/www/vhosts/live/httpdocs
	/var/www/vhosts/test/httpdocs
	/var/www/vhosts/dev/httpdocs

You will need to create a directory to hold all the config files. If at all possible, this directory should not be web accessible. For example, it might be at:-

	/var/www/sitepush/config

You will also probably want to create a directory for any backups SitePush makes, such as:-

	/var/www/sitepush/backups

Finally, you will need to create a database for each of your sites. Consult the [WordPress installation instructions](http://codex.wordpress.org/Installing_WordPress) and your web host for how to do this.

Download WordPress and unzip it into one of your sites. I normally keep WordPress in its own subdirectory, for example:-

	/var/www/vhosts/live/httpdocs/wordpress

That way, the root directory stays clean, and if I install anything else outside of WordPress, there won't be any confusion of which files belong where. You need to make a couple of changes for this setup to work - see [WordPress documentation](http://codex.wordpress.org/Giving_WordPress_Its_Own_Directory) for more details. Note that for multisite installs, though, you will need to install WordPress in the root directory.

I do, however, put my wp-config.php file in the root directory (WordPress is smart enough to find it).

Next you will need to create the SitePush config files and put them in the config directory you created above. See [the SitePush installation instructions](http://wordpress.org/extend/plugins/sitepush/installation) for what needs to go in your sites config file and your database config file (I usually call them sites.ini.php and dbs.ini.php).

 Now, copy the files from the site you just set up to your other sites, for example:-

 	cd /var/www/vhosts
 	cp -r live/httpdocs dev/httpdocs

To save a bit of disk space (at the expense of possibly messing things up between sites), you can also symlink the uploads directory between sites so there is only one copy of any media files uploaded. For example:-

 	cd /var/www/vhosts/dev/httpdocs/wordpress/wp-content
 	rmdir uploads
 	ln -s ../../../../live/httpdocs/wordpress/wp-content/uploads uploads

 The exact paths will depend on your setup.

Finally, log into your live site, install, activate and configure SitePush, and now you are set up to easily move files and content between 3 versions of your site!

= How to setup SitePush in a single vhost =

You can run your separate versions of a site in a single vhost, or in separate vhosts. Depending on your web host, running them all in a single vhost can be bit easier to set up, though it does mean you need to share one .htaccess file across all versions of your site, and won't work for WordPress Multisite setups.

If you are able to set up separate vhosts (or subdomains as some hosts call them) I recommend you do it that way, but if not, these instructions show how you can have multiple version so of your site on one vhost.

Let's say you want to have three versions of your site - live, test, and dev.

First make sure that you can set up domain aliases on your host - so that multiple domains point to the same files. For example, you might set up:-

	live.example.com
	test.example.com
	dev.example.com

If your host allows wildcard domain setups, so for example anything.example.com would point to your files, that would also work

Set up a subdirectory for each site. Where they all sit on your server will depend on your hosting setup, but let's say they are at:-

	/var/www/httpdocs/live
	/var/www/httpdocs/test
	/var/www/httpdocs/dev

You will need to create a directory to hold all the config files. If at all possible, this directory should not be web accessible. For example, it might be at:-

	/var/www/sitepush/config

You will also probably want to create a directory for any backups SitePush makes, such as:-

	/var/www/sitepush/backups

Download WordPress and unzip it into one of the directories for your sites. For example:-

	/var/www/httpdocs/live

Follow the [instructions](http://codex.wordpress.org/Giving_WordPress_Its_Own_Directory) for more installing WordPress in a subdirectory.

You should also now create a database for each of your sites. Consult the [WordPress installation instructions](http://codex.wordpress.org/Installing_WordPress) and your web host for how to do this.

Complete any other required configuration (WordPress setup, plugin installs etc) and make sure that your site is now working properly. Don't forget to install SitePush!

Next you need to create the SitePush config files and put them in the config directory you created above. See here readme.txt or [the SitePush installation instructions](http://wordpress.org/extend/plugins/sitepush/installation) for what needs to go in your sites config file and your database config file (I usually call them sites.ini.php and dbs.ini.php).

Now, copy the files from the site you just set up to your other sites, for example:-

 	cd /var/www/httpdocs
 	cp -r live dev
 	cp -r live test

To save a bit of disk space (at the expense of possibly messing things up between sites), you can also symlink the uploads directory between sites so there is only one copy of any media files uploaded. For example:-

 	cd /var/www/httpdocs/dev/wp-content
 	rmdir uploads
 	ln -s ../../live/wp-content/uploads uploads

The exact paths will depend on your setup.

Next you need to make some changes to your wp-config.php file so that it will point to the correct site files and database depending on what domain name was used. The exact details will vary depending on your setup, but you will want something like this, which should be inserted immediately above the line `/* That's all, stop editing! Happy blogging. */`:-

	switch ( $_SERVER['SERVER_NAME'] ) {
		case 'test.example.com':
			$site_dir='test';
			define('DB_NAME', 'database_name_here');
			define('DB_USER', 'username_here');
			define('DB_PASSWORD', 'password_here');
			break;

		case 'dev.example.com':
			define('DB_NAME', 'database_name_here');
			define('DB_USER', 'username_here');
			define('DB_PASSWORD', 'password_here');
			$site_dir='dev';
			break;

		case 'www.example.com':
		case 'live.example.com':
		default:
			define('DB_NAME', 'database_name_here');
			define('DB_USER', 'username_here');
			define('DB_PASSWORD', 'password_here');
			$site_dir='live';
			break;
	}

Insert whatever constant definitions are specific to a site in that section, and delete or comment them out from their original location in wp-config.

Lastly, you need to edit the last line of wp-config so it reads:-

	require("./{$site_dir}/wp-blog-header.php");

You can now log into your live site, activate and configure SitePush. Once that is done, you can push everything to your other sites and you should now be able to access all three versions of your site. You are now set up to easily move files and content between 3 versions of your site!



== Screenshots ==

1. Push screen for non-admins. Site admin can configure what non-admins can push, so they can't push anything too dangerous.
2. Push screen as seen by admins. Admins can push any set of files or DB tables.
3. Push screen for a multisite installation as seen by admins. In this case, the admin has defined some custom table groups for Gravity Forms.
4. Main options screen.



== Frequently Asked Questions ==

= Will SitePush work on Windows systems? =

No. But if you would like to help me make it work on Windows please contact me.

= Can I use SitePush to move my site to a new server, or to backup my installation? =

In theory you probably could, but it's likely more effort than it's worth - SitePush is designed to make it really easy to repeatedly move database and files between sites, not for a one off move or automated backup. If you are looking for a plugin to do this, you could use something like [WordPress Move](http://wordpress.org/extend/plugins/wordpress-move/) or [WP Remote](http://wordpress.org/extend/plugins/wpremote/) or [BackupWordPress](http://wordpress.org/extend/plugins/backupwordpress/).

= How do I use SitePush on my Multisite installation =

Support for Multisite is experimental. You can enable it by defining SITEPUSH_ALLOW_MULTISITE as TRUE in wp-config. SitePush should work OK with Multisite setups, but there may be a few rough edges and it has not been thoroughly tested, so make sure you have appropriate backups.

= How do I push custom tables created by another plugin? =

You can add groups of custom tables to be pushed in the "Custom DB table groups" option on the main settings screen.

= SitePush times out before pushes complete =

By default, SitePush will run for up to 10 minutes to push. If your push is taking longer than that, you are either trying to push a very large database, a lot of large files or something is wrong. <a href="http://dev.mysql.com/doc/refman/5.5/en/mysqlcheck.html">Repairing and optimizing your database</a> can help. Also, some web hosts have proxy servers with their own timeouts - if they time out in less than 10 minutes, there's nothing SitePush can do to lengthen the timeout.

If you do have problems with timeouts, you can also try pushing things separately.



== Changelog ==

= 0.4 (2012-09-06) =
* SitePush no longer depends on rsync to push files. If you don't have rsync on your server, SitePush will copy files using PHP.
* You can now define custom groups of database tables to push, allowing any custom tables created by plugins to be pushed without pushing the whole database.
* Added debug mode which lists information about your environment at the top of the options screen. Add define('SITEPUSH_DEBUG',TRUE); to your wp-config.php file to enable debug mode.
* Detect various problems with hosting setups and add more helpful error messages.
* Various bug fixes.

= 0.3 (2012-07-06) =
* Initial public alpha release


== Upgrade Notice ==

= 0.4 =
SitePush no longer depends on rsync to push files, and allows you to define custom groups of DB tables to push. Many bugfixes and improved error reporting.

= 0.3 =
Initial public alpha release
