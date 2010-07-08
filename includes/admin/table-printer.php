<?php

/**
 * Utility class for printing the link listing table.
 * 
 * @package Broken Link Checker
 * @access public
 */
class blcTablePrinter {
	
	var $current_filter;       //The current search filter. Also contains the list of links to display. 
	var $page;                 //The current page number
	var $per_page;             //Max links per page
	var $core;                 //A reference to the main plugin object
	var $neutral_current_url;  //The "safe" version of the current URL, for use in the bulk action form.
	
	var $bulk_actions_html = '';
	var $pagination_html = '';
	
	var $columns;
	var $layouts;
	
	function blcTablePrinter($current_filter, &$core){
		$this->current_filter = $current_filter;
		$this->page = $current_filter['page'];
		$this->per_page = $current_filter['per_page'];
		$this->core = &$core;
		
		//Initialize layout and column definitions
		$this->setup_columns();
		$this->setup_layouts();
		
		//Figure out what the "safe" URL to acccess the current page would be.
		//This is used by the bulk action form. 
		$special_args = array('_wpnonce', '_wp_http_referer', 'action', 'selected_links');
		$this->neutral_current_url = remove_query_arg($special_args);				
	}
	
	/**
	 * Print the entire link table and associated navigation elements.   
	 * 
	 * @return void
	 */
	function print_table($layout = 'classic'){
		$this->prepare_nav_html();
		
		echo '<form id="blc-bulk-action-form" action="', $this->neutral_current_url, '" method="post">';
		wp_nonce_field('bulk-action');
		
		//Top navigation
		$this->navigation();
		
		//Table header
		echo '<table class="widefat" id="blc-links"><thead><tr>';
		$layout = $this->layouts[$layout];
		foreach($layout as $column_id){
			$column = $this->columns[$column_id];
			printf(
				'<th scope="col" class="%sblc-column-%s"%s>%s</th>',
				isset($column['class']) ? $column['class'].' ' : '',
				$column_id,
				isset($column['id']) ? ' id="' . $column['id'] . '"' : '',
				$column['heading']
			);
		}
		echo '</tr></thead>';
		
		//Table body
		echo '<tbody id="the-list">';
		$rownum = 0;
        foreach ($this->current_filter['links'] as $link) {
        	$rownum++;
        	$this->link_row($link, $layout, $rownum);
        	$this->link_details_row($link, $layout, $rownum);
       	}
		echo '</tbody></table>';
						
		//Bottom navigation				
		$this->navigation('2');
		echo '</form>';
	}
	
	/**
	 * Print the "Bulk Actions" dropdown and navigation links
	 * 
	 * @param string $suffix Optional. Appended to ID and name attributes of the bulk action dropdown. 
	 * @return void
	 */
	function navigation($suffix = ''){
		//Display the "Bulk Actions" dropdown
		echo '<div class="tablenav">',
				'<div class="alignleft actions">',
					'<select name="action', $suffix ,'" id="blc-bulk-action', $suffix ,'">',
						$this->bulk_actions_html,
					'</select>',
				' <input type="submit" name="doaction', $suffix ,'" id="doaction',$suffix,'" value="', 
					esc_attr(__('Apply', 'broken-link-checker')),
					'" class="button-secondary action">',
				'</div>';
	
		//Display pagination links 
		if ( !empty($this->pagination_html) ){
			echo $this->pagination_html;
		}
		
		echo '</div>';
	}
	
	/**
	 * Initialize the internal list of available table columns.
	 * 
	 * @return void
	 */
	function setup_columns(){
		$this->columns = array(
			'checkbox' => array(
				'heading' => '<input type="checkbox" />',
				'id' => 'cb',
				'class' => 'check-column',
				'content' => array(&$this, 'column_checkbox'),
			),
			
			'source' => array(
				'heading' => __('Source', 'broken-link-checker'),
				'class' => 'column-title',
				'content' => array(&$this, 'column_source'), 
			),
			
			'link-text' => array(
				'heading' => __('Link Text', 'broken-link-checker'),
				'content' => array(&$this, 'column_link_text'),
			),
			
			'url' => array(
		 		'heading' => __('URL', 'broken-link-checker'),
		 		'content' => array(&$this, 'column_url'),
			),
		);
	}
	
