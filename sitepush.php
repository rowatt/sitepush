<?php
/*
Plugin Name: SitePush
Plugin URI: http://rowatt.com/sitepush
Description: Easily move code and content between versions of a site
Version: 0.2.1-alpha
Author: Mark Rowatt Anderson
Author URI: http://rowatt.com
License: GPL2
*/

/*  Copyright 2009-2011  Mark Rowatt Anderson  (sitepush -at- mark.anderson.vg)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


require_once('classes/class-sitepush-plugin.php');
require_once('classes/class-sitepush-errors.php');
SitePushPlugin::get_instance();

//load classes which are required for admin
if( is_admin() )
{
	require_once('classes/class-sitepush-core.php');
	require_once('classes/class-sitepush-screen.php');
	require_once('classes/class-sitepush-options-screen.php');
	require_once('classes/class-sitepush-push-screen.php');
}

//get the plugin basename and abs path to plugin directory
//__FILE__ won't work for basename if path has symlinks
//if basename using __FILE__ has more than one '/' we probably
//have symlinks, in which case we have to assume that plugin is at
//sitepush/sitepush.php - so don't change dir if using symlinks!
if( substr_count(plugin_basename(__FILE__), '/') <= 1 )
	define('MRA_SITEPUSH__FILE', __FILE__);
else
	define('MRA_SITEPUSH__FILE', WP_PLUGIN_DIR . '/' . 'sitepush/sitepush.php' );

define( 'MRA_SITEPUSH_PLUGIN_DIR_URL', plugins_url( '', MRA_SITEPUSH__FILE ) );
define( 'MRA_SITEPUSH_PLUGIN_DIR', dirname(MRA_SITEPUSH__FILE) );
define( 'MRA_SITEPUSH_BASENAME', plugin_basename(MRA_SITEPUSH__FILE) );

/* EOF */