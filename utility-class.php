<?php

/**
 * @author W-Shadow
 * @copyright 2009
 */
 
 
if (!function_exists('json_encode')){
	//Load JSON functions for PHP < 5.2
	if (!class_exists('Services_JSON')){
		require 'JSON.php';
	}
	
	//Backwards fompatible json_encode.
	function json_encode($data) {
	    $json = new Services_JSON();
	    return( $json->encode($data) );
	}
}

if ( !class_exists('blcUtility') ){

class blcUtility {
	
    //A regxp for images
    function img_pattern(){
	    //        \1                        \2       \3 URL       \4
	    return '/(<img[\s]+[^>]*src\s*=\s*)([\"\']?)([^\'\">]+)\2([^<>]*>)/i';
	}
	
	//A regexp for links
	function link_pattern(){
	    //	      \1                       \2      \3 URL        \4       \5 Text  \6
	    return '/(<a[\s]+[^>]*href\s*=\s*)([\"\']+)([^\'\">]+)\2([^<>]*>)((?sU).*)(<\/a>)/i';
	}	
	
  /**
   * blcUtility::normalize_url()
   *
   * @param string $url
   * @return string A normalized URL or FALSE if the URL is invalid
   */
	function normalize_url($url){
	    $parts=@parse_url($url);
	    if(!$parts) return false;
	
	    if(isset($parts['scheme'])) {
	        //Only HTTP(S) links are checked. Other protocols are not supported.
	        if ( ($parts['scheme'] != 'http') && ($parts['scheme'] != 'https') )
	            return false;
	    }
	
	    $url = html_entity_decode($url);
	    $url = preg_replace(
	        array('/([\?&]PHPSESSID=\w+)$/i',
	              '/(#[^\/]*)$/',
	              '/&amp;/',
	              '/^(javascript:.*)/i',
	              '/([\?&]sid=\w+)$/i'
	              ),
	        array('','','&','',''),
	        $url);
	    $url=trim($url);
	
	    if($url=='') return false;
		
	    // turn relative URLs into absolute URLs
	    $url = blcUtility::relative2absolute( get_option('siteurl'), $url);
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
	    if(isset($p["scheme"])) return $relative;
	
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

}//class

}//class_exists

?>