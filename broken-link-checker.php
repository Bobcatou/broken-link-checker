<?php

/*
Plugin Name: Broken Link Checker
Plugin URI: http://w-shadow.com/blog/2007/08/05/broken-link-checker-for-wordpress/
Description: Checks your posts for broken links and missing images and notifies you on the dashboard if any are found.
Version: 0.6.5
Author: Janis Elsts
Author URI: http://w-shadow.com/blog/
*/

/*
Created by Janis Elsts (email : whiteshadow@w-shadow.com)
MySQL 4.0 compatibility by Jeroen (www.yukka.eu)
*/

define('BLC_DEBUG', false);

/*
//FirePHP for debugging
if ( !class_exists('FB') ) {
	require_once 'FirePHPCore/fb.php4';
}
//FB::setEnabled(false);

//to comment out all calls : (^[^\/]*)(FB::)  ->  $1\/\/$2
//to uncomment : \/\/(\s*FB::)  ->   $1
//*/

//Make sure some useful constants are defined
if ( ! defined( 'WP_CONTENT_URL' ) )
	define( 'WP_CONTENT_URL', get_option( 'siteurl' ) . '/wp-content' );
if ( ! defined( 'WP_CONTENT_DIR' )  )
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
if ( ! defined( 'WP_PLUGIN_URL' ) )
	define( 'WP_PLUGIN_URL', WP_CONTENT_URL. '/plugins' );
if ( ! defined( 'WP_PLUGIN_DIR' ) )
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );

//The HTTP code of a link record can be set to one of these in some special circumstances
if ( ! defined('BLC_CHECKING') ) 
	define('BLC_CHECKING', 1); //The link is currently being checked. If this state persists, suspect a glitch. 
if ( ! defined('BLC_TIMEOUT') )	
	define('BLC_TIMEOUT', 0);  //The code used for links that timed out and didn't return an actual response.

//Load and initialize the plugin's configuration
$blc_directory = dirname(__FILE__);
require $blc_directory . '/config-manager.php';
$blc_config_manager = new blcConfigurationManager(
	//Save the plugin's configuration into this DB option
	'wsblc_options', 
	//Initialize default settings
	array(
        'max_execution_time' => 5*60, 	//How long the worker instance may run, at most. 
        'check_threshold' => 72, 		//Check each link every 72 hours.
        'mark_broken_links' => true, 	//Whether to add the broken_link class to broken links in posts.
        'broken_link_css' => ".broken_link, a.broken_link {\n\ttext-decoration: line-through;\n}",
        'exclusion_list' => array(), 	//Links that contain a substring listed in this array won't be checked. 
        'recheck_count' => 3, 			//[Internal] How many times a broken link should be re-checked (slightly buggy)
		
		//These three are currently ignored. Everything is checked by default.
		'check_posts' => true, 
        'check_custom_fields' => true,
        'check_blogroll' => true,
        
        'custom_fields' => array(),		//List of custom fields that can contain URLs and should be checked.
        
        'autoexpand_widget' => true, 	//Autoexpand the Dashboard widget if broken links are detected 
		
		'need_resynch' => false,  		//[Internal flag]
		'current_db_version' => 0,		//The current version of the plugin's tables
		
		'custom_tmp_dir' => '',			//The lockfile will be stored in this directory. 
										//If this option is not set, the plugin's own directory or the 
										//system-wide /tmp directory will be used instead.
										
		'timeout' => 30,				//Links that take longer than this to respond will be treated as broken.  
   )
);

	
if ( !is_admin() ){
	//This is user-side request, so the only thing we may need to do is run the broken link highlighter.
	if ( $blc_config_manager->options['mark_broken_links'] ){
		//Load some utilities (used by the higlighter) and the highlighter itself
		require $blc_directory . '/utility-class.php';
		require $blc_directory . '/highlighter-class.php';
		$blc_link_highlighter = new blcLinkHighlighter( $blc_config_manager->options['broken_link_css'] );
	}
} else {
	//Load everything
	require $blc_directory . '/utility-class.php';
	require $blc_directory . '/instance-classes.php';
	require $blc_directory . '/link-classes.php';
	require $blc_directory . '/core.php';
	
	$ws_link_checker = new wsBrokenLinkChecker( __FILE__ , $blc_config_manager );	
}

?>