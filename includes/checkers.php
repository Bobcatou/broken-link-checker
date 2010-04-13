<?php

/**
 * Base class for link checking algorithms.
 *
 * All link checkering algorithms should extend this class.
 *
 * @package Broken Link Checker
 * @access public
 */
class blcChecker {
	
  /**
   * Priority determines the order in which the plugin will try all registered checkers 
   * when looking for one that can check a particular URL. Registered checkers will be
   * tried in order, from highest to lowest priority, and the first one that returns
   * true when its can_check() method is called will be used.
   * 
   * Checker implementations should set their priority depending on how specific they are
   * in choosing the URLs that they check.
   *
   * -10 .. 10  : checks all URLs that have a certain protocol, e.g. all HTTP URLs.
   * 11  .. 100 : checks only URLs from a restricted number of domains, e.g. video site URLs.	
   * 100+ 		: checks only certain URLs from a certain domain, e.g. YouTube video links.
   * 
   */
	var $priority = -100;
	
  /**
   * Check if this checker knows how to check a particular URL.
   *
   * @param string $url
   * @param array|false $parsed_url The result of parsing $url with parse_url(). See PHP docs for details.
   * @return bool  
   */
	function can_check($url, $parsed_url){
		return false;
	}
	
  /**
   * Check an URL.
   *
   * This method returns an associative array containing results of 
   * the check. The following array keys are recognized by the plugin and
   * their values will be stored in the link's DB record :
   *	'broken' (bool) - True if the URL points to a missing/broken page. Required.
   *	'http_code' (int) - HTTP code returned when requesting the URL. Defaults to 0.     
   *	'redirect_count' (int) - The number of redirects. Defaults to 0.  
   *	'final_url' (string) - The redirected-to URL. Assumed to be equal to the checked URL by default.
   *	'request_duration' (float) - How long it took for the server to respond. Defaults to 0 seconds.
   *	'timeout' (bool) - True if checking the URL resulted in a timeout. Defaults to false.
   *	'may_recheck' (bool) - Allow the plugin to re-check the URL after 'recheck_threshold' seconds (see broken-link-checker.php).
   *	'log' (string) - Free-form log of the performed check. It will be displayed in the "Details" section of the checked link.
   *	'result_hash' (string) - A free-form hash or code uniquely identifying the detected link status. See sub-classes for examples. Max 200 characters.   
   *
   * @see blcLink:check() 
   *
   * @param string $url
   * @return array 
   */
	function check($url){
		trigger_error('Function blcChecker::check() must be over-ridden in a subclass', E_USER_ERROR);
	}
}

class blcCheckerRegistry {
	var $registered_checkers = array();
	
  /**
   * Register a link checker.
   *
   * @param string $class_name Class name of the checker.
   * @return void
   */
	function register_checker($class_name){
		$checker = new $class_name;
		$this->registered_checkers[] = $checker;
		
		usort($this->registered_checkers, array(&$this, 'compare_checkers'));
	}
	
  /**
   * Callback for sorting checkers by priority.
   *
   * @access private
   *
   * @param blcChecker $a
   * @param blcChecker $b
   * @return int
   */
	function compare_checkers($a, $b){
		return $b->priority - $a->priority;
	}
	
  /**
   * Get a checker object that can check the specified URL. 
   *
   * @param string $url
   * @return blcChecker|null
   */
	function get_checker_for($url){
		$parsed = @parse_url($url);
		
		foreach($this->registered_checkers as $checker){
			if ( $checker->can_check($url, $parsed) ){
				return $checker;
			}
		}
		
		return null;
	}
}

$GLOBALS['blc_checker_registry'] = new blcCheckerRegistry();

/**
 * Register a new link checker.
 *
 * @param string $class_name
 * @return void
 */
function blc_register_checker($class_name){
	return $GLOBALS['blc_checker_registry']->register_checker($class_name);
}

/**
 * Get the checker algo. implementation that knows how to check a specific URL.
 *
 * @param string $url The URL that needs to be checked.
 * @return blcChecker|null 
 */
function blc_get_checker_for($url){
	return $GLOBALS['blc_checker_registry']->get_checker_for($url);
}

?>