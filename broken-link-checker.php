<?php
/*
Plugin Name: Broken Link Checker
Plugin URI: http://w-shadow.com/blog/2007/08/05/broken-link-checker-for-wordpress/
Description: Checks your blog for broken links and missing images and notifies you on the dashboard if any are found.
Version: 0.9.7
Author: Janis Elsts
Author URI: http://w-shadow.com/blog/
Text Domain: broken-link-checker
*/

if ( !function_exists('blc_get_plugin_file') ){
	/**
	 * Retrieve the fully qualified filename of BLC's main PHP file.
	 * 
	 * @return string
	 */
	function blc_get_plugin_file(){
		//You'd be surprised on how useful this can be. 
		return __FILE__; 
	}
}

//Load the actual plugin
require 'core/init.php';

?>