<?php

/**
 * Parser Registry class for managing parsers.
 *
 * @see blcParser
 *
 * @package Broken Link Checker
 * @access public
 */
class blcParserRegistry {
	
  /**
   * @access protected 
   */
	var $registered_parsers = array();
	
  /**
   * Register a new link parser.
   *
   * @param string $parser_type A unique string identifying the parser.
   * @param string $class_name Name of the class implementing the parser.
   * @return bool True on success, false if this parser type is already registered.
   */
	function register_parser( $parser_type, $class_name ){
		if ( isset($this->registered_parsers[$parser_type]) ){
			return false;
		}
		
		$parser = new $class_name($parser_type);
		$this->registered_parsers[$parser_type] = $parser;
		
		return true;
	}
	
  /**
   * Get the parser matching a parser type ID.
   *
   * @param string $parser_type
   * @return blcParser|null
   */
	function get_parser( $parser_type ){
		if ( isset($this->registered_parsers[$parser_type]) ){
			return $this->registered_parsers[$parser_type];
		} else {
			return null;
		}
	}
	
  /**
   * Get all parsers that support either the specified format or the container type.
   * If a parser supports both, it will still be included only once.
   *
   * @param string $format
   * @param string $container_type
   * @return array of blcParser
   */
	function get_parsers( $format, $container_type ){
		$found = array();
		
		foreach($this->registered_parsers as $parser){
			if ( in_array($format, $parser->supported_formats) || in_array($container_type, $parser->supported_containers) ){
				array_push($found, $parser);
			}
		}
		
		return $found;
	}
			
}

//Create the parser registry singleton.
$GLOBALS['blc_parser_registry'] = new blcParserRegistry();


/**
 * A base class for parsers.
 *
 * In the context of this plugin, a "parser" is a class that knows how to extract or modfify 
 * a specific type of links from a given piece of text. For example, there could be a "HTML Link"
 * parser that knows how to find and modify standard HTML links such as this one : 
 * <a href="http://example.com/">Example</a>
 * 
 * Other parsers could extract plaintext URLs or handle metadata fields.
 *
 * Each parser has a list of supported formats (e.g. "html", "plaintext", etc) and container types
 * (e.g. "post", "comment", "blogroll", etc). When something needs to be parsed, the involved
 * container class will look up the parsers that support the relevant format or the container's type,
 * and apply them to the to-be-parsed string.
 *
 * All sub-classes of blcParser should override at least the blcParser::parse() method.
 *
 * @see blcContainer::$fields
 *
 * @package Broken Link Checker
 * @access public
 */
class blcParser {
	
	var $parser_type;
	var $supported_formats = array();
	var $supported_containers = array();
	
  /**
   * Class construtor.
   *
   * @param string $parser_type
   * @return void
   */
	function __construct( $parser_type ){
		$this->parser_type = $parser_type;
	}
	
  /**
   * PHP4 constructor
   *
   * @param string $parser_type
   * @return void
   */
	function blcParser( $parser_type ){
		$this->__construct( $parser_type );
	}
	
  /**
   * Parse a string for links.
   *
   * @param string $content The text to parse.
   * @param string $base_url The base URL to use for normalizing relative URLs. If ommitted, the blog's root URL will be used. 
   * @param string $default_link_text 
   * @return array An array of new blcLinkInstance objects. The objects will include info about the links found, but not about the corresponding container entity. 
   */
	function parse($content, $base_url = '', $default_link_text = ''){
		return array();
	}
	
  /**
   * Change all links that have a certain URL to a new URL. 
   *
   * @param string $content Look for links in this string.
   * @param string $new_url Change the links to this URL.
   * @param string $old_url The URL to look for.
   * @param string $old_raw_url The raw, not-normalized URL of the links to look for. Optional. 
   *
   * @return array|WP_Error If successful, the return value will be an associative array with two
   * keys : 'content' - the modified content, and 'raw_url' - the new raw, non-normalized URL used
   * for the modified links. In most cases, the returned raw_url will be equal to the new_url.
   */
	function edit($content, $new_url, $old_url, $old_raw_url){
		return new WP_Error(
			'not_implemented',
			sprintf(__("Editing is not implemented in the '%s' parser", 'broken-link-checker'), $this->parser_type)
		);
	}
	
  /**
   * Remove all links that have a certain URL, leaving anchor text intact.
   *
   * @param string $content	Look for links in this string.
   * @param string $url The URL to look for.
   * @param string $raw_url The raw, non-normalized version of the URL to look for. Optional.
   * @return string Input string with all matching links removed. 
   */
	function unlink($content, $url, $raw_url){
		return new WP_Error(
			'not_implemented',
			sprintf(__("Unlinking is not implemented in the '%s' parser", 'broken-link-checker'), $this->parser_type)
		);
	}
	
