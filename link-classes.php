<?php

/**
 * @author W-Shadow 
 * @copyright 2009
 */

if (!class_exists('blcLink')){
class blcLink {
	
	//Object state
	var $is_new = false;
	var $last_headers = '';
	var $meets_check_threshold = false; //currently unused
	
	//DB fields
	var $link_id = 0;
	var $url = '';
	var $last_check='0000-00-00 00:00:00';
	var $check_count = 0;
	var $final_url = '';
	var $log = '';
	var $http_code = 0;
	var $request_duration = 0;
	var $timeout = false;
	var $redirect_count = 0;
	
	function __construct($arg = null){
		global $wpdb;
		
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
		foreach( $arr as $key => $value ){
			$this->$key = $value;
		}
	}
	
  /**
   * blcLink::valid()
   * Verifies whether the object represents a valid link
   *
   * @return bool
   */
	function valid(){
		return !empty( $this->url ) && ( !empty($this->link_id) || $this->is_new );
	}
	
  /**
   * blcLink::check()
   * Check if the link is working.
   *
   * @return bool 
   */
	function check(){
		if ( !$this->valid() ) return false;
		
		//General note : there is usually no need to save() the result of the check
		//in this method because it will be typically called from wsBrokenLinkChecker::work() 
		//that will call the save() method for us.
		
		/*
        Check for problematic (though not necessarily "broken") links.
        If a link has been checked multiple times and still hasn't been marked as 
		timed-out or broken then probably the checking algorithm is having problems with 
		that link. Mark it as timed-out and hope the user sorts it out.
        */
        if ( ($this->check_count >= 3) && ( !$this->timeout ) && ( $this->http_code == BLC_CHECKING ) ) {
        	$this->timeout = true;
        	$this->http_code = BLC_TIMEOUT;
        	$this->last_check = date('Y-m-d H:i:s');
        	$this->log .= "\r\n[A weird error was detected. This should never happen.]";
            return false;
        }
        
        //Update the DB record before actually performing the check.
        //Useful if something goes terribly wrong while checkint this particular URL.
        //Note : might be unnecessary.
        $this->check_count++;
        $this->last_check = date('Y-m-d H:i:s');
        $this->log = '';
        $this->final_url = '';
        $this->http_code = BLC_CHECKING;
        $this->request_duration = 0;
        $this->timeout = false;
        $this->redirect_count = 0;
        $this->save();
        
        //Empty some variables before running the check 
        $this->last_headers = '';
        
        //Save the URL into a local var; we'll need it later.
        $url = $this->url;
        
        $parts = parse_url($url);
        //Only HTTP links are checked. All others are automatically considered okay.
        if ( ($parts['scheme'] != 'http') && ($parts['scheme'] != 'https') ) {
            $this->log .= "URL protocol ($parts[scheme]) is not HTTP(S). This link won't be checked.\n";
            $this->http_code = 200;
            return true;
        }
        
        //Kill the #anchor if it's present
        $anchor_start = strpos($url, '#');
        if ( $anchor_start !== false ){
			$url = substr($url, 0, $anchor_start);
		}
        
        //******* Use CURL if available ***********
        if ( function_exists('curl_init') ) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, blcUtility::urlencodefix($url));
            //Masquerade as Internet explorer
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
            //Add a semi-plausible referer header to avoid tripping up some bot traps 
            curl_setopt($ch, CURLOPT_REFERER, get_option('home'));
            
            curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);

            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);

            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            if (defined('WP_PROXY_HOST')) {
				curl_setopt($ch, CURLOPT_PROXY, WP_PROXY_HOST);
			}
			
			if (defined('WP_PROXY_PORT')) { 
				curl_setopt($ch, CURLOPT_PROXYPORT, WP_PROXY_PORT);
			}
			
			if (defined('WP_PROXY_USERNAME')){
				$auth = WP_PROXY_USERNAME;
				if (defined('WP_PROXY_PASSWORD')){
					$auth .= ':' . WP_PROXY_PASSWORD;
				}
				curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
			}

            curl_setopt($ch, CURLOPT_FAILONERROR, false);

            $nobody=false;
            if( $parts['scheme'] == 'https' ){
            	//TODO: Redirects don't work with HTTPS
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,  0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            } else {
                $nobody=true;
                curl_setopt($ch, CURLOPT_NOBODY, true);
                //curl_setopt($ch, CURLOPT_RANGE, '0-1023');
            }
            
            curl_setopt($ch, CURLOPT_HEADER, true);
            //register a callback function which will process the headers
			curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(&$this,'read_header'));

			//Execute the request
            $response = curl_exec($ch);
            
			$info = curl_getinfo($ch);
            $code = intval( $info['http_code'] );

            $this->log .= "=== First try : $code ".(!$code?'(No response) ':'')."===\n\n";
            $this->log .= $this->last_headers."\n";

            if ( (($code<200) || ($code>=400)) && $nobody) {
                $this->log .= "Trying a second time with different settings...\n";
                $this->last_headers = '';
                
                curl_setopt($ch, CURLOPT_NOBODY, false);
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                curl_setopt($ch, CURLOPT_RANGE, '0-2047');
                $response = curl_exec($ch);
                
                $info = curl_getinfo($ch);
            	$code = intval( $info['http_code'] );

                $this->log .= "=== Second try : $code ".(!$code?'(No response) ':'')."===\n\n";
            	$this->log .= $this->last_headers."\n";
            }
            
            $this->http_code = $code != 0 ? $code : BLC_TIMEOUT;
            $this->final_url = $info['url'];
            $this->request_duration = $info['total_time'];
            $this->redirect_count = $info['redirect_count'];

            curl_close($ch);

        } elseif ( class_exists('Snoopy') ) {
            //******** Use Snoopy if CURL is not available *********
            //Note : Snoopy doesn't work too well with HTTPS URLs.
            $this->log .= "<em>(Using Snoopy)</em>\n";

			$start_time = microtime_float(true);
			
            $snoopy = new Snoopy;
            $snoopy->read_timeout = 60; //read timeout in seconds
            $snoopy->maxlength = 1024*5; //load up to 5 kilobytes
            $snoopy->fetch($url);
            
            $this->request_duration = microtime_float(true) - $start_time;

            $this->http_code = $snoopy->status; //HTTP status code (note : Snoopy returns -100 on timeout)
            if ( $this->http_code == -100 ){
				$this->http_code = BLC_TIMEOUT;
				$this->timeout = true;
			}

            if ($snoopy->error)
                $this->log .= $snoopy->error."\n";
            if ($snoopy->timed_out)
                $this->log .= "Request timed out.\n";

			if ( is_array($snoopy->headers) )
            	$this->log .= implode("", $snoopy->headers)."\n"; //those headers already contain newlines

			//Redirected? 
            if ( $snoopy->lastredirectaddr ) {
                $this->final_url = $snoopy->lastredirectaddr;
                $this->redirect_count = $snoopy->_redirectdepth;
            } else {
				$this->final_url = $this->url;
			}
        }

        /*"Good" response codes are anything in the 2XX range (e.g "200 OK") and redirects  - the 3XX range.
          HTTP 401 Unauthorized is a special case that is considered OK as well. Other errors - the 4XX range -
          are treated as "page doesn't exist'". */
        //TODO: Treat circular redirects as broken links.
        if ( (($this->http_code>=200) && ($this->http_code<400)) || ($this->http_code == 401) ) {
        	$this->log .= "Link is valid.";
        	//Reset the check count for valid links.
        	$this->check_count = 0; 
        	return true;
        } else {
			$this->log .= "Link is broken.";
			if ( $this->http_code == BLC_TIMEOUT ){
				//This is probably a timeout
				$this->timeout = true;
				$this->log .= "\r\n(Most likely the connection timed out or the domain doesn't exist)";
			}
			return false;
		}
	}
	
	function read_header($ch, $header){
		$this->last_headers .= $header;
		return strlen($header);
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
		
		if ( $this->is_new ){
			
			//Insert a new row
			$q = "
			INSERT INTO {$wpdb->prefix}blc_links
				  ( url, last_check, check_count, final_url, redirect_count, log, http_code, request_duration, timeout )
			VALUES( %s,  %s,         %d,          %s,        %d,             %s,  %d,        %f,               %d  )";
			$q = $wpdb->prepare($q, $this->url, $this->last_check, $this->check_count, $this->final_url,
				$this->redirect_count, $this->log, $this->http_code, $this->request_duration, (integer)$this->timeout );
			$rez = $wpdb->query($q);
			
			$rez = $rez !== false;
			
			if ($rez){
				$this->link_id = $wpdb->insert_id;
				//echo "Link added, ID : {$this->link_id}\r\n<br>";
				//If the link was successfully saved then it's no longer "new"
				$this->is_new = !$rez;
			} else {
				echo "Error adding link $url : {$wpdb->last_error}\r\n<br>";
			}
				
			return $rez;
									
		} else {
			
			//Update an existing DB record
			$q = "UPDATE {$wpdb->prefix}blc_links SET url=%s, last_check=%s, check_count=%d, final_url=%s,
				  redirect_count=%d, log=%s, http_code=%d, request_duration=%f, timeout=%d
				  WHERE link_id=%d";
				  
			$q = $wpdb->prepare($q, $this->url, $this->last_check, $this->check_count, $this->final_url,
				$this->redirect_count, $this->log, $this->http_code, $this->request_duration, (integer)$this->timeout, $this->link_id );
			
			$rez = $wpdb->query($q);
			if ( $rez !== false ){
				//echo "Link updated, ID : {$this->link_id}\r\n<br>";
			} else {
				echo "Error updating link {$this->link_id} : {$wpdb->last_error}\r\n<br>";
			}
			return $rez !== false;			
		}
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
   * @return array An associative array with the new link ID, the number of successfully edited instances and the number of failed edits. 
   */
	function edit($new_url){
		if ( !$this->valid() ){
			return false;
		}
		
		//FB::info('Changing link '.$this->link_id .' to URL "'.$new_url.'"');
		
		$instances = $this->get_instances();
		//Fail if there are no instances
		if (empty($instances)) return false;
		
		//Load or create a link with the URL = $new_url  
		$new_link = new blcLink($new_url);
		$was_new = $new_link->is_new;
		if ($new_link->is_new) {
			//FB::log($new_link, 'Saving a new link');
			$new_link->save(); //so that we get a valid link_id
		}
		
		if ( empty($new_link->link_id) ){
			//FB::error("Failed to create a new link record");
			return false;
		}
			
		//Edit each instance.
		//FB::info('Editing ' . count($instances) . ' instances');
		$cnt_okay = $cnt_error = 0;
		foreach ( $instances as $instance ){
			if ( $instance->edit( $this->url, $new_url ) ){
				$cnt_okay++;
				$instance->link_id = $new_link->link_id;
				$instance->save();
				//FB::info($instance, 'Successfully edited instance '  . $instance->instance_id);
			} else {
				$cnt_error++;
				//FB::error($instance, 'Failed to edit instance ' . $instance->instance_id);
			}
		}
		
		//If all instances were edited successfully we can delete the old link record.
		//And copy the new link data into this object. UNLESS this link is equal to the new link
		//(which should never happen, but whatever)
		if ( ( $cnt_error == 0 ) && ( $cnt_okay > 0 ) && ( $this->link_id != $new_link->link_id ) ){
			$this->forget( false );
			
			$this->link_id = $new_link->link_id;
			$this->url = $new_link->url;
			$this->final_url = $new_link->url;
			$this->log = $new_link->log;
			$this->http_code = $new_link->http_code;
			$this->redirect_count = $new_link->redirect_count;
			$this->timeout = $new_link->timeout;
		}
		
		//On the other hand, if no instances could be edited and the $new_link was really new,
		//then delete it.
		if ( ( $cnt_okay == 0 ) && $was_new ){
			$new_link->forget( false );
		}
		
		return array(
			'new_link_id' => $this->link_id,
			'cnt_okay' => $cnt_okay,
			'cnt_error' => $cnt_error, 
		 );			 
	}
	
	//Delete (unlink) all instances and the link itself
	function unlink(){
		if ( !$this->valid() ){
			return false;
		}
		
		//FB::info($this, 'Removing link');
		
		$instances = $this->get_instances();
		//Fail if there are no instances
		if (empty($instances)) {
			//FB::warn("This link has no instances. Deleting the link.");
			return $this->forget( false ) !== false;
		}
		
		//Unlink each instance.
		//FB::info('Unlinking ' . count($instances) . ' instances');
		$cnt_okay = $cnt_error = 0;
		foreach ( $instances as $instance ){
			if ( $instance->unlink( $this->url ) ){
				$cnt_okay++;
				//FB::info( $instance, 'Successfully unlinked instance' );
			} else {
				$cnt_error++;
				//FB::error( $instance, 'Failed to unlink instance' );
			}
		}
		
		//If all instances were unlinked successfully we can delete the link record.
		if ( ( $cnt_error == 0 ) && ( $cnt_okay > 0 ) ){
			//FB::log('Instances removed, deleting the link.');
			return $this->forget() !== false;
		} else {
			//FB::error("Something went wrong. Unlinked instances : $cnt_okay, errors : $cnt_error");
			return false;
		} 
	}
	
  /**
   * blcLink::forget()
   * Remove the link and instance records from the DB. Doesn't alter posts/etc.
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
   * blcLink::get_instances()
   * Get a list of the link's instances
   *
   * @param integer $max_count The maximum number of instances to return. The default is -1 (no limit)
   * @return array An array of instance objects or FALSE on failure.
   */
	function get_instances($max_count = -1){
		global $wpdb;
		if ( !$this->valid() || empty($this->link_id) ) return false;
		
		$limit = $max_count > 0 ? "LIMIT $max_count":'';
		
		//Get all instances of this link
		$q = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}blc_instances WHERE link_id=%d $limit", $this->link_id); 
		$results = $wpdb->get_results($q, ARRAY_A);
		
		if ( !empty($results) ) {
			//Create an object for each instance
			$instances = array();		
			foreach ($results as $result){
				//Each source/link type combination has it's own subclass. E.g. _post_image or _blogroll_link.
				$classname = 'blcLinkInstance_' . $result['source_type'] . '_' . $result['instance_type'];
				$instances[] = new $classname($result);
			}
			return $instances;
		} else {
			return false;
		}
	}
	
  /**
   * blcLink::add_instance()
   * Record a new instance of the link.
   *
   * @param int $source_id
   * @param string $source_type
   * @param string $link_text
   * @param string $instance_type
   * @return object The created instance or FALSE on error.
   */
	function add_instance($source_id, $source_type, $link_text, $instance_type){
		
		//The link must be saved before an instance can be added
		if ($this->is_new) {
			if ( !$this->save()) return false;
		}
		
		//Create a new instance tied to this link
		$classname = 'blcLinkInstance_' . $source_type . '_' . $instance_type;
		if ( !class_exists($classname) ){
			$classname = 'blcLinkInstance';
		}
		$inst = new $classname( array(
			'link_id' => $this->link_id,
			'source_id' => $source_id,
			'source_type' => $source_type,
			'link_text' => $link_text,
			'instance_type' => $instance_type, 
		 ) );
		 
		//Save the instance to the DB
		if ( $inst->save() ){
			return $inst;
		} else {
			return false;
		};
	}
}
} //class_exists

?>