<?php

if( !defined('ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') )
	exit();

if ( !class_exists('NephilaClavata_Admin') )
	require(dirname(__FILE__).'/includes/class-NephilaClavata_Admin.php');
if ( !class_exists('NephilaClavata') )
	require(dirname(__FILE__).'/includes/class-NephilaClavata.php');

global $wpdb;

delete_option(NephilaClavata_Admin::OPTION_KEY);

$sql = $wpdb->prepare(
	"delete from {$wpdb->postmeta} where meta_key in (%s, %s)",
	NephilaClavata::META_KEY,
	NephilaClavata::META_KEY.'-replace'
	);
$wpdb->query($sql);