<?php
/*
Plugin Name: Nephila clavata
Version: 0.2.5.1
Plugin URI: https://github.com/wokamoto/nephila-clavata
Description: Media uploader for AWS S3.Allows you to mirror your WordPress media uploads over to Amazon S3 for storage and delivery.
Author: wokamoto
Author URI: http://dogmap.jp/
Text Domain: nephila-clavata
Domain Path: /languages/

License:
 Released under the GPL license
  http://www.gnu.org/copyleft/gpl.html
  Copyright 2013-2016 wokamoto (email : wokamoto1973@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
if ( !class_exists('S3_helper') )
	require(dirname(__FILE__).'/includes/class-S3_helper.php');
if ( !class_exists('NephilaClavata_Admin') )
	require(dirname(__FILE__).'/includes/class-NephilaClavata_Admin.php');
if ( !class_exists('NephilaClavata') )
	require(dirname(__FILE__).'/includes/class-NephilaClavata.php');

load_plugin_textdomain(NephilaClavata::TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages/');

// Go Go Go!
$nephila_clavata = NephilaClavata::get_instance();
$nephila_clavata->init(NephilaClavata_Admin::get_option());
$nephila_clavata->add_hook();

if (is_admin()) {
	$nephila_clavata_admin = NephilaClavata_Admin::get_instance();
	$nephila_clavata_admin->init();
	$nephila_clavata_admin->add_hook();
}
