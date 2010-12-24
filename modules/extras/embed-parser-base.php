<?php

/**
 * @author Janis Elsts
 * @copyright 2010
 */

if ( !class_exists('blcEmbedParserBase') ):

/**
 * Base class for embed code parsers.
 * 
 * Sub-classes should override the link_url_from_src() method and set the $url_search_string,
 * $short_title and $long_title properties to meaningful values.
 * 
 * @package Broken Link Checker
 * @author Janis Elsts
 * @access public
 */
class blcEmbedParserBase extends blcParser {
	var $supported_formats = array('html');
	
	var $short_title = '';      //Short desc. of embeds handled by this parser, singular. Example: "YouTube Video".
	var $long_title = '';       //Longer version of the above, e.g. "Embedded YouTube video".
	var $url_search_string = '';//Only consider embeds where the SRC contains this string. Example: "youtube.com/v/"	
	
  /**
   * Parse a string for embed codes.
   *
   * @param string $content The text to parse.
   * @param string $base_url The base URL. Ignored.  
   * @param string $default_link_text Default link text. Ignored.
   * @return array An array of new blcLinkInstance objects. The objects will include info about the embeds found, but not about the corresponding container entity. 
   */
	function parse($content, $base_url = '', $default_link_text = ''){
		$instances = array();
		
		//Find likely-looking <embed> elements
		$embeds = blcUtility::extract_embeds($content);
		foreach($embeds as $embed){
			//Do we know how to handle this embed? (first-pass verification) 
			if ( strpos($embed['attributes']['src'], $this->url_search_string) === false ){
				continue;
			}
			
			//Get the original URL of the embedded object (may perform more complex verification)
			$url = $this->link_url_from_src($embed['attributes']['src']);
			if ( empty($url) ){
				continue;
			}
						
			//Create a new link instance.
			$instance = new blcLinkInstance();
			    
		    $instance->set_parser($this);
		    $instance->raw_url = $embed['wrapper']['full_tag']; //The entire contents of the <object> tag that contains this <embed>
		    $instance->link_text = '[' . $this->short_title .']';
		    
		    $link_obj = new blcLink($url); //Creates or loads the link
		    $instance->set_link($link_obj);
		    
		    $instances[] = $instance;
		}
		
		return $instances;
	}
	
  /**
   * Remove all occurences of the specified embed from a string.
   *
   * @param string $content	Look for embeds in this string.
   * @param string $url Ignored.
   * @param string $embed_code The full embed code to look for.
   * @return string Input string with all matching embeds removed. 
   */
	function unlink($content, $url, $embed_code){
		if ( empty($embed_code) ){
			return $content;
		}
		
		return str_replace($embed_code, '', $content); //Super-simple.
	}

  /**
   * Get the link text for printing in the "Broken Links" table.
   *
   * @param blcLinkInstance $instance
   * @return string HTML 
   */
	function ui_get_link_text(&$instance, $context = 'display'){
		$image_url = sprintf(
			'/images/%s.png',
			$this->parser_type
		);
		
		$image_html = sprintf(
			'<img src="%s" class="blc-small-image" title="%2$s" alt="%2$s"> ',
			esc_attr( plugins_url($image_url, BLC_PLUGIN_FILE) ),
			$this->long_title
		);
		
		$field_html = sprintf(
			'%s',
			 $this->short_title
		); 
		
		if ( $context != 'email' ){
			$field_html = $image_html . $field_html;
		}
		
		return $field_html;
	}
	
	/**
	 * Determine the original URL of an embedded object by analysing its SRC attribute.
	 * 
	 * For example, if the object in question is an embedded YouTube video, this 
	 * method should return the URL of the original video; e.g. 'http://www.youtube.com/watch?v=example1234' 
	 *  
	 * Should be overridden in a sub-class.
	 * 
	 * @return string The URL of the embedded object, or an empty string if the URL can't be determined. 
	 */
	function link_url_from_src(){
		return '';
	}
	
	/**
	 * Editing is disabled in embed parsers. Calling this function will yield an instance of WP_Error.
	 * 
	 * @param string $content
	 * @param string $new_url
	 * @param string $old_url
	 * @param string $old_raw_url
	 * @return WP_Error
	 */
	function edit($content, $new_url, $old_url, $old_raw_url){
		return new WP_Error(
			'not_implemented',
			sprintf(__("Embedded videos can't be edited using Broken Link Checker. Please edit or replace the video in question manually.", 'broken-link-checker'), $this->parser_type)
		);
	}
}

endif;

?>