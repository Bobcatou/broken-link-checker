<?php

/**
 * @author W-Shadow 
 * @copyright 2010
 */
 
if (!class_exists('blcLink')){
class blcLink {
	
	//Object state
	var $is_new = false;
	
	//DB fields
	var $link_id = 0;
	var $url = '';
	
	var $being_checked = false;
	var $last_check = 0;
	var $last_check_attempt = 0;
	var $check_count = 0;
	var $http_code = 0;
	var $request_duration = 0;
	var $timeout = false;
	
	var $redirect_count = 0;
	var $final_url = '';
	
	var $broken = false;
	var $first_failure = 0;
	var $last_success = 0;
	var $may_recheck = 1; 
	
	var $false_positive = false;
	var $result_hash = '';
	
	var $log = '';
	
	//A list of DB fields and their storage formats
	var $field_format;
	
	//A cached list of the link's instances
	var $_instances = null;
	
	function __construct($arg = null){
		global $wpdb;
		
		$this->field_format = array(
			'url' => '%s',
			'first_failure' => 'datetime',
			'last_check' => 'datetime',
			'last_success' => 'datetime',
			'last_check_attempt' => 'datetime',
			'check_count' => '%d',
			'final_url' => '%s',
			'redirect_count' => '%d',
			'log' => '%s',
			'http_code' => '%d',
			'request_duration' => '%f',
			'timeout' => 'bool',
			'result_hash' => '%s',
			'broken' => 'bool',
			'false_positive' => 'bool',
			'may_recheck' => 'bool',
			'being_checked' => 'bool',
		);
		
		if (is_int($arg)){
			//Load a link with ID = $arg from the DB.
			$q = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}blc_links WHERE link_id=%d LIMIT 1", $arg);
			$arr = $wpdb->get_row( $q, ARRAY_A );
			
			if ( is_array($arr) ){ //Loaded successfully
				$this->set_values($arr);
			} else {
				//Link not found. The object is invalid. 
				//I'd throw an error, but that wouldn't be PHP 4 compatible...	
			}			
			
		} else if (is_string($arg)){
			//Load a link with URL = $arg from the DB. Create a new one if the record isn't found.
			$q = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}blc_links WHERE url=%s LIMIT 1", $arg);
			$arr = $wpdb->get_row( $q, ARRAY_A );
			
			if ( is_array($arr) ){ //Loaded successfully
				$this->set_values($arr);
			} else { //Link not found, treat as new
				$this->url = $arg;
				$this->is_new = true;
			}			
			
		} else if (is_array($arg)){
			$this->set_values($arg);
			//Is this a new link?
			$this->is_new  = empty($this->link_id);
		} else {
			$this->is_new = true;
		}
	}
	
	function blcLink($arg = null){
		$this->__construct($arg);
	}
	
  /**
   * blcLink::set_values()
   * Set the internal values to the ones provided in an array (doesn't sanitize).
   *
   * @param array $arr An associative array of values
   * @return void
   */
	function set_values($arr){
		$arr = $this->to_native_format($arr);
		
		foreach( $arr as $key => $value ){
			$this->$key = $value;
		}
	}
	
  /**
   * Check whether the object represents a valid link
   *
   * @return bool
   */
	function valid(){
		return !empty( $this->url ) && ( !empty($this->link_id) || $this->is_new );
	}
	
  /**
   * Check if the link is working.
   *
   * @param bool $save_results Automatically save the results of the check. 
   * @return bool 
   */
	function check( $save_results = true ){
		if ( !$this->valid() ) return false;
		
		$this->last_check_attempt = time();
		
		/*
		If the link is stil marked as in the process of being checked, that probably means
		that the last time the plugin tried to check it the script got terminated by PHP for 
		running over the execution time limit or causing a fatal error. Lets assume the link is broken.  
        */
        if ( $this->being_checked ) {
        	
        	$this->being_checked = false;
        	
        	$this->broken = true;
        	$this->timeout = true;
        	$this->http_code = BLC_TIMEOUT;
        	
        	$this->request_duration = 0;
        	$this->redirect_count = 0;
        	$this->final_url = $this->url;
        	
        	$this->log .= "\r\n[" . __("The plugin script was terminated while trying to check the link.", 'broken-link-checker') . "]";
        	
        	$this->status_changed($this->broken, 'link_checker_terminated');

        	        	
        	if ( $save_results ){
				$this->save();
			}
        	
            return false;
        }
        
        $this->being_checked = true;
        $this->check_count++;
		
		if ( $save_results ) {
			
	        //Update the DB record before actually performing the check.
	        //Useful if something goes terribly wrong while checking this particular URL 
			//(e.g. the server might kill the script for running over the exec. time limit).
	        //Note : might be unnecessary.
	        $this->save();
        }
        
        $defaults = array(
        	'broken' => false,
        	'http_code' => 0,
        	'redirect_count' => 0,
        	'final_url' => $this->url,
        	'request_duration' => 0,
        	'timeout' => false,
        	'may_recheck' => true,
        	'log' => '',
        	'result_hash' => '',
		);
        
        
        $checker = blc_get_checker_for($this->url);
        
		if ( is_null($checker) ){
			//Oops, there are no checker implementations that can handle this link.
			//Assume the link is working, but leave a note in the log.
			$this->broken = false;
			$this->being_checked = false;
			$this->log = __("The plugin doesn't know how to check this type of link.", 'broken-link-checker');
						
			if ( $save_results ){
				$this->save();
			}
			
			return true;
		}
		
		//Check the link
		$rez = $checker->check($this->url);
		//FB::info($rez, "Check results");
		
		//Filter the returned array to leave only the restricted set of keys that we're interested in.
		$results = array();
		foreach($rez as $name => $value){
			if ( array_key_exists($name, $defaults) ){
				$results[$name] = $value;
			}
		}
		$results = array_merge($defaults, $results);
		
		//The result hash is special - see blcLink::status_changed()
		$new_result_hash = $results['result_hash'];
		unset($results['result_hash']);
		
		//Update the object's fields with the new results
		$this->set_values($results);
		
		//Update timestamps & state-dependent fields
		$this->status_changed($results['broken'], $new_result_hash);
		$this->being_checked = false;
		
		//Save results to the DB 
		if($save_results){
			$this->save();
		}
		
		return $this->broken;
	}
	
  /**
   * A helper method used to update timestamps & other state-dependent fields 
   * after the state of the link (broken vs working) has just been determined.
   *
   * @access private
   *
   * @param bool $broken
   * @return void
   */
	function status_changed($broken, $new_result_hash = ''){
		
		if ( $this->false_positive && !empty($new_result_hash) ){
			//If the link has been marked as a (probable) false positive, 
			//mark it as broken *only* if the new result is different from 
			//the one that caused the user to mark it as a false positive.
			if ( $broken ){
				if ( $this->result_hash == $new_result_hash ){
					//Got the same result as before, assume it's still incorrect and the link actually works.
					$broken = false; 
				} else {
					//Got a new result. Assume (quite optimistically) that it's not a false positive.
					$this->false_positive = false;
				}
			} else {
				//The plugin now thinks the link is working, 
				//so it's no longer a false positive.
				$this->false_positive = false;
			}
		}
		
		$this->broken = $broken;
		$this->result_hash = $new_result_hash;
		
		//Update timestamps
		$this->last_check = $this->last_check_attempt;
		if ( $this->broken ){
			if ( empty($this->first_failure) ){
				$this->first_failure = $this->last_check;
			}
		} else {
			$this->first_failure = 0;
			$this->last_success = $this->last_check;
			$this->check_count = 0;
		}
		
		//Add a line indicating link status to the log
		if ( !$broken ) {
        	$this->log .= "\n" . __("Link is valid.", 'broken-link-checker');
        } else {
			$this->log .= "\n" . __("Link is broken.", 'broken-link-checker');
		}
	}
	
  /**
   * blcLink::save()
   * Save link data to DB.
   *
   * @return bool True if saved successfully, false otherwise.
   */
	function save(){
		global $wpdb;

		if ( !$this->valid() ) return false;
		
		//Make a list of fields to be saved and their values in DB format
		$values = array();
		foreach($this->field_format as $field => $format){
			$values[$field] = $this->$field;
		}
		$values = $this->to_db_format($values);
		
		if ( $this->is_new ){
			
			//Insert a new row
			$q = sprintf(
				"INSERT INTO {$wpdb->prefix}blc_links( %s ) VALUES( %s )", 
				implode(', ', array_keys($values)), 
				implode(', ', array_values($values))
			);
			//FB::log($q, 'Link add query');
			
			$rez = $wpdb->query($q) !== false;
			
			if ($rez){
				$this->link_id = $wpdb->insert_id;
				//FB::info($this->link_id, "Link added");
				//If the link was successfully saved then it's no longer "new"
				$this->is_new = false;
			} else {
				//FB::error($wpdb->last_error, "Error adding link {$this->url}");
			}
				
			return $rez;
									
		} else {
			
			//Generate the field = dbvalue expressions 
			$set_exprs = array();
			foreach($values as $name => $value){
				$set_exprs[] = "$name = $value";
			}
			$set_exprs = implode(', ', $set_exprs);
			
			//Update an existing DB record
			$q = sprintf(
				"UPDATE {$wpdb->prefix}blc_links SET %s WHERE link_id=%d",
				$set_exprs,
				intval($this->link_id)
			);
			//FB::log($q, 'Link update query');
			
			$rez = $wpdb->query($q) !== false;
			
			if ( $rez ){
				//FB::log($this->link_id, "Link updated");
			} else {
				//FB::error($wpdb->last_error, "Error updating link {$this->url}");
			}
			
			return $rez;			
		}
	}
	
  /**
   * A helper method for converting the link's field values to DB format and escaping them 
   * for use in SQL queries. 
   *
   * @param array $values
   * @return array
   */
	function to_db_format($values){
		global $wpdb;
		
		$dbvalues = array();
		
		foreach($values as $name => $value){
			//Skip fields that don't exist in the blc_links table.
			if ( !isset($this->field_format[$name]) ){
				continue;
			}
			
			$format = $this->field_format[$name];
			
			//Convert native values to a format comprehensible to the DB
			switch($format){
				
				case 'datetime' :
					if ( empty($value) ){
						$value = '0000-00-00 00:00:00';
					} else {
						$value = date('Y-m-d H:i:s', $value);
					}
					$format = '%s';
					break;
					
				case 'bool':
					if ( $value ){
						$value = 1;
					} else {
						$value = 0;
					}
					$format = '%d';
					break;
			}
			
			//Escapize
			$value = $wpdb->prepare($format, $value);
			
			$dbvalues[$name] = $value;
		}
		
		return $dbvalues;		
	}
	
  /**
   * A helper method for converting values fetched from the database to native datatypes.
   *
   * @param array $values
   * @return array
   */
	function to_native_format($values){
		
		foreach($values as $name => $value){
			//Don't process ffields that don't exist in the blc_links table.
			if ( !isset($this->field_format[$name]) ){
				continue;
			}
			
			$format = $this->field_format[$name];
			
			//Convert values in DB format to native datatypes.
			switch($format){
				
				case 'datetime' :
					if ( $value == '0000-00-00 00:00:00' ){
						$value = 0;
					} elseif (is_string($value)) {
						$value = strtotime($value);
					}
					break;
					
				case 'bool':
					$value = (bool)$value;
					break;
					
				case '%d':
					$value = intval($value);
					break;
					
				case '%f':
					$value = floatval($value);
					break;
					
			}
			
			$values[$name] = $value;
		}
		
		return $values;
	}
	
  /**
   * blcLink::edit()
   * Edit all instances of the link by changing the URL.
   *
   * Here's how this really works : create a new link with the new URL. Then edit()
   * all instances and point them to the new link record. If some instance can't be 
   * edited they will still point to the old record. The old record is deleted
   * if all instances were edited successfully.   
   *
   * @param string $new_url
   * @return array An associative array with these keys : 
   *   new_link_id - the database ID of the new link.
   *   new_link - the new link (an instance of blcLink).
   *   cnt_okay - the number of successfully edited link instances. 
   *   cnt_error - the number of instances that caused problems.
   *   errors - an array of WP_Error objects corresponding to the failed edits.  
   */
	function edit($new_url){
		if ( !$this->valid() ){
			return new WP_Error(
				'link_invalid',
				__("Link is not valid", 'broken-link-checker')
			);
		}
		
		//FB::info('Changing link '.$this->link_id .' to URL "'.$new_url.'"');
		
		$instances = $this->get_instances();
		//Fail if there are no instances
		if (empty($instances)) {
			return array(
				'new_link_id' => $this->link_id,
				'new_link' => $this,
				'cnt_okay' => 0,
				'cnt_error' => 0,
				'errors' => array(
					new WP_Error(
						'no_instances_found',
						__('This link can not be edited because it is not used anywhere on this site.', 'broken-link-checker')
					)
				)
			);
		};
		
		//Load or create a link with the URL = $new_url  
		$new_link = new blcLink($new_url);
		$was_new = $new_link->is_new;
		if ($new_link->is_new) {
			//FB::log($new_link, 'Saving a new link');
			$new_link->save(); //so that we get a valid link_id
		}
		
		//FB::log("Changing link to $new_url");
		
		if ( empty($new_link->link_id) ){
			//FB::error("Failed to create a new link record");
			return array(
				'new_link_id' => $this->link_id,
				'new_link' => $this,
				'cnt_okay' => 0,
				'cnt_error' => 0,
				'errors' => array(
					new WP_Error(
						'link_creation_failed',
						__('Failed to create a DB entry for the new URL.', 'broken-link-checker')
					)
				)
			);;
		}
		
		$cnt_okay = $cnt_error = 0;
		$errors = array();
		
		//Edit each instance.
		//FB::info('Editing ' . count($instances) . ' instances');
		foreach ( $instances as $instance ){
			$rez = $instance->edit( $new_url, $this->url ); 			
			if ( is_wp_error($rez) ){
				$cnt_error++;
				array_push($errors, $rez);
				//FB::error($instance, 'Failed to edit instance ' . $instance->instance_id);
			} else {
				$cnt_okay++;
				$instance->link_id = $new_link->link_id;
				$instance->save();
				//FB::info($instance, 'Successfully edited instance '  . $instance->instance_id);
			}
		}
		
		//If all instances were edited successfully we can delete the old link record.
		//UNLESS this link is equal to the new link (which should never happen, but whatever).
		if ( ( $cnt_error == 0 ) && ( $cnt_okay > 0 ) && ( $this->link_id != $new_link->link_id ) ){
			$this->forget( false );
		}
		
		//On the other hand, if no instances could be edited and the $new_link was really new,
		//then delete it.
		if ( ( $cnt_okay == 0 ) && $was_new ){
			$new_link->forget( false );
			$new_link = $this;
		}
		
		return array(
			'new_link_id' => $new_link->link_id,
			'new_link' => $new_link,
			'cnt_okay' => $cnt_okay,
			'cnt_error' => $cnt_error, 
			'errors' => $errors,
		 );			 
	}
	
  /**
   * Edit all of of this link's instances and replace the URL with the URL that it redirects to. 
   * This method does nothing if the link isn't a redirect.
   *
   * @see blcLink::edit() 
   *
   * @return array|WP_Error  
   */ 
	function deredirect(){
		if ( !$this->valid() ){
			return new WP_Error(
				'link_invalid',
				__("Link is not valid", 'broken-link-checker')
			);
		}
		
		if ( ($this->redirect_count <= 0) || empty($this->final_url) ){
			return array(
				'new_link_id' => $this->link_id,
				'new_link' => $this,
				'cnt_okay' => 0,
				'cnt_error' => 0, 
				'errors' => array(
					new WP_Error(
						'not_redirect',
						__("This link is not a redirect", 'broken-link-checker')
					)
				),
			);
		}
		
		return $this->edit($this->final_url);
	}

  /**
   * Unlink all instances and delete the link record.
   *
   * @return array|WP_Error An associative array with these keys : 
   *    cnt_okay - the number of successfully removed instances.
   *    cnt_error - the number of instances that couldn't be removed.
   *    link_deleted - true if the link record was deleted.
   *    errors - an array of WP_Error objects describing the errors that were encountered, if any.
   */
	function unlink(){
		if ( !$this->valid() ){
			return new WP_Error(
				'link_invalid',
				__("Link is not valid", 'broken-link-checker')
			);
		}
		
		//FB::info($this, 'Removing link');
		$instances = $this->get_instances();
		
		//No instances? Just remove the link then.
		if (empty($instances)) {
			//FB::warn("This link has no instances. Deleting the link.");
			$rez = $this->forget( false ) !== false;
			
			if ( $rez ){
				return array(
					'cnt_okay' => 1,
					'cnt_error' => 0,
					'link_deleted' => true,
					'errors' => array(), 
				);
			} else {
				return array(
					'cnt_okay' => 0,
					'cnt_error' => 0,
					'link_deleted' => false,
					'errors' => array(
						new WP_Error(
							"deletion_failed",
							__("Couldn't delete the link's database record", 'broken-link-checker')
						)
					), 
				);
			}
		}
		
		
		//FB::info('Unlinking ' . count($instances) . ' instances');
		
		$cnt_okay = $cnt_error = 0;
		$errors = array();
		
		//Unlink each instance.
		foreach ( $instances as $instance ){
			$rez = $instance->unlink( $this->url ); 
			
			if ( is_wp_error($rez) ){
				$cnt_error++;
				array_push($errors, $rez);
				//FB::error( $instance, 'Failed to unlink instance' );
			} else {
				$cnt_okay++;
				//FB::info( $instance, 'Successfully unlinked instance' );
			}
		}
		
		//If all instances were unlinked successfully we can delete the link record.
		if ( ( $cnt_error == 0 ) && ( $cnt_okay > 0 ) ){
			//FB::log('Instances removed, deleting the link.');
			$link_deleted = $this->forget() !== false;
			
			if ( !$link_deleted ){
				array_push(
					$errors, 
					new WP_Error(
						"deletion_failed",
						__("Couldn't delete the link's database record", 'broken-link-checker')
					)
				);
			}
			
		} else {
			//FB::error("Something went wrong. Unlinked instances : $cnt_okay, errors : $cnt_error");
			$link_deleted = false;
		}
		
		return array(
			'cnt_okay' => $cnt_okay,
			'cnt_error' => $cnt_error,
			'link_deleted' => $link_deleted,
			'errors' => $errors,
		); 
	}
	
  /**
   * Remove the link and (optionally) its instance records from the DB. Doesn't alter posts/etc.
   *
   * @return mixed 1 on success, 0 if link not found, false on error. 
   */
	function forget($remove_instances = true){
		global $wpdb;
		if ( !$this->valid() ) return false;
		
		if ( !empty($this->link_id) ){
			//FB::info($this, 'Deleting link from DB');
			
			if ( $remove_instances ){
				//Remove instances, if any
				$wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->prefix}blc_instances WHERE link_id=%d", $this->link_id) );
			}
			
			//Remove the link itself
			$rez = $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->prefix}blc_links WHERE link_id=%d", $this->link_id) );
			$this->link_id = 0;
			
			return $rez;
		} else {
			return false;
		}
		
	}
	
  /**
   * Get a list of the link's instances
   *
   * @param bool $ignore_cache Don't use the internally cached instance list.
   * @param string $purpose 
   * @return array An array of instance objects or FALSE on failure.
   */
	function get_instances( $ignore_cache = false, $purpose = '' ){
		global $wpdb;
		if ( !$this->valid() || empty($this->link_id) ) return false;
		
		if ( $ignore_cache || is_null($this->_instances) ){
			$instances = blc_get_instances( array($this->link_id), $purpose );
			if ( !empty($instances) ){
				$this->_instances = $instances[$this->link_id];
			}
		}
		
		return $this->_instances;
	}
}

} //class_exists

