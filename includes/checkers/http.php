<?php

/**
 * Base class for checkers that deal with HTTP(S) URLs.
 *
 * @package Broken Link Checker
 * @access public
 */
class blcHttpChecker extends blcChecker{
	
	var $priority = -1;
	
	function clean_url($url){
		$url = html_entity_decode($url);
	    $url = preg_replace(
	        array(
				'/([\?&]PHPSESSID=\w+)$/i',	//remove session ID
	            '/(#[^\/]*)$/',				//and anchors/fragments
	            '/&amp;/',					//convert improper HTML entities
	            '/([\?&]sid=\w+)$/i'		//remove another flavour of session ID
	        ),
	        array('','','&','',''),
	        $url
		);
	    $url = trim($url);
	    
	    return $url;
	}
	
	function is_error_code($http_code){
		/*"Good" response codes are anything in the 2XX range (e.g "200 OK") and redirects  - the 3XX range.
          HTTP 401 Unauthorized is a special case that is considered OK as well. Other errors - the 4XX range -
          are treated as "page doesn't exist'". */
		$good_code = ( ($http_code >= 200) && ($http_code < 400) ) || ( $http_code == 401 );
		return !$good_code;
	}
	
  /**
   * This checker only accepts HTTP(s) links.
   *
   * @param string $url
   * @param array|false $parsed
   * @return bool
   */
	function can_check($url, $parsed){
		if ( !isset($parsed['scheme']) ) return false;
		
		return in_array( strtolower($parsed['scheme']), array('http', 'https') );
	}
	
  /**
   * Takes an URL and replaces spaces and some other non-alphanumeric characters with their urlencoded equivalents.
   *
   * @param string $str
   * @return string
   */
	function urlencodefix($url){
		//TODO: Remove/fix this. Probably not a good idea to "fix" invalid URLs like that.  
		return preg_replace_callback(
			'|[^a-z0-9\+\-\/\\#:.,;=?!&%@()$\|*~]|i', 
			create_function('$str','return rawurlencode($str[0]);'), 
			$url
		 );
	}
	
}

//TODO: Rewrite sub-classes as transports, not stand-alone checkers
class blcCurlHttp extends blcHttpChecker {
	
	var $last_headers = '';
	
	function check($url, $use_get = false){
		$this->last_headers = '';
		
		$url = $this->clean_url($url);
		
		$result = array(
			'broken' => false,
		);
		$log = '';
		
		//Get the BLC configuration. It's used below to set the right timeout values and such.
		$conf = blc_get_configuration();
		
		//Init curl.
	 	$ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->urlencodefix($url));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        //Masquerade as Internet explorer
        //$ua = 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
        $ua = 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; .NET CLR 1.1.4322; .NET CLR 2.0.50727; .NET CLR 3.0.04506.30)';
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        
        //Add a semi-plausible referer header to avoid tripping up some bot traps 
        curl_setopt($ch, CURLOPT_REFERER, get_option('home'));
        
        //Redirects don't work when safe mode or open_basedir is enabled.
        if ( !blcUtility::is_safe_mode() && !blcUtility::is_open_basedir() ) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        }
        //Set maximum redirects
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        
        //Set the timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, $conf->options['timeout']);
        
        //Set the proxy configuration. The user can provide this in wp-config.php 
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

		//Make CURL return a valid result even if it gets a 404 or other error.
        curl_setopt($ch, CURLOPT_FAILONERROR, false);

		
        $nobody = !$use_get; //Whether to send a HEAD request (the default) or a GET request
        
        $parts = @parse_url($url);
        if( $parts['scheme'] == 'https' ){
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); //Required to make HTTPS URLs work.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); //Likewise.
            $nobody = false; //Can't use HEAD with HTTPS.
        }
        
        if ( $nobody ){
        	//If possible, use HEAD requests for speed.
			curl_setopt($ch, CURLOPT_NOBODY, true);  
		} else {
			//If we must use GET at least limit the amount of downloaded data.
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Range: bytes=0-2048')); //2 KB
		}
        
        //Register a callback function which will process the HTTP header(s).
		//It can be called multiple times if the remote server performs a redirect. 
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(&$this,'read_header'));

		//Execute the request
        curl_exec($ch);
        
		$info = curl_getinfo($ch);
		curl_close($ch);
		
		//Store the results
        $result['http_code'] = intval( $info['http_code'] );
        $result['timeout'] = ($result['http_code'] == 0); //If the code is 0 then it's probably a timeout
        $result['final_url'] = $info['url'];
        $result['request_duration'] = $info['total_time'];
        $result['redirect_count'] = $info['redirect_count'];
        
        //Determine if the link counts as "broken"
		$result['broken'] = $this->is_error_code($result['http_code']) || $result['timeout'];
        
        if ( $nobody && $result['broken'] ){
			//The site in question might be expecting GET instead of HEAD, so lets retry the request 
			//using the GET verb.
			return $this->check($url, true);
			 
			//Note : normally a server that doesn't allow HEAD requests on a specific resource *should*
			//return "405 Method Not Allowed". Unfortunately, there are sites that return 404 or
			//another, even more general, error code instead. So just checking for 405 wouldn't be enough. 
		}
        
        //When safe_mode or open_basedir is enabled CURL will be forbidden from following redirects,
        //so redirect_count will be 0 for all URLs. As a workaround, set it to 1 when the HTTP
		//response codes indicates a redirect but redirect_count is zero.
		//Note to self : Extracting the Location header might also be helpful.
		if ( ($result['redirect_count'] == 0) && ( in_array( $result['http_code'], array(301, 302, 303, 307) ) ) ){
			$result['redirect_count'] = 1;
		} 
		
        //Build the log from HTTP code and headers.
        //TODO: Put some kind of a color-coded error explanation at the top of the log, not a cryptic HTTP code. 
        $log .= '=== ';
        if ( $result['http_code'] ){
			$log .= sprintf( __('HTTP code : %d', 'broken-link-checker'), $result['http_code']);
		} else {
			$log .= __('(No response)', 'broken-link-checker');
		}
		$log .= " ===\n\n";
        $log .= $this->last_headers;
        
        if ( $result['broken'] && $result['timeout'] ) {
			$log .= "\n(" . __("Most likely the connection timed out or the domain doesn't exist.", 'broken-link-checker') . ')';
		}
        
        $result['log'] = $log;
        
        //The hash should contain info about all pieces of data that pertain to determining if the 
		//link is working.  
        $result['result_hash'] = implode('|', array(
			$result['http_code'],
			$result['broken']?'broken':'0', 
			$result['timeout']?'timeout':'0',
			md5($result['final_url']),
		));
        
        return $result;
	}
	
	function read_header($ch, $header){
		$this->last_headers .= $header;
		return strlen($header);
	}
	
}

