<?php

if( !defined('ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') )
	exit();


if ( !class_exists('NephilaClavataAdmin') )
	require_once(dirname(__FILE__).'/includes/class-admin-menu.php');

delete_option(NephilaClavataAdmin::OPTION_KEY);
