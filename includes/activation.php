<?php

global $blclog, $blc_config_manager, $wpdb;
$queryCnt = $wpdb->num_queries;

//Completing the installation/upgrade is required for the plugin to work, so make sure 
//the script doesn't get aborted by (for example) the browser timing out.
set_time_limit(300);  //5 minutes should be plenty, anything more would probably indicate an infinite loop or a deadlock 
ignore_user_abort(true);

//Log installation progress to a DB option
$blclog = new blcCachedOptionLogger('blc_installation_log');
register_shutdown_function(array(&$blclog, 'save')); //Make sure the log is saved even if the plugin crashes

$blclog->clear();
$blclog->info( sprintf('Plugin activated at %s.', date_i18n('Y-m-d H:i:s')) );

//Reset the "installation_complete" flag
$blc_config_manager->options['installation_complete'] = false;
//Note the time of the first installation (not very accurate, but still useful)
if ( empty($blc_config_manager->options['first_installation_timestamp']) ){
	$blc_config_manager->options['first_installation_timestamp'] = time();
}
$blc_config_manager->save_options();
$blclog->info('Installation/update begins.');

//Load the base classes and utilities
require_once BLC_DIRECTORY . '/includes/links.php';
require_once BLC_DIRECTORY . '/includes/link-query.php';
require_once BLC_DIRECTORY . '/includes/instances.php';
require_once BLC_DIRECTORY . '/includes/utility-class.php';

//Load the module subsystem
require_once BLC_DIRECTORY . '/includes/modules.php';
$moduleManager = blcModuleManager::getInstance();	
       
//If upgrading, activate/deactivate custom field and comment containers based on old ver. settings
if ( isset($blc_config_manager->options['check_comment_links']) ){
	if ( !$blc_config_manager->options['check_comment_links'] ){
		$moduleManager->deactivate('comment');
	}
	unset($blc_config_manager->options['check_comment_links']);
}
if ( empty($blc_config_manager->options['custom_fields']) ){
	$moduleManager->deactivate('custom_field');
}

//Prior to 1.5.2 (released 2012-05-27), there was a bug that would cause the donation flag to be
//set incorrectly. So we'll unset the flag in that case.
$reset_donation_flag =
	($blc_config_manager->options['first_installation_timestamp'] < strtotime('2012-05-27 00:00')) &&
	!$blc_config_manager->get('donation_flag_fixed', false);

if ( $reset_donation_flag) {
	$blc_config_manager->set('user_has_donated', false);
	$blc_config_manager->set('donation_flag_fixed', true);
}

//Prepare the database.
$blclog->info('Upgrading the database...');
require_once BLC_DIRECTORY . '/includes/admin/db-upgrade.php';
blcDatabaseUpgrader::upgrade_database();

//Remove invalid DB entries
$blclog->info('Cleaning up the database...');
blc_cleanup_database();

//Notify modules that the plugin has been activated. This will cause container
//modules to create and update synch. records for all new/modified posts and other items.
$blclog->info('Notifying modules...'); 
$moduleManager->plugin_activated();
blc_got_unsynched_items();

//Turn off load limiting if it's not available on this server.
$blclog->info('Updating server load limit settings...');
$load = blcUtility::get_server_load();
if ( empty($load) ){
	$blc_config_manager->options['enable_load_limit'] = false;
}

//And optimize my DB tables, too (for good measure)
$blclog->info('Optimizing the database...'); 
blcUtility::optimize_database();

$blclog->info('Completing installation...');
$blc_config_manager->options['installation_complete'] = true;
if ( $blc_config_manager->save_options() ){
    $blclog->info('Configuration saved.');
} else {
    $blclog->error('Error saving plugin configuration!');
};

$blclog->info(sprintf(
	'Installation/update completed at %s with %d queries executed.', 
	date_i18n('Y-m-d H:i:s'),
	$wpdb->num_queries - $queryCnt
));
$blclog->save();

?>