class blcSnoopyHttp extends blcHttpChecker {
	
	function check($url){
		$url = $this->clean_url($url); //Note : Snoopy doesn't work too well with HTTPS URLs.
		
		$result = array(
			'broken' => false,
		);
		$log = '';
		
		//Get the timeout setting from the BLC configuration. 
		$conf = blc_get_configuration();
		$timeout = $conf->options['timeout'];
		
		$start_time = microtime_float(true);
		
		//Fetch the URL with Snoopy
        $snoopy = new Snoopy;
        $snoopy->read_timeout = $timeout; //read timeout in seconds
        $snoopy->agent = "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1)"; //masquerade as IE 7
        $snoopy->maxlength = 1024*5; //load up to 5 kilobytes
        $snoopy->fetch($url);
        
        $result['request_duration'] = microtime_float(true) - $start_time;

        $result['http_code'] = $snoopy->status; //HTTP status code
		//Snoopy returns -100 on timeout 
        if ( $result['http_code'] == -100 ){
			$result['http_code'] = 0;
			$result['timeout'] = true;
		}
		
		//Build the log
		$log .= '=== ';
        if ( $result['http_code'] ){
			$log .= sprintf( __('HTTP code : %d', 'broken-link-checker'), $result['http_code']);
		} else {
			$log .= __('(No response)', 'broken-link-checker');
		}
		$log .= " ===\n\n";

        if ($snoopy->error)
            $log .= $snoopy->error."\n";
        if ($snoopy->timed_out) {
            $log .= __("Request timed out.", 'broken-link-checker') . "\n";
            $result['timeout'] = true;
        }

		if ( is_array($snoopy->headers) )
        	$log .= implode("", $snoopy->headers)."\n"; //those headers already contain newlines

		//Redirected? 
        if ( $snoopy->lastredirectaddr ) {
            $result['final_url'] = $snoopy->lastredirectaddr;
            $result['redirect_count'] = $snoopy->_redirectdepth;
        } else {
			$result['final_url'] = $url;
		}
		
		//Determine if the link counts as "broken"
		$result['broken'] = $this->is_error_code($result['http_code']) || $result['timeout'];
		
		$log .= "<em>(" . __('Using Snoopy', 'broken-link-checker') . ")</em>";
		$result['log'] = $log;
		
		//The hash should contain info about all pieces of data that pertain to determining if the 
		//link is working.  
        $result['result_hash'] = implode('|', array(
			$result['http_code'],
			$result['broken']?'broken':'0', 
			$result['timeout']?'timeout':'0',
			md5($result['final_url']),
		));
		
		return $result;
	}
	
}

if ( function_exists('curl_init') ) {
	blc_register_checker('blcCurlHttp');
} else {
	//Try to load Snoopy.
	if ( !class_exists('Snoopy') ){
		$snoopy_file = ABSPATH. WPINC . '/class-snoopy.php';
		if (file_exists($snoopy_file) ){
			include $snoopy_file;
		}
	}
	
	//If Snoopy is available, it will be used in place of CURL.
	if ( class_exists('Snoopy') ){
		blc_register_checker('blcSnoopyHttp');
	}
}



?>