class blcLinkQuery {
	
	var $native_filters;
	var $search_filter;
	var $custom_filters = array();
	
	var $valid_url_params = array(); 
	
	function __construct(){
		//Init. the available native filters.
		$this->native_filters = array(
			'broken' => array(
				'params' => array(
					'where_expr' => '( broken = 1 )',
				),
				'name' => __('Broken', 'broken-link-checker'),
				'heading' => __('Broken Links', 'broken-link-checker'),
				'heading_zero' => __('No broken links found', 'broken-link-checker'),
				'native' => true,
			 ), 
			 'redirects' => array(
			 	'params' => array(
					'where_expr' => '( redirect_count > 0 )',
				),
				'name' => __('Redirects', 'broken-link-checker'),
				'heading' => __('Redirected Links', 'broken-link-checker'),
				'heading_zero' => __('No redirects found', 'broken-link-checker'),
				'native' => true,
			 ), 
			 
			'all' => array(
				'params' => array(
					'where_expr' => '1',
				),
				'name' => __('All', 'broken-link-checker'),
				'heading' => __('Detected Links', 'broken-link-checker'),
				'heading_zero' => __('No links found (yet)', 'broken-link-checker'),
				'native' => true,
			 ), 
		);
		
		//Create the special "search" filter
		$this->search_filter = array(
			'name' => __('Search', 'broken-link-checker'),
			'heading' => __('Search Results', 'broken-link-checker'),
			'heading_zero' => __('No links found for your query', 'broken-link-checker'),
			'params' => array(),
			'use_url_params' => true,
			'hidden' => true,
		);
		
		//These search arguments may be passed via the URL if the filter's 'use_url_params' field is set to True.
		//They map to the fields of the search form on the Tools -> Broken Links page. Only these arguments
		//can be used in user-defined filters.
		$this->valid_url_params = array( 
 			's_link_text',
 			's_link_url',
 			's_parser_type',
 			's_container_type',
 			's_link_type',   
 			's_http_code',
 			's_filter',
		);
	}
	
