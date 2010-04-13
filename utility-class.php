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
    if (@file_exists($tempfile)) {
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
	    
	    //If the relative URL is just a query string, simply attach it to the absolute URL and return
	    if ( substr($relative, 0, 1) == '?' ){
			return $absolute . $relative;
		}
	
	    $parts=(parse_url($absolute));
	    
	    if(substr($relative,0,1)=='/') {
	    	//Relative URL starts with a slash => ignore the base path and jump straight to the root. 
	        $path_segments = explode("/", $relative);
	        array_shift($path_segments);
	    } else {
	        if(isset($parts['path'])){
	            $aparts=explode('/',$parts['path']);
	            array_pop($aparts);
	            $aparts=array_filter($aparts);
	        } else {
	            $aparts=array();
	        }
	        
	        //Merge together the base path & the relative path
	        $aparts = array_merge($aparts, explode("/", $relative));
	        
	        //Filter the merged path 
	        $path_segments = array();
	        foreach($aparts as $part){
	        	if ( $part == '.' ){
					continue; //. = "this directory". It's basically a no-op, so we skip it.
				} elseif ( $part == '..' )  {
					array_pop($path_segments);	//.. = one directory up. Remove the last seen path segment.
				} else {
					array_push($path_segments, $part); //Normal directory -> add it to the path.
				}
			}
	    }
	    $path = implode("/", $path_segments);
	
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
	
  /**
   * blcUtility::is_safe_mode()
   * Checks if PHP is running in safe mode
   *
   * @return bool
   */
	function is_safe_mode(){
		$safe_mode = ini_get('safe_mode');
		//Null, 0, '', '0' and so on count as false 
		if ( !$safe_mode ) return false;
		//Test for some textual true/false variations
		switch ( strtolower($safe_mode) ){
			case 'on':
			case 'true':
			case 'yes':
				return true;
				
			case 'off':
			case 'false':
			case 'no':
				return false;
				
			default: //Let PHP handle anything else
				return (bool)(int)$safe_mode;
		}
	}
	
  /**
   * blcUtility::is_open_basedir()
   * Checks if open_basedir is enabled
   *
   * @return bool
   */
	function is_open_basedir(){
		$open_basedir = ini_get('open_basedir');
		return $open_basedir && ( strtolower($open_basedir) != 'none' );
	}
	
  /**
   * Truncate a string on a specified boundary character.
   *
   * @param string $text The text to truncate.
   * @param integer $max_characters Return no more than $max_characters
   * @param string $break Break on this character. Defaults to space.
   * @param string $pad Pad the truncated string with this string. Defaults to an HTML ellipsis.
   * @return
   */
	function truncate($text, $max_characters = 0, $break = ' ', $pad = '&hellip;'){
		if ( strlen($text) <= $max_characters ){
			return $text;
		}
		
		$text = substr($text, 0, $max_characters);
		$break_pos = strrpos($text, $break);
		if ( $break_pos !== false ){
			$text = substr($text, 0, $break_pos);
		}
		
		return $text.$pad;
	}
	
	/**
	 * extract_tags()
	 * Extract specific HTML tags and their attributes from a string.
	 *
	 * You can either specify one tag, an array of tag names, or a regular expression that matches the tag name(s). 
	 * If multiple tags are specified you must also set the $selfclosing parameter and it must be the same for 
	 * all specified tags (so you can't extract both normal and self-closing tags in one go).
	 * 
	 * The function returns a numerically indexed array of extracted tags. Each entry is an associative array
	 * with these keys :
	 * 	tag_name	- the name of the extracted tag, e.g. "a" or "img".
	 *	offset		- the numberic offset of the first character of the tag within the HTML source.
	 *	contents	- the inner HTML of the tag. This is always empty for self-closing tags.
	 *	attributes	- a name -> value array of the tag's attributes, or an empty array if the tag has none.
	 *	full_tag	- the entire matched tag, e.g. '<a href="http://example.com">example.com</a>'. This key 
	 *		          will only be present if you set $return_the_entire_tag to true.	   
	 *
	 * @param string $html The HTML code to search for tags.
	 * @param string|array $tag The tag(s) to extract.							 
	 * @param bool $selfclosing	Whether the tag is self-closing or not. Setting it to null will force the script to try and make an educated guess. 
	 * @param bool $return_the_entire_tag Return the entire matched tag in 'full_tag' key of the results array.  
	 * @param string $charset The character set of the HTML code. Defaults to ISO-8859-1.
	 *
	 * @return array An array of extracted tags, or an empty array if no matching tags were found. 
	 */
	function extract_tags( $html, $tag, $selfclosing = null, $return_the_entire_tag = false, $charset = 'ISO-8859-1' ){
	 
		if ( is_array($tag) ){
			$tag = implode('|', $tag);
		}
	 
		//If the user didn't specify if $tag is a self-closing tag we try to auto-detect it
		//by checking against a list of known self-closing tags.
		$selfclosing_tags = array( 'area', 'base', 'basefont', 'br', 'hr', 'input', 'img', 'link', 'meta', 'col', 'param' );
		if ( is_null($selfclosing) ){
			$selfclosing = in_array( $tag, $selfclosing_tags );
		}
	 
		//The regexp is different for normal and self-closing tags because I can't figure out 
		//how to make a sufficiently robust unified one.
		if ( $selfclosing ){
			$tag_pattern = 
				'@<(?P<tag>'.$tag.')			# <tag
				(?P<attributes>\s[^>]+)?		# attributes, if any
				\s*/?>							# /> or just >, being lenient here 
				@xsi';
		} else {
			$tag_pattern = 
				'@<(?P<tag>'.$tag.')			# <tag
				(?P<attributes>\s[^>]+)?		# attributes, if any
				\s*>							# >
				(?P<contents>.*?)				# tag contents
				</(?P=tag)>						# the closing </tag>
				@xsi';
		}
	 
		$attribute_pattern = 
			'@
			(?P<name>\w+)											# attribute name
			\s*=\s*
			(
				(?P<quote>[\"\'])(?P<value_quoted>.*?)(?P=quote)	# a quoted value
				|							# or
				(?P<value_unquoted>[^\s"\']+?)(?:\s+|$)				# an unquoted value (terminated by whitespace or EOF) 
			)
			@xsi';
	 
		//Find all tags 
		if ( !preg_match_all($tag_pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ){
			//Return an empty array if we didn't find anything
			return array();
		}
	 
		$tags = array();
		foreach ($matches as $match){
	 
			//Parse tag attributes, if any
			$attributes = array();
			if ( !empty($match['attributes'][0]) ){ 
	 
				if ( preg_match_all( $attribute_pattern, $match['attributes'][0], $attribute_data, PREG_SET_ORDER ) ){
					//Turn the attribute data into a name->value array
					foreach($attribute_data as $attr){
						if( !empty($attr['value_quoted']) ){
							$value = $attr['value_quoted'];
						} else if( !empty($attr['value_unquoted']) ){
							$value = $attr['value_unquoted'];
						} else {
							$value = '';
						}
	 
						//Passing the value through html_entity_decode is handy when you want
						//to extract link URLs or something like that. You might want to remove
						//or modify this call if it doesn't fit your situation.
						$value = html_entity_decode( $value, ENT_QUOTES, $charset );
	 
						$attributes[$attr['name']] = $value;
					}
				}
	 
			}
	 
			$tag = array(
				'tag_name' => $match['tag'][0],
				'offset' => $match[0][1], 
				'contents' => !empty($match['contents'])?$match['contents'][0]:'', //empty for self-closing tags
				'attributes' => $attributes, 
			);
			if ( $return_the_entire_tag ){
				$tag['full_tag'] = $match[0][0]; 			
			}
	 
			$tags[] = $tag;
		}
	 
		return $tags;
	}
	
}//class

}//class_exists

?>