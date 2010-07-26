<?php

/**
 * @author W-Shadow
 * @copyright 2010
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
	
	/**
	 * Extract <embed> elements from a HTML string.
	 * 
	 * This function returns an array of <embed> elements found in the input
	 * string. Only <embed>'s that are inside <object>'s are considered. Embeds
	 * without a 'src' attribute are skipped. 
	 * 
	 * Each array item has the same basic structure as the array items
	 * returned by blcUtility::extract_tags(), plus an additional 'wrapper' key 
	 * that contains similarly structured info about the wrapping <object> tag.  
	 *  
	 * @uses blcUtility::extract_tags() This function is a simple wrapper around extract_tags()
	 * 
	 * @param string $html
	 * @return array 
	 */
	function extract_embeds($html){
		$results = array();
		
		//remove all <code></code> blocks first
		$content = preg_replace('/<code[^>]*>.+?<\/code>/si', ' ', $content);
		
		//Find likely-looking <object> elements
		$objects = blcUtility::extract_tags($html, 'object', false, true);
		foreach($objects as $candidate){
			//Find the <embed> tag
			$embed = blcUtility::extract_tags($candidate['full_tag'], 'embed', false);
			if ( empty($embed)) continue;
			$embed = reset($embed); //Take the first (and only) found <embed> element
			
			if ( empty($embed['attributes']['src']) ){
				continue;
			}
			
			$embed['wrapper'] = $candidate;
			
			$results[] = $embed;
		}
		
		return $results;
	}
	
	/**
     * Get the value of a cookie.
     * 
     * @param string $cookie_name The name of the cookie to return.
     * @param string $default_value Optional. If the cookie is not set, this value will be returned instead. Defaults to an empty string.
     * @return mixed Either the value of the requested cookie, or $default_value.
     */
    function get_cookie($cookie_name, $default_value = ''){
    	if ( isset($_COOKIE[$cookie_name]) ){
    		return $_COOKIE[$cookie_name];
    	} else {
    		return $default_value;
    	}
    }
    
  /**
   * Format a time delta using a fuzzy format, e.g. '2 minutes ago', '2 days', etc.
   *
   * @param int $delta Time period in seconds.
   * @param string $type Optional. The output template to use. 
   * @return string
   */
	function fuzzy_delta($delta, $template = 'default'){
		$ONE_MINUTE = 60;
		$ONE_HOUR = 60 * $ONE_MINUTE;
		$ONE_DAY = 24 * $ONE_HOUR;
		$ONE_MONTH = $ONE_DAY * 3652425 / 120000;
		$ONE_YEAR = $ONE_DAY * 3652425 / 10000;
		
		$templates = array(
			'seconds' => array(
				'default' => _n_noop('%d second', '%d seconds'),
				'ago' => _n_noop('%d second ago', '%d seconds ago'),
			),
			'minutes' => array(
				'default' => _n_noop('%d minute', '%d minutes'),
				'ago' => _n_noop('%d minute ago', '%d minutes ago'),
			),
			'hours' => array(
				'default' => _n_noop('%d hour', '%d hours'),
				'ago' => _n_noop('%d hour ago', '%d hours ago'),
			),
			'days' => array(
				'default' => _n_noop('%d day', '%d days'),
				'ago' => _n_noop('%d day ago', '%d days ago'),
			),
			'months' => array(
				'default' => _n_noop('%d month', '%d months'),
				'ago' => _n_noop('%d month ago', '%d months ago'),
			),
		);
		
		if ( $delta < 1 ) {
			$delta = 1;
		}
		
		if ( $delta < $ONE_MINUTE ){
			$units = 'seconds';
		} elseif ( $delta < $ONE_HOUR ){
			$delta = intval($delta / $ONE_MINUTE);
			$units = 'minutes';
		} elseif ( $delta < $ONE_DAY ){
			$delta = intval($delta / $ONE_HOUR);
			$units = 'hours';
		} elseif ( $delta < $ONE_MONTH ){
			$delta = intval($delta / $ONE_DAY);
		 	$units = 'days';
		} else {
			$delta = intval( $delta / $ONE_MONTH );
			$units = 'months';
		}
		
		return sprintf(
			_n(
				$templates[$units][$template][0],
				$templates[$units][$template][1],
				$delta,
				'broken-link-checker'
			),
			$delta
		);
	}
	
}//class

}//class_exists

?>