	function blcLinkQuery(){
		$this->__construct();
	}
	
  /**
   * Load and return the list of user-defined link filters.
   *
   * @return array An array of custom filter definitions. If there are no custom filters defined returns an empty array.
   */
	function load_custom_filters(){
		global $wpdb;
		
		$filter_data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}blc_filters ORDER BY name ASC", ARRAY_A);
		$filters = array();
		
		if ( !empty($filter_data) ) {		
			foreach($filter_data as $data){
				wp_parse_str($data['params'], $params);
				
				$filters[ 'f'.$data['id'] ] = array(
					'name' => $data['name'],
					'params' => $params,
					'heading' => ucwords($data['name']),
					'heading_zero' => __('No links found for your query', 'broken-link-checker'),
					'custom' => true,
				);
			}
		}
		
		$this->custom_filters = $filters;
		
		return $filters;
	}
	
  /**
   * Add a custom link filter.
   *
   * @param string $name Filter name.
   * @param string|array $params Filter params. Either as a query string, or an array.
   * @return string|bool The ID of the newly added filter, or False.  
   */
	function create_custom_filter($name, $params){
		global $wpdb;
		
		if ( is_array($params) ){
			$params = http_build_query($params, null, '&');
		}
		
		//Save the new filter
		$q = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}blc_filters(name, params) VALUES (%s, %s)",
			$name, $params
		);
		
		if ( $wpdb->query($q) !== false ){
			$filter_id = 'f'.$wpdb->insert_id;
			return $filter_id;
		} else {
			return false;
		}
	}
	
  /**
   * Delete a custom filter
   *
   * @param string $filter_id
   * @return bool True on success, False if a database error occured.
   */
	function delete_custom_filter($filter_id){
		global $wpdb;
		
		//Remove the "f" character from the filter ID to get its database key
		$filter_id = intval(ltrim($_POST['filter_id'], 'f'));
		
		//Try to delete the filter
		$q = $wpdb->prepare("DELETE FROM {$wpdb->prefix}blc_filters WHERE id = %d", $filter_id);
		if ( $wpdb->query($q) !== false ){
			return true;
		} else {
			return false;
		}
	}
	
	function get_filters(){
		$filters = array_merge($this->native_filters, $this->custom_filters);
		$filters['search'] = $this->search_filter;
		return $filters;
	}
	
  /**
   * Get a link search filter by filter ID.
   *
   * @param string $filter_id
   * @return array|null
   */
	function get_filter($filter_id){
		$filters = $this->get_filters();
		if ( isset($filters[$filter_id]) ){
			return $filters[$filter_id];
		} else {
			return null;
		}
	}
	
  /**
   * Get link search parameters from the specified filter. 
   *
   * @param array $filter
   * @return array An array of parameters suitable for use with blcLinkQuery::get_links()
   */
	function get_search_params( $filter = null ){
		//If present, the filter's parameters may be saved either as an array or a string.
		$params = array();
		if ( !empty($filter) && !empty($filter['params']) ){
			$params = $filter['params']; 
			if ( is_string( $params ) ){
				wp_parse_str($params, $params);
			}
		}
		
		//Merge in the parameters from the current request, if required
		if ( isset($filter['use_url_params']) && $filter['use_url_params'] ){
			$params = array_merge($params, $this->get_url_search_params());
		}
		
		return $params;
	}
	
  /**
   * Extract search query parameters from the current URL
   *
   * @return array
   */
	function get_url_search_params(){
		$url_params = array();
		foreach ($_GET as $param => $value){
			if ( in_array($param, $this->valid_url_params) ){
				$url_params[$param] = $value;
			}
		}
		return $url_params;
	}
	
	
	
  /**
   * A helper method for parsing a list of search criteria and generating the parts of the SQL query.
   *
   * @see blcLinkQuery::get_links() 
   *
   * @param array $params An array of search criteria.
   * @return array 'where_exprs' - an array of search expressions, 'join_instances' - whether joining the instance table is required. 
   */
	function compile_search_params($params){
		global $wpdb;		
		
		//Track whether we'll need to left-join the instance table to run the query.
		$join_instances = false;
		
		//Generate the individual clauses of the WHERE expression and store them in an array.
		$pieces = array();
		
		//A part of the WHERE expression can be specified explicitly
		if ( !empty($params['where_expr']) ){
			$pieces[] = $params['where_expr'];
			$join_instances = $join_instances || ( stripos($params['where_expr'], 'instances') !== false );
		}
		
		//List of allowed link ids (either an array or comma-separated)
		if ( !empty($params['link_ids']) ){
			$link_ids = $params['link_ids'];
			
			if ( is_string($link_ids) ){
				$link_ids = preg_split('/[,\s]+/', $link_ids);
			}
			
			//Only accept non-zero integers
			$sanitized_link_ids = array();
			foreach($link_ids as $id){
				$id = intval($id);
				if ( $id != 0 ){
					$sanitized_link_ids[] = $id;
				}
			}
			
			$pieces[] = 'link_id IN (' . implode(', ', $sanitized_link_ids) . ')';
		}
		
		//Anchor text - use LIKE search
		if ( !empty($params['s_link_text']) ){
			$s_link_text = like_escape($wpdb->escape($params['s_link_text']));
			$s_link_text  = str_replace('*', '%', $s_link_text);
			
			$pieces[] = '(instances.link_text LIKE "%' . $s_link_text . '%")';
			$join_instances = true;
		}
		
		//URL - try to match both the initial URL and the final URL.
		//There is limited wildcard support, e.g. "google.*/search" will match both 
		//"google.com/search" and "google.lv/search" 
		if ( !empty($params['s_link_url']) ){
			$s_link_url = like_escape($wpdb->escape($params['s_link_url']));
			$s_link_url = str_replace('*', '%', $s_link_url);
			
			$pieces[] = '(links.url LIKE "%'. $s_link_url .'%") OR '.
				        '(links.final_url LIKE "%'. $s_link_url .'%")';
		}
		
		//Parser type should match the parser_type column in the instance table.
		if ( !empty($params['s_parser_type']) ){
			$s_parser_type = $wpdb->escape($params['s_parser_type']);
			$pieces[] = "instances.parser_type = '$s_parser_type'";
			$join_instances = true;
		}
		
		//Container type should match the container_type column in the instance table.
		if ( !empty($params['s_container_type']) ){
			$s_container_type = $wpdb->escape($params['s_container_type']);
			$pieces[] = "instances.container_type = '$s_container_type'";
			$join_instances = true;
		}
		
		//Container ID should match... you guessed it - container_id
		if ( !empty($params['s_container_id']) ){
			$s_container_id = intval($params['s_container_id']);
			if ( $s_container_id != 0 ){
				$pieces[] = "instances.container_id = $s_container_id";
				$join_instances = true;
			}
		}
			
		//Link type can match either the the parser_type or the container_type.
		if ( !empty($params['s_link_type']) ){
			$s_link_type = $wpdb->escape($params['s_link_type']);
			$pieces[] = "instances.parser_type = '$s_link_type' OR instances.container_type='$s_link_type'";
			$join_instances = true;
		}
			
		//HTTP code - the user can provide a list of HTTP response codes and code ranges.
		//Example : 201,400-410,500 
		if ( !empty($params['s_http_code']) ){
			//Strip spaces.
			$params['s_http_code'] = str_replace(' ', '', $params['s_http_code']);
			//Split by comma
			$codes = explode(',', $params['s_http_code']);
			
			$individual_codes = array();
			$ranges = array();
			
			//Try to parse each response code or range. Invalid ones are simply ignored.
			foreach($codes as $code){
				if ( is_numeric($code) ){
					//It's a single number
					$individual_codes[] = abs(intval($code));
				} elseif ( strpos($code, '-') !== false ) {
					//Try to parse it as a range
					$range = explode( '-', $code, 2 );
					if ( (count($range) == 2) && is_numeric($range[0]) && is_numeric($range[0]) ){
						//Make sure the smaller code comes first
						$range = array( intval($range[0]), intval($range[1]) );
						$ranges[] = array( min($range), max($range) );
					}
				}
			}
			
			$piece = array();
			
			//All individual response codes get one "http_code IN (...)" clause 
			if ( !empty($individual_codes) ){
				$piece[] = '(links.http_code IN ('. implode(', ', $individual_codes) .'))';
			}
			
			//Ranges get a "http_code BETWEEN min AND max" clause each
			if ( !empty($ranges) ){
				$range_strings = array();
				foreach($ranges as $range){
					$range_strings[] = "(links.http_code BETWEEN $range[0] AND $range[1])";
				}
				$piece[] = '( ' . implode(' OR ', $range_strings) . ' )';
			}
			
			//Finally, generate a composite WHERE clause for both types of response code queries
			if ( !empty($piece) ){
				$pieces[] = implode(' OR ', $piece);
			}
			
		}			
			
		//Custom filters can optionally call one of the native filters
		//to narrow down the result set. 
		if ( !empty($params['s_filter']) && isset($this->native_filters[$params['s_filter']]) ){
			$the_filter = $this->native_filters[$params['s_filter']];
			$extra_criteria = $this->compile_search_params($the_filter['params']);
			
			$pieces = array_merge($pieces, $extra_criteria['where_exprs']);
			$join_instances = $join_instances || $extra_criteria['join_instances'];			
		}
		
		return array(
			'where_exprs' => $pieces,
			'join_instances' => $join_instances,
		);
	}
	
  /**
   * blcLinkQuery::get_links()
   *
   * @see blc_get_links()
   *
   * @param array $params
   * @param string $purpose
   * @return array|int
   */
	function get_links($params = null){
		global $wpdb;
		
		if( !is_array($params) ){
			$params = array();
		} 
		
		$defaults = array(
			'offset' => 0,
			'max_results' => 0,
			'load_instances' => false,
			'load_containers' => false,
			'load_wrapped_objects' => false,
			'count_only' => false,
			'purpose' => '',
		);
		
		$params = array_merge($defaults, $params);
		
		//Compile the search-related params into search expressions usable in a WHERE clause
		$criteria = $this->compile_search_params($params);
		
		//Build the WHERE clause
		if ( !empty($criteria['where_exprs']) ){
			$where_expr = "\t( " . implode(" ) AND\n\t( ", $criteria['where_exprs']) . ' ) ';
		} else {
			$where_expr = '1';
		}
		
		//Join the blc_instances table if it's required to perform the search.  
		$joins = "";
		if ( $criteria['join_instances'] ){
			$joins = "JOIN {$wpdb->prefix}blc_instances AS instances ON links.link_id = instances.link_id";
		}
		
		if ( $params['count_only'] ){
			//Only get the number of matching links.
			$q = "
				SELECT COUNT(*)
				FROM (	
					SELECT 0
					
					FROM 
						{$wpdb->prefix}blc_links AS links 
						$joins
					
					WHERE
						$where_expr
					
				   GROUP BY links.link_id) AS foo";
			
			return $wpdb->get_var($q);
		}
		 
		//Select the required links.
		$q = "SELECT 
				 links.*
				
			  FROM 
				 {$wpdb->prefix}blc_links AS links
				 $joins
				
			   WHERE
				 $where_expr
				
			   GROUP BY links.link_id";
			   
		//Add the LIMIT clause
		if ( $params['max_results'] || $params['offset'] ){
			$q .= sprintf("\nLIMIT %d, %d", $params['offset'], $params['max_results']);
		}
		
		$results = $wpdb->get_results($q, ARRAY_A);
		if ( empty($results) ){
			return array();
		}
		
		//Create the link objects
		$links = array();
		
		foreach($results as $result){
			$link = new blcLink($result);
			$links[$link->link_id] = $link;
		}
		
		$purpose = $params['purpose'];
		/*
		Preload instances if :
			* It has been requested via the 'load_instances' argument. 
			* The links are going to be displayed or edited, which involves instances. 
		*/
		$load_instances = $params['load_instances'] || in_array($purpose, array(BLC_FOR_DISPLAY, BLC_FOR_EDITING));
		
		if ( $load_instances ){
			$link_ids = array_keys($links);
			$all_instances = blc_get_instances($link_ids, $purpose, $params['load_containers'], $params['load_wrapped_objects']);
			//Assign each batch of instances to the right link
			foreach($all_instances as $link_id => $instances){
				$links[$link_id]->_instances = $instances;
			}
		}

		return $links;
	}
	
  /**
   * Calculate the number of results for all known filters
   *
   * @return void
   */
	function count_filter_results(){
		foreach($this->native_filters as $filter_id => $filter){
			$this->native_filters[$filter_id]['count'] = $this->get_filter_links(
				$filter, array('count_only' => true)
			);
		}
		
		foreach($this->custom_filters as $filter_id => $filter){
			$this->custom_filters[$filter_id]['count'] = $this->get_filter_links(
				$filter, array('count_only' => true)
			);
		}
		
		$this->search_filter['count'] = $this->get_filter_links($this->search_filter, array('count_only' => true));
	}
	
  /**
   * Retrieve a list of links matching a filter. 
   *
   * @uses blcLinkQuery::get_links()
   *
   * @param string|array $filter Either a filter ID or an array containing filter data.
   * @param array $extra_params Optional extra criteria that will override those set by the filter. See blc_get_links() for details. 
   * @return array|int Either an array of blcLink objects, or an integer indicating the number of links that match the filter. 
   */
	function get_filter_links($filter, $extra_params = null){
		if ( is_string($filter) ){
			$filter = $this->get_filter($filter);
		}
		
		$params = $this->get_search_params($filter);
		

		if ( !empty($extra_params) ){
			$params = array_merge($params, $extra_params);
		}
		
		return $this->get_links($params);		
	}
}

