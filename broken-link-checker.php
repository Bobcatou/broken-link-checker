<?php

/*
Plugin Name: Broken Link Checker
Plugin URI: http://w-shadow.com/blog/2007/08/05/broken-link-checker-for-wordpress/
Description: Checks your blog for broken links and missing images and notifies you on the dashboard if any are found.
Version: 0.9.3
Author: Janis Elsts
Author URI: http://w-shadow.com/blog/
Text Domain: broken-link-checker
*/

/*
Created by Janis Elsts (email : whiteshadow@w-shadow.com)
MySQL 4.0 compatibility by Jeroen (www.yukka.eu)
*/

/***********************************************
				Debugging stuff
************************************************/

define('BLC_DEBUG', false);


/***********************************************
				Constants
************************************************/

/*
For performance, some internal APIs used for retrieving multiple links, instances or containers 
can take an optional "$purpose" argument. Those APIs will try to use this argument to pre-load 
any DB data required for the specified purpose ahead of time. 

For example, if you're loading a bunch of link containers for the purposes of parsing them and 
thus set $purpose to BLC_FOR_PARSING, the relevant container managers will (if applicable) precache
the parse-able fields in each returned container object. Still, setting $purpose to any particular 
value does not *guarantee* any data will be preloaded - it's only a suggestion that it should.

The currently supported values for the $purpose argument are : 
*/ 	
define('BLC_FOR_EDITING', 'edit');
define('BLC_FOR_PARSING', 'parse');
define('BLC_FOR_DISPLAY', 'display');

/***********************************************
				Configuration
************************************************/

//Load and initialize the plugin's configuration
global $blc_directory;
$blc_directory = dirname(__FILE__);
require $blc_directory . '/config-manager.php';

global $blc_config_manager;
$blc_config_manager = new blcConfigurationManager(
	//Save the plugin's configuration into this DB option
	'wsblc_options', 
	//Initialize default settings
	array(
        'max_execution_time' => 5*60, 	//(in seconds) How long the worker instance may run, at most. 
        'check_threshold' => 72, 		//(in hours) Check each link every 72 hours.
        
        'recheck_count' => 3, 			//How many times a broken link should be re-checked. 
		'recheck_threshold' => 20*60,	//(in seconds) Re-check broken links after 20 minutes.   
		
		'run_in_dashboard' => true,		//Run the link checker algo. continuously while the Dashboard is open.
		'run_via_cron' => true,			//Run it hourly via WordPress pseudo-cron.
        
        'mark_broken_links' => true, 	//Whether to add the broken_link class to broken links in posts.
        'broken_link_css' => ".broken_link, a.broken_link {\n\ttext-decoration: line-through;\n}",
        'nofollow_broken_links' => false, //Whether to add rel="nofollow" to broken links in posts.
        
        'mark_removed_links' => false, 	//Whether to add the removed_link class when un-linking a link.
        'removed_link_css' => ".removed_link, a.removed_link {\n\ttext-decoration: line-through;\n}",
        
        'exclusion_list' => array(), 	//Links that contain a substring listed in this array won't be checked.
		
		'send_email_notifications' => false,//Whether to send email notifications about broken links
		'notification_schedule' => 'daily', //How often (at most) notifications will be sent. Possible values : 'daily', 'weekly'
		'last_notification_sent' => 0,		//When the last email notification was send (Unix timestamp)
		
		'server_load_limit' => 4,		//Stop parsing stuff & checking links if the 1-minute load average
										//goes over this value. Only works on Linux servers. 0 = no limit.
		'enable_load_limit' => true,	//Enable/disable load monitoring. 
		
        'custom_fields' => array(),		//List of custom fields that can contain URLs and should be checked.
        
        'autoexpand_widget' => true, 	//Autoexpand the Dashboard widget if broken links are detected 
		
		'need_resynch' => false,  		//[Internal flag] True if there are unparsed items.
		'current_db_version' => 0,		//The currently set-up version of the plugin's tables
		
		'custom_tmp_dir' => '',			//The lockfile will be stored in this directory. 
										//If this option is not set, the plugin's own directory or the 
										//system-wide /tmp directory will be used instead.
										
		'timeout' => 30,				//(in seconds) Links that take longer than this to respond will be treated as broken.
		
		'highlight_permanent_failures' => false,//Highlight links that have appear to be permanently broken (in Tools -> Broken Links).
		'failure_duration_threshold' => 3, 		//(days) Assume a link is permanently broken if it still hasn't 
												//recovered after this many days.
												
		'highlight_feedback_widget' => true, //Highlight the "Feedback" button in vivid orange
												
		'installation_complete' => false,
		'installation_failed' => false,
   )
);

