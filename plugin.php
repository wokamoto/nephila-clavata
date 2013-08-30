<?php
/*
Plugin Name: Nephila clavata
Version: 0.2.1
Plugin URI: https://github.com/wokamoto/nephila-clavata
Description: Media uploader for AWS S3.Allows you to mirror your WordPress media uploads over to Amazon S3 for storage and delivery. 
Author: wokamoto
Author URI: http://dogmap.jp/
Text Domain: nephila-clavata
Domain Path: /languages/

License:
 Released under the GPL license
  http://www.gnu.org/copyleft/gpl.html
  Copyright 2013 wokamoto (email : wokamoto1973@gmail.com)

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
$nephila_clavata = new NephilaClavata(NephilaClavata_Admin::get_option());

// replace url
add_filter('the_content', array($nephila_clavata, 'the_content'));
add_filter('widget_text', array($nephila_clavata, 'widget_text'));
add_filter('wp_get_attachment_url', array($nephila_clavata, 'get_attachment_url'), 10, 2);

// delete S3 object
add_action('delete_attachment', array($nephila_clavata, 'delete_attachment'));

if (is_admin())
	new NephilaClavata_Admin();
