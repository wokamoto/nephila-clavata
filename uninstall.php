<?php

if( !defined('ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') )
	exit();


if ( !class_exists('NephilaClavataAdmin') )
	require_once(dirname(__FILE__).'/includes/class-admin-menu.php');

global $wpdb;

delete_option(NephilaClavataAdmin::OPTION_KEY);

$sql = $wpdb->prepare("
 delete from {$wpdb->postmeta}
 where meta_key = %s",
	'_s3_media_files'
);
$wpdb->query($sql);