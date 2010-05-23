<?php

/**
 * Simple function to replicate PHP 5 behaviour
 */
if ( !function_exists( 'microtime_float' ) ) {
	function microtime_float()
	{
	    list($usec, $sec) = explode(" ", microtime());
	    return ((float)$usec + (float)$sec);
	}
}

if (!class_exists('wsBrokenLinkChecker')) {

class wsBrokenLinkChecker {
    var $conf;
    
	var $loader;
    var $my_basename = '';	
    
    var $db_version = 4; 		//The required version of the plugin's DB schema.
    
    var $execution_start_time; 	//Used for a simple internal execution timer in start_timer()/execution_time()
    var $lockfile_handle = null; 
    
  /**
   * wsBrokenLinkChecker::wsBrokenLinkChecker()
   * Class constructor
   *
   * @param string $loader The fully qualified filename of the loader script that WP identifies as the "main" plugin file.
   * @param blcConfigurationManager $conf An instance of the configuration manager
   * @return void
   */
    function wsBrokenLinkChecker ( $loader, $conf ) {
        global $wpdb;
        
        $this->conf = $conf;
        $this->loader = $loader;
        $this->my_basename = plugin_basename( $this->loader );

        register_activation_hook($this->my_basename, array(&$this,'activation'));
        register_deactivation_hook($this->my_basename, array(&$this, 'deactivation'));
        
        add_action('init', array(&$this,'load_language'));
        
        add_action('admin_menu', array(&$this,'admin_menu'));

		//Load jQuery on Dashboard pages (probably redundant as WP already does that)
        add_action('admin_print_scripts', array(&$this,'admin_print_scripts'));
        
        //The dashboard widget
        add_action('wp_dashboard_setup', array(&$this, 'hook_wp_dashboard_setup'));
		
        //AJAXy hooks
        add_action( 'wp_ajax_blc_full_status', array(&$this,'ajax_full_status') );
        add_action( 'wp_ajax_blc_dashboard_status', array(&$this,'ajax_dashboard_status') );
        add_action( 'wp_ajax_blc_work', array(&$this,'ajax_work') );
        add_action( 'wp_ajax_blc_discard', array(&$this,'ajax_discard') );
        add_action( 'wp_ajax_blc_edit', array(&$this,'ajax_edit') );
        add_action( 'wp_ajax_blc_link_details', array(&$this,'ajax_link_details') );
        add_action( 'wp_ajax_blc_unlink', array(&$this,'ajax_unlink') );
        add_action( 'wp_ajax_blc_current_load', array(&$this,'ajax_current_load') );
        add_action( 'wp_ajax_blc_save_highlight_settings', array(&$this,'ajax_save_highlight_settings') );
        add_action( 'wp_ajax_blc_disable_widget_highlight', array(&$this,'ajax_disable_widget_highlight') );
        
        //Check if it's possible to create a lockfile and nag the user about it if not.
        if ( $this->lockfile_name() ){
            //Lockfiles work, so it's safe to enable the footer hook that will call the worker
            //function via AJAX.
            add_action('admin_footer', array(&$this,'admin_footer'));
        } else {
            //No lockfiles, nag nag nag!
            add_action( 'admin_notices', array( &$this, 'lockfile_warning' ) );
        }
        
        //Add/remove Cron events
        $this->setup_cron_events();
        
        //Set hooks that listen for our Cron actions
    	add_action('blc_cron_email_notifications', array( &$this, 'send_email_notifications' ));
		add_action('blc_cron_check_links', array(&$this, 'cron_check_links'));
    }

  /**
   * Output the script that runs the link monitor while the Dashboard is open.
   *
   * @return void
   */
    function admin_footer(){
    	if ( !$this->conf->options['run_in_dashboard'] ){
			return;
		}
        ?>
        <!-- wsblc admin footer -->
        <script type='text/javascript'>
        (function($){
				
			//(Re)starts the background worker thread 
			function blcDoWork(){
				$.post(
					"<?php echo admin_url('admin-ajax.php'); ?>",
					{
						'action' : 'blc_work'
					}
				);
			}
			//Call it the first time
			blcDoWork();
			
			//Then call it periodically every X seconds 
			setInterval(blcDoWork, <?php echo (intval($this->conf->options['max_execution_time']) + 1 )*1000; ?>);
			
		})(jQuery);
        </script>
        <!-- /wsblc admin footer -->
        <?php
    }
    
  /**
   * Check if an URL matches the exclusion list.
   *
   * @param string $url
   * @return bool
   */
    function is_excluded($url){
        if (!is_array($this->conf->options['exclusion_list'])) return false;
        foreach($this->conf->options['exclusion_list'] as $excluded_word){
            if (stristr($url, $excluded_word)){
                return true;
            }
        }
        return false;
    }

    function dashboard_widget(){
        ?>
        <p id='wsblc_activity_box'><?php _e('Loading...', 'broken-link-checker');  ?></p>
        <script type='text/javascript'>
        	jQuery( function($){
        		var blc_was_autoexpanded = false;
        		
				function blcDashboardStatus(){
					$.getJSON(
						"<?php echo admin_url('admin-ajax.php'); ?>",
						{
							'action' : 'blc_dashboard_status'
						},
						function (data, textStatus){
							if ( data && ( typeof(data.text) != 'undefined' ) ) {
								$('#wsblc_activity_box').html(data.text); 
								<?php if ( $this->conf->options['autoexpand_widget'] ) { ?>
								//Expand the widget if there are broken links.
								//Do this only once per pageload so as not to annoy the user.
								if ( !blc_was_autoexpanded && ( data.status.broken_links > 0 ) ){
									$('#blc_dashboard_widget.postbox').removeClass('closed');
									blc_was_autoexpanded = true;
								};
								<?php } ?>
							} else {
								$('#wsblc_activity_box').html('<?php _e('[ Network error ]', 'broken-link-checker'); ?>');
							}
							
							setTimeout( blcDashboardStatus, 120*1000 ); //...update every two minutes
						}
					);
				}
				
				blcDashboardStatus();//Call it the first time
			
			} );
        </script>
        <?php
    }
    
    function dashboard_widget_control( $widget_id, $form_inputs = array() ){
		if ( 'POST' == $_SERVER['REQUEST_METHOD'] && 'blc_dashboard_widget' == $_POST['widget_id'] ) {
			//It appears $form_inputs isn't used in the current WP version, so lets just use $_POST
			$this->conf->options['autoexpand_widget'] = !empty($_POST['blc-autoexpand']);
			$this->conf->save_options();
		}
	
		?>
		<p><label for="blc-autoexpand">
			<input id="blc-autoexpand" name="blc-autoexpand" type="checkbox" value="1" <?php if ( $this->conf->options['autoexpand_widget'] ) echo 'checked="checked"'; ?> />
			<?php _e('Automatically expand the widget if broken links have been detected', 'broken-link-checker'); ?>
		</label></p>
		<?php
    }

    function admin_print_scripts(){
        //jQuery is used for AJAX and effects
        wp_enqueue_script('jquery');
    }
    
    function enqueue_settings_scripts(){
    	//jQuery UI is used on the settings page
		wp_enqueue_script('jquery-ui-core');   //Used for background color animation
        wp_enqueue_script('jquery-ui-dialog');
	}
	
	function enqueue_link_page_scripts(){
		wp_enqueue_script('jquery-ui-core');   //Used for background color animation
        wp_enqueue_script('jquery-ui-dialog'); //Used for the search form
        wp_enqueue_script('sprintf', WP_PLUGIN_URL . '/' . dirname($this->my_basename) . '/js/sprintf.js'); //Used in error messages
	}
	
  /**
   * Output the JavaScript that adds the "Feedback" widget to screen meta.
   *
   * @return void
   */
	function print_uservoice_widget(){
		$highlight = '';
		if ( $this->conf->options['highlight_feedback_widget'] ){
			$highlight = 'blc-feedback-highlighted';
		}; 
		?>
		<script type="text/javascript">
		(function($){
			$('#screen-meta-links').append(
				'<div id="blc-feedback-widget-wrap" class="hide-if-no-js screen-meta-toggle <?php echo $highlight; ?>">' +
					'<a href="#" id="blc-feedback-widget" class="show-settings">Feedback</a>' +
				'</div>'
			);
			
			$('#blc-feedback-widget').click(function(){
				<?php
				if($this->conf->options['highlight_feedback_widget']):
				?>
				
				//Return the "Feedback" button to the boring gray state
				$(this).parent().animate({ backgroundColor: "#E3E3E3" }, 500).removeClass('blc-feedback-highlighted');
				$.post(
					"<?php echo admin_url('admin-ajax.php'); ?>",
					{
						'action' : 'blc_disable_widget_highlight',
						'_ajax_nonce' : '<?php echo esc_js(wp_create_nonce('blc_disable_widget_highlight'));  ?>'
					}
				);
				
				<?php
				endif;
				?>
				
				//Launch UserVoice
				UserVoice.Popin.show(uservoiceOptions); 
				return false;
			});
		})(jQuery);
		</script>
		<?php
	}
	
  /**
   * Load the UserVoice script for use with the "Feedback" widget
   *
   * @return void
   */
	function uservoice_widget(){
		?>
		<script type="text/javascript">
		  var uservoiceOptions = {
		    key: 'whiteshadow',
		    host: 'feedback.w-shadow.com', 
		    forum: '58400',
		    lang: 'en',
		    showTab: false
		  };
		  function _loadUserVoice() {
		    var s = document.createElement('script');
		    s.src = ("https:" == document.location.protocol ? "https://" : "http://") + "cdn.uservoice.com/javascripts/widgets/tab.js";
		    document.getElementsByTagName('head')[0].appendChild(s);
		  }
		  _loadSuper = window.onload;
		  window.onload = (typeof window.onload != 'function') ? _loadUserVoice : function() { _loadSuper(); _loadUserVoice(); };
		</script>
		<?php
	}
	
  /**
   * Turn off the widget highlight. Expected to be called via AJAX.
   * 
   * @return void
   */
	function ajax_disable_widget_highlight(){
		check_ajax_referer('blc_disable_widget_highlight');
		
		if ( current_user_can('edit_others_posts') || current_user_can('manage_options') ){
			$this->conf->options['highlight_feedback_widget'] = false;
			$this->conf->save_options();
			die('OK');
		}
		die('-2');
	}

  /**
   * Initiate a full recheck - reparse everything and check all links anew. 
   *
   * @return void
   */
    function initiate_recheck(){
    	global $wpdb;
    	
    	//Delete all discovered instances
    	$wpdb->query("TRUNCATE {$wpdb->prefix}blc_instances");
    	
    	//Delete all discovered links
    	$wpdb->query("TRUNCATE {$wpdb->prefix}blc_links");
    	
    	//Mark all posts, custom fields and bookmarks for processing.
    	blc_resynch(true);
	}

  /**
   * This is a hook that's executed when the plugin is activated. 
   * It set's up and populates the plugin's DB tables & performs
   * other installation tasks.
   *
   * @return void
   */
    function activation(){
    	global $blclog;
    	
    	$blclog = new blcOptionLogger('blc_installation_log');
		$blclog->clear();
    	
    	$blclog->info('Plugin activated.');
    	
    	$this->conf->options['installation_complete'] = false;
    	$this->conf->options['installation_failed'] = true;
        $this->conf->save_options();
        $blclog->info('Installation/update begins.');
        
        $blclog->info('Initializing components...');
    	blc_init_all_components();
    	
    	//Prepare the database.
    	$blclog->info('Upgrading the database...');
        $this->upgrade_database();

		//Mark all new posts and other parse-able objects as unsynchronized.
		$blclog->info('Updating database entries...'); 
        blc_resynch();

		//Turn off load limiting if it's not available on this server.
		$blclog->info('Updating server load limit settings...');
		$load = $this->get_server_load();
		if ( empty($load) ){
			$this->conf->options['enable_load_limit'] = false;
		}
		
		//And optimize my DB tables, too (for good measure)
		$blclog->info('Optimizing the database...'); 
        $this->optimize_database();
		
		$blclog->info('Completing installation...');
        $this->conf->options['installation_complete'] = true;
        $this->conf->options['installation_failed'] = false;
        $this->conf->save_options();
        
        $blclog->info('Installation/update successfully completed.');
    }
    
  /**
   * A hook executed when the plugin is deactivated.
   *
   * @return void
   */
    function deactivation(){
    	//Remove our Cron events
		wp_clear_scheduled_hook('blc_cron_check_links');
		wp_clear_scheduled_hook('blc_cron_email_notifications');
	}
	
  /**
   * Create and/or upgrade the plugin's database tables.
   *
   * @return bool
   */
    function upgrade_database(){
		global $wpdb, $blclog;
		
		//Do we need to upgrade?
		if ( $this->db_version == $this->conf->options['current_db_version'] ) {
			//The DB is up to date, but lets make sure all the required tables are present
			//in case the user has decided to delete some of them.
			$blclog->info( sprintf(
				'The database appears to be up to date (current version : %d).',
				$this->conf->options['current_db_version']
			));
			return $this->maybe_create_tables();	
		}
		
		//Upgrade to DB version 4
		if ( $this->db_version == 4 ){
			$blclog->info(sprintf(
				'Upgrading the database to version %d',
				$this->db_version
			));
			
			//The 4th version makes a lot of backwards-incompatible changes to the main
			//BLC tables (in particular, it adds several required fields to blc_instances).
			//While it would be possible to import data from an older version of the DB,
			//some things - like link editing - wouldn't work with the old data. 
			
			//So we just drop, recreate and repopulate most tables.
			$blclog->info('Deleting old tables'); 
			$tables = array(
				$wpdb->prefix . 'blc_linkdata',
				$wpdb->prefix . 'blc_postdata',
				$wpdb->prefix . 'blc_instances',
				$wpdb->prefix . 'blc_synch',
				$wpdb->prefix . 'blc_links',
			);
			
			$q = "DROP TABLE IF EXISTS " . implode(', ', $tables);
			$rez = $wpdb->query( $q );
			
			if ( $rez === false ){
				$error = sprintf(
					__("Failed to delete old DB tables. Database error : %s", 'broken-link-checker'),
					$wpdb->last_error
				); 
				
				$blclog->error($error);
				trigger_error($error, E_USER_ERROR);
			}
			$blclog->info('Done.');
			
			//Create new DB tables.
			if ( !$this->maybe_create_tables() ){
				return false;
			};
			
		} else {
			//This should never happen.
			$error = sprintf(
				__(
					"Unexpected error: The plugin doesn't know how to upgrade its database to version '%d'.",
					'broken-link-checker'
				),
				$this->db_version
			); 
			
			$blclog->error($error);
			trigger_error($error, E_USER_ERROR);
		}
		
		//Upgrade was successful.
		$this->conf->options['current_db_version'] = $this->db_version;
		$this->conf->save_options();
		
		$blclog->info('Database successfully upgraded.');
		return true;
	}
	
  /**
   * Create the plugin's DB tables, unless they already exist.
   *
   * @return bool
   */
	function maybe_create_tables(){
		global $wpdb, $blclog;
		
		$blclog->info('Creating database tables');
		
		//Search filters
		$blclog->info('... Creating the search filter table');
		$q = <<<EOD
			CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}blc_filters` (
			  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `name` varchar(100) NOT NULL,
			  `params` text NOT NULL,
			  PRIMARY KEY (`id`)
			)
EOD;
		if ( $wpdb->query($q) === false ){
			$error = sprintf(
				__("Failed to create table '%s'. Database error: %s", 'broken-link-checker'),
				$wpdb->prefix . 'blc_filters',
				$wpdb->last_error
			); 
			
			$blclog->error($error);
			trigger_error(
				$error,
				E_USER_ERROR
			);
		}
		
		//Link instances (i.e. link occurences inside posts and other items)
		$blclog->info('... Creating the link instance table');
		$q = <<<EOT
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}blc_instances` (
		  `instance_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		  `link_id` int(10) unsigned NOT NULL,
		  `container_id` int(10) unsigned NOT NULL,
		  `container_type` varchar(40) NOT NULL DEFAULT 'post',
		  `link_text` varchar(250) NOT NULL DEFAULT '',
		  `parser_type` varchar(40) NOT NULL DEFAULT 'link',
		  `container_field` varchar(250) NOT NULL DEFAULT '',
		  `link_context` varchar(250) NOT NULL DEFAULT '',
		  `raw_url` text NOT NULL,
		  
		  PRIMARY KEY (`instance_id`),
		  KEY `link_id` (`link_id`),
		  KEY `source_id` (`container_id`,`container_type`)
		);
EOT;
		if ( $wpdb->query($q) === false ){ 
			$error = sprintf(
				__("Failed to create table '%s'. Database error: %s", 'broken-link-checker'),
				$wpdb->prefix . 'blc_instances',
				$wpdb->last_error
			);
			
			$blclog->error($error);
			trigger_error(
				$error,
				E_USER_ERROR
			);
		}
		
		//Links themselves. Note : The 'url' and 'final_url' columns must be collated
		//in a case-sensitive manner. This is because "http://a.b/cde" may be a page 
		//very different from "http://a.b/CDe".
		$blclog->info('... Creating the link table');
		$q = <<<EOS
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}blc_links` (
		  `link_id` int(20) unsigned NOT NULL AUTO_INCREMENT,
		  `url` text CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
		  `first_failure` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  `last_check` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  `last_success` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  `last_check_attempt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  `check_count` int(4) unsigned NOT NULL DEFAULT '0',
		  `final_url` text CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
		  `redirect_count` smallint(5) unsigned NOT NULL DEFAULT '0',
		  `log` text NOT NULL,
		  `http_code` smallint(6) NOT NULL DEFAULT '0',
		  `request_duration` float NOT NULL DEFAULT '0',
		  `timeout` tinyint(1) unsigned NOT NULL DEFAULT '0',
		  `broken` tinyint(1) NOT NULL DEFAULT '0',
		  `may_recheck` tinyint(1) NOT NULL DEFAULT '1',
		  `being_checked` tinyint(1) NOT NULL DEFAULT '0',
		  `result_hash` varchar(200) NOT NULL DEFAULT '',
		  `false_positive` tinyint(1) NOT NULL DEFAULT '0',
		  
		  PRIMARY KEY (`link_id`),
		  KEY `url` (`url`(150)),
		  KEY `final_url` (`final_url`(150)),
		  KEY `http_code` (`http_code`),
		  KEY `broken` (`broken`)
		);
EOS;
		if ( $wpdb->query($q) === false ){
			$error = sprintf(
				__("Failed to create table '%s'. Database error: %s", 'broken-link-checker'),
				$wpdb->prefix . 'blc_links',
				$wpdb->last_error
			);
			
			$blclog->error($error);
			trigger_error(
				$error,
				E_USER_ERROR
			);
		}
		
		//Synchronization records. This table is used to keep track of if and when various items 
		//(e.g. posts, comments, etc) were parsed.
		$blclog->info('... Creating the synch. record table');
		$q = <<<EOZ
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}blc_synch` (
		  `container_id` int(20) unsigned NOT NULL,
		  `container_type` varchar(40) NOT NULL,
		  `synched` tinyint(3) unsigned NOT NULL,
		  `last_synch` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  
		  PRIMARY KEY (`container_type`,`container_id`),
		  KEY `synched` (`synched`)
		);
EOZ;
		
		if ( $wpdb->query($q) === false ){
			$error = sprintf(
				__("Failed to create table '%s'. Database error: %s", 'broken-link-checker'),
				$wpdb->prefix . 'blc_synch',
				$wpdb->last_error
			);
			
			$blclog->error($error);		
			trigger_error(
				$error,
				E_USER_ERROR
			);
		}
		
		//All good.
		$blclog->info('All database tables successfully created.');
		return true;
	}
	
  /**
   * Optimize the plugin's tables
   *
   * @return void
   */
	function optimize_database(){
		global $wpdb;
		
		$wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}blc_links, {$wpdb->prefix}blc_instances, {$wpdb->prefix}blc_synch");
	}

    function admin_menu(){
    	if (current_user_can('manage_options'))
          add_filter('plugin_action_links', array(&$this, 'plugin_action_links'), 10, 2);
    	
        $options_page_hook = add_options_page( 
			__('Link Checker Settings', 'broken-link-checker'), 
			__('Link Checker', 'broken-link-checker'), 
			'manage_options',
            'link-checker-settings',array(&$this, 'options_page')
		);
		
        $links_page_hook = add_management_page(
			__('View Broken Links', 'broken-link-checker'), 
			__('Broken Links', 'broken-link-checker'), 
			'edit_others_posts',
            'view-broken-links',array(&$this, 'links_page')
		);
		 
		//Add plugin-specific scripts and CSS only to the it's own pages
		add_action( 'admin_print_styles-' . $options_page_hook, array(&$this, 'options_page_css') );
        add_action( 'admin_print_styles-' . $links_page_hook, array(&$this, 'links_page_css') );
		add_action( 'admin_print_scripts-' . $options_page_hook, array(&$this, 'enqueue_settings_scripts') );
        add_action( 'admin_print_scripts-' . $links_page_hook, array(&$this, 'enqueue_link_page_scripts') );
        
        //Add the UserVoice widget to the plugin's pages
        add_action( 'admin_footer-' . $options_page_hook, array(&$this, 'uservoice_widget') );
        add_action( 'admin_footer-' . $links_page_hook, array(&$this, 'uservoice_widget') );
        
        /*
		Display a checkbox in "Screen Options" that lets the user highlight links that 
		have been broken for a long time. The "Screen Options" panel isn't directly 
		customizable, so we must resort to ugly hacks using add_meta_box() and JavaScript. 
		*/ 
        $input_html = sprintf(
        	'</label><input style="margin-left: -1em" type="text" name="failure_duration_threshold" id="failure_duration_threshold" value="%d" size="2">',
        	$this->conf->options['failure_duration_threshold']
		);
        $title_html = sprintf(
			__('Highlight links broken for at least %s days', 'broken-link-checker'),
			$input_html
		);
        add_meta_box('highlight_permanent_failures', $title_html, array(&$this, 'noop'), $links_page_hook);
    }
    
  /**
   * Dummy callback for the non-existent 'highlight_permanent_failures' meta box. Does nothing.
   *
   * @return void
   */
    function noop(){
		//Do nothing.
	}

  /**
   * plugin_action_links()
   * Handler for the 'plugin_action_links' hook. Adds a "Settings" link to this plugin's entry
   * on the plugin list.
   *
   * @param array $links
   * @param string $file
   * @return array
   */
    function plugin_action_links($links, $file) {
        if ($file == $this->my_basename)
            $links[] = "<a href='options-general.php?page=link-checker-settings'>" . __('Settings') . "</a>";
        return $links;
    }

    function options_page(){
    	global $blc_container_registry;
    	
    	//Sanity check : make sure the DB is all set up 
    	if ( $this->db_version != $this->conf->options['current_db_version'] ) {
        	printf(
				__("Error: The plugin's database tables are not up to date! (Current version : %d, expected : %d)", 'broken-link-checker'),
				$this->conf->options['current_db_version'],
				$this->db_version
			);
			return;
		}
    	
        if (isset($_GET['recheck']) && ($_GET['recheck'] == 'true')) {
            $this->initiate_recheck();
        }
        
        if(isset($_POST['submit'])) {
			check_admin_referer('link-checker-options');
			
			//The execution time limit must be above zero
            $new_execution_time = intval($_POST['max_execution_time']);
            if( $new_execution_time > 0 ){
                $this->conf->options['max_execution_time'] = $new_execution_time;
            }

			//The check threshold also must be > 0
            $new_check_threshold=intval($_POST['check_threshold']);
            if( $new_check_threshold > 0 ){
                $this->conf->options['check_threshold'] = $new_check_threshold;
            }
            
            $this->conf->options['mark_broken_links'] = !empty($_POST['mark_broken_links']);
            $new_broken_link_css = trim($_POST['broken_link_css']);
            $this->conf->options['broken_link_css'] = $new_broken_link_css;
            
            $this->conf->options['mark_removed_links'] = !empty($_POST['mark_removed_links']);
            $new_removed_link_css = trim($_POST['removed_link_css']);
            $this->conf->options['removed_link_css'] = $new_removed_link_css;
            
            $this->conf->options['nofollow_broken_links'] = !empty($_POST['nofollow_broken_links']);

            $this->conf->options['exclusion_list'] = array_filter( 
				preg_split( 
					'/[\s\r\n]+/',				//split on newlines and whitespace 
					$_POST['exclusion_list'], 
					-1,
					PREG_SPLIT_NO_EMPTY			//skip empty values
				) 
			);
                
            //Parse the custom field list
            $new_custom_fields = array_filter( 
				preg_split( '/[\s\r\n]+/', $_POST['blc_custom_fields'], -1, PREG_SPLIT_NO_EMPTY )
			);
            
			//Calculate the difference between the old custom field list and the new one (used later)
            $diff1 = array_diff( $new_custom_fields, $this->conf->options['custom_fields'] );
            $diff2 = array_diff( $this->conf->options['custom_fields'], $new_custom_fields );
            $this->conf->options['custom_fields'] = $new_custom_fields;
            
            //Temporary file directory
            $this->conf->options['custom_tmp_dir'] = trim( stripslashes(strval($_POST['custom_tmp_dir'])) );
            
            //HTTP timeout
            $new_timeout = intval($_POST['timeout']);
            if( $new_timeout > 0 ){
                $this->conf->options['timeout'] = $new_timeout ;
            }
            
            //Server load limit 
            if ( isset($_POST['server_load_limit']) ){
            	$this->conf->options['server_load_limit'] = floatval($_POST['server_load_limit']);
            	if ( $this->conf->options['server_load_limit'] < 0 ){
					$this->conf->options['server_load_limit'] = 0;
				}
				
				$this->conf->options['enable_load_limit'] = $this->conf->options['server_load_limit'] > 0;
            }
            
            //When to run the checker
            $this->conf->options['run_in_dashboard'] = !empty($_POST['run_in_dashboard']);
            $this->conf->options['run_via_cron'] = !empty($_POST['run_via_cron']);
            
            //Email notifications on/off
            $email_notifications = !empty($_POST['send_email_notifications']);
            if ( $email_notifications && ! $this->conf->options['send_email_notifications']){
            	/*
            	The plugin should only send notifications about links that have become broken
				since the time when email notifications were turned on. If we don't do this,
				the first email notification will be sent nigh-immediately and list *all* broken
				links that the plugin currently knows about.
				*/
				$this->options['last_notification_sent'] = time();
			}
            $this->conf->options['send_email_notifications'] = $email_notifications;
            
			//Make settings that affect our Cron events take effect immediately
			$this->setup_cron_events();
			
            $this->conf->save_options();
			
			/*
			 If the list of custom fields was modified then we MUST resynchronize or
			 custom fields linked with existing posts may not be detected. This is somewhat
			 inefficient.  
			 */
			if ( ( count($diff1) > 0 ) || ( count($diff2) > 0 ) ){
				$manager = $blc_container_registry->get_manager('custom_field');
				if ( !is_null($manager) ){
					$manager->resynch();
					blc_got_unsynched_items();
				}
			}
			
			//Redirect back to the settings page
			$base_url = remove_query_arg( array('_wpnonce', 'noheader', 'updated', 'error', 'action', 'message') );
			wp_redirect( add_query_arg( array( 'settings-updated' => true), $base_url ) );
        }
        
        //Show a confirmation message when settings are saved. 
        if ( !empty($_GET['settings-updated']) ){
        	echo '<div id="message" class="updated fade"><p><strong>',__('Settings saved.', 'broken-link-checker'), '</strong></p></div>';
        	
        }
        
		$debug = $this->get_debug_info();
		
		$this->print_uservoice_widget();
		?>
		
        <div class="wrap"><h2><?php _e('Broken Link Checker Options', 'broken-link-checker'); ?></h2>
		
        <form name="link_checker_options" method="post" action="<?php 
			echo admin_url('options-general.php?page=link-checker-settings&noheader=1'); 
		?>">
        <?php 
			wp_nonce_field('link-checker-options');
		?>

        <table class="form-table">

        <tr valign="top">
        <th scope="row">
			<?php _e('Status','broken-link-checker'); ?>
			<br>
			<a href="javascript:void(0)" id="blc-debug-info-toggle"><?php _e('Show debug info', 'broken-link-checker'); ?></a>
		</th>
        <td>

        <div id='wsblc_full_status'>
            <br/><br/><br/>
        </div>
        <script type='text/javascript'>
        	(function($){
				
				function blcUpdateStatus(){
					$.getJSON(
						"<?php echo admin_url('admin-ajax.php'); ?>",
						{
							'action' : 'blc_full_status'
						},
						function (data, textStatus){
							if ( data && ( typeof(data['text']) != 'undefined' ) ){
								$('#wsblc_full_status').html(data.text);
							} else {
								$('#wsblc_full_status').html('<?php _e('[ Network error ]', 'broken-link-checker'); ?>');
							}
							
							setTimeout(blcUpdateStatus, 10000); //...update every 10 seconds							
						}
					);
				}
				blcUpdateStatus();//Call it the first time
				
			})(jQuery);
        </script>
        <?php //JHS: Recheck all posts link: ?>
        <p><input class="button" type="button" name="recheckbutton" 
				  value="<?php _e('Re-check all pages', 'broken-link-checker'); ?>" 
				  onclick="location.replace('<?php echo basename($_SERVER['PHP_SELF']); ?>?page=link-checker-settings&amp;recheck=true')" />
		</p>
        
        <table id="blc-debug-info">
        <?php
        
        //Output the debug info in a table
		foreach( $debug as $key => $value ){
			printf (
				'<tr valign="top" class="blc-debug-item-%s"><th scope="row">%s</th><td>%s<div class="blc-debug-message">%s</div></td></tr>',
				$value['state'],
				$key,
				$value['value'], 
				( array_key_exists('message', $value)?$value['message']:'')
			);
		}
        ?>
        </table>
        
        </td>
        </tr>

        <tr valign="top">
        <th scope="row"><?php _e('Check each link','broken-link-checker'); ?></th>
        <td>

		<?php
			printf( 
				__('Every %s hours','broken-link-checker'),
				sprintf(
					'<input type="text" name="check_threshold" id="check_threshold" value="%d" size="5" maxlength="5" />',
					$this->conf->options['check_threshold']
				)
			 ); 
		?>
        <br/>
        <span class="description">
        <?php _e('Existing links will be checked this often. New links will usually be checked ASAP.', 'broken-link-checker'); ?>
        </span>

        </td>
        </tr>

        <tr valign="top">
        <th scope="row"><?php _e('Broken link CSS','broken-link-checker'); ?></th>
        <td>
        	<label for='mark_broken_links'>
        		<input type="checkbox" name="mark_broken_links" id="mark_broken_links"
            	<?php if ($this->conf->options['mark_broken_links']) echo ' checked="checked"'; ?>/>
            	<?php _e('Apply <em>class="broken_link"</em> to broken links', 'broken-link-checker'); ?>
			</label>
			<br/>
        <textarea name="broken_link_css" id="broken_link_css" cols='45' rows='4'/><?php
            if( isset($this->conf->options['broken_link_css']) )
                echo $this->conf->options['broken_link_css'];
        ?></textarea>

        </td>
        </tr>
        
        <tr valign="top">
        <th scope="row"><?php _e('Removed link CSS','broken-link-checker'); ?></th>
        <td>
        	<label for='mark_removed_links'>
        		<input type="checkbox" name="mark_removed_links" id="mark_removed_links"
            	<?php if ($this->conf->options['mark_removed_links']) echo ' checked="checked"'; ?>/>
            	<?php _e('Apply <em>class="removed_link"</em> to unlinked links', 'broken-link-checker'); ?>
			</label>
			<br/>
        <textarea name="removed_link_css" id="removed_link_css" cols='45' rows='4'/><?php
            if( isset($this->conf->options['removed_link_css']) )
                echo $this->conf->options['removed_link_css'];
        ?></textarea>

        </td>
        </tr>
        
        <tr valign="top">
        <th scope="row"><?php _e('Broken link SEO','broken-link-checker'); ?></th>
        <td>
        	<label for='nofollow_broken_links'>
        		<input type="checkbox" name="nofollow_broken_links" id="nofollow_broken_links"
            	<?php if ($this->conf->options['nofollow_broken_links']) echo ' checked="checked"'; ?>/>
            	<?php _e('Apply <em>rel="nofollow"</em> to broken links', 'broken-link-checker'); ?>
			</label>
        </td>
        </tr>

        <tr valign="top">
        <th scope="row"><?php _e('Exclusion list', 'broken-link-checker'); ?></th>
        <td><?php _e("Don't check links where the URL contains any of these words (one per line) :", 'broken-link-checker'); ?><br/>
        <textarea name="exclusion_list" id="exclusion_list" cols='45' rows='4' wrap='off'/><?php
            if( isset($this->conf->options['exclusion_list']) )
                echo implode("\n", $this->conf->options['exclusion_list']);
        ?></textarea>

        </td>
        </tr>
        
        <tr valign="top">
        <th scope="row"><?php _e('Custom fields', 'broken-link-checker'); ?></th>
        <td><?php _e('Check URLs entered in these custom fields (one per line) :', 'broken-link-checker'); ?><br/>
        <textarea name="blc_custom_fields" id="blc_custom_fields" cols='45' rows='4' /><?php
            if( isset($this->conf->options['custom_fields']) )
                echo implode("\n", $this->conf->options['custom_fields']);
        ?></textarea>

        </td>
        </tr>
        
        <tr valign="top">
        <th scope="row"><?php _e('E-mail notifications', 'broken-link-checker'); ?></th>
        <td>
        	<p style="margin-top: 0px;">
        	<label for='send_email_notifications'>
        		<input type="checkbox" name="send_email_notifications" id="send_email_notifications"
            	<?php if ($this->conf->options['send_email_notifications']) echo ' checked="checked"'; ?>/>
            	<?php _e('Send me e-mail notifications about newly detected broken links', 'broken-link-checker'); ?>
			</label><br>
			</p>
        </td>
        </tr>
        
        </table>
        
        <h3><?php _e('Advanced','broken-link-checker'); ?></h3>
        
        <table class="form-table">
        
        <tr valign="top">
        <th scope="row"><?php _e('Timeout', 'broken-link-checker'); ?></th>
        <td>

		<?php
		
		printf(
			__('%s seconds', 'broken-link-checker'),
			sprintf(
				'<input type="text" name="timeout" id="blc_timeout" value="%d" size="5" maxlength="3" />', 
				$this->conf->options['timeout']
			)
		);
		
		?>
        <br/><span class="description">
        <?php _e('Links that take longer than this to load will be marked as broken.','broken-link-checker'); ?> 
		</span>

        </td>
        </tr>
        
        <tr valign="top">
        <th scope="row"><?php _e('Link monitor', 'broken-link-checker'); ?></th>
        <td>
			<label for='run_in_dashboard'>
				<p>	
	        		<input type="checkbox" name="run_in_dashboard" id="run_in_dashboard"
	            	<?php if ($this->conf->options['run_in_dashboard']) echo ' checked="checked"'; ?>/>
	            	<?php _e('Run continuously while the Dashboard is open', 'broken-link-checker'); ?>
            	</p>
			</label>
			
			<label for='run_via_cron'>
				<p>
	        		<input type="checkbox" name="run_via_cron" id="run_via_cron"
	            	<?php if ($this->conf->options['run_via_cron']) echo ' checked="checked"'; ?>/>
	            	<?php _e('Run hourly in the background', 'broken-link-checker'); ?>
            	</p>
			</label>		

        </td>
        </tr>
        
        <tr valign="top">
        <th scope="row"><?php _e('Max. execution time', 'broken-link-checker'); ?></th>
        <td>

		<?php
		
		printf(
			__('%s seconds', 'broken-link-checker'),
			sprintf(
				'<input type="text" name="max_execution_time" id="max_execution_time" value="%d" size="5" maxlength="5" />', 
				$this->conf->options['max_execution_time']
			)
		);
		
		?>
        <br/><span class="description">
        <?php
        
        _e('The plugin works by periodically launching a background job that parses your posts for links, checks the discovered URLs, and performs other time-consuming tasks. Here you can set for how long, at most, the link monitor may run each time before stopping.', 'broken-link-checker');
		
		?> 
		</span>

        </td>
        </tr>
        
        <tr valign="top">
        <th scope="row">
			<a name='lockfile_directory'></a><?php _e('Custom temporary directory', 'broken-link-checker'); ?></th>
        <td>

        <input type="text" name="custom_tmp_dir" id="custom_tmp_dir"
            value="<?php echo htmlspecialchars( $this->conf->options['custom_tmp_dir'] ); ?>" size='53' maxlength='500'/>
            <?php 
            if ( !empty( $this->conf->options['custom_tmp_dir'] ) ) {
				if ( @is_dir( $this->conf->options['custom_tmp_dir'] ) ){
					if ( @is_writable( $this->conf->options['custom_tmp_dir'] ) ){
						echo "<strong>", __('OK', 'broken-link-checker'), "</strong>";
					} else {
						echo '<span class="error">';
						_e("Error : This directory isn't writable by PHP.", 'broken-link-checker');
						echo '</span>';
					}
				} else {
					echo '<span class="error">';
					_e("Error : This directory doesn't exist.", 'broken-link-checker');
					echo '</span>';
				}
			}
			
			?>
        <br/>
        <span class="description">
        <?php _e('Set this field if you want the plugin to use a custom directory for its lockfiles. Otherwise, leave it blank.','broken-link-checker'); ?>
        </span>

        </td>
        </tr>
        
                <tr valign="top">
        <th scope="row"><?php _e('Server load limit', 'broken-link-checker'); ?></th>
        <td>
		<?php
		
		$load = $this->get_server_load();
		$available = !empty($load);
		
		if ( $available ){
			$value = !empty($this->conf->options['server_load_limit'])?sprintf('%.2f', $this->conf->options['server_load_limit']):'';
			printf(
				'<input type="text" name="server_load_limit" id="server_load_limit" value="%s" size="5" maxlength="5"/> ',
				$value
			);
			
			?>
		Current load : <span id='wsblc_current_load'>...</span>
        <script type='text/javascript'>
        	(function($){
				
				function blcUpdateLoad(){
					$.get(
						"<?php echo admin_url('admin-ajax.php'); ?>",
						{
							'action' : 'blc_current_load'
						},
						function (data, textStatus){
							$('#wsblc_current_load').html(data);
							
							setTimeout(blcUpdateLoad, 10000); //...update every 10 seconds							
						}
					);
				}
				blcUpdateLoad();//Call it the first time
				
			})(jQuery);
        </script>
			<?php
			
			echo '<br/><span class="description">';
	        printf(
	        	__(
					'Link checking will be suspended if the average <a href="%s">server load</a> rises above this number. Leave this field blank to disable load limiting.', 
					'broken-link-checker'
				),
				'http://en.wikipedia.org/wiki/Load_(computing)'
	        );
	        echo '</span>';
        
        } else {
        	echo '<input type="text" disabled="disabled" value="Not available" size="13"/><br>';
        	echo '<span class="description">';
			_e('Load limiting only works on Linux-like systems where <code>/proc/loadavg</code> is present and accessible.', 'broken-link-checker');
			echo '</span>';
		}
		?> 
        </td>
        </tr>
        
        </table>
        
        <p class="submit"><input type="submit" name="submit" class='button-primary' value="<?php _e('Save Changes') ?>" /></p>
        </form>
        </div>
        
        <script type='text/javascript'>
        	jQuery(function($){
        		var toggleButton = $('#blc-debug-info-toggle'); 
        		
				toggleButton.click(function(){
					
					var box = $('#blc-debug-info'); 
					box.toggle();
					if( box.is(':visible') ){
						toggleButton.text('<?php _e('Hide debug info', 'broken-link-checker'); ?>');
					} else {
						toggleButton.text('<?php _e('Show debug info', 'broken-link-checker'); ?>');
					}
					
				});
			});
		</script>
        <?php
    }
    
    function options_page_css(){
    	wp_enqueue_style('blc-links-page', plugin_dir_url($this->loader) . 'css/options-page.css' );
    	wp_enqueue_style('blc-uservoice', plugin_dir_url($this->loader) . 'css/uservoice.css' );
	}
	

    function links_page(){
        global $wpdb, $blc_link_query;
        
        //Sanity check : Make sure the plugin's tables are all set up.
        if ( $this->db_version != $this->conf->options['current_db_version'] ) {
        	printf(
				__("Error: The plugin's database tables are not up to date! (Current version : %d, expected : %d)", 'broken-link-checker'),
				$this->conf->options['current_db_version'],
				$this->db_version
			);
			return;
		}
        
        $action = !empty($_POST['action'])?$_POST['action']:'';
        if ( intval($action) == -1 ){
        	//Try the second bulk actions box
			$action = !empty($_POST['action2'])?$_POST['action2']:'';
		}
        
        //Get the list of link IDs selected via checkboxes
        $selected_links = array();
		if ( isset($_POST['selected_links']) && is_array($_POST['selected_links']) ){
			//Convert all link IDs to integers (non-numeric entries are converted to zero)
			$selected_links = array_map('intval', $_POST['selected_links']);
			//Remove all zeroes
			$selected_links = array_filter($selected_links);
		}
        
        $message = '';
        $msg_class = 'updated';
        
        //Run the selected bulk action, if any
        if ( $action == 'create-custom-filter' ){
        	list($message, $msg_class) = $this->do_create_custom_filter(); 
		} elseif ( $action == 'delete-custom-filter' ){
			list($message, $msg_class) = $this->do_delete_custom_filter();
		} elseif ($action == 'bulk-delete-sources') {
			list($message, $msg_class) = $this->do_bulk_delete_sources($selected_links);
		} elseif ($action == 'bulk-unlink') {
			list($message, $msg_class) = $this->do_bulk_unlink($selected_links);
		} elseif ($action == 'bulk-deredirect') {
			list($message, $msg_class) = $this->do_bulk_deredirect($selected_links);
		} elseif ($action == 'bulk-recheck') {
			list($message, $msg_class) = $this->do_bulk_recheck($selected_links);
		}
		
		if ( !empty($message) ){
			echo '<div id="message" class="'.$msg_class.' fade"><p>'.$message.'</p></div>';
		}
		
        //Load custom filters, if any
        $blc_link_query->load_custom_filters();
		
		//Calculate the number of links matching each filter
		$blc_link_query->count_filter_results();
		
		$filters = $blc_link_query->get_filters();

		//Get the selected filter (defaults to displaying broken links)
		$filter_id = isset($_GET['filter_id'])?$_GET['filter_id']:'broken';
		$current_filter = $blc_link_query->get_filter($filter_id);
		if ( empty($current_filter) ){
			$filter_id = 'broken';
			$current_filter = $blc_link_query->get_filter('broken');
			
		}
		
		//Get the desired page number (must be > 0) 
		$page = isset($_GET['paged'])?intval($_GET['paged']):'1';
		if ($page < 1) $page = 1;
		
		//Links per page [1 - 200]
		$per_page = isset($_GET['per_page'])?intval($_GET['per_page']):'30';
		if ($per_page < 1){
			$per_page = 30;
		} else if ($per_page > 200){
			$per_page = 200;
		}
		
		//Calculate the maximum number of pages.
		$max_pages = ceil($current_filter['count'] / $per_page);
		
		//Select the required links
		$extra_params = array(
			'offset' => ( ($page-1) * $per_page ),
			'max_results' => $per_page,
			'purpose' => BLC_FOR_DISPLAY,
		);
		$links = $blc_link_query->get_filter_links($current_filter, $extra_params);
		
		//Error?		
		if ( empty($links) && !empty($wpdb->last_error) ){
			printf( __('Database error : %s', 'broken-link-checker'), $wpdb->last_error);
		}
		
		//If the current request is a user-initiated search query (either directly or 
		//via a custom filter), save the search params. They can later be used to pre-fill
		//the search form or build a new/modified custom filter. 
		if ( !empty($current_filter['custom']) || ($filter_id == 'search') ){
			$search_params = $blc_link_query->get_search_params($current_filter);
		}
		
		//Figure out what the "safe" URL to acccess the current page would be.
		//This is used by the bulk action form. 
		$special_args = array('_wpnonce', '_wp_http_referer', 'action', 'selected_links');
		$neutral_current_url = remove_query_arg($special_args);
		
		//Add the "Feedback" widget to the screen meta bar
		$this->print_uservoice_widget();
        ?>
        
<script type='text/javascript'>
	var blc_current_filter = '<?php echo $filter_id; ?>';
	var blc_is_broken_filter = <?php
		if ( ($filter_id == 'broken') || ( isset($current_filter['params']['s_filter']) && ($current_filter['params']['s_filter'] = 'broken') ) ){
			echo 'true';
		} else {
			echo 'false';
		}
	?>;
</script>
        
<div class="wrap">
<h2><?php
	//Output a header matching the current filter
	if ( $current_filter['count'] > 0 ){
		echo $current_filter['heading'] . " (<span class='current-link-count'>{$current_filter['count']}</span>)";
	} else {
		echo $current_filter['heading_zero'] . "<span class='current-link-count'></span>";
	}
?>
</h2>
	<ul class="subsubsub">
    	<?php
    		//Construct a submenu of filter types
    		$items = array();
			foreach ($filters as $filter => $data){
				if ( !empty($data['hidden']) ) continue; //skip hidden filters
																
				$class = $number_class = '';
				
				if ( $filter_id == $filter ) {
					$class = 'class="current"';
					$number_class = 'current-link-count';	
				}
				
				$items[] = "<li><a href='tools.php?page=view-broken-links&filter_id=$filter' $class>
					{$data['name']}</a> <span class='count'>(<span class='$number_class'>{$data['count']}</span>)</span>";
			}
			echo implode(' |</li>', $items);
			unset($items);
		?>
	</ul>
	
<?php
		//Display the "Search" form and associated buttons.
		//The form requires the $filter_id and $search_params variables to be set.
		include dirname($this->loader) . '/includes/admin/search-form.php';


		//Do we have any links to display?
        if( $links && ( count($links) > 0 ) ) {
?>
<!-- The link list -->
<form id="blc-bulk-action-form" action="<?php echo $neutral_current_url;  ?>" method="post">
	<?php 
		wp_nonce_field('bulk-action');
		
		$bulk_actions = array(
			'-1' => __('Bulk Actions', 'broken-link-checker'),
			"bulk-recheck" => __('Recheck', 'broken-link-checker'),
			"bulk-deredirect" => __('Fix redirects', 'broken-link-checker'),
			"bulk-unlink" => __('Unlink', 'broken-link-checker'),
			"bulk-delete-sources" => __('Delete sources', 'broken-link-checker'),
		);
		
		$bulk_actions_html = '';
		foreach($bulk_actions as $value => $name){
			$bulk_actions_html .= sprintf('<option value="%s">%s</option>', $value, $name);
		} 
	?>
	
	<div class='tablenav'>
		<div class="alignleft actions">
			<select name="action" id="blc-bulk-action">
				<?php echo $bulk_actions_html; ?>
			</select>
			<input type="submit" name="doaction" id="doaction" value="<?php echo esc_attr(__('Apply', 'broken-link-checker')); ?>" class="button-secondary action">
		</div>
		<?php
			//Display pagination links 
			$page_links = paginate_links( array(
				'base' => add_query_arg( 'paged', '%#%' ),
				'format' => '',
				'prev_text' => __('&laquo;'),
				'next_text' => __('&raquo;'),
				'total' => $max_pages,
				'current' => $page
			));
			
			if ( $page_links ) { 
				echo '<div class="tablenav-pages">';
				$page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of <span class="current-link-count">%s</span>', 'broken-link-checker' ) . '</span>%s',
					number_format_i18n( ( $page - 1 ) * $per_page + 1 ),
					number_format_i18n( min( $page * $per_page, $current_filter['count'] ) ),
					number_format_i18n( $current_filter['count'] ),
					$page_links
				); 
				echo $page_links_text; 
				echo '</div>';
			}
		?>
	
	</div>
            <table class="widefat" id="blc-links">
                <thead>
                <tr>

				<th scope="col" id="cb" class="check-column">
					<input type="checkbox">
				</th>
                <th scope="col" class="column-title blc-column-source"><?php _e('Source', 'broken-link-checker'); ?></th>
                <th scope="col" class="blc-column-link-text"><?php _e('Link Text', 'broken-link-checker'); ?></th>
                <th scope="col" class="blc-column-url"><?php _e('URL', 'broken-link-checker'); ?></th>
                </tr>
                </thead>
                <tbody id="the-list">
            <?php
            $rowclass = ''; $rownum = 0;
            foreach ($links as $link) {
            	$rownum++;
            	
            	$rowclass = ($rownum % 2)? 'alternate' : '';
            	$excluded = $this->is_excluded( $link->url ); 
            	if ( $excluded ) $rowclass .= ' blc-excluded-link';
            	
            	if ( $link->redirect_count > 0){
					$rowclass .= ' blc-redirect';
				}
            	
            	$days_broken = 0;
            	if ( $link->broken ){
					$rowclass .= ' blc-broken-link';
					
					//Add a highlight to broken links that appear to be permanently broken
					$days_broken = intval( (time() - $link->first_failure) / (3600*24) );
					if ( $days_broken >= $this->conf->options['failure_duration_threshold'] ){
						$rowclass .= ' blc-permanently-broken';
						if ( $this->conf->options['highlight_permanent_failures'] ){
							$rowclass .= ' blc-permanently-broken-hl';
						}
					}
				}
				
            	
                ?>
                <tr id='<?php echo "blc-row-" . $link->link_id; ?>' class='blc-row <?php echo $rowclass; ?>' days_broken="<?php echo $days_broken; ?>">
                
				<th class="check-column" scope="row">
					<input type="checkbox" name="selected_links[]" value="<?php echo $link->link_id; ?>">
				</th>
				                
                <td class='post-title column-title'>
                	<span class='blc-link-id' style='display:none;'><?php echo $link->link_id; ?></span> 	
               <?php
					
				//Pick one link instance to display in the table
				$instance = null;
				$instances = $link->get_instances();
				
				if ( !empty($instances) ){
					//Try to find one that matches the selected link type, if any
					if( !empty($search_params['s_link_type']) ){
						foreach($instances as $candidate){
							if ( ($candidate->container_type == $search_params['s_link_type']) || ($candidate->parser_type == $search_params['s_link_type']) ){
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

				  	?>
				</td>
                <td class='blc-link-text'><?php
                //The "Link text" column
				if ( !is_null($instance) ){
					echo $instance->ui_get_link_text();
				} else {
					echo '<em>N/A</em>';
				}
				?>
				</td>
                <td class='column-url'>
                    <a href="<?php print esc_attr($link->url); ?>" target='_blank' class='blc-link-url' title="<?php echo esc_attr($link->url); ?>">
                    	<?php print blcUtility::truncate($link->url, 50, ''); ?></a>
                    <input type='text' id='link-editor-<?php print $rownum; ?>' 
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

                </tr>
                <!-- Link details -->
                <tr id='<?php print "link-details-$rownum"; ?>' style='display:none;' class='blc-link-details'>
					<td colspan='4'><?php $this->link_details_row($link); ?></td>
				</tr><?php
            }
            ?></tbody></table>
            
	<div class="tablenav">			
		<div class="alignleft actions">
			<select name="action2"  id="blc-bulk-action2">
				<?php echo $bulk_actions_html; ?>
			</select>
			<input type="submit" name="doaction2" id="doaction2" value="<?php echo esc_attr(__('Apply', 'broken-link-checker')); ?>" class="button-secondary action">
		</div><?php
            
            //Also display pagination links at the bottom
            if ( $page_links ) {
				echo '<div class="tablenav-pages">';
				$page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of <span class="current-link-count">%s</span>', 'broken-link-checker' ) . '</span>%s',
					number_format_i18n( ( $page - 1 ) * $per_page + 1 ),
					number_format_i18n( min( $page * $per_page, $current_filter['count'] ) ),
					number_format_i18n( $current_filter['count'] ),
					$page_links
				); 
				echo $page_links_text; 
				echo '</div>';
			}
?>
	</div>
	
</form>
<?php

        }; //End of the links table & assorted nav stuff
        
?>

		<?php
			//Load assorted JS event handlers and other shinies
			include dirname($this->loader) . '/includes/admin/links-page-js.php'; 
		?>
</div>
        <?php
    } //Function ends
    
  /**
   * Create a custom link filter using params passed in $_POST.
   *
   * @uses $_POST
   * @uses $_GET to replace the current filter ID (if any) with that of the newly created filter.   
   *
   * @return array Message and the CSS class to apply to the message.  
   */
    function do_create_custom_filter(){
		//Create a custom filter!
		global $blc_link_query;
    	check_admin_referer( 'create-custom-filter' );
    	$msg_class = 'updated';
    	
    	//Filter name must be set
		if ( empty($_POST['name']) ){
			$message = __("You must enter a filter name!", 'broken-link-checker');
			$msg_class = 'error';
		//Filter parameters (a search query) must also be set
		} elseif ( empty($_POST['params']) ){
			$message = __("Invalid search query.", 'broken-link-checker');
			$msg_class = 'error';
		} else {
			//Save the new filter
			$filter_id = $blc_link_query->create_custom_filter($_POST['name'], $_POST['params']);
			
			if ( $filter_id ){
				//Saved
				$message = sprintf( __('Filter "%s" created', 'broken-link-checker'), $_POST['name']);
				//A little hack to make the filter active immediately
				$_GET['filter_id'] = $filter_id;			
			} else {
				//Error
				$message = sprintf( __("Database error : %s", 'broken-link-checker'), $wpdb->last_error);
				$msg_class = 'error';
			}
		}
		
		return array($message, $msg_class);
	}
	
  /**
   * Delete a custom link filter.
   *
   * @uses $_POST
   *
   * @return array Message and a CSS class to apply to the message. 
   */
	function do_delete_custom_filter(){
		//Delete an existing custom filter!
		global $blc_link_query;
		check_admin_referer( 'delete-custom-filter' );
		$msg_class = 'updated';
		
		//Filter ID must be set
		if ( empty($_POST['filter_id']) ){
			$message = __("Filter ID not specified.", 'broken-link-checker');
			$msg_class = 'error';
		} else {
			//Try to delete the filter
			if ( $blc_link_query->delete_custom_filter($_POST['filter_id']) ){
				//Success
				$message = __('Filter deleted', 'broken-link-checker');
			} else {
				//Either the ID is wrong or there was some other error
				$message = __('Database error : %s', 'broken-link-checker');
				$msg_class = 'error';
			}
		}
		
		return array($message, $msg_class);
	}
	
  /**
   * Modify multiple links to point to their target URLs.
   *
   * @param array $selected_links
   * @return array The message to display and its CSS class.
   */
	function do_bulk_deredirect($selected_links){
		//For all selected links, replace the URL with the final URL that it redirects to.
		
		$message = '';
		$msg_class = 'updated';
			
		check_admin_referer( 'bulk-action' );
		
		if ( count($selected_links) > 0 ) {	
			//Fetch all the selected links
			$links = blc_get_links(array(
				'link_ids' => $selected_links,
				'purpose' => BLC_FOR_EDITING,
			));
			
			if ( count($links) > 0 ) {
				$processed_links = 0;
				$failed_links = 0;
				
				//Deredirect all selected links
				foreach($links as $link){
					$rez = $link->deredirect();
					if ( !is_wp_error($rez) ){
						$processed_links++;
					} else {
						$failed_links++;
					}
				}	
				
				$message = sprintf(
					_n(
						'Replaced %d redirect with a direct link',
						'Replaced %d redirects with direct links',
						$processed_links, 
						'broken-link-checker'
					),
					$processed_links
				);			
				
				if ( $failed_links > 0 ) {
					$message .= '<br>' . sprintf(
						_n(
							'Failed to fix %d redirect', 
							'Failed to fix %d redirects',
							$failed_links,
							'broken-link-checker'
						),
						$failed_links
					);
					$msg_class = 'error';
				}
			} else {
				$message = __('None of the selected links are redirects!', 'broken-link-checker');
			}
		}
		
		return array($message, $msg_class);
	}
	
  /**
   * Unlink multiple links.
   *
   * @param array $selected_links
   * @return array Message and a CSS classname.
   */
	function do_bulk_unlink($selected_links){
		//Unlink all selected links.
		$message = '';
		$msg_class = 'updated';
			
		check_admin_referer( 'bulk-action' );
		
		if ( count($selected_links) > 0 ) {	
			
			//Fetch all the selected links
			$links = blc_get_links(array(
				'link_ids' => $selected_links,
				'purpose' => BLC_FOR_EDITING,
			));
			
			if ( count($links) > 0 ) {
				$processed_links = 0;
				$failed_links = 0;
				
				//Unlink (delete) each one
				foreach($links as $link){
					$rez = $link->unlink();
					if ( ($rez == false) || is_wp_error($rez) ){
						$failed_links++;
					} else {
						$processed_links++;
					}
				}	
				
				//This message is slightly misleading - it doesn't account for the fact that 
				//a link can be present in more than one post.
				$message = sprintf(
					_n(
						'%d link removed',
						'%d links removed',
						$processed_links, 
						'broken-link-checker'
					),
					$processed_links
				);			
				
				if ( $failed_links > 0 ) {
					$message .= '<br>' . sprintf(
						_n(
							'Failed to remove %d link', 
							'Failed to remove %d links',
							$failed_links,
							'broken-link-checker'
						),
						$failed_links
					);
					$msg_class = 'error';
				}
			}
		}
		
		return array($message, $msg_class);
	}
	
  /**
   * Delete posts, bookmarks and other items that contain any of the specified links.
   *
   * @param array $selected_links An array of link IDs
   * @return array Confirmation message and its CSS class.
   */
	function do_bulk_delete_sources($selected_links){
		global $blc_container_registry;
		
		$message = '';
		$msg_class = 'updated';
		
		//Delete posts, blogroll entries and any other link containers that contain any of the selected links.
		//
		//Note that once all cotnainers containing a particular link have been deleted,
		//there is no need to explicitly delete the link record itself. The hooks attached to 
		//the actions that execute when something is deleted (e.g. "post_deleted") will 
		//take care of that. 
					
		check_admin_referer( 'bulk-action' );
		
		if ( count($selected_links) > 0 ) {	
			$messages = array();
			
			//Fetch all the selected links
			$links = blc_get_links(array(
				'link_ids' => $selected_links,
				'load_instances' => true,
			));
			
			//Make a list of all containers associated with these links, with each container
			//listed only once.
			$containers = array();
			foreach($links as $link){
				$instances = $link->get_instances();
				foreach($instances as $instance){
					$key = $instance->container_type . '|' . $instance->container_id;
					$containers[$key] = array($instance->container_type, $instance->container_id);
				}
			}
			
			//Instantiate the containers
			$containers = blc_get_containers($containers);

			//Delete their associated entities
			$deleted = array();
			foreach($containers as $container){
				$container_type = $container->container_type;
				
				$rez = $container->delete_wrapped_object();
				
				if ( is_wp_error($rez) ){
					//Record error messages for later display
					$messages[] = $rez->get_error_message();
					$msg_class = 'error';
				} else {
					//Keep track of how many of each type were deleted.
					if ( isset($deleted[$container_type]) ){
						$deleted[$container_type]++;
					} else {
						$deleted[$container_type] = 1;
					}
				}
			}
			
			//Generate delete confirmation messages
			foreach($deleted as $container_type => $number){
				$messages[] = $blc_container_registry->ui_bulk_delete_message($container_type, $number);
			}
			
			if ( count($messages) > 0 ){
				$message = implode('<br>', $messages);
			} else {
				$message = __("Didn't find anything to delete!", 'broken-link-checker');
				$msg_class = 'error';
			}
		}
		
		return array($message, $msg_class);
	}
	
  /**
   * Mark multiple links as unchecked.
   *
   * @param array $selected_links An array of link IDs
   * @return array Confirmation nessage and the CSS class to use with that message.
   */
	function do_bulk_recheck($selected_links){
		global $wpdb;
		
		$message = '';
		$msg_class = 'updated';
		
		if ( count($selected_links) > 0 ){
			$q = "UPDATE {$wpdb->prefix}blc_links 
				  SET last_check_attempt = '0000-00-00 00:00:00' 
				  WHERE link_id IN (".implode(', ', $selected_links).")";
			$changes = $wpdb->query($q);
			
			$message = sprintf(
				_n(
					"%d link scheduled for rechecking",
					"%d links scheduled for rechecking",
					$changes, 
					'broken-link-chekcer'
				),
				$changes
			);
		}
		
		return array($message, $msg_class);
	}
	
    
	function links_page_css(){
		wp_enqueue_style('blc-links-page', plugin_dir_url($this->loader) . 'css/links-page.css' );
		wp_enqueue_style('blc-uservoice', plugin_dir_url($this->loader) . 'css/uservoice.css' );
	}
	
	function link_details_row($link){
		?>
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
						$this->fuzzy_delta($delta)
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
		<?php
	}
	
  /**
   * Format a time delta using a fuzzy format, e.g. 'less than a minute', '2 days', etc.
   *
   * @param int $delta Time period in seconds.
   * @return string
   */
	function fuzzy_delta($delta){
		$ONE_MINUTE = 60;
		$ONE_HOUR = 60 * $ONE_MINUTE;
		$ONE_DAY = 24 * $ONE_HOUR;
		$ONE_MONTH = $ONE_DAY * 3652425 / 120000;
		$ONE_YEAR = $ONE_DAY * 3652425 / 10000;
		
		if ( $delta < $ONE_MINUTE ){
			return __('less than a minute', 'broken-link-checker');
		}
		
		if ( $delta < $ONE_HOUR ){
			$minutes = intval($delta / $ONE_MINUTE);
			
			return sprintf(
				_n(
					'%d minute',
					'%d minutes',
					$minutes,
					'broken-link-checker'
				),
				$minutes
			);
		}
		
		if ( $delta < $ONE_DAY ){
			$hours = intval($delta / $ONE_HOUR);
			
			return sprintf(
				_n(
					'%d hour',
					'%d hours',
					$hours,
					'broken-link-checker'
				),
				$hours
			);
		}
		
		if ( $delta < $ONE_MONTH ){
			$days = intval($delta / $ONE_DAY);
			$hours = intval( ($delta - $days * $ONE_DAY)/$ONE_HOUR );
			 
			$ret = sprintf(
				_n(
					'%d day',
					'%d days',
					$days,
					'broken-link-checker'
				),
				$days
			);
			
			
			if ( ($days < 2) && ($hours > 0) ){
				$ret .= ' ' . sprintf(
					_n(
						'%d hour',
						'%d hours',
						$hours,
						'broken-link-checker'
					),
					$hours
				); 
			}
			
			return $ret;
		}
		
		
		$months = intval( $delta / $ONE_MONTH );
		$days = intval( ($delta - $months * $ONE_MONTH)/$ONE_DAY );
		 
		$ret = sprintf(
			_n(
				'%d month',
				'%d months',
				$months,
				'broken-link-checker'
			),
			$months
		);
		
		if ( $days > 0 ){
			$ret .= ' ' . sprintf(
				_n(
					'%d day',
					'%d days',
					$days,
					'broken-link-checker'
				),
				$days
			); 
		}
		
		return $ret;
	}
	
	function start_timer(){
		$this->execution_start_time = microtime_float();
	}
	
	function execution_time(){
		return microtime_float() - $this->execution_start_time;
	}
	
  /**
   * The main worker function that does all kinds of things.
   *
   * @return void
   */
	function work(){
		global $wpdb;
		
		//Sanity check : make sure the DB is all set up 
    	if ( $this->db_version != $this->conf->options['current_db_version'] ) {
    		//FB::error("The plugin's database tables are not up to date! Stop.");
			return;
		}
		
		if ( !$this->acquire_lock() ){
			//FB::warn("Another instance of BLC is already working. Stop.");
			return;
		}
		
		if ( $this->server_too_busy() ){
			//FB::warn("Server is too busy. Stop.");
			return;
		}
		
		$this->start_timer();
		
		$max_execution_time = $this->conf->options['max_execution_time'];
	
		/*****************************************
						Preparation
		******************************************/
		// Check for safe mode
		if( blcUtility::is_safe_mode() ){
		    // Do it the safe mode way - obey the existing max_execution_time setting
		    $t = ini_get('max_execution_time');
		    if ($t && ($t < $max_execution_time)) 
		    	$max_execution_time = $t-1;
		} else {
		    // Do it the regular way
		    @set_time_limit( $max_execution_time * 2 ); //x2 should be plenty, running any longer would mean a glitch.
		}
		
		//Don't stop the script when the connection is closed
		ignore_user_abort( true );
		
		//Close the connection as per http://www.php.net/manual/en/features.connection-handling.php#71172
		//This reduces resource usage and may solve the mysterious slowdowns certain users have 
		//encountered when activating the plugin.
		//(Disable when debugging or you won't get the FirePHP output)
		if ( !constant('BLC_DEBUG') ){
			@ob_end_clean(); //Discard the existing buffer, if any
	 		header("Connection: close");
			ob_start();
			echo ('Connection closed'); //This could be anything
			$size = ob_get_length();
			header("Content-Length: $size");
	 		ob_end_flush(); // Strange behaviour, will not work
	 		flush();        // Unless both are called !
 		}
 		
		$orphans_possible = false;
		$still_need_resynch = $this->conf->options['need_resynch'];
		
		/*****************************************
				Parse posts and bookmarks
		******************************************/
		
		if ( $still_need_resynch ) {
			
			//FB::log("Looking for containers that need parsing...");
			
			while( $containers = blc_get_unsynched_containers(50) ){
				//FB::log($containers, 'Found containers');
				
				foreach($containers as $container){
					//FB::log($container, "Parsing container");
					$container->synch();
					
					//Check if we still have some execution time left
					if( $this->execution_time() > $max_execution_time ){
						//FB::log('The alloted execution time has run out');
						blc_cleanup_links();
						$this->release_lock();
						return;
					}
					
					//Check if the server isn't overloaded
					if ( $this->server_too_busy() ){
						//FB::log('Server overloaded, bailing out.');
						blc_cleanup_links();
						$this->release_lock();
						return;
					}
				}
				$orphans_possible = true;
			}
			
			//FB::log('No unparsed items found.');
			$still_need_resynch = false;
			
		} else {
			//FB::log('Resynch not required.');
		}
		
		/******************************************
				    Resynch done?
		*******************************************/
		if ( $this->conf->options['need_resynch'] && !$still_need_resynch ){
			$this->conf->options['need_resynch']  = $still_need_resynch;
			$this->conf->save_options();
		}
		
		/******************************************
				    Remove orphaned links
		*******************************************/
		
		if ( $orphans_possible ) {
			//FB::log('Cleaning up the link table.');
			blc_cleanup_links();
		}
		
		//Check if we still have some execution time left
		if( $this->execution_time() > $max_execution_time ){
			//FB::log('The alloted execution time has run out');
			$this->release_lock();
			return;
		}
		
		if ( $this->server_too_busy() ){
			//FB::log('Server overloaded, bailing out.');
			$this->release_lock();
			return;
		}
		
		/*****************************************
						Check links
		******************************************/
		while ( $links = $this->get_links_to_check(50) ){
		
			//Some unchecked links found
			//FB::log("Checking ".count($links)." link(s)");
			
			foreach ($links as $link) {
				//Does this link need to be checked? Excluded links aren't checked, but their URLs are still
				//tested periodically to see if they're still on the exlusion list.
        		if ( !$this->is_excluded( $link->url ) ) {
        			//Check the link.
        			//FB::log($link->url, "Checking link {$link->link_id}");
					$link->check( true );
				} else {
					//FB::info("The URL {$link->url} is excluded, skipping link {$link->link_id}.");
					$link->last_check_attempt = time();
					$link->save();
				}
				
				//Check if we still have some execution time left
				if( $this->execution_time() > $max_execution_time ){
					//FB::log('The alloted execution time has run out');
					$this->release_lock();
					return;
				}
				
				//Check if the server isn't overloaded
				if ( $this->server_too_busy() ){
					//FB::log('Server overloaded, bailing out.');
					$this->release_lock();
					return;
				}
			}
			
		}
		//FB::log('No links need to be checked right now.');
		
		$this->release_lock();
		//FB::log('All done.');
	}
	
  /**
   * This function is called when the plugin's cron hook executes.
   * Its only purpose is to invoke the worker function.
   *
   * @uses wsBrokenLinkChecker::work() 
   *
   * @return void
   */
	function cron_check_links(){
		$this->work();
	}
	
  /**
   * Retrieve links that need to be checked or re-checked.
   *
   * @param integer $max_results The maximum number of links to return. Defaults to 0 = no limit.
   * @param bool $count_only If true, only the number of found links will be returned, not the links themselves. 
   * @return int|array
   */
	function get_links_to_check($max_results = 0, $count_only = false){
		global $wpdb;
		
		$check_threshold = date('Y-m-d H:i:s', strtotime('-'.$this->conf->options['check_threshold'].' hours'));
		$recheck_threshold = date('Y-m-d H:i:s', time() - $this->conf->options['recheck_threshold']);
		
		//FB::log('Looking for links to check (threshold : '.$check_threshold.', recheck_threshold : '.$recheck_threshold.')...');
		
		//Select some links that haven't been checked for a long time or
		//that are broken and need to be re-checked again. Links that are
		//marked as "being checked" and have been that way for several minutes
		//can also be considered broken/buggy, so those will be selected 
		//as well.
		
		//Note : This is a slow query, but AFAIK there is no way to speed it up.
		//I could put an index on last_check_attempt, but that value is almost 
		//certainly unique for each row so it wouldn't be much better than a full table scan.
		if ( $count_only ){
			$q = "SELECT COUNT(*)\n";
		} else {
			$q = "SELECT *\n";
		}
		$q .= "FROM {$wpdb->prefix}blc_links
		      WHERE 
			  	( last_check_attempt < %s ) 
				OR 
		 	  	( 
					(broken = 1 OR being_checked = 1) 
					AND may_recheck = 1
					AND check_count < %d 
					AND last_check_attempt < %s 
				)";
		if ( !$count_only ){
			$q .= "\nORDER BY last_check_attempt ASC\n";
			if ( !empty($max_results) ){
				$q .= "LIMIT " . intval($max_results);
			}
		}
		
		$link_q = $wpdb->prepare(
			$q, 
			$check_threshold, 
			$this->conf->options['recheck_count'], 
			$recheck_threshold
		);
		//FB::log($link_q, "Find links to check");
	
		//If we just need the number of links, retrieve it and return
		if ( $count_only ){
			return $wpdb->get_var($link_q);
		}
		
		//Fetch the link data
		$link_data = $wpdb->get_results($link_q, ARRAY_A);
		if ( empty($link_data) ){
			return array();
		}
		
		//Instantiate blcLink objects for all fetched links
		$links = array();
		foreach($link_data as $data){
			$links[] = new blcLink($data);
		}
		
		return $links;
	}
	
  /**
   * Output the current link checker status in JSON format.
   * Ajax hook for the 'blc_full_status' action.
   *
   * @return void
   */
	function ajax_full_status( ){
		$status = $this->get_status();
		$text = $this->status_text( $status );
		
		echo json_encode( array(
			'text' => $text,
			'status' => $status, 
		 ) );
		
		die();
	}
	
  /**
   * Generates a status message based on the status info in $status
   *
   * @param array $status
   * @return string
   */
	function status_text( $status ){
		$text = '';
	
		if( $status['broken_links'] > 0 ){
			$text .= sprintf( 
				"<a href='%s' title='" . __('View broken links', 'broken-link-checker') . "'><strong>". 
					_n('Found %d broken link', 'Found %d broken links', $status['broken_links'], 'broken-link-checker') .
				"</strong></a>",
			  	admin_url('tools.php?page=view-broken-links'), 
				$status['broken_links']
			);
		} else {
			$text .= __("No broken links found.", 'broken-link-checker');
		}
		
		$text .= "<br/>";
		
		if( $status['unchecked_links'] > 0) {
			$text .= sprintf( 
				_n('%d URL in the work queue', '%d URLs in the work queue', $status['unchecked_links'], 'broken-link-checker'), 
				$status['unchecked_links'] );
		} else {
			$text .= __("No URLs in the work queue.", 'broken-link-checker');
		}
		
		$text .= "<br/>";
		if ( $status['known_links'] > 0 ){
			$text .= sprintf( 
				_n('Detected %d unique URL', 'Detected %d unique URLs', $status['known_links'], 'broken-link-checker') .
					' ' . _n('in %d link', 'in %d links', $status['known_instances'], 'broken-link-checker'),
				$status['known_links'],
				$status['known_instances']
			 );
			if ($this->conf->options['need_resynch']){
				$text .= ' ' . __('and still searching...', 'broken-link-checker');
			} else {
				$text .= '.';
			}
		} else {
			if ($this->conf->options['need_resynch']){
				$text .= __('Searching your blog for links...', 'broken-link-checker');
			} else {
				$text .= __('No links detected.', 'broken-link-checker');
			}
		}
		
		return $text;
	}
	
  /**
   * @uses wsBrokenLinkChecker::ajax_full_status() 
   *
   * @return void
   */
	function ajax_dashboard_status(){
		//Just display the full status.
		$this->ajax_full_status();
	}
	
  /**
   * Output the current average server load (over the last one-minute period).
   * Called via AJAX.
   *
   * @return void
   */
	function ajax_current_load(){
		$load = $this->get_server_load();
		if ( empty($load) ){
			die('Unknown');
		}
		
		$one_minute = reset($load);
		printf('%.2f', $one_minute);
		die();
	}
	
  /**
   * Returns an array with various status information about the plugin. Array key reference: 
   *	check_threshold 	- date/time; links checked before this threshold should be checked again.
   *	recheck_threshold 	- date/time; broken links checked before this threshold should be re-checked.
   *	known_links 		- the number of detected unique URLs (a misleading name, yes).
   *	known_instances 	- the number of detected link instances, i.e. actual link elements in posts and other places.
   *	broken_links		- the number of detected broken links.	
   *	unchecked_links		- the number of URLs that need to be checked ASAP; based on check_threshold and recheck_threshold.
   *
   * @return array
   */
	function get_status(){
		global $wpdb, $blc_link_query;
		
		$check_threshold=date('Y-m-d H:i:s', strtotime('-'.$this->conf->options['check_threshold'].' hours'));
		$recheck_threshold=date('Y-m-d H:i:s', time() - $this->conf->options['recheck_threshold']);
		
		$q = "SELECT count(*) FROM {$wpdb->prefix}blc_links WHERE 1";
		$known_links = $wpdb->get_var($q);
		
		$q = "SELECT count(*) FROM {$wpdb->prefix}blc_instances WHERE 1";
		$known_instances = $wpdb->get_var($q);
		
		$broken_links = $blc_link_query->get_filter_links('broken', array('count_only' => true));
		
		$unchecked_links = $this->get_links_to_check(0, true);
		
		return array(
			'check_threshold' => $check_threshold,
			'recheck_threshold' => $recheck_threshold,
			'known_links' => $known_links,
			'known_instances' => $known_instances,
			'broken_links' => $broken_links,
			'unchecked_links' => $unchecked_links,
		 );
	}
	
	function ajax_work(){
		//Run the worker function 
		$this->work();
		die();
	}
	
  /**
   * AJAX hook for the "Not broken" button. Marks a link as broken and as a likely false positive.
   *
   * @return void
   */
	function ajax_discard(){
		if (!current_user_can('edit_others_posts') || !check_ajax_referer('blc_discard', false, false)){
			die( __("You're not allowed to do that!", 'broken-link-checker') );
		}
		
		if ( isset($_POST['link_id']) ){
			//Load the link
			$link = new blcLink( intval($_POST['link_id']) );
			
			if ( !$link->valid() ){
				printf( __("Oops, I can't find the link %d", 'broken-link-checker'), intval($_POST['link_id']) );
				die();
			}
			//Make it appear "not broken"
			$link->broken = false;  
			$link->false_positive = true;
			$link->last_check_attempt = time();
			$link->log = __("This link was manually marked as working by the user.", 'broken-link-checker');
			
			//Save the changes
			if ( $link->save() ){
				die( "OK" );
			} else {
				die( __("Oops, couldn't modify the link!", 'broken-link-checker') ) ;
			}
		} else {
			die( __("Error : link_id not specified", 'broken-link-checker') );
		}
	}
	
  /**
   * AJAX hook for the inline link editor on Tools -> Broken Links. 
   *
   * @return void
   */
	function ajax_edit(){
		if (!current_user_can('edit_others_posts') || !check_ajax_referer('blc_edit', false, false)){
			die( json_encode( array(
					'error' => __("You're not allowed to do that!", 'broken-link-checker') 
				 )));
		}
		
		if ( isset($_GET['link_id']) && !empty($_GET['new_url']) ){
			//Load the link
			$link = new blcLink( intval($_GET['link_id']) );
			
			if ( !$link->valid() ){
				die( json_encode( array(
					'error' => sprintf( __("Oops, I can't find the link %d", 'broken-link-checker'), intval($_GET['link_id']) ) 
				 )));
			}
			
			$new_url = $_GET['new_url'];
			$new_url = stripslashes($new_url);
			
			$parsed = @parse_url($parsed);
			if ( !$parsed ){
				die( json_encode( array(
					'error' => __("Oops, the new URL is invalid!", 'broken-link-checker') 
				 )));
			}
			
			//Try and edit the link
			//FB::log($new_url, "Ajax edit");
			//FB::log($_GET, "Ajax edit");
			$rez = $link->edit($new_url);
			
			if ( $rez === false ){
				die( json_encode( array(
					'error' => __("An unexpected error occured!", 'broken-link-checker')
				 )));
			} else {
				$response = array(
					'new_link_id' => $rez['new_link_id'],
					'cnt_okay' => $rez['cnt_okay'],
					'cnt_error' => $rez['cnt_error'],
					'errors' => array(),
				);
				foreach($rez['errors'] as $error){
					array_push( $response['errors'], implode(', ', $error->get_error_messages()) );
				}
				
				die( json_encode($response) );
			}
			
		} else {
			die( json_encode( array(
					'error' => __("Error : link_id or new_url not specified", 'broken-link-checker')
				 )));
		}
	}
	
  /**
   * AJAX hook for the "Unlink" action links in Tools -> Broken Links. 
   * Removes the specified link from all posts and other supported items.
   *
   * @return void
   */
	function ajax_unlink(){
		if (!current_user_can('edit_others_posts') || !check_ajax_referer('blc_unlink', false, false)){
			die( json_encode( array(
					'error' => __("You're not allowed to do that!", 'broken-link-checker') 
				 )));
		}
		
		if ( isset($_POST['link_id']) ){
			//Load the link
			$link = new blcLink( intval($_POST['link_id']) );
			
			if ( !$link->valid() ){
				die( json_encode( array(
					'error' => sprintf( __("Oops, I can't find the link %d", 'broken-link-checker'), intval($_POST['link_id']) ) 
				 )));
			}
			
			//Try and unlink it
			$rez = $link->unlink();
			
			if ( $rez === false ){
				die( json_encode( array(
					'error' => __("An unexpected error occured!", 'broken-link-checker')
				 )));
			} else {
				$response = array(
					'cnt_okay' => $rez['cnt_okay'],
					'cnt_error' => $rez['cnt_error'],
					'errors' => array(),
				);
				foreach($rez['errors'] as $error){
					array_push( $response['errors'], implode(', ', $error->get_error_messages()) );
				}
				
				die( json_encode($response) );
			}
			
		} else {
			die( json_encode( array(
					'error' => __("Error : link_id not specified", 'broken-link-checker') 
				 )));
		}
	}
	
	function ajax_link_details(){
		global $wpdb;
		
		if (!current_user_can('edit_others_posts')){
			die( __("You don't have sufficient privileges to access this information!", 'broken-link-checker') );
		}
		
		//FB::log("Loading link details via AJAX");
		
		if ( isset($_GET['link_id']) ){
			//FB::info("Link ID found in GET");
			$link_id = intval($_GET['link_id']);
		} else if ( isset($_POST['link_id']) ){
			//FB::info("Link ID found in POST");
			$link_id = intval($_POST['link_id']);
		} else {
			//FB::error('Link ID not specified, you hacking bastard.');
			die( __('Error : link ID not specified', 'broken-link-checker') );
		}
		
		//Load the link. 
		$link = new blcLink($link_id);
		
		if ( !$link->is_new ){
			//FB::info($link, 'Link loaded');
			$this->link_details_row($link);
			die();
		} else {
			printf( __('Failed to load link details (%s)', 'broken-link-checker'), $wpdb->last_error );
			die();
		}
	}
	
  /**
   * AJAX hook for saving the settings from the "Screen Options" panel in Tools -> Broken Links.
   *
   * @return void
   */
	function ajax_save_highlight_settings(){
		if (!current_user_can('edit_others_posts') || !check_ajax_referer('blc_save_highlight_settings', false, false)){
			die( json_encode( array(
				'error' => __("You're not allowed to do that!", 'broken-link-checker') 
			 )));
		}
		
		$this->conf->options['highlight_permanent_failures'] = !empty($_POST['highlight_permanent_failures']);
		
		$failure_duration_threshold = intval($_POST['failure_duration_threshold']);
		if ( $failure_duration_threshold >=1 ){
			$this->conf->options['failure_duration_threshold'] = $failure_duration_threshold;
		}
		
		$this->conf->save_options();
		die('1');
	}
	
  /**
   * Create and lock a temporary file.
   *
   * @return bool
   */
	function acquire_lock(){
		//Maybe we already have the lock?
		if ( $this->lockfile_handle ){
			return true;
		}
		
		$fn = $this->lockfile_name();
		if ( $fn ){
			//Open the lockfile
			$this->lockfile_handle = fopen($fn, 'w+');
			if ( $this->lockfile_handle ){
				//Do an exclusive lock
				if (flock($this->lockfile_handle, LOCK_EX | LOCK_NB)) {
					//File locked successfully 
					return true; 
				} else {
					//Something went wrong
					fclose($this->lockfile_handle);
					$this->lockfile_handle = null;
				    return false;
				}
			} else {
				//Can't open the file, fail.
				return false;
			}
		} else {
			//Uh oh, can't generate a lockfile name. This is bad.
			//FB::error("Can't find a writable directory to use for my lock file!"); 
			return false;
		};
	}
	
  /**
   * Unlock and delete the temporary file
   *
   * @return bool
   */
	function release_lock(){
		if ( $this->lockfile_handle ){
			//Close the file (implicitly releasing the lock)
			fclose( $this->lockfile_handle );
			//Delete the file
			$fn = $this->lockfile_name();
			if ( file_exists( $fn ) ) {
				unlink( $fn );
			}
			$this->lockfile_handle = null;			
			return true;
		} else {
			//We didn't have the lock anyway...
			return false;
		}
	}
	
  /**
   * Generate system-specific lockfile filename
   *
   * @return string A filename or FALSE on error 
   */
	function lockfile_name(){
		//Try the user-specified temp. directory first, if any
		if ( !empty( $this->conf->options['custom_tmp_dir'] ) ) {
			if ( @is_writable($this->conf->options['custom_tmp_dir']) && @is_dir($this->conf->options['custom_tmp_dir']) ) {
				return trailingslashit($this->conf->options['custom_tmp_dir']) . 'wp_blc_lock';
			} else {
				return false;
			}
		}
		
		//Try the plugin's own directory.
		if ( @is_writable( dirname(__FILE__) ) ){
			return dirname(__FILE__) . '/wp_blc_lock';
		} else {
			
			//Try the system-wide temp directory
			$path = sys_get_temp_dir();
			if ( $path && @is_writable($path)){
				return trailingslashit($path) . 'wp_blc_lock';
			}
			
			//Try the upload directory.  
			$path = ini_get('upload_tmp_dir');
			if ( $path && @is_writable($path)){
				return trailingslashit($path) . 'wp_blc_lock';
			}
			
			//Fail
			return false;
		}
	}
	
  /**
   * Check if server is currently too overloaded to run the link checker.
   *
   * @return bool
   */
	function server_too_busy(){
		if ( !$this->conf->options['enable_load_limit'] ){
			return false;
		}
		
		$loads = $this->get_server_load();
		if ( empty($loads) ){
			return false;
		}
		$one_minute = floatval(reset($loads));
		
		return $one_minute > $this->conf->options['server_load_limit'];
	}
	
  /**
   * Get the server's load averages.
   *
   * Returns an array with three samples - the 1 minute avg, the 5 minute avg, and the 15 minute avg.
   *
   * @param integer $cache How long the load averages may be cached, in seconds. Set to 0 to get maximally up-to-date data.
   * @return array|null Array, or NULL if retrieving load data is impossible (e.g. when running on a Windows box). 
   */
	function get_server_load($cache = 5){
		static $cached_load = null;
		static $cached_when = 0;
		
		if ( !empty($cache) && ((time() - $cached_when) <= $cache) ){
			return $cached_load;
		}
		
		$load = null;
		
		if ( function_exists('sys_getloadavg') ){
			$load = sys_getloadavg();
		} else {
			$loadavg_file = '/proc/loadavg';
	        if (@is_readable($loadavg_file)) {
	            $load = explode(' ',file_get_contents($loadavg_file));
	            $load = array_map('floatval', $load);
	        }
		}
		
		$cached_load = $load;
		$cached_when = time();
		return $load;
	}
	
	function hook_wp_dashboard_setup(){
		if ( function_exists( 'wp_add_dashboard_widget' ) ) {
			wp_add_dashboard_widget(
				'blc_dashboard_widget', 
				'Broken Link Checker', 
				array( &$this, 'dashboard_widget' ),
				array( &$this, 'dashboard_widget_control' )
			 );
		}
	}
	
	function lockfile_warning(){
		$my_dir =  '/plugins/' . basename(dirname(__FILE__)) . '/';
		$settings_page = admin_url( 'options-general.php?page=link-checker-settings#lockfile_directory' );
		
		//Make the notice customized to the current settings
		if ( !empty($this->conf->options['custom_tmp_dir']) ){
			$action_notice = sprintf(
				__('The current temporary directory is not accessible; please <a href="%s">set a different one</a>.', 'broken-link-checker'),
				$settings_page
			);
		} else {
			$action_notice = sprintf(
				__('Please make the directory <code>%1$s</code> writable by plugins or <a href="%2$s">set a custom temporary directory</a>.', 'broken-link-checker'),
				$my_dir, $settings_page
			);
		}
					
		echo sprintf('
			<div id="blc-lockfile-warning" class="error"><p>
				<strong>' . __("Broken Link Checker can't create a lockfile.", 'broken-link-checker') . 
				'</strong> %s <a href="javascript:void(0)" onclick="jQuery(\'#blc-lockfile-details\').toggle()">' . 
				__('Details', 'broken-link-checker') . '</a> </p>
				
				<div id="blc-lockfile-details" style="display:none;"><p>' . 
				__("The plugin uses a file-based locking mechanism to ensure that only one instance of the resource-heavy link checking algorithm is running at any given time. Unfortunately, BLC can't find a writable directory where it could store the lockfile - it failed to detect the location of your server's temporary directory, and the plugin's own directory isn't writable by PHP. To fix this problem, please make the plugin's directory writable or enter a specify a custom temporary directory in the plugin's settings.", 'broken-link-checker') .
				'</p> 
				</div>
			</div>',
			$action_notice);
	}
	
  /**
   * Collect various debugging information and return it in an associative array
   *
   * @return array
   */
	function get_debug_info(){
		global $wpdb;
		
		//Collect some information that's useful for debugging 
		$debug = array();
		
		//PHP version. Any one is fine as long as WP supports it.
		$debug[ __('PHP version', 'broken-link-checker') ] = array(
			'state' => 'ok',
			'value' => phpversion(), 
		);
		
		//MySQL version
		$debug[ __('MySQL version', 'broken-link-checker') ] = array(
			'state' => 'ok',
			'value' => @mysql_get_server_info( $wpdb->dbh ), 
		);
		
		//CURL presence and version
		if ( function_exists('curl_version') ){
			$version = curl_version();
			
			if ( version_compare( $version['version'], '7.16.0', '<=' ) ){
				$data = array(
					'state' => 'warning', 
					'value' => $version['version'],
					'message' => __('You have an old version of CURL. Redirect detection may not work properly.', 'broken-link-checker'),
				);
			} else {
				$data = array(
					'state' => 'ok', 
					'value' => $version['version'],
				);
			}
			
		} else {
			$data = array(
				'state' => 'warning', 
				'value' => __('Not installed', 'broken-link-checker'),
			);
		}
		$debug[ __('CURL version', 'broken-link-checker') ] = $data;
		
		//Snoopy presence
		if ( class_exists('Snoopy') || file_exists(ABSPATH. WPINC . '/class-snoopy.php') ){
			$data = array(
				'state' => 'ok',
				'value' => __('Installed', 'broken-link-checker'),
			);
		} else {
			//No Snoopy? This should never happen, but if it does we *must* have CURL. 
			if ( function_exists('curl_init') ){
				$data = array(
					'state' => 'ok',
					'value' => __('Not installed', 'broken-link-checker'),
				);
			} else {
				$data = array(
					'state' => 'error',
					'value' => __('Not installed', 'broken-link-checker'),
					'message' => __('You must have either CURL or Snoopy installed for the plugin to work!', 'broken-link-checker'),
				);
			}
			
		}
		$debug['Snoopy'] = $data;
		
		//Safe_mode status
		if ( blcUtility::is_safe_mode() ){
			$debug['Safe mode'] = array(
				'state' => 'warning',
				'value' => __('On', 'broken-link-checker'),
				'message' => __('Redirects may be detected as broken links when safe_mode is on.', 'broken-link-checker'),
			);
		} else {
			$debug['Safe mode'] = array(
				'state' => 'ok',
				'value' => __('Off', 'broken-link-checker'),
			);
		}
		
		//Open_basedir status
		if ( blcUtility::is_open_basedir() ){
			$debug['open_basedir'] = array(
				'state' => 'warning',
				'value' => sprintf( __('On ( %s )', 'broken-link-checker'), ini_get('open_basedir') ),
				'message' => __('Redirects may be detected as broken links when open_basedir is on.', 'broken-link-checker'),
			);
		} else {
			$debug['open_basedir'] = array(
				'state' => 'ok',
				'value' => __('Off', 'broken-link-checker'),
			);
		}
		
		//Lockfile location
		$lockfile = $this->lockfile_name();
		if ( $lockfile ){
			$debug['Lockfile'] = array(
				'state' => 'ok',
				'value' => $lockfile,
			);
		} else {
			$debug['Lockfile'] = array(
				'state' => 'error',
				'message' => __("Can't create a lockfile. Please specify a custom temporary directory.", 'broken-link-checker'),
			);
		}
		
		return $debug;
	}
	
	function send_email_notifications(){
		global $wpdb;
		
		//Find links that have been detected as broken since the last sent notification.
		$last_notification = date('Y-m-d H:i:s', $this->conf->options['last_notification_sent']);
		$where = $wpdb->prepare('( first_failure >= %s )', $last_notification);
		
		$links = blc_get_links(array(
			's_filter' => 'broken',
			'where_expr' => $where,
			'load_instances' => true,
			'max_results' => 0,
		));
		
		if ( empty($links) ){
			return;
		}
		
		$cnt = count($links);
		
		//Prepare email message
		$subject = sprintf(
			__("[%s] Broken links detected", 'broken-link-checker'),
			get_option('blogname')
		);
		
		$body = sprintf(
			_n(
				"Broken Link Checker has detected %d new broken link on your site.",
				"Broken Link Checker has detected %d new broken links on your site.",
				$cnt,
				'broken-link-checker'
			),
			$cnt
		);
		
		$body .= "<br>";
		
		$max_displayed_links = 5;
		
		if ( $cnt > $max_displayed_links ){
			$line = sprintf(
				_n(
					"Here's a list of the first %d broken links:",
					"Here's a list of the first %d broken links:",
					$max_displayed_links,
					'broken-link-checker'
				),
				$max_displayed_links
			);
		} else {
			$line = __("Here's a list of the new broken links: ", 'broken-link-checker');
		}
		
		$body .= "<p>$line</p>";
		
		//Show up to $max_displayed_links broken link instances right in the email.
		$displayed = 0;
		foreach($links as $link){
			
			$instances = $link->get_instances();
			foreach($instances as $instance){
				$pieces = array(
					sprintf( __('Link text : %s', 'broken-link-checker'), $instance->ui_get_link_text('email') ),
					sprintf( __('Link URL : <a href="%s">%s</a>', 'broken-link-checker'), htmlentities($link->url), blcUtility::truncate($link->url, 70, '') ),
					sprintf( __('Source : %s', 'broken-link-checker'), $instance->ui_get_source('email') ),
				);
				
				$link_entry = implode("<br>", $pieces);
				$body .= "$link_entry<br><br>";
				
				$displayed++;
				if ( $displayed >= $max_displayed_links ){
					break 2; //Exit both foreach loops
				}
			}
		}
		
		//Add a link to the "Broken Links" tab.
		$body .= __("You can see all broken links here:", 'brokenk-link-checker') . "<br>";
		$link_page = admin_url('tools.php?page=view-broken-links'); 
		$body .= sprintf('<a href="%1$s">%1$s</a>', $link_page);
		
		//Need to override the default 'text/plain' content type to send a HTML email.
		add_filter('wp_mail_content_type', array(&$this, 'override_mail_content_type'));
		
		//Send the notification
		$rez = wp_mail(
			get_option('admin_email'),
			$subject,
			$body
		);
		if ( $rez ){
			$this->conf->options['last_notification_sent'] = time();
			$this->conf->save_options();
		}
		
		//Remove the override so that it doesn't interfere with other plugins that might
		//want to send normal plaintext emails. 
		remove_filter('wp_mail_content_type', array(&$this, 'override_mail_content_type'));

	}
	
	function override_mail_content_type($content_type){
		return 'text/html';
	}
	
  /**
   * Install or uninstall the plugin's Cron events based on current settings.
   *
   * @uses wsBrokenLinkChecker::$conf Uses $conf->options to determine if events need to be (un)installed.  
   *
   * @return void
   */
	function setup_cron_events(){
		//Link monitor
        if ( $this->conf->options['run_via_cron'] ){
            if (!wp_next_scheduled('blc_cron_check_links')) {
				wp_schedule_event( time(), 'hourly', 'blc_cron_check_links' );
			}
		} else {
			wp_clear_scheduled_hook('blc_cron_check_links');
		}
		
		//Email notifications about broken links
		$notification_email = get_option('admin_email');
		if ( $this->conf->options['send_email_notifications'] && !empty($notification_email) ){
			if ( !wp_next_scheduled('blc_cron_email_notifications') ){
				wp_schedule_event(time(), $this->conf->options['notification_schedule'], 'blc_cron_email_notifications');
			}
		} else {
			wp_clear_scheduled_hook('blc_cron_email_notifications');
		}
	} 
	
  /**
   * Load the plugin's textdomain
   *
   * @return void
   */
	function load_language(){
		load_plugin_textdomain( 'broken-link-checker', false, basename(dirname($this->loader)) . '/languages' );
	}

}//class ends here

} // if class_exists...

?>