<?php

class blcPostContainer extends blcContainer {
	var $fields = array('post_content' => 'html');
	var $default_field = 'post_content';
	
  /**
   * Get action links for this post.
   *
   * @param string $container_field Ignored.
   * @return array of action link HTML.
   */
	function ui_get_action_links($container_field = ''){
		$actions = array();
		if ( current_user_can('edit_post', $this->container_id) ) {
			$actions['edit'] = '<span class="edit"><a href="' . $this->get_edit_url() . '" title="' . esc_attr(__('Edit this post')) . '">' . __('Edit') . '</a>';
			
			if ( constant('EMPTY_TRASH_DAYS') ) { 
				$actions['trash'] = "<a class='submitdelete' title='" . esc_attr(__('Move this post to the Trash')) . "' href='" . get_delete_post_link($this->container_id) . "'>" . __('Trash') . "</a>";
			} else {
				$actions['delete'] = "<a class='submitdelete' title='" . esc_attr(__('Delete this post permanently')) . "' href='" . wp_nonce_url( admin_url("post.php?action=delete&amp;post=".$this->container_id), 'delete-post_' . $this->container_id) . "' onclick=\"if ( confirm('" . esc_js(sprintf( __("You are about to delete this post '%s'\n 'Cancel' to stop, 'OK' to delete."), get_the_title($this->container_id) )) . "') ) { return true;}return false;\">" . __('Delete') . "</a>";
			}
		}
		$actions['view'] = '<span class="view"><a href="' . get_permalink($this->container_id) . '" title="' . esc_attr(sprintf(__('View "%s"', 'broken-link-checker'), get_the_title($this->container_id))) . '" rel="permalink">' . __('View') . '</a>';
		
		return $actions;
	}
	
  /**
   * Get the HTML for displaying the post title in the "Source" column.
   *
   * @param string $container_field Ignored.
   * @param string $context How to filter the output. Optional, defaults to 'display'. 
   * @return string HTML
   */
	function ui_get_source($container_field = '', $context = 'display'){
		$source = '<a class="row-title" href="%s" title="%s">%s</a>';
		$source = sprintf(
			$source,
			$this->get_edit_url(),
			esc_attr(__('Edit this post')),
			get_the_title($this->container_id)
		);
		
		return $source;
	}
	
  /**
   * Get edit URL for this container. Returns the URL of the Dashboard page where the item 
   * associated with this container can be edited.
   *
   * @access protected   
   *
   * @return
   */
	function get_edit_url(){
		return get_edit_post_link($this->container_id);
	}
	
  /**
   * Retrieve the post associated with this container. 
   *
   * @access protected
   *
   * @param bool $ensure_consistency Set this to true to ignore the cached $wrapped_object value and retrieve an up-to-date copy of the wrapped object from the DB (or WP's internal cache).
   * @return object Post data.
   */
	function get_wrapped_object($ensure_consistency = false){
		if( $ensure_consistency || is_null($this->wrapped_object) ){
			$this->wrapped_object = get_post($this->container_id);
		}		
		return $this->wrapped_object;
	}

  /**
   * Update the post associated with this container.
   *
   * @access protected
   *
   * @return bool|WP_Error True on success, an error if something went wrong.
   */
	function update_wrapped_object(){
		if ( is_null($this->wrapped_object) ){
			return new WP_Error(
				'no_wrapped_object',
				__('Nothing to update', 'broken-link-checker')
			);
		}
		
		$id = wp_update_post($this->wrapped_object);
		if ( $id != 0 ){
			return true;
		} else {
			return new WP_Error(
				'update_failed',
				sprintf(__('Updating post %d failed', 'broken-link-checker'), $this->container_id)
			);
		}
	}
	
  /**
   * Get the base URL of the container. For posts, the post permalink is used
   * as the base URL when normalizing relative links.
   *
   * @return string
   */
	function base_url(){
		return get_permalink($this->container_id);
	}
	
  /**
   * Delete the post corresponding to this container.
   *
   * @return bool|WP_error
   */
	function delete_wrapped_object(){
		if ( wp_delete_post($this->container_id) ){
			//Note that we don't need to delete the synch record and instances here - 
			//wp_delete_post() will run the post_delete hook, which will be caught
			//by blcPostContainerManager, which will delete anything that needs to be
			//deleted.
			return true;
		} else {
			return new WP_Error(
				'delete_failed',
				sprintf(
					__('Failed to delete post "%s" (%d)', 'broken-link-checker'),
					get_the_title($this->container_id),
					$this->container_id
				)
			);
		};
	}	
}

class blcPostContainerManager extends blcContainerManager {
	var $container_class_name = 'blcPostContainer';
	