$GLOBALS['blc_link_query'] = new blcLinkQuery();

/**
 * Retrieve a list of links matching some criteria.
 *
 * The function argument should be an associative array describing the criteria.
 * The supported keys are :  
 *     'offset' - Skip the first X results. Default is 0. 
 *     'max_results' - The maximum number of links to return. Defaults to returning all results.
 *     'link_ids' - Retrieve only links with these IDs. This should either be a comma-separated list or an array.
 *     's_link_text' - Link text must match this keyphrase (performs a fulltext search).
 *     's_link_url' - Link URL must contain this string. You can use "*" as a wildcard.
 *     's_parser_type' - Filter links by the type of link parser that was used to find them.
 *     's_container_type' - Filter links by where they were found, e.g. 'post'.
 *     's_container_id' - Find links that belong to a container with this ID (should be used together with s_container_type).
 *     's_link_type' - Either parser type or container type must match this.   
 *     's_http_code' - Filter by HTTP code. Example : 201,400-410,500
 *     's_filter' - Use a built-in filter. Available filters : 'broken', 'redirects', 'all'
 *     'where_expr' - Advanced. Lets you directly specify a part of the WHERE clause.
 *     'load_instances' - Pre-load all link instance data for each link. Default is false. 
 *     'load_containers' - Pre-load container data for each instance. Default is false.
 *     'load_wrapped_objects' - Pre-load wrapped object data (e.g. posts, comments, etc) for each container. Default is false.
 *     'count_only' - Only return the number of results (int), not the whole result set. 'offset' and 'max_results' will be ignored if this is set. Default is false.
 *     'purpose' -  An optional code indicating how the links will be used.
 *
 * All keys are optional.
 *
 * @uses blcLinkQuery::get_links();
 *
 * @param array $params
 * @return int|array Either an array of blcLink objects, or the number of results for the query.
 */
function blc_get_links($params = null){
	global $blc_link_query;
	return $blc_link_query->get_links($params, $purpose);
}

/**
 * Remove orphaned links that have no corresponding instances.
 *
 * @param int|array $link_id (optional) Only check these links
 * @return bool
 */
function blc_cleanup_links( $link_id = null ){
	global $wpdb;
	
	$q = "DELETE FROM {$wpdb->prefix}blc_links 
			USING {$wpdb->prefix}blc_links LEFT JOIN {$wpdb->prefix}blc_instances 
				ON {$wpdb->prefix}blc_instances.link_id = {$wpdb->prefix}blc_links.link_id
			WHERE
				{$wpdb->prefix}blc_instances.link_id IS NULL";
				
	if ( $link_id !== null ) {
		if ( !is_array($link_id) ){
			$link_id = array( intval($link_id) );
		}
		$q .= " AND {$wpdb->prefix}blc_links.link_id IN (" . implode(', ', $link_id) . ')';
	}
	
	return $wpdb->query( $q ) !== false;
}

?>