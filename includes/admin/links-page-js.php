<script type='text/javascript'>

function alterLinkCounter(factor){
    cnt = parseInt(jQuery('.current-link-count').eq(0).html());
    cnt = cnt + factor;
    jQuery('.current-link-count').html(cnt);
}

function replaceLinkId(old_id, new_id){
	var master = jQuery('#blc-row-'+old_id);
	
	//Save the new ID 
	master.attr('id', 'blc-row-'+new_id);
	master.find('.blc-link-id').html(new_id);
}

function reloadDetailsRow(link_id){
	var master = jQuery('#blc-row-'+link_id);
	
	//Load up the new link info                     (so sue me)    
	master.next('.blc-link-details').find('td').html('<center><?php echo esc_js(__('Loading...' , 'broken-link-checker')); ?></center>').load(
		"<?php echo admin_url('admin-ajax.php'); ?>",
		{
			'action' : 'blc_link_details',
			'link_id' : link_id
		}
	);
}

jQuery(function($){
	
	//The details button - display/hide detailed info about a link
    $(".blc-details-button, .blc-link-text").click(function () {
    	$(this).parents('.blc-row').next('.blc-link-details').toggle();
    });
	
	//The discard button - manually mark the link as valid. The link will be checked again later.
	$(".blc-discard-button").click(function () {
		var me = $(this);
		me.html('<?php echo esc_js(__('Wait...', 'broken-link-checker')); ?>');
		
		var link_id = $(me).parents('.blc-row').find('.blc-link-id').html();
        
        $.post(
			"<?php echo admin_url('admin-ajax.php'); ?>",
			{
				'action' : 'blc_discard',
				'link_id' : link_id,
				'_ajax_nonce' : '<?php echo esc_js(wp_create_nonce('blc_discard'));  ?>'
			},
			function (data, textStatus){
				if (data == 'OK'){
					var master = $(me).parents('.blc-row'); 
					var details = master.next('.blc-link-details');
					
					//Remove the "Not broken" link
					me.parent().remove();
					
					master.removeClass('blc-broken-link');
					
					//Flash the main row green to indicate success, then remove it if the current view
					//is supposed to show only broken links.
					var oldColor = master.css('background-color');
					master.animate({ backgroundColor: "#E0FFB3" }, 200).animate({ backgroundColor: oldColor }, 300, function(){
						if ( blc_is_broken_filter ){
							details.remove();
							master.remove();
						} else {
							reloadDetailsRow(link_id);
						}
					});
					
					//Update the elements displaying the number of results for the current filter.
					if( blc_is_broken_filter ){
                    	alterLinkCounter(-1);
                    }
				} else {
					$(me).html('<?php echo esc_js(__('Not broken' , 'broken-link-checker'));  ?>');
					alert(data);
				}
			}
		);
		
		return false;
    });
    
    //The edit button - edit/save the link's URL
    $(".blc-edit-button").click(function () {
		var edit_button = $(this);
		var master = $(edit_button).parents('.blc-row');
		var editor = $(master).find('.blc-link-editor');
		var url_el = $(master).find('.blc-link-url');
		var cancel_button_container = $(master).find('.blc-cancel-button-container');
		
      	//Find the current/original URL
    	var orig_url = url_el.attr('href');
    	//Find the link ID
    	var link_id = $(master).find('.blc-link-id').html();
    	
        if ( !$(editor).is(':visible') ){
        	//Dislay the editing UI
        	url_el.hide();
        	//Reset the edit box to the actual URL value in case the user has already tried and failed to edit this link.
        	editor.val( url_el.attr('href') );  
            editor.show();
            cancel_button_container.show();
            editor.focus();
            editor.select();
            edit_button.html('<?php echo esc_js(__('Save URL' , 'broken-link-checker')); ?>');
        } else {
        	//"Save" clicked.
            editor.hide();
            cancel_button_container.hide();
			url_el.show();
			
            new_url = editor.val();
            
            if (new_url != orig_url){
                //Save the changed link
                url_el.html('<?php echo esc_js(__('Saving changes...' , 'broken-link-checker')); ?>');
                
                $.getJSON(
					"<?php echo admin_url('admin-ajax.php'); ?>",
					{
						'action' : 'blc_edit',
						'link_id' : link_id,
						'new_url' : new_url,
						'_ajax_nonce' : '<?php echo esc_js(wp_create_nonce('blc_edit'));  ?>'
					},
					function (data, textStatus){
						var display_url = '';
						
						if ( data && (typeof(data['error']) != 'undefined') ){
							//An internal error occured before the link could be edited.
							//data.error is an error message.
							alert(data.error);
							display_url = orig_url;
						} else {
							//data contains info about the performed edit
							if ( data.errors.length == 0 ){
								//Everything went well. 
								
								//Replace the displayed link URL with the new one.
								display_url = new_url;
								url_el.attr('href', new_url);
								
								//Save the new ID 
								replaceLinkId(link_id, data.new_link_id);
								//Load up the new link info
								reloadDetailsRow(data.new_link_id);
								//Remove classes indicating link state - they're probably wrong by now
								master.removeClass('blc-broken-link').removeClass('blc-redirect');
								
								//Flash the row green to indicate success
								var oldColor = master.css('background-color');
								master.animate({ backgroundColor: "#E0FFB3" }, 200).animate({ backgroundColor: oldColor }, 300);
								
							} else {
								display_url = orig_url;
								
								//Build and display an error message.
								var msg = '';
								
								if ( data.cnt_okay > 0 ){
									var msgpiece = sprintf(
										'<?php echo esc_js(__('%d instances of the link were successfully modified.', 'broken-link-checker')); ?>',
										data.cnt_okay 
									);
									msg = msg + msgpiece + '\n';
									if ( data.cnt_error > 0 ){
										msgpiece = sprintf(
											'<?php echo esc_js(__("However, %d instances couldn't be edited and still point to the old URL.", 'broken-link-checker')); ?>',
											data.cnt_error
										);
										msg = msg + msgpiece + "\n";
									}
								} else {
									msg = msg + '<?php echo esc_js(__('The link could not be modified.', 'broken-link-checker')); ?>\n';
								}
																
								msg = msg + '\n<?php echo esc_js(__("The following error(s) occured :", 'broken-link-checker')); ?>\n* ';
								msg = msg + data.errors.join('\n* ');
								
								alert(msg);
							}
						};
						
						//Shorten the displayed URL if it's > 50 characters
						if ( display_url.length > 50 ){
							display_url = display_url.substr(0, 47) + '...';
						}
						url_el.html(display_url);
					}
				);
                
            } else {
				//It's the same URL, so do nothing.
			}
			edit_button.html('<?php echo esc_js(__('Edit URL', 'broken-link-checker')); ?>');
        }
    });
    
    //Let the user use Enter and Esc as shortcuts for "Save URL" and "Cancel"
    $('input.blc-link-editor').keypress(function (e) {
		if ((e.which && e.which == 13) || (e.keyCode && e.keyCode == 13)) {
			$(this).parents('.blc-row').find('.blc-edit-button').click();
			return false;
		} else if ((e.which && e.which == 27) || (e.keyCode && e.keyCode == 27)) {
			$(this).parents('.blc-row').find('.blc-cancel-button').click();
			return false;
		} else {
			return true;
		}
	});
    
    $(".blc-cancel-button").click(function () { 
		var master = $(this).parents('.blc-row');
		var url_el = $(master).find('.blc-link-url');
		
		//Hide the cancel button
		$(this).parent().hide();
		//Show the un-editable URL again 
		url_el.show();
		//reset and hide the editor
		master.find('.blc-link-editor').hide().val(url_el.attr('href'));
		//Set the edit button to say "Edit URL"
		master.find('.blc-edit-button').html('<?php echo esc_js(__('Edit URL' , 'broken-link-checker')); ?>');
    });
    
    //The unlink button - remove the link/image from all posts, custom fields, etc.
    $(".blc-unlink-button").click(function () { 
    	var me = this;
    	var master = $(me).parents('.blc-row');
		$(me).html('<?php echo esc_js(__('Wait...' , 'broken-link-checker')); ?>');
		
		var link_id = $(me).parents('.blc-row').find('.blc-link-id').html();
        
        $.post(
			"<?php echo admin_url('admin-ajax.php'); ?>",
			{
				'action' : 'blc_unlink',
				'link_id' : link_id,
				'_ajax_nonce' : '<?php echo esc_js(wp_create_nonce('blc_unlink'));  ?>'
			},
			function (data, textStatus){
				eval('data = ' + data);
				 
				if ( data && (typeof(data['error']) != 'undefined') ){
					//An internal error occured before the link could be edited.
					//data.error is an error message.
					alert(data.error);
				} else {
					if ( data.errors.length == 0 ){
						//The link was successfully removed. Hide its details. 
						master.next('.blc-link-details').hide();
						//Flash the main row green to indicate success, then hide it.
						var oldColor = master.css('background-color');
						master.animate({ backgroundColor: "#E0FFB3" }, 200).animate({ backgroundColor: oldColor }, 300, function(){
							master.hide();
						});
						
						alterLinkCounter(-1);
						
						return;
					} else {
						//Build and display an error message.
						var msg = '';
						
						if ( data.cnt_okay > 0 ){
							msg = msg + sprintf(
								'<?php echo esc_js(__("%d instances of the link were successfully unlinked.", 'broken-link-checker')); ?>\n', 
								data.cnt_okay
							);
							
							if ( data.cnt_error > 0 ){
								msg = msg + sprintf(
									'<?php echo esc_js(__("However, %d instances couldn't be removed.", 'broken-link-checker')); ?>\n',
									data.cnt_error
								);
							}
						} else {
							msg = msg + '<?php echo esc_js(__("The plugin failed to remove the link.", 'broken-link-checker')); ?>\n';
						}
														
						msg = msg + '\n<?php echo esc_js(__("The following error(s) occured :", 'broken-link-checker')); ?>\n* ';
						msg = msg + data.errors.join('\n* ');
						
						//Show the error message
						alert(msg);
					}				
				}
				
				$(me).html('<?php echo esc_js(__('Unlink' , 'broken-link-checker')); ?>'); 
			}
		);
    });
    
    //--------------------------------------------
    //The search box(es)
    //--------------------------------------------
    
    var searchForm = $('#search-links-dialog');
	    
    searchForm.dialog({
		autoOpen : false,
		dialogClass : 'blc-search-container',
		resizable: false
	});
    
    $('#blc-open-search-box').click(function(){
    	if ( searchForm.dialog('isOpen') ){
			searchForm.dialog('close');
		} else {
			//Display the search form under the "Search" button
	    	var button_position = $('#blc-open-search-box').offset();
	    	var button_height = $('#blc-open-search-box').outerHeight(true);
	    	var button_width = $('#blc-open-search-box').outerWidth(true);
	    	
			var dialog_width = searchForm.dialog('option', 'width');
						
	    	searchForm.dialog('option', 'position', 
				[ 
					button_position.left - dialog_width + button_width/2, 
					button_position.top + button_height + 1 - $(document).scrollTop()
				]
			);
			searchForm.dialog('open');
		}
	});
	
	$('#blc-cancel-search').click(function(){
		searchForm.dialog('close');
	});
	
	//The "Save This Search Query" button creates a new custom filter based on the current search
	$('#blc-create-filter').click(function(){
		var filter_name = prompt("<?php echo esc_js(__("Enter a name for the new custom filter", 'broken-link-checker')); ?>", "");
		if ( filter_name ){
			$('#blc-custom-filter-name').val(filter_name);
			$('#custom-filter-form').submit();
		}
	});
	
	//Display a confirmation dialog when the user clicks the "Delete This Filter" button 
	$('#blc-delete-filter').click(function(){
		if ( confirm('<?php 
			echo esc_js(  
					__("You are about to delete the current filter.\n'Cancel' to stop, 'OK' to delete", 'broken-link-checker')
				); 
		?>') ){
			return true;
		} else {
			return false;
		}
	});
	
	//--------------------------------------------
    // Bulk actions
    //--------------------------------------------
    
    $('#blc-bulk-action-form').submit(function(){
    	var action = $('#blc-bulk-action').val();
    	if ( action ==  '-1' ){
			var action = $('#blc-bulk-action2').val(); 
		}
    	
    	//Convey the gravitas of deleting link sources.
    	if ( action == 'bulk-delete-sources' ){
    		var message = '<?php 
				echo esc_js(  
					__("Are you sure you want to delete all posts, bookmarks or other items that contain any of the selected links? This action can't be undone.\n'Cancel' to stop, 'OK' to delete", 'broken-link-checker')
				); 
			?>'; 
			if ( !confirm(message) ){
				return false;
			}
		}
	});
	
	//------------------------------------------------------------
    // Manipulate highlight settings for permanently broken links
    //------------------------------------------------------------
    var highlight_permanent_failures_checkbox = $('#highlight_permanent_failures-hide');
	var failure_duration_threshold_input = $('#failure_duration_threshold');
	
	//Update the checkbox depending on current settings.
	<?php
	$conf = blc_get_configuration();
	if ( $conf->options['highlight_permanent_failures'] ){
		echo 'highlight_permanent_failures_checkbox.attr("checked", "checked");';
	} else {
		echo 'highlight_permanent_failures_checkbox.removeAttr("checked");';
	}
	?>;
	
    //Apply/remove highlights when the checkbox is (un)checked
    highlight_permanent_failures_checkbox.change(function(){
    	save_highlight_settings();
    	
		if ( this.checked ){
			$('#blc-links tr.blc-permanently-broken').addClass('blc-permanently-broken-hl');
		} else {
			$('#blc-links tr.blc-permanently-broken').removeClass('blc-permanently-broken-hl');
		}
	});
	
	//Apply/remove highlights when the duration threshold is changed.
	failure_duration_threshold_input.change(function(){
		var new_threshold = parseInt($(this).val());
		save_highlight_settings();
		if (isNaN(new_threshold) || (new_threshold < 1)) {
			return;
		}
		
		highlight_permanent_failures = highlight_permanent_failures_checkbox.is(':checked');
		
		$('#blc-links tr.blc-row').each(function(index){
			var days_broken = $(this).attr('days_broken');
			if ( days_broken >= new_threshold ){
				$(this).addClass('blc-permanently-broken');
				if ( highlight_permanent_failures ){
					$(this).addClass('blc-permanently-broken-hl');
				}
			} else {
				$(this).removeClass('blc-permanently-broken').removeClass('blc-permanently-broken-hl');
			}
		});
	});
	
	//Don't let the user manually submit the "Screen Options" form - it wouldn't work properly anyway.
	$('#adv-settings').submit(function(){
		return false;	
	});
	
	//Save highlight settings using AJAX
	function save_highlight_settings(){
		var $ = jQuery; 
		
		var highlight_permanent_failures = highlight_permanent_failures_checkbox.is(':checked');
		var failure_duration_threshold = parseInt(failure_duration_threshold_input.val());
		
		if ( isNaN(failure_duration_threshold) || ( failure_duration_threshold < 1 ) ){
			failure_duration_threshold = 1;
		}
		
		failure_duration_threshold_input.val(failure_duration_threshold);
		
		$.post(
			"<?php echo admin_url('admin-ajax.php'); ?>",
			{
				'action' : 'blc_save_highlight_settings',
				'failure_duration_threshold' : failure_duration_threshold,
				'highlight_permanent_failures' : highlight_permanent_failures?1:0,
				'_ajax_nonce' : '<?php echo esc_js(wp_create_nonce('blc_save_highlight_settings'));  ?>'
			}
		);
	}
	
	
	
});

</script>