	var $_conf; //Keep a local reference to the BLC configuration manager. Yields a minor performance benefit. 
	
  /**
   * Set up hooks that monitor added/modified/deleted posts.
   *
   * @return void
   */
	function init(){
		//These hooks update the synch & instance records when posts are added, deleted or modified.
		add_action('delete_post', array(&$this,'post_deleted'));
        add_action('save_post', array(&$this,'post_saved'));
        //We also treat post trashing/untrashing as delete/save. 
        add_action('trash_post', array(&$this,'post_deleted'));
        add_action('untrash_post', array(&$this,'post_saved'));
        
        //Highlight and nofollow broken links in posts & pages
        $this->_conf = blc_get_configuration();
        if ( $this->_conf->options['mark_broken_links'] || $this->_conf->options['nofollow_broken_links'] ){
        	add_filter( 'the_content', array(&$this,'hook_the_content') );
        	if ( $this->_conf->options['mark_broken_links'] && !empty( $this->_conf->options['broken_link_css'] ) ){
	            add_action( 'wp_head', array(&$this,'hook_wp_head') );
			}
        }
	}	
	
  /**
   * Remove the synch. record and link instances associated with a post when it's deleted 
   *
   * @param int $post_id
   * @return void
   */
	function post_deleted($post_id){
		//Get the associated container object
		$post_container = blc_get_container( array($this->container_type, intval($post_id)) );
		//Delete it
		$post_container->delete();
		//Clean up any dangling links
		blc_cleanup_links();
	}
	
  /**
   * When a post is saved or modified, mark it as unparsed.
   * 
   * @param int $post_id
   * @return void
   */
	function post_saved($post_id){
		//Get the container
		$args = array($this->container_type, intval($post_id));
		$post_container = blc_get_container( $args );
		
		//Get the post
		$post = $post_container->get_wrapped_object();
		
        //Only check links in posts, not revisions and attachments
        if ( ($post->post_type != 'post') && ($post->post_type != 'page') ) return;
        //Only check published posts
        if ( $post->post_status != 'publish' ) return;
        
        $post_container->mark_as_unsynched();
	}
	
	
  /**
   * Instantiate multiple containers of the container type managed by this class.
   *
   * @param array $containers Array of assoc. arrays containing container data.
   * @param string $purpose An optional code indicating how the retrieved containers will be used.
   * @param bool $load_wrapped_objects Preload wrapped objects regardless of purpose. 
   * 
   * @return array of blcPostContainer indexed by "container_type|container_id"
   */
	function get_containers($containers, $purpose = '', $load_wrapped_objects = false){
		$containers = $this->make_containers($containers);
		
		//Preload post data if it is likely to be useful later
		$preload = $load_wrapped_objects || in_array($purpose, array(BLC_FOR_DISPLAY, BLC_FOR_PARSING));
		if ( $preload ){
			$post_ids = array();
			foreach($containers as $container){
				$post_ids[] = $container->container_id;
			}
			
			$args = array('include' => implode(',', $post_ids));
			$posts = get_posts($args);
			
			foreach($posts as $post){
				$key = $this->container_type . '|' . $post->ID;
				if ( isset($containers[$key]) ){
					$containers[$key]->wrapped_object = $post;
				}
			}
		}
		
		return $containers;
	}
	
  /**
   * Create or update synchronization records for all posts.
   *
   * @param bool $forced If true, assume that all synch. records are gone and will need to be recreated from scratch. 
   * @return void
   */
	function resynch($forced = false){
		global $wpdb;
		
		if ( $forced ){
			//Create new synchronization records for all posts. 
	    	$q = "INSERT INTO {$wpdb->prefix}blc_synch(container_id, container_type, synched)
				  SELECT id, 'post', 0
				  FROM {$wpdb->posts}
				  WHERE
				  	{$wpdb->posts}.post_status = 'publish'
	 				AND {$wpdb->posts}.post_type IN ('post', 'page')";
	 		$wpdb->query( $q );
 		} else {
 			//Delete synch records corresponding to posts that no longer exist.
 			$q = "DELETE synch.*
				  FROM 
					 {$wpdb->prefix}blc_synch AS synch LEFT JOIN {$wpdb->posts} AS posts
					 ON posts.ID = synch.container_id
				  WHERE 
					 synch.container_type = 'post' AND posts.ID IS NULL";
			$wpdb->query( $q );
 			
			//Remove the 'synched' flag from all posts that have been updated
			//since the last time they were parsed/synchronized.
			$q = "UPDATE 
					{$wpdb->prefix}blc_synch AS synch
					JOIN {$wpdb->posts} AS posts ON (synch.container_id = posts.ID and synch.container_type='post')
				  SET 
					synched = 0
				  WHERE
					synch.last_synch < posts.post_modified";
			$wpdb->query( $q );
			
			//Create synch. records for posts that don't have them.
			$q = "INSERT INTO {$wpdb->prefix}blc_synch(container_id, container_type, synched)
				  SELECT id, 'post', 0
				  FROM 
				    {$wpdb->posts} AS posts LEFT JOIN {$wpdb->prefix}blc_synch AS synch
					ON (synch.container_id = posts.ID and synch.container_type='post')  
				  WHERE
				  	posts.post_status = 'publish'
	 				AND posts.post_type IN ('post', 'page')
					AND synch.container_id IS NULL";
			$wpdb->query($q);	 				
		}
	}
	
