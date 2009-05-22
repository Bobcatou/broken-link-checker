<?php

/**
 * @author W-Shadow 
 * @copyright 2009
 *
 * The terrifying uninstallation script.
 */

if( !defined( 'ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') && !current_user_can('delete_plugins') )
    exit();
    
if( !isset($wpdb) ) 
	exit();

//Remove the plugin's settings
delete_option('wsblc_options');

//EXTERMINATE!
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}blc_linkdata, {$wpdb->prefix}blc_postdata, {$wpdb->prefix}blc_instances, {$wpdb->prefix}blc_links, {$wpdb->prefix}blc_synch" );

?>