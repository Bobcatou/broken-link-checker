<?php

/**
 * @author W-Shadow 
 * @copyright 2009
 *
 * The terrifying uninstallation script.
 */

if( defined( 'ABSPATH') && defined('WP_UNINSTALL_PLUGIN') ) {

	//Remove the plugin's settings
	delete_option('wsblc_options');

	//Remove the database tables
	$mywpdb = $GLOBALS['wpdb'];    
	if( isset($mywpdb) ) { 
		//EXTERMINATE!
		$mywpdb->query( "DROP TABLE IF EXISTS {$mywpdb->prefix}blc_linkdata, {$mywpdb->prefix}blc_postdata, {$mywpdb->prefix}blc_instances, {$mywpdb->prefix}blc_links, {$mywpdb->prefix}blc_synch, {$mywpdb->prefix}blc_filters" );
	}
}

?>