/***********************************************
				Logging
************************************************/

include 'logger.php';

global $blclog;
$blclog = new blcDummyLogger;


/*
if ( constant('BLC_DEBUG') ){
	//Load FirePHP for debug logging
	if ( !class_exists('FB') ) {
		require_once 'FirePHPCore/fb.php4';
	}
	//FB::setEnabled(false);
}
//to comment out all calls : (^[^\/]*)(FB::)  ->  $1\/\/$2
//to uncomment : \/\/(\s*FB::)  ->   $1
//*/

/***********************************************
				Global functions
************************************************/

/**
 * Initialize link containers.
 * 
 * @uses do_action() on 'blc_init_containers' after all built-in link containers have been loaded.
 * @see blcContainer
 *
 * @return void
 */
function blc_init_containers(){
	global $blc_directory;
	
	//Only init once.
	static $done = false;
	if ( $done ) return;
	
	//Load the base container classes 
	require $blc_directory . '/includes/containers.php';
	
	//Load built-in link containers
	require $blc_directory . '/includes/containers/post.php';
	require $blc_directory . '/includes/containers/blogroll.php';
	require $blc_directory . '/includes/containers/custom_field.php';
	require $blc_directory . '/includes/containers/comment.php';
	require $blc_directory . '/includes/containers/dummy.php';
	
	//Notify other plugins that they may register their custom containers now.
	do_action('blc_init_containers');
	
	$done = true;
}

/**
 * Initialize link parsers.
 *
 * @uses do_action() on 'blc_init_parsers' after all built-in parsers have been loaded.
 *
 * @return void
 */
function blc_init_parsers(){
	global $blc_directory;
	
	//Only init once.
	static $done = false;
	if ( $done ) return;
	
	//Load the base parser classes
	require $blc_directory . '/includes/parsers.php';
	
	//Load built-in parsers
	require $blc_directory . '/includes/parsers/html_link.php';
	require $blc_directory . '/includes/parsers/image.php';
	require $blc_directory . '/includes/parsers/metadata.php';
	require $blc_directory . '/includes/parsers/url_field.php';
	
	do_action('blc_init_parsers');
	$done = true;
}

/**
 * Initialize link checkers.
 *
 * @uses do_action() on 'blc_init_checkers' after all built-in checker implementations have been loaded.
 *
 * @return void
 */
function blc_init_checkers(){
	global $blc_directory;
	
	//Only init once.
	static $done = false;
	if ( $done ) return;	
	
	//Load the base classes for link checker algorithms
	require $blc_directory . '/includes/checkers.php';
	
	//Load built-in checker implementations (only HTTP at the time)
	require $blc_directory . '/includes/checkers/http.php';

	do_action('blc_init_checkers');
	$done = true;
}

/**
 * Load and register all containers, parsers and checkers.
 *
 * @return void
 */
function blc_init_all_components(){
	blc_init_containers();
	blc_init_parsers();
	blc_init_checkers();
}

/**
 * Get the configuration object used by Broken Link Checker.
 *
 * @return blcConfigurationManager
 */
function blc_get_configuration(){
	return $GLOBALS['blc_config_manager'];
}

/**
 * Notify the link checker that there are unsynched items 
 * that might contain links (e.g. a new or edited post).
 *
 * @return void
 */
