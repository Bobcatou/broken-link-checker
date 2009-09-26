<?php

/**
 * @author W-Shadow 
 * @copyright 2009
 *
 * @requires blcUtility
 */

class blcLinkHighlighter {
	
	var $links_to_remove;
	var $broken_link_css;
	var $current_permalink;
	
	function blcLinkHighlighter( $broken_link_css = '' ) {
		if ( !empty( $broken_link_css ) ){
			$this->broken_link_css = $broken_link_css;
            add_action( 'wp_head', array(&$this,'hook_wp_head') );
		}
		
		add_filter( 'the_content', array(&$this,'hook_the_content') );
		$this->current_permalink = '';
	}
	
	function hook_the_content($content){
        global $post, $wpdb;
        if ( empty($post) ) return $content;
        
        //Get the post permalink - it's used to resolve relative URLs
		$this->current_permalink = get_permalink( $post->ID );
        
        $q = "
        	SELECT instances.link_text, links.*

			FROM {$wpdb->prefix}blc_instances AS instances, {$wpdb->prefix}blc_links AS links
			
			WHERE 
				instances.source_id = %d
				AND instances.source_type = 'post'
				AND instances.instance_type = 'link'
				
				AND instances.link_id = links.link_id
			    AND links.check_count > 0
			    AND ( links.http_code < 200 OR links.http_code >= 400 OR links.timeout = 1 )
				AND links.http_code <> " . BLC_CHECKING;
				
		$rows = $wpdb->get_results( $wpdb->prepare( $q, $post->ID ), ARRAY_A ); 
        if( $rows ){
        	$this->links_to_remove = array();
        	foreach($rows as $row){
				$this->links_to_remove[$row['url']] = $row;
			}
            $content = preg_replace_callback( blcUtility::link_pattern(), array(&$this,'mark_broken_links'), $content );
        };
        
        return $content;
    }

    function mark_broken_links($matches){
    	//TODO: Tooltip-style popups with more info
        $url = blcUtility::normalize_url( html_entity_decode( $matches[3] ), $this->current_permalink );
        if( isset( $this->links_to_remove[$url] ) ){
            return $matches[1].$matches[2].$matches[3].$matches[2].' class="broken_link" '.$matches[4].
                   $matches[5].$matches[6];
        } else {
            return $matches[0];
        }
    }
    
    function hook_wp_head(){
		echo '<style type="text/css">',$this->broken_link_css,'</style>';
	}
}

?>