	/**
	 * Initialize the list of available layouts
	 * 
	 * @return void
	 */
	function setup_layouts(){
		$this->layouts = array(
			'classic' => array('checkbox', 'source', 'link-text', 'url'),
		);
	}
	
	/**
	 * Pre-generate some HTML fragments used for both the top and bottom navigation/bulk action boxes. 
	 * 
	 * @return void
	 */
	function prepare_nav_html(){
		//Generate an <option> element for each possible bulk action. The list doesn't change,
		//so we can do it once and reuse the generated HTML.
		$bulk_actions = array(
			'-1' => __('Bulk Actions', 'broken-link-checker'),
			"bulk-recheck" => __('Recheck', 'broken-link-checker'),
			"bulk-deredirect" => __('Fix redirects', 'broken-link-checker'),
			"bulk-not-broken" => __('Mark as not broken', 'broken-link-checker'),
			"bulk-unlink" => __('Unlink', 'broken-link-checker'),
			"bulk-delete-sources" => __('Delete sources', 'broken-link-checker'),
		);
		
		$bulk_actions_html = '';
		foreach($bulk_actions as $value => $name){
			$bulk_actions_html .= sprintf('<option value="%s">%s</option>', $value, $name);
		}
		
		$this->bulk_actions_html = $bulk_actions_html;
		
		//Pagination links can also be pre-generated.
		//WP has a built-in function for pagination :)
		$page_links = paginate_links( array(
			'base' => add_query_arg( 'paged', '%#%' ),
			'format' => '',
			'prev_text' => __('&laquo;'),
			'next_text' => __('&raquo;'),
			'total' => $this->current_filter['max_pages'],
			'current' => $this->page
		));
		
		if ( $page_links ) {
			$this->pagination_html = '<div class="tablenav-pages">';
			$this->pagination_html .= sprintf( 
				'<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of <span class="current-link-count">%s</span>', 'broken-link-checker' ) . '</span>%s',
				number_format_i18n( ( $this->page - 1 ) * $this->per_page + 1 ),
				number_format_i18n( min( $this->page * $this->per_page, $this->current_filter['count'] ) ),
				number_format_i18n( $this->current_filter['count'] ),
				$page_links
			); 
			$this->pagination_html .= '</div>';
		} else {
			$this->pagination_html = '';
		}
	}
	
	/**
	 * Print the link row.
	 * 
	 * @param object $link The link to display.
	 * @param array $layout List of columns to display.
	 * @param integer $rownum Table row number.
	 * @return void
	 */
	function link_row(&$link, $layout, $rownum = 0){
		
		//Figure out what CSS classes the link row should have
		$rowclass = ($rownum % 2)? 'alternate' : '';
		
    	$excluded = $this->core->is_excluded( $link->url ); 
    	if ( $excluded ) $rowclass .= ' blc-excluded-link';
    	
    	if ( $link->redirect_count > 0){
			$rowclass .= ' blc-redirect';
		}
    	
    	$days_broken = 0;
    	if ( $link->broken ){
			$rowclass .= ' blc-broken-link';
			
			//Add a highlight to broken links that appear to be permanently broken
			$days_broken = intval( (time() - $link->first_failure) / (3600*24) );
			if ( $days_broken >= $this->core->conf->options['failure_duration_threshold'] ){
				$rowclass .= ' blc-permanently-broken';
				if ( $this->core->conf->options['highlight_permanent_failures'] ){
					$rowclass .= ' blc-permanently-broken-hl';
				}
			}
		}
		
		//Pick one link instance to display in the table
		$instance = null;
		$instances = $link->get_instances();
		
		if ( !empty($instances) ){
			//Try to find one that matches the selected link type, if any
			if( !empty($this->search_params['s_link_type']) ){
				foreach($instances as $candidate){
					if ( ($candidate->container_type == $this->search_params['s_link_type']) || ($candidate->parser_type == $this->search_params['s_link_type']) ){
						$instance = $candidate;
						break;
					}
				}
			}
			//If there's no specific link type set, or no suitable instances were found,
			//just use the first one.
			if ( is_null($instance) ){
				$instance = $instances[0];
			}

		}
		
		printf(
			'<tr id="blc-row-%s" class="blc-row %s" days_broken="%d">',
			 $link->link_id,
			 $rowclass,
			 $days_broken
		);
		
		foreach($layout as $column_id){
			$column = $this->columns[$column_id];
			if ( isset($column['content']) ){
				if ( is_callable($column['content']) ){
					call_user_func($column['content'], $link, $instance);
				} else {
					echo $column['content'];
				}
			} else {
				printf('<td>%s</td>', $column_id);
			}
		}
		
		echo '</tr>';
	} 
	
	/**
	 * Print the details row for a specific link.
	 * 
	 * @param object $link
	 * @return void
	 */
	function link_details_row(&$link, $layout, $rownum = 0){
		?>
		<tr id='<?php print "link-details-{$rownum}"; ?>' class='blc-link-details'>
		<td colspan='4'>
		
		<div class="blc-detail-container">
			<div class="blc-detail-block" style="float: left; width: 49%;">
		    	<ol style='list-style-type: none;'>
		    	<?php if ( !empty($link->post_date) ) { ?>
		    	<li><strong><?php _e('Post published on', 'broken-link-checker'); ?> :</strong>
		    	<span class='post_date'><?php
					echo date_i18n(get_option('date_format'),strtotime($link->post_date));
		    	?></span></li>
		    	<?php } ?>
		    	<li><strong><?php _e('Link last checked', 'broken-link-checker'); ?> :</strong>
		    	<span class='check_date'><?php
					$last_check = $link->last_check;
		    		if ( $last_check < strtotime('-10 years') ){
						_e('Never', 'broken-link-checker');
					} else {
		    			echo date_i18n(get_option('date_format'), $last_check);
		    		}
		    	?></span></li>
		    	
		    	<li><strong><?php _e('HTTP code', 'broken-link-checker'); ?> :</strong>
		    	<span class='http_code'><?php 
		    		print $link->http_code; 
		    	?></span></li>
		    	
		    	<li><strong><?php _e('Response time', 'broken-link-checker'); ?> :</strong>
		    	<span class='request_duration'><?php 
		    		printf( __('%2.3f seconds', 'broken-link-checker'), $link->request_duration); 
		    	?></span></li>
		    	
		    	<li><strong><?php _e('Final URL', 'broken-link-checker'); ?> :</strong>
		    	<span class='final_url'><?php 
		    		print $link->final_url; 
		    	?></span></li>
		    	
		    	<li><strong><?php _e('Redirect count', 'broken-link-checker'); ?> :</strong>
		    	<span class='redirect_count'><?php 
		    		print $link->redirect_count; 
		    	?></span></li>
		    	
		    	<li><strong><?php _e('Instance count', 'broken-link-checker'); ?> :</strong>
		    	<span class='instance_count'><?php 
		    		print count($link->get_instances()); 
		    	?></span></li>
		    	
		    	<?php if ( $link->broken && (intval( $link->check_count ) > 0) ){ ?>
		    	<li><br/>
				<?php 
					printf(
						_n('This link has failed %d time.', 'This link has failed %d times.', $link->check_count, 'broken-link-checker'),
						$link->check_count
					);
					
					echo '<br>';
					
					$delta = time() - $link->first_failure;
					printf(
						__('This link has been broken for %s.', 'broken-link-checker'),
						$this->core->fuzzy_delta($delta)
					);
				?>
				</li>
		    	<?php } ?>
				</ol>
			</div>
			
			<div class="blc-detail-block" style="float: right; width: 50%;">
		    	<ol style='list-style-type: none;'>
		    		<li><strong><?php _e('Log', 'broken-link-checker'); ?> :</strong>
		    	<span class='blc_log'><?php 
		    		print nl2br($link->log); 
		    	?></span></li>
				</ol>
			</div>
			
			<div style="clear:both;"> </div>
		</div>
		
		</td></tr>
		<?php
	}
	
	function column_checkbox(&$link){
		?>
		<th class="check-column" scope="row">
			<input type="checkbox" name="selected_links[]" value="<?php echo $link->link_id; ?>" />
		</th>
		<?php
	}
	
	function column_source(&$link, &$instance = null){
		echo '<td class="post-title column-title">',
				'<span class="blc-link-id" style="display:none;">',
					$link->link_id,
				'</span>';
				 	
		//Print the contents of the "Source" column
		if ( !is_null($instance) ){
			echo $instance->ui_get_source();
			
			$actions = $instance->ui_get_action_links();
			
			echo '<div class="row-actions">';
			echo implode(' | </span>', $actions);
			echo '</div>';
			
		} else {
			_e("[An orphaned link! This is a bug.]", 'broken-link-checker');
		}

		echo '</td>';
	}
	
	function column_url(&$link){
		?>
		<td class='column-url'>
            <a href="<?php print esc_attr($link->url); ?>" target='_blank' class='blc-link-url' title="<?php echo esc_attr($link->url); ?>">
            	<?php print blcUtility::truncate($link->url, 50, ''); ?></a>
            <input type='text' id='link-editor-<?php print $link->link_id; ?>' 
            	value="<?php print esc_attr($link->url); ?>" 
                class='blc-link-editor' style='display:none' />
        <?php
        	//Output inline action links for the link/URL                  	
          	$actions = array();
          	
			$actions['details'] = "<span class='view'><a class='blc-details-button' href='javascript:void(0)' title='". esc_attr(__('Show more info about this link', 'broken-link-checker')) . "'>". __('Details', 'broken-link-checker') ."</a>";
          	
			$actions['delete'] = "<span class='delete'><a class='submitdelete blc-unlink-button' title='" . esc_attr( __('Remove this link from all posts', 'broken-link-checker') ). "' ".
				"id='unlink-button-$rownum' href='javascript:void(0);'>" . __('Unlink', 'broken-link-checker') . "</a>";
			
			if ( $link->broken ){
				$actions['discard'] = sprintf(
					'<span><a href="#" title="%s" class="blc-discard-button">%s</a>',
					esc_attr(__('Remove this link from the list of broken links and mark it as valid', 'broken-link-checker')),
					__('Not broken', 'broken-link-checker')
				);
			}
			
			$actions['edit'] = "<span class='edit'><a href='javascript:void(0)' class='blc-edit-button' title='" . esc_attr( __('Edit link URL' , 'broken-link-checker') ) . "'>". __('Edit URL' , 'broken-link-checker') ."</a>";
			
			echo '<div class="row-actions">';
			echo implode(' | </span>', $actions);
			
			echo "<span style='display:none' class='blc-cancel-button-container'> " .
				 "| <a href='javascript:void(0)' class='blc-cancel-button' title='". esc_attr(__('Cancel URL editing' , 'broken-link-checker')) ."'>". __('Cancel' , 'broken-link-checker') ."</a></span>";

			echo '</div>';
        ?>
        </td>
		<?php
	}
	
	function column_link_text(&$link, &$instance = null){
		echo '<td class="blc-link-text">';
		if ( is_null($instance) ){
			echo '<em>N/A</em>';
		} else {
			echo $instance->ui_get_link_text();
		}
		echo '</td>';
	}
}

?>