  /**
   * Get the message to display after $n posts have been deleted.
   *
   * @param int $n Number of deleted posts.
   * @return string A delete confirmation message, e.g. "5 posts were moved to trash"
   */
	function ui_bulk_delete_message($n){
		//Since the "Trash" feature has been introduced, calling wp_delete_post
		//doesn't actually delete the post (unless you set force_delete to True), 
		//just moves it to the trash. So we pick the message accordingly. 
		if ( function_exists('wp_trash_post') && EMPTY_TRASH_DAYS ){
			$delete_msg = _n("%d post moved to the trash", "%d posts moved to the trash", $n, 'broken-link-checker');
		} else {
			$delete_msg = _n("%d post deleted", "%d posts deleted", $n, 'broken-link-checker');
		}
		return sprintf($delete_msg, $n);
	}
	
  /**
   * Hook for the 'the_content' filter. Scans the current post and adds the 'broken_link' 
   * CSS class to all links that are known to be broken. Currently works only on standard
   * HTML links (i.e. the '<a href=...' kind). 
   *
   * @param string $content Post content
   * @return string Modified post content.
   */
	function hook_the_content($content){
		global $post, $wpdb;
        if ( empty($post) ) return $content;
        
        //Retrieve info about all occurences of broken links in the current post 
        $q = "
			SELECT instances.raw_url
			FROM {$wpdb->prefix}blc_instances AS instances JOIN {$wpdb->prefix}blc_links AS links 
				ON instances.link_id = links.link_id
			WHERE 
				instances.container_type = %s
				AND instances.container_id = %d
				AND links.broken = 1
				AND parser_type = 'link' 
		";
		$q = $wpdb->prepare($q, $this->container_type, $post->ID);
		$links = $wpdb->get_results($q, ARRAY_A);
		
		//Return the content unmodified if there are no broken links in this post.
		if ( empty($links) || !is_array($links) ){
			return $content;
		}
				
		//Put the broken link URLs in an array
		$broken_link_urls = array();
		foreach($links as $link){
			$broken_link_urls[] = $link['raw_url'];
		}
		
        //Iterate over all HTML links and modify the broken ones
		$parser = blc_get_parser('link');
		$content = $parser->multi_edit($content, array(&$this, 'highlight_broken_link'), $broken_link_urls);
		
		return $content;
	}
	
  /**
   * Analyse a link and add 'broken_link' CSS class if the link is broken.
   *
   * @see blcHtmlLink::multi_edit() 
   *
   * @param array $link Associative array of link data.
   * @param array $broken_link_urls List of broken link URLs present in the current post.
   * @return array|string The modified link
   */
	function highlight_broken_link($link, $broken_link_urls){
		if ( !in_array($link['href'], $broken_link_urls) ){
			//Link not broken = return the original link tag
			return $link['#raw'];
		}
		
		//Add 'broken_link' to the 'class' attribute (unless already present).
		if ( $this->_conf->options['mark_broken_links'] ){
			if ( isset($link['class']) ){
				$classes = explode(' ', $link['class']);
				if ( !in_array('broken_link', $classes) ){
					$classes[] = 'broken_link';
					$link['class'] = implode(' ', $classes);
				}
			} else {
				$link['class'] = 'broken_link';
			}
		}
		
		//Nofollow the link (unless it's already nofollow'ed)
		if ( $this->_conf->options['nofollow_broken_links'] ){
			if ( isset($link['rel']) ){
				$relations = explode(' ', $link['rel']);
				if ( !in_array('nofollow', $relations) ){
					$relations[] = 'nofollow';
					$link['rel'] = implode(' ', $relations);
				}
			} else {
				$link['rel'] = 'nofollow';
			}
		}
		
		return $link;
	}
	
  /**
   * A hook for the 'wp_head' action. Outputs the user-defined broken link CSS.
   *
   * @return void
   */
	function hook_wp_head(){
		$conf = blc_get_configuration();
		echo '<style type="text/css">',$conf->options['broken_link_css'],'</style>';
	}
}

blc_register_container('post', 'blcPostContainerManager');

?>