  /**
   * Get the link text for printing in the "Broken Links" table.
   * Sub-classes should override this method and display the link text in a way appropriate for the link type.
   *
   * @param blcLinkInstance $instance
   * @return string HTML 
   */
	function ui_get_link_text($instance, $context = 'display'){
		return $instance->link_text;
	}
	
  /**
   * Turn a relative URL into an absolute one.
   *
   * @param string $url Relative URL.
   * @param string $base_url Base URL. If omitted, the blog's root URL will be used.
   * @return string
   */
	function relative2absolute($url, $base_url = ''){
		if ( empty($base_url) ){
			$base_url = get_option('siteurl');
		}
		
		$p = @parse_url($url);
	    if(!$p) {
	        //URL is a malformed
	        return false;
	    }
	    if( isset($p["scheme"]) ) return $url;
	    
	    //If the relative URL is just a query string, simply attach it to the absolute URL and return
	    if ( substr($relative, 0, 1) == '?' ){
			return $absolute . $relative;
		}
	
	    $parts=(parse_url($base_url));
	    
	    if(substr($url,0,1)=='/') {
	    	//Relative URL starts with a slash => ignore the base path and jump straight to the root. 
	        $path_segments = explode("/", $url);
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
	        $aparts = array_merge($aparts, explode("/", $url));
	        
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
	
		//Build the absolute URL.
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
   * Apply a callback function to all links found in a string and return the results.
   *
   * The first argument passed to the callback function will be an associative array
   * of link data. If the optional $extra parameter is set, it will be passed as the 
   * second argument to the callback function.
   *
   * The link data array will contain at least these keys :
   *  'href' - the URL of the link, as-is (i.e. without any sanitization or relative-to-absolute translation).
   *  '#raw' - the raw link code, e.g. the entire '<a href="...">...</a>' tag of a HTML link.
   *
   * Sub-classes may also set additional keys.
   *
   * This method is currently used only internally, so sub-classes are not required
   * to implement it.
   *
   * @param string $content A text string to parse for links. 
   * @param callback $callback Callback function to apply to all found links.  
   * @param mixed $extra If the optional $extra param. is supplied, it will be passed as the second parameter to the function $callback. 
   * @return array An array of all detected links after applying $callback to each of them.
   */
	function map($content, $callback, $extra = null){
		return array(); 
	}
	
  /**
   * Modify all links found in a string using a callback function.
   *
   * The first argument passed to the callback function will be an associative array
   * of link data. If the optional $extra parameter is set, it will be passed as the 
   * second argument to the callback function. See the map() method of this class for
   * details on the first argument.
   * 
   * The callback function should return either an associative array or a string. If 
   * a string is returned, the parser will replace the current link with the contents
   * of that string. If an array is returned, the current link will be modified/rebuilt
   * by substituting the new values for the old ones (e.g. returning array with the key
   * 'href' set to 'http://example.com/' will replace the current link's URL with 
   * http://example.com/).
   *
   * This method is currently only used internally, so sub-classes are not required
   * to implement it.
   *
   * @see blcParser::map()
   *
   * @param string $content A text string containing the links to edit.
   * @param callback $callback Callback function used to modify the links.
   * @param mixed $extra If supplied, $extra will be passed as the second parameter to the function $callback. 
   * @return string The modified input string. 
   */
	function multi_edit($content, $callback, $extra = null){
		return $content; //No-op
	}	
}

/**
 * Register a new link parser.
 *
 * @see blcParser
 *
 * @uses blcParserRegistry::register_parser() 
 *
 * @param string $parser_type A unique string identifying the parser, e.g. "html_link" 
 * @param string $class_name Name of the class that implements the parser. 
 * @return bool
 */
function blc_register_parser( $parser_type, $class_name ) {
	global $blc_parser_registry;
	return $blc_parser_registry->register_parser($parser_type, $class_name);
}

/**
 * Get the parser matching a parser type id.
 *
 * @uses blcParserRegistry::get_parser() 
 *
 * @param string $parser_type
 * @return blcParser|null
 */
function blc_get_parser( $parser_type ){
	global $blc_parser_registry;
	return $blc_parser_registry->get_parser($parser_type);
}

/**
 * Get all parsers that support either the specified format or container type.
 *
 * @uses blcParserRegistry::get_parsers()
 *
 * @param string $format
 * @param string $container_type
 * @return array of blcParser
 */
function blc_get_parsers( $format, $container_type ){
	global $blc_parser_registry;
	return $blc_parser_registry->get_parsers($format, $container_type);
}


?>