function blc_got_unsynched_items(){
	$conf = blc_get_configuration();
	
	if ( !$conf->options['need_resynch'] ){
		$conf->options['need_resynch'] = true;
		$conf->save_options();
	}
}

/**
 * (Re)create synchronization records for all containers and mark them all as unparsed.
 *
 * @param bool $forced If true, the plugin will recreate all synch. records from scratch.
 * @return void
 */
function blc_resynch( $forced = false ){
	global $wpdb, $blclog;
	
	if ( $forced ){
		$blclog->info('... Forced resynchronization initiated');
		
		//Drop all synchronization records
		$wpdb->query("TRUNCATE {$wpdb->prefix}blc_synch");
	} else {
		$blclog->info('... Resynchronization initiated');
	}
	
	//(Re)create and update synch. records for all container types.
	$blclog->info('... (Re)creating container records');
	blc_resynch_containers($forced);
	
	//Delete invalid instances
	$blclog->info('... Deleting invalid link instances');
	blc_cleanup_instances();
	
	//Delete orphaned links
	$blclog->info('... Deleting orphaned links');
	blc_cleanup_links();
	
	$blclog->info('... Setting resync. flags');
	blc_got_unsynched_items();
	
	//All done.
	$blclog->info('Database resynchronization complete.');
}

/***********************************************
				Utility hooks
************************************************/

/**
 * Add a weekly Cron schedule for email notifications
 *
 * @param array $schedules Existing Cron schedules.
 * @return array
 */
function blc_cron_schedules($schedules){
	if ( !isset($schedules['weekly']) ){
		$schedules['weekly'] = array(
	 		'interval' => 604800,
	 		'display' => __('Once Weekly')
	 	);
 	}
	return $schedules;
}
add_filter('cron_schedules', 'blc_cron_schedules');

/**
 * Display installation errors (if any) on the Dashboard.
 *
 * @return void
 */
function blc_print_installation_errors(){
	$conf = blc_get_configuration();
	if ( !$conf->options['installation_failed'] ){
		return;
	}
	
	$logger = new blcOptionLogger('blc_installation_log');
	$log = $logger->get_messages();
	
	$message = array(
		'<strong>' . __('Broken Link Checker installation failed', 'broken-link-checker') . '</strong>',
		'',
		'<em>Installation log follows :</em>',
	);
	foreach($log as $entry){
		array_push($message, $entry);
	}
	$message = implode("<br>\n", $message);
	
	echo "<div class='error'><p>$message</p></div>";
}
add_action('admin_notices', 'blc_print_installation_errors');


/***********************************************
				Main functionality
************************************************/

//Load the base classes
require $blc_directory . '/includes/links.php';
require $blc_directory . '/includes/instances.php';

if ( is_admin() || defined('DOING_CRON') ){
	
	//It's an admin-side or Cron request. Load all plugin components.
	add_action('plugins_loaded', 'blc_init_all_components');
	require $blc_directory . '/utility-class.php';
	require $blc_directory . '/core.php';
	$ws_link_checker = new wsBrokenLinkChecker( __FILE__ , $blc_config_manager );
	
} else {
	
	//This is user-side request, so we don't need to load the core.
	//We do need to load containers (for the purposes of catching
	//new comments and such). 
	add_action('plugins_loaded', 'blc_init_containers');
	
	//If broken links need to be marked, we also need to load parsers
	//(used to find & modify links) and utilities (used by some parsers).
	if ( $blc_config_manager->options['mark_broken_links'] || $blc_config_manager->options['nofollow_broken_links'] ){
		require $blc_directory . '/utility-class.php';
		add_action('plugins_loaded', 'blc_init_parsers');
	}
	
	//And possibly inject the CSS for removed links
	if ( $blc_config_manager->options['mark_removed_links'] && !empty($blc_config_manager->options['removed_link_css']) ){
		function blc_print_remove_link_css(){
			global $blc_config_manager;
			echo '<style type="text/css">',$blc_config_manager->options['removed_link_css'],'</style>';
		}
		add_action('wp_head', 'blc_print_remove_link_css');
	}
}





?>