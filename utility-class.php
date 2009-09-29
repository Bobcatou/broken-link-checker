<?php

/**
 * @author W-Shadow
 * @copyright 2009
 */
 
 
if ( is_admin() && !function_exists('json_encode') ){
	//Load JSON functions for PHP < 5.2
	if (!class_exists('Services_JSON')){
		require 'JSON.php';
	}
	
	//Backwards compatible json_encode
	function json_encode($data) {
	    $json = new Services_JSON();
	    return( $json->encode($data) );
	}
}

if ( !function_exists('sys_get_temp_dir')) {
  function sys_get_temp_dir() {
    if (!empty($_ENV['TMP'])) { return realpath($_ENV['TMP']); }
    if (!empty($_ENV['TMPDIR'])) { return realpath( $_ENV['TMPDIR']); }
    if (!empty($_ENV['TEMP'])) { return realpath( $_ENV['TEMP']); }
    $tempfile = tempnam(uniqid(rand(),TRUE),'');
    if (file_exists($tempfile)) {
	    unlink($tempfile);
	    return realpath(dirname($tempfile));
    }
  }
}

if ( !class_exists('blcUtility') ){

class blcUtility {
	
    //A regxp for images
    function img_pattern(){
	    //        \1                        \2      \3 URL    \4
	    return '/(<img[\s]+[^>]*src\s*=\s*)([\"\'])([^>]+?)\2([^<>]*>)/i';
	}
	
	//A regexp for links
	function link_pattern(){
	    //	      \1                       \2      \3 URL    \4       \5 Text  \6
	    return '/(<a[\s]+[^>]*href\s*=\s*)([\"\'])([^>]+?)\2([^<>]*>)((?sU).*)(<\/a>)/i';
	}	
	
  /**
   * blcUtility::normalize_url()
   *
   * @param string $url
   * @params string $base_url (Optional) The base URL is used to convert a relative URL to a fully-qualified one
   * @return string A normalized URL or FALSE if the URL is invalid
   */
	function normalize_url($url, $base_url = ''){
		//Sometimes links may contain shortcodes. Parse them.
		$url = do_shortcode($url);
		
	    $parts = @parse_url($url);
	    if(!$parts) return false; //Invalid URL
	
	    if(isset($parts['scheme'])) {
	        //Only HTTP(S) links are checked. Other protocols are not supported.
	        if ( ($parts['scheme'] != 'http') && ($parts['scheme'] != 'https') )
	            return false;
	    }
	
	    $url = html_entity_decode($url);
	    $url = preg_replace(
	        array('/([\?&]PHPSESSID=\w+)$/i', //remove session ID
	              '/(#[^\/]*)$/',			  //and anchors/fragments
	              '/&amp;/',				  //convert improper HTML entities
	              '/^(javascript:.*)/i',	  //treat links that contain JS as links with an empty URL 	
	              '/([\?&]sid=\w+)$/i'		  //remove another flavour of session ID
	              ),
	        array('','','&','',''),
	        $url);
	    $url = trim($url);
	
	    if ( $url=='' ) return false;
	    
	    // turn relative URLs into absolute URLs
	    if ( empty($base_url) ) $base_url = get_option('siteurl');
	    $url = blcUtility::relative2absolute( $base_url, $url);
	    return $url;
	}
	
  /**
   * blcUtility::relative2absolute()
   * Turns a relative URL into an absolute one given a base URL.
   *
   * @param string $absolute Base URL
   * @param string $relative A relative URL
   * @return string
   */
	function relative2absolute($absolute, $relative) {
	    $p = @parse_url($relative);
	    if(!$p) {
	        //WTF? $relative is a seriously malformed URL
	        return false;
	    }
	    if( isset($p["scheme"]) ) return $relative;
	
	    $parts=(parse_url($absolute));
	
	    if(substr($relative,0,1)=='/') {
	        $cparts = (explode("/", $relative));
	        array_shift($cparts);
	    } else {
	        if(isset($parts['path'])){
	            $aparts=explode('/',$parts['path']);
	            array_pop($aparts);
	            $aparts=array_filter($aparts);
	        } else {
	            $aparts=array();
	        }
	
	        $rparts = (explode("/", $relative));
	
	        $cparts = array_merge($aparts, $rparts);
	        foreach($cparts as $i => $part) {
	            if($part == '.') {
	                unset($cparts[$i]);
	            } else if($part == '..') {
	                unset($cparts[$i]);
	                unset($cparts[$i-1]);
	            }
	        }
	    }
	    $path = implode("/", $cparts);
	
	    $url = '';
	    if($parts['scheme']) {
	        $url = "$parts[scheme]://";
	    }
	    if(isset($parts['user'])) {
	        $url .= $parts['user'];
	        if(isset($parts['pass'])) {
	            $url .= ":".$parts['pass'];
	        }
	        $url .= "@";
	    }
	    if(isset($parts['host'])) {
	        $url .= $parts['host']."/";
	    }
	    $url .= $path;
	
	    return $url;
	}
	
	
  /**
   * blcUtility::urlencodefix()
   * Takes an URL and replaces spaces and some other non-alphanumeric characters with their urlencoded equivalents.
   *
   * @param string $str
   * @return string
   */
	function urlencodefix($url){
		return preg_replace_callback(
			'|[^a-z0-9\+\-\/\\#:.=?&%@]|i', 
			create_function('$str','return rawurlencode($str[0]);'), 
			$url
		 );
	}

}//class

}//class_exists

?>