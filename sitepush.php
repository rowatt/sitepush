<?php
/*
Plugin Name: SitePush
Plugin URI: http://sitepush.rowatt.com
Description: Easily move code and content between versions of a site
Version: 0.1.2alpha
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


//include required files & instantiate classes
require_once('classes/class-sitepush-plugin.php');
new SitePushPlugin;

require_once('classes/class-sitepush-core.php');
require_once('classes/class-sitepush-screen.php');
require_once('classes/class-sitepush-options-screen.php');
require_once('classes/class-sitepush-push-screen.php');

define( 'MRA_SITEPUSH_PLUGIN_DIR_URL', plugins_url( '', __FILE__ ) );
define( 'MRA_SITEPUSH_PLUGIN_DIR', dirname(__FILE__) );
define( 'MRA_SITEPUSH_BASENAME', plugin_basename(__FILE__) );

define( 'MRA_SITEPUSH_OUTPUT_LEVEL', 3 ); //@debug

/* EOF */