<?php
/*
Plugin Name: Broken Link Checker
Plugin URI: http://w-shadow.com/blog/2007/08/05/broken-link-checker-for-wordpress/
Description: Checks your posts for broken links and missing images and notifies you on the dashboard if any are found.
Version: 0.5.2
Author: Janis Elsts
Author URI: http://w-shadow.com/blog/
*/

/*
Created by Janis Elsts (email : whiteshadow@w-shadow.com)
MySQL 4.0 compatibility by Jeroen (www.yukka.eu)
*/

//The plugin will use Snoopy in case CURL is not available
if (!class_exists('Snoopy')) require_once(ABSPATH.'/wp-includes/class-snoopy.php');

/**
 * Simple function to replicate PHP 5 behaviour
 */
if ( !function_exists('microtime_float') ) {
	function microtime_float()
	{
	    list($usec, $sec) = explode(" ", microtime());
	    return ((float)$usec + (float)$sec);
	}
}

//Make sure some useful constants are defined
if ( ! defined( 'WP_CONTENT_URL' ) )
	define( 'WP_CONTENT_URL', get_option( 'siteurl' ) . '/wp-content' );
if ( ! defined( 'WP_CONTENT_DIR' ) )
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
if ( ! defined( 'WP_PLUGIN_URL' ) )
	define( 'WP_PLUGIN_URL', WP_CONTENT_URL. '/plugins' );
if ( ! defined( 'WP_PLUGIN_DIR' ) )
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );

/*
//FirePHP for debugging
if ( !class_exists('FB') ) {
	require 'FirePHPCore/fb.php';
}
//FB::setEnabled(false);

//to comment out all calls : (^[^\/]*)(FB::)  ->  $1\/\/$2
//to uncomment : \/\/(\s*FB::)  ->   $1
//*/

require 'utility-class.php';
require 'instance-classes.php';
require 'link-classes.php';

if (!class_exists('ws_broken_link_checker')) {

class ws_broken_link_checker {
    var $options;
    var $options_name='wsblc_options';
    var $myfile='';
    var $myfolder='';
    var $mybasename='';
    var $siteurl;
    var $defaults;
    
    var $execution_start_time;
    var $lockfile_handle = null;

    function ws_broken_link_checker() {
        global $wpdb;

        //set default options
        $this->defaults = array(
            'max_execution_time' => 5*60, 	//How long the worker instance may run, at most. 
            'check_threshold' => 72, 		//Check each link every 72 hours.
            'mark_broken_links' => true, 	//Whether to add the broken_link class to broken links in posts.
            'broken_link_css' => ".broken_link, a.broken_link {\n\ttext-decoration: line-through;\n}",
            'exclusion_list' => array(), 	//Links that contain a substring listed in this array won't be checked. 
            'recheck_count' => 3, 			//[Internal] How many times a broken link should be re-checked (slightly buggy)
			
			//These are currently ignored. Everything is checked by default.
			'check_posts' => true, 
            'check_custom_fields' => true,
            'check_blogroll' => true,
            
            'custom_fields' => array(),		//List of custom fields that can contain URLs and should be checked.
            
            'autoexpand_widget' => true, 
			
			'need_resynch' => false,  		//[Internal flag] 
			
        );
        
        $this->load_options();

        $this->siteurl = get_option('siteurl');

        $my_file = str_replace('\\', '/',__FILE__);
        $my_file = preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', $my_file);
        add_action('activate_' . plugin_basename(__FILE__), array(&$this,'activation'));
        $this->myfile=$my_file;
        $this->myfolder=basename(dirname(__FILE__));
        $this->mybasename=plugin_basename(__FILE__);
        
        add_action('admin_menu', array(&$this,'admin_menu'));

        //These hooks update the plugin's internal records when posts are added, deleted or modified.
		add_action('delete_post', array(&$this,'post_deleted'));
        add_action('save_post', array(&$this,'post_saved'));
        
        //These do the same for (blogroll) links.
        add_action('add_link', array(&$this,'hook_add_link'));
        add_action('edit_link', array(&$this,'hook_edit_link'));
        add_action('delete_link', array(&$this,'hook_delete_link'));
        
        add_action('admin_footer', array(&$this,'admin_footer'));
        add_action('admin_print_scripts', array(&$this,'admin_print_scripts'));
        //The dashboard widget
        add_action('wp_dashboard_setup', array(&$this, 'hook_wp_dashboard_setup'));

        if ( $this->options['mark_broken_links'] ){
            add_filter( 'the_content', array(&$this,'the_content') );
            if ( !empty($this->options['broken_link_css']) ){
                add_action( 'wp_head', array(&$this,'header_css') );
            }
        }
        
        //AJAXy hooks
        add_action( 'wp_ajax_blc_full_status', array(&$this,'ajax_full_status') );
        add_action( 'wp_ajax_blc_dashboard_status', array(&$this,'ajax_dashboard_status') );
        add_action( 'wp_ajax_blc_work', array(&$this,'ajax_work') );
        add_action( 'wp_ajax_blc_discard', array(&$this,'ajax_discard') );
        add_action( 'wp_ajax_blc_edit', array(&$this,'ajax_edit') );
        add_action( 'wp_ajax_blc_link_details', array(&$this,'ajax_link_details') );
        add_action( 'wp_ajax_blc_exclude_link', array(&$this,'ajax_exclude_link') );
        add_action( 'wp_ajax_blc_unlink', array(&$this,'ajax_unlink') );
    }

    function admin_footer(){
        ?>
        <!-- wsblc admin footer -->
        <div id='wsblc_updater_div'></div>
        <script type='text/javascript'>
        (function($){
				
			function blcDoWork(){
				$.post(
					"<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php",
					{
						'action' : 'blc_work'
					},
					function (data, textStatus){}
				);
			}
			//Call it the first time
			blcDoWork();
			//...and every max_execution_time seconds
			setInterval(blcDoWork, <?php echo ($this->options['max_execution_time'] + 1 )*1000; ?>);
			
		})(jQuery);
        </script>
        <!-- /wsblc admin footer -->
        <?php
    }

    function header_css(){
        echo '<style type="text/css">',$this->options['broken_link_css'],'</style>';
    }

    function the_content($content){
        global $post, $wpdb;
        if ( empty($post) ) return $content;
        
        $q = "
        	SELECT instances.link_text, links.*

			FROM {$wpdb->prefix}blc_instances AS instances, {$wpdb->prefix}blc_links AS links
			
			WHERE 
				instances.source_id = %d
				AND instances.source_type = 'post'
				AND instances.instance_type = 'link'
				
				AND instances.link_id = links.link_id
			    AND links.check_count > 0
			    AND ( links.http_code < 200 OR links.http_code >= 400 OR links.timeout = 1 )";
				
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
    	//TODO:Tooltip-style popups with more info
        $url = blcUtility::normalize_url( html_entity_decode( $matches[3] ) );
        if( isset( $this->links_to_remove[$url] ) ){
            return $matches[1].$matches[2].$matches[3].$matches[2].' class="broken_link" '.$matches[4].
                   $matches[5].$matches[6];
        } else {
            return $matches[0];
        }
    }


    function is_excluded($url){
        if (!is_array($this->options['exclusion_list'])) return false;
        foreach($this->options['exclusion_list'] as $excluded_word){
            if (stristr($url, $excluded_word)){
                return true;
            }
        }
        return false;
    }

    function dashboard_widget(){
        ?>
        <div id='wsblc_activity_box' style="line-height : 140%">Loading...</div>
        <script type='text/javascript'>
        	jQuery(function($){
        		var blc_was_autoexpanded = false;
        		
				function blcDashboardStatus(){
					$.getJSON(
						"<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php",
						{
							'action' : 'blc_dashboard_status'
						},
						function (data, textStatus){
							if ( typeof(data['text']) != 'undefined'){
								$('#wsblc_activity_box').html(data.text); 
								<?php if ( $this->options['autoexpand_widget'] ) { ?>
								//Expand the widget if there are broken links.
								//Do this only once per pageload so as not to annoy the user.
								if ( !blc_was_autoexpanded && ( data.status.broken_links > 0 ) ){
									$('#blc_dashboard_widget.postbox').removeClass('closed');
									blc_was_autoexpanded = true;
								};
								<?php } ?>
							} else {
								$('#wsblc_activity_box').html('[ Network error ]');
							}
							
							setTimeout( blcDashboardStatus, 120*1000 ); //...update every two minutes
						}
					);
				}
				blcDashboardStatus();//Call it the first time
			
			});
        </script>
        <?php
    }
    
    function dashboard_widget_control( $widget_id, $form_inputs = array() ){
		if ( 'POST' == $_SERVER['REQUEST_METHOD'] && 'blc_dashboard_widget' == $_POST['widget_id'] ) {
			//It appears $form_inputs isn't used in the current WP version, so lets just use $_POST
			$this->options['autoexpand_widget'] = !empty($_POST['blc-autoexpand']);
			$this->save_options();
		}
	
		?>
		<p><label for="blc-autoexpand">
			<input id="blc-autoexpand" name="blc-autoexpand" type="checkbox" value="1" <?php if ( $this->options['autoexpand_widget'] ) echo 'checked="checked"'; ?> />
			Automatically expand the widget if broken links have been detected
		</label></p>
		<?php
    }

    function admin_print_scripts(){
        //jQuery is used for AJAX and effects
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-core');
    }

  /**
   * ws_broken_link_checker::post_deleted()
   * A hook for post_deleted. Remove link instances associated with that post. 
   *
   * @param int $post_id
   * @return void
   */
    function post_deleted($post_id){
        global $wpdb;
        
        //FB::log($post_id, "Post deleted");        
        //Remove this post's instances
        $q = "DELETE FROM {$wpdb->prefix}blc_instances 
			  WHERE source_id = %d AND (source_type = 'post' OR source_type='custom_field')";
		$q = $wpdb->prepare($q, intval($post_id) );
		
		//FB::log($q, 'Executing query');
		
        if ( $wpdb->query( $q ) === false ){
			//FB::error($wpdb->last_error, "Database error");
		}
        
        //Remove the synch record
        $q = "DELETE FROM {$wpdb->prefix}blc_synch 
			  WHERE source_id = %d AND source_type = 'post'";
        $wpdb->query( $wpdb->prepare($q, intval($post_id)) );
        
        //Remove any dangling link records
        $this->cleanup_links();
    }

    function post_saved($post_id){
        global $wpdb;

        $post = get_post($post_id);
        //Only check links in posts, not revisions and attachments
        if ( ($post->post_type != 'post') && ($post->post_type != 'page') ) return null;
        //Only check published posts
        if ( $post->post_status != 'publish' ) return null;
        
        $this->mark_unsynched( $post_id, 'post' );
    }
    
    function initiate_recheck(){
    	global $wpdb;
    	
    	//Delete all discovered instances
    	$wpdb->query("TRUNCATE {$wpdb->prefix}blc_instances");
    	
    	//Delete all discovered links
    	$wpdb->query("TRUNCATE {$wpdb->prefix}blc_links");
    	
    	//Mark all posts, custom fields and bookmarks for processing.
    	$this->resynch();
	}

    function resynch(){
    	global $wpdb;
    	
    	//Drop all synchronization records
    	$wpdb->query("TRUNCATE {$wpdb->prefix}blc_synch");
    	
    	
    	//Create new synchronization records for posts 
    	$q = "INSERT INTO {$wpdb->prefix}blc_synch(source_id, source_type, synched)
			  SELECT id, 'post', 0
			  FROM {$wpdb->posts}
			  WHERE
			  	{$wpdb->posts}.post_status = 'publish'
 				AND {$wpdb->posts}.post_type IN ('post', 'page')";
 		$wpdb->query( $q );
 		
 		//Create new synchronization records for bookmarks (the blogroll)
 		$q = "INSERT INTO {$wpdb->prefix}blc_synch(source_id, source_type, synched)
			  SELECT link_id, 'blogroll', 0
			  FROM {$wpdb->links}
			  WHERE 1";
 		$wpdb->query( $q );
    	
		//Delete invalid instances
		$this->cleanup_instances();
		//Delete orphaned links
		$this->cleanup_links();
		
		$this->options['need_resynch'] = true;
		$this->save_options();
	}
	
	function mark_unsynched( $source_id, $source_type ){
		global $wpdb;
		
		$q = "REPLACE INTO {$wpdb->prefix}blc_synch( source_id, source_type, synched, last_synch)
			  VALUES( %d, %s, %d, NOW() )";
		$rez = $wpdb->query( $wpdb->prepare( $q, $source_id, $source_type, 0 ) );
		
		if ( !$this->options['need_resynch'] ){
			$this->options['need_resynch'] = true;
			$this->save_options();
		}
		
		return $rez;
	}
	
	function mark_synched( $source_id, $source_type ){
		global $wpdb;
		//FB::log("Marking $source_type $source_id as synched.");
		$q = "REPLACE INTO {$wpdb->prefix}blc_synch( source_id, source_type, synched, last_synch)
			  VALUES( %d, %s, %d, NOW() )";
		return $wpdb->query( $wpdb->prepare( $q, $source_id, $source_type, 1 ) );
	}
	
    function activation(){
    	//Prepare the database.
        $this->upgrade_database();

		//Clear the instance table and mark all posts and other parse-able objects as unsynchronized. 
        $this->resynch();

        //Save the default options. 
        $this->save_options();
    }
    
  /**
   * ws_broken_link_checker::upgrade_database()
   * Create and/or upgrade database tables
   *
   * @return bool
   */
    function upgrade_database(){
		global $wpdb;
		
		//Delete tables used by older versions of the plugin
		$rez = $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}blc_linkdata, {$wpdb->prefix}blc_postdata" );
		if ( $rez === false ){
			//FB::error($wpdb->last_error, "Database error");
			return false;
		}
		
		//Create the link table if it doesn't exist yet.
		$rez = $wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}blc_links (
				link_id int(20) unsigned NOT NULL auto_increment,
				url text NOT NULL,
				last_check datetime NOT NULL default '0000-00-00 00:00:00',
				check_count int(2) unsigned NOT NULL default '0',
				final_url text NOT NULL,
				redirect_count smallint(5) unsigned NOT NULL,
				log text NOT NULL,
				http_code smallint(6) NOT NULL,
				request_duration float NOT NULL default '0',
				timeout tinyint(1) unsigned NOT NULL default '0',
				  
				PRIMARY KEY  (link_id),
				KEY url (url(150)),
				KEY final_url (final_url(150)),
				KEY http_code (http_code),
				KEY timeout (timeout)
			)"
		 );
		if ( $rez === false ){
			//FB::error($wpdb->last_error, "Database error");
			return false;
		}
		 
		//Create the instance table if it doesn't exist yet.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}blc_instances (
				instance_id int(10) unsigned NOT NULL auto_increment,
				link_id int(10) unsigned NOT NULL,
				source_id int(10) unsigned NOT NULL,
				source_type enum('post','blogroll','custom_field') NOT NULL default 'post',
				link_text varchar(250) NOT NULL,
				instance_type enum('link','image') NOT NULL default 'link',
				
				PRIMARY KEY  (instance_id),
				KEY link_id (link_id),
				KEY source_id (source_id,source_type)
			)"
		 );
		if ( $rez === false ){
			//FB::error($wpdb->last_error, "Database error");
			return false;
		}
		
		//....
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}blc_synch (
			  source_id int(20) unsigned NOT NULL,
			  source_type enum('post','blogroll') NOT NULL,
			  synched tinyint(3) unsigned NOT NULL,
			  last_synch datetime NOT NULL,
			  PRIMARY KEY  (source_id, source_type),
			  KEY synched (synched)
			)"
		 );
		if ( $rez === false ){
			//FB::error($wpdb->last_error, "Database error");
			return false;
		}
		
		return true;
	}

    function admin_menu(){
        add_options_page('Link Checker Settings', 'Link Checker', 'manage_options',
            'link-checker-settings',array(&$this, 'options_page'));
        if (current_user_can('manage_options'))
            add_filter('plugin_action_links', array(&$this, 'plugin_action_links'), 10, 2);

        add_management_page('View Broken Links', 'Broken Links', 'edit_others_posts',
            'view-broken-links',array(&$this, 'links_page'));
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
        if ($file == $this->mybasename)
            $links[] = "<a href='options-general.php?page=link-checker-settings'>" . __('Settings') . "</a>";
        return $links;
    }

    function mytruncate($str, $max_length=50){
        if(strlen($str)<=$max_length) return $str;
        return (substr($str, 0, $max_length-3).'...');
    }

    function options_page(){

        if (isset($_GET['recheck']) && ($_GET['recheck'] == 'true')) {
            $this->initiate_recheck();
        }
        if (isset($_GET['updated']) && ($_GET['updated'] == 'true')) {
            if(isset($_POST['submit'])) {

                $new_execution_time = intval($_POST['max_execution_time']);
                if( $new_execution_time > 0 ){
                    $this->options['max_execution_time'] = $new_execution_time;
                }

                $new_check_threshold=intval($_POST['check_threshold']);
                if( $new_check_threshold > 0 ){
                    $this->options['check_threshold'] = $new_check_threshold;
                }
                
                $this->options['mark_broken_links'] = !empty($_POST['mark_broken_links']);
                $new_broken_link_css = trim($_POST['broken_link_css']);
                $this->options['broken_link_css'] = $new_broken_link_css;

                $this->options['exclusion_list']=array_filter( preg_split( '/[\s\r\n]+/',
                    $_POST['exclusion_list'], -1, PREG_SPLIT_NO_EMPTY ) );
                //TODO: Maybe update affected links when exclusion list changes (expensive).
                    
                
                $new_custom_fields = array_filter( preg_split( '/[\s\r\n]+/',
                    $_POST['blc_custom_fields'], -1, PREG_SPLIT_NO_EMPTY ) );
                $diff1 = array_diff( $new_custom_fields, $this->options['custom_fields'] );
                $diff2 = array_diff( $this->options['custom_fields'], $new_custom_fields );
                $this->options['custom_fields'] = $new_custom_fields;

                $this->save_options();
				
				/*
				 If the list of custom fields was modified then we MUST resynchronize or
				 custom fields linked with existing posts may not be detected. This is somewhat
				 inefficient.  
				 */
				if ( ( count($diff1) > 0 ) || ( count($diff2) > 0 ) ){
					$this->resynch();
				}
            }

        }

        ?>
        <div class="wrap"><h2>Broken Link Checker Options</h2>

        <form name="link_checker_options" method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=link-checker-settings&amp;updated=true">

        <table class="form-table">

        <tr valign="top">
        <th scope="row">Status</th>
        <td>


        <div id='wsblc_full_status'>
            <br/><br/><br/>
        </div>
        <script type='text/javascript'>
        	(function($){
				
				function blcUpdateStatus(){
					$.getJSON(
						"<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php",
						{
							'action' : 'blc_full_status'
						},
						function (data, textStatus){
							if ( typeof(data['text']) != 'undefined'){
								$('#wsblc_full_status').html(data.text);
							} else {
								$('#wsblc_full_status').html('[ Network error ]');
							}
							
							setTimeout(blcUpdateStatus, 10000); //...update every 10 seconds							
						}
					);
				}
				blcUpdateStatus();//Call it the first time
				
			})(jQuery);
        </script>
        <?php //JHS: Recheck all posts link: ?>
        <p><input class="button" type="button" name="recheckbutton" value="Re-check all pages" onclick="location.replace('<?php echo $_SERVER['PHP_SELF']; ?>?page=link-checker-settings&amp;recheck=true')" /></p>
        </td>
        </tr>

        <tr valign="top">
        <th scope="row">Check each link</th>
        <td>

        Every <input type="text" name="check_threshold" id="check_threshold"
            value="<?php echo $this->options['check_threshold']; ?>" size='5' maxlength='3'/>
        hours
        <br/>
        <span class="description">
        Existing links will be checked this often. New links will usually be checked ASAP.
        </span>

        </td>
        </tr>

        <tr valign="top">
        <th scope="row">Broken link CSS</th>
        <td>
        <input type="checkbox" name="mark_broken_links" id="mark_broken_links"
            <?php if ($this->options['mark_broken_links']) echo ' checked="checked"'; ?>/>
            <label for='mark_broken_links'>Apply <em>class="broken_link"</em> to broken links</label><br/>
        <textarea name="broken_link_css" id="broken_link_css" cols='45' rows='4'/><?php
            if( isset($this->options['broken_link_css']) )
                echo $this->options['broken_link_css'];
        ?></textarea>

        </td>
        </tr>

        <tr valign="top">
        <th scope="row">Exclusion list</th>
        <td>Don't check links where the URL contains any of these words (one per line) :<br/>
        <textarea name="exclusion_list" id="exclusion_list" cols='45' rows='4' wrap='off'/><?php
            if( isset($this->options['exclusion_list']) )
                echo implode("\n", $this->options['exclusion_list']);
        ?></textarea>

        </td>
        </tr>
        
        <tr valign="top">
        <th scope="row">Custom fields</th>
        <td>Check URLs entered in these custom fields (one per line) : <br/>
        <textarea name="blc_custom_fields" id="blc_custom_fields" cols='45' rows='4' /><?php
            if( isset($this->options['custom_fields']) )
                echo implode("\n", $this->options['custom_fields']);
        ?></textarea>

        </td>
        </tr>

        <tr valign="top">
        <th scope="row">Max. execution time (advanced)</th>
        <td>

        <input type="text" name="max_execution_time" id="max_execution_time"
            value="<?php echo $this->options['max_execution_time']; ?>" size='5' maxlength='3'/>
        seconds
        <br/><span class="description">
        The plugin works by periodically creating a background worker instance that parses your posts looking for links,
		checks the discovered URLs, and performs other time-consuming tasks. Here you can set for how long, at most, 
		the background instance may run each time before stopping. 
		</span>

        </td>
        </tr>

        </table>

        <p class="submit"><input type="submit" name="submit" class='button-primary' value="<?php _e('Save Changes') ?>" /></p>
        </form>
        </div>
        <?php
    }

    function links_page(){
        global $wpdb;
        
		//Available filters by link type + the appropriate WHERE expressions
		$filters = array(
			'broken' => array(
				'where_expr' => '( http_code < 200 OR http_code >= 400 OR timeout = 1 ) AND ( check_count > 0 )',
				'name' => 'Broken',
				'heading' => 'Broken Links',
				'heading_zero' => 'No broken links found'
			 ), 
			 'redirects' => array(
				'where_expr' => '( redirect_count > 0 )',
				'name' => 'Redirects',
				'heading' => 'Redirected Links',
				'heading_zero' => 'No redirects found'
			 ), 
			 
			'all' => array(
				'where_expr' => '1',
				'name' => 'All',
				'heading' => 'Detected Links',
				'heading_zero' => 'No links found (yet)'
			 ), 
		);	
		
		$link_type = isset($_GET['link_type'])?$_GET['link_type']:'broken';
		if ( !isset($filters[$link_type]) ){
			$link_type = 'broken';
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
		
		//calculate the number of various links
		foreach ($filters as $filter => $data){
			$filters[$filter]['count'] = $wpdb->get_var( 
				"SELECT COUNT(*) FROM {$wpdb->prefix}blc_links WHERE ".$data['where_expr'] );
		}
		$current_filter = $filters[$link_type];
		$max_pages = ceil($current_filter['count'] / $per_page);
		

		//Select the required links + 1 instance per link.
		//Note : The query might be somewhat inefficient, but I can't think of any better way to do this. 
		$q = "SELECT 
				 links.*, 
				 instances.instance_id, instances.source_id, instances.source_type, 
				 instances.link_text, instances.instance_type,
				 COUNT(*) as instance_count,
				 posts.post_title,
				 posts.post_date
				
			  FROM 
				 {$wpdb->prefix}blc_links AS links, 
				 {$wpdb->prefix}blc_instances as instances LEFT JOIN {$wpdb->posts} as posts ON instances.source_id = posts.ID
				
			   WHERE
				 links.link_id = instances.link_id
				 AND ". $current_filter['where_expr'] ."
				
			   GROUP BY links.link_id
			   LIMIT ".( ($page-1) * $per_page ).", $per_page";
		//echo "<pre>$q</pre>";	   
			
		$links = $wpdb->get_results($q, ARRAY_A);
		if ($links){
			/*
			echo '<pre>';
			print_r($links);
			echo  '</pre>';
			//*/
		} else {
			echo $wpdb->last_error;
		}
        ?>
        
<script type='text/javascript'>
	var blc_current_filter = '<?php echo $link_type; ?>';
</script>
        
<style type='text/css'>
.blc-link-editor {
    font-size: 1em;
    width: 95%;
}

.blc-excluded-link {
	background-color: #E2E2E2;
}

.blc-small-image {
	display : block;
	float: left;
	padding-top: 2px;
	margin-right: 3px;
}
</style>

<div class="wrap">
<h2><?php
	//Output a header matching the current filter
	if ( $current_filter['count'] > 0 ){
		echo "<span class='current-link-count'>{$current_filter[count]}</span> " . $current_filter['heading'];
	} else {
		echo "<span class='current-link-count'></span>" . $current_filter['heading_zero'];
	}
?></h2>

	<div class='tablenav'>
	    <ul class="subsubsub">
	    	<?php
	    		//Construct a submenu of filter types
	    		$items = array();
				foreach ($filters as $filter => $data){
					$class = $number_class = '';
					
					if ( $link_type == $filter ) $class = 'class="current"';
					if ( $link_type == $filter ) $number_class = 'current-link-count';
					
					$items[] = "<li><a href='tools.php?page=view-broken-links&link_type=$filter' $class>
						{$data[name]}</a> <span class='count'>(<span class='$number_class'>{$data[count]}</span>)</span>";
				}
				echo implode(' |</li>', $items);
				unset($items);
			?>
		</ul>
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
				$page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of <span class="current-link-count">%s</span>' ) . '</span>%s',
					number_format_i18n( ( $page - 1 ) * $per_page + 1 ),
					number_format_i18n( min( $page * $per_page, count($links) ) ),
					number_format_i18n( $current_filter['count'] ),
					$page_links
				); 
				echo $page_links_text; 
				echo '</div>';
			}
		?>
	
	</div>



<?php
        if($links && (count($links)>0)){
            ?>
            <table class="widefat">
                <thead>
                <tr>

                <th scope="col">Source
                </th>
                <th scope="col">Link Text</th>
                <th scope="col">URL</th>

				<?php if ( 'broken' == $link_type ) { ?> 
                <th scope="col"> </th>
                <?php } ?>

                </tr>
                </thead>
                <tbody id="the-list">
            <?php
            $rowclass = ''; $rownum = 0;
            foreach ($links as $link) {
            	$rownum++;
            	
            	$rowclass = 'alternate' == $rowclass ? '' : 'alternate';
            	$excluded = $this->is_excluded( $link['url'] ); 
            	if ( $excluded ) $rowclass .= ' blc-excluded-link';
            	
                ?>
                <tr id='<?php echo "blc-row-$rownum"; ?>' class='blc-row <?php echo $rowclass; ?>'>
                <td class='post-title column-title'>
                	<span class='blc-link-id' style='display:none;'><?php echo $link['link_id']; ?></span> 	
                  <?php 
				  if ( ('post' == $link['source_type']) || ('custom_field' == $link['source_type']) ){
				  	 
                  	echo "<a class='row-title' href='post.php?action=edit&amp;post=$link[source_id]' title='Edit this post'>{$link[post_title]}</a>";

					//Output inline action links (copied from edit-post-rows.php)                  	
                  	$actions = array();
					if ( current_user_can('edit_post', $link['source_id']) ) {
						$actions['edit'] = '<span class="edit"><a href="' . get_edit_post_link($link['source_id'], true) . '" title="' . attribute_escape(__('Edit this post')) . '">' . __('Edit') . '</a>';
						$actions['delete'] = "<span class='delete'><a class='submitdelete' title='" . attribute_escape(__('Delete this post')) . "' href='" . wp_nonce_url("post.php?action=delete&amp;post=".$link['source_id'], 'delete-post_' . $link['source_id']) . "' onclick=\"if ( confirm('" . js_escape(sprintf( __("You are about to delete the post '%s'\n 'Cancel' to stop, 'OK' to delete."), $link['post_title'] )) . "') ) { return true;}return false;\">" . __('Delete') . "</a>";
					}
					$actions['view'] = '<span class="view"><a href="' . get_permalink($link['source_id']) . '" title="' . attribute_escape(sprintf(__('View "%s"'), $link['post_title'])) . '" rel="permalink">' . __('View') . '</a>';
					echo '<div class="row-actions">';
					echo implode(' | </span>', $actions);
					echo '</div>';
					
                  } elseif ( 'blogroll' == $link['source_type'] ) {
                  	
                  	echo "<a class='row-title' href='link.php?action=edit&amp;link_id=$link[source_id]' title='Edit this bookmark'>{$link[link_text]}</a>";
                  	
                  	//Output inline action links                  	
                  	$actions = array();
					if ( current_user_can('manage_links') ) {
						$actions['edit'] = '<span class="edit"><a href="link.php?action=edit&amp;link_id=' . $link['source_id'] . '" title="' . attribute_escape(__('Edit this bookmark')) . '">' . __('Edit') . '</a>';
						$actions['delete'] = "<span class='delete'><a class='submitdelete' href='" . wp_nonce_url("link.php?action=delete&amp;link_id={$link[source_id]}", 'delete-bookmark_' . $link['source_id']) . "' onclick=\"if ( confirm('" . js_escape(sprintf( __("You are about to delete this link '%s'\n  'Cancel' to stop, 'OK' to delete."), $link['link_text'])) . "') ) { return true;}return false;\">" . __('Delete') . "</a>";
					}
					
					echo '<div class="row-actions">';
					echo implode(' | </span>', $actions);
					echo '</div>';
                  	
				  } elseif ( empty($link['source_type']) ){
				  	
					echo "[An orphaned link! This is a bug.]";
					
				  }
				  	?>
				</td>
                <td class='blc-link-text'><?php
                if ( 'post' == $link['source_type'] ){
                	
					if ( 'link' == $link['instance_type'] ) {	 
						print strip_tags($link['link_text']);
					} elseif ( 'image' == $link['instance_type'] ){
						echo "<img src='" . WP_PLUGIN_URL . "/broken-link-checker/images/image.png' class='blc-small-image' alt='Image' title='Image'> Image";
					} else {
						echo '[ ??? ]';
					}
						
				} elseif ( 'custom_field' == $link['source_type'] ){
					
					echo "<img src='" . WP_PLUGIN_URL . "/broken-link-checker/images/script_code.png' class='blc-small-image' title='Custom field' alt='Custom field'> ";
					echo "<code>".$link['link_text']."</code>";
					
				} elseif ( 'blogroll' == $link['source_type'] ){
					//echo $link['link_text'];
					echo "<img src='" . WP_PLUGIN_URL . "/broken-link-checker/images/link.png' class='blc-small-image' title='Bookmark' alt='Bookmark'> Bookmark";
				}
				?>
				</td>
                <td class='column-url'>
                    <a href='<?php print $link['url']; ?>' target='_blank' class='blc-link-url'>
                    	<?php print $this->mytruncate($link['url']); ?></a>
                    <input type='text' id='link-editor-<?php print $rownum; ?>' 
                    	value='<?php print attribute_escape($link['url']); ?>'
                        class='blc-link-editor' style='display:none' />
                <?php
                	//Output inline action links for the link/URL                  	
                  	$actions = array();
                  	
					$actions['details'] = "<span class='view'><a class='blc-details-button' href='javascript:void(0)' title='Show more info about this link'>Details</a>";
                  	
					$actions['delete'] = "<span class='delete'><a class='submitdelete blc-unlink-button' title='Remove this link from all posts' ".
						"id='unlink-button-$rownum' href='javascript:void(0);'>Unlink</a>";
					
					if ( $excluded ){
						$actions['exclude'] = "<span class='delete'>Excluded";
					} else {
						$actions['exclude'] = "<span class='delete'><a class='submitdelete blc-exclude-button' title='Add this URL to the exclusion list' ".
							"id='exclude-button-$rownum' href='javascript:void(0);'>Exclude</a>";
					}
					
					$actions['edit'] = "<span class='edit'><a href='javascript:void(0)' class='blc-edit-button' title='Edit link URL'>Edit URL</a>";
						
					echo '<div class="row-actions">';
					echo implode(' | </span>', $actions);
					
					echo "<span style='display:none' class='blc-cancel-button-container'> ",
						 "| <a href='javascript:void(0)' class='blc-cancel-button' title='Cancel URL editing'>Cancel</a></span>";
					   	
					echo '</div>';
                ?>
                </td>
                <?php if ( 'broken' == $link_type ) { ?> 
				<td><a href='javascript:void(0);'  
					id='discard_button-<?php print $rownum; ?>'
					class='blc-discard-button'
					title='Remove this message and mark the link as valid'>Discard</a>
				</td>
                <?php } ?>
                </tr>
                <!-- Link details -->
                <tr id='<?php print "link-details-$rownum"; ?>' style='display:none;' class='blc-link-details'>
					<td colspan='4'><?php $this->link_details_row($link); ?></td>
				</tr><?php
            }
            ?></tbody></table><?php
            
            //Also display pagination links at the bottom
            if ( $page_links ) { 
				echo '<div class="tablenav"><div class="tablenav-pages">';
				$page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of <span class="current-link-count">%s</span>' ) . '</span>%s',
					number_format_i18n( ( $page - 1 ) * $per_page + 1 ),
					number_format_i18n( min( $page * $per_page, count($links) ) ),
					number_format_i18n( $current_filter['count'] ),
					$page_links
				); 
				echo $page_links_text; 
				echo '</div></div>';
			}
        };
?>
		<?php $this->links_page_js(); ?>
</div>
        <?php
    }
    
    function links_page_js(){
		?>
<script type='text/javascript'>

function alterLinkCounter(factor){
    cnt = parseInt(jQuery('.current-link-count').eq(0).html());
    cnt = cnt + factor;
    jQuery('.current-link-count').html(cnt);
}

jQuery(function($){
	
	//The discard button - manually mark the link as valid. The link will be checked again later.
	$(".blc-discard-button").click(function () {
		var me = this;
		$(me).html('Wait...');
		
		var link_id = $(me).parents('.blc-row').find('.blc-link-id').html();
        
        $.post(
			"<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php",
			{
				'action' : 'blc_discard',
				'link_id' : link_id
			},
			function (data, textStatus){
				if (data == 'OK'){
					var master = $(me).parents('.blc-row'); 
					var details = master.next('.blc-link-details'); 
					
					details.hide();
					//Flash the main row green to indicate success, then hide it.
					var oldColor = master.css('background-color');
					master.animate({ backgroundColor: "#E0FFB3" }, 200).animate({ backgroundColor: oldColor }, 300, function(){
						master.hide();
					});
					
                    alterLinkCounter(-1);
				} else {
					$(me).html('Discard');
					alert(data);
				}
			}
		);
    });
    
    //The details button - display/hide detailed info about a link
    $(".blc-details-button, .blc-link-text").click(function () {
    	$(this).parents('.blc-row').next('.blc-link-details').toggle();
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
        	//Begin editing
        	url_el.hide();
            editor.show();
            cancel_button_container.show();
            editor.focus();
            editor.select();
            edit_button.html('Save URL');
        } else {
            editor.hide();
            cancel_button_container.hide();
			url_el.show();
			
            new_url = editor.val();
            
            if (new_url != orig_url){
                //Save the changed link
                url_el.html('Saving changes...');
                
                $.getJSON(
					"<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php",
					{
						'action' : 'blc_edit',
						'link_id' : link_id,
						'new_url' : new_url
					},
					function (data, textStatus){
						var display_url = '';
						
						if ( typeof(data['error']) != 'undefined'){
							//data.error is an error message
							alert(data.error);
							display_url = orig_url;
						} else {
							//data contains info about the performed edit
							if ( data.cnt_okay > 0 ){
								display_url = new_url;
								
								url_el.attr('href', new_url);
								
								if ( data.cnt_error > 0 ){
									var msg = "The link was successfully modifed.";
									msg = msg + "\nHowever, "+data.cnt_error+" instances couldn't be edited and still point to the old URL."
									alert(msg);
								} else {
									//Flash the row green to indicate success
									var oldColor = master.css('background-color');
									master.animate({ backgroundColor: "#E0FFB3" }, 200).animate({ backgroundColor: oldColor }, 300);
									
									//Save the new ID 
									master.find('.blc-link-id').html(data.new_link_id);
									//Load up the new link info                     (so sue me)    
									master.next('.blc-link-details').find('td').html('<center>Loading...</center>').load(
										"<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php",
										{
											'action' : 'blc_link_details',
											'link_id' : data.new_link_id
										}
									);
								}
							} else {
								alert("Something went wrong. The plugin failed to edit "+
									data.cnt_error + ' instance(s) of this link.');
									
								display_url = orig_url;
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
			edit_button.html('Edit URL');
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
		master.find('.blc-edit-button').html('Edit URL');
    });
    
    //The unlink button - remove the link/image from all posts, custom fields, etc.
    $(".blc-unlink-button").click(function () { 
    	var me = this;
    	var master = $(me).parents('.blc-row');
		$(me).html('Wait...');
		
		var link_id = $(me).parents('.blc-row').find('.blc-link-id').html();
        
        $.post(
			"<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php",
			{
				'action' : 'blc_unlink',
				'link_id' : link_id
			},
			function (data, textStatus){
				eval('data = ' + data);
				 
				if ( typeof(data['ok']) != 'undefined'){
					//Hide the details 
					master.next('.blc-link-details').hide();
					//Flash the main row green to indicate success, then hide it.
					var oldColor = master.css('background-color');
					master.animate({ backgroundColor: "#E0FFB3" }, 200).animate({ backgroundColor: oldColor }, 300, function(){
						master.hide();
					});

					alterLinkCounter(-1);
				} else {
					$(me).html('Unlink');
					//Show the error message
					alert(data.error);
				}
			}
		);
    });
    
    //The exclude button - Add this link to the exclusion list
    $(".blc-exclude-button").click(function () { 
      	var me = this;
      	var master = $(me).parents('.blc-row');
      	var details = master.next('.blc-link-details');
		$(me).html('Wait...');
		
		var link_id = $(me).parents('.blc-row').find('.blc-link-id').html();
        
        $.post(
			"<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php",
			{
				'action' : 'blc_exclude_link',
				'link_id' : link_id
			},
			function (data, textStatus){
				eval('data = ' + data);
				 
				if ( typeof(data['ok']) != 'undefined'){
					
					if ( 'broken' == blc_current_filter ){
						//Flash the row green to indicate success, then hide it.
						$(me).replaceWith('Excluded');
						master.animate({ backgroundColor: "#E0FFB3" }, 200).animate({ backgroundColor: '#E2E2E2' }, 200, function(){
							details.hide();
							master.hide();
							alterLinkCounter(-1);
						});
						master.addClass('blc-excluded-link');
					} else {
						//Flash the row green to indicate success and fade to the "excluded link" color
						master.animate({ backgroundColor: "#E0FFB3" }, 200).animate({ backgroundColor: '#E2E2E2' }, 300);
						master.addClass('blc-excluded-link');
						$(me).replaceWith('Excluded');
					}
				} else {
					$(me).html('Exclude');
					alert(data.error);
				}
			}
		);
    });
	
});

    function removeLinkFromPost(link_id){
    	if (!confirm('Do you really want to remove this link from all posts, custom fields and the blogroll?')) return;
    	
        $('unlink_button-'+link_id).innerHTML = 'Wait...';

        new Ajax.Request(
            '<?php
        echo get_option( "siteurl" ).'/wp-content/plugins/'.$this->myfolder.'/wsblc_ajax.php?';
        ?>action=remove_link&id='+link_id,
            {
                method:'get',
                onSuccess: function(transport){
                    var re = /OK:.*/i
                    var response = transport.responseText || "";
                    if (re.test(response)){
                        $('link-'+link_id).hide();
                        $('link-details-'+link_id).hide();
                        alterLinkCounter(-1);
                    } else {
                        $('unlink_button-'+link_id).innerHTML = 'Unlink';
                        alert(response);
                    }
                }
            }
        );
    }
</script>
		<?php
	}
	
	function link_details_row($link){
		?>
		<span id='post_date_full' style='display:none;'><?php 
    		print $link['post_date'];
    	?></span>
    	<span id='check_date_full' style='display:none;'><?php
    		print $link['last_check'];
    	?></span>
    	<ol style='list-style-type: none; width: 50%; float: right;'>
    		<li><strong>Log :</strong>
    	<span class='blc_log'><?php 
    		print nl2br($link['log']); 
    	?></span></li>
		</ol>
		
    	<ol style='list-style-type: none; padding-left: 2px;'>
    	<?php if ( !empty($link['post_date']) ) { ?>
    	<li><strong>Post published on :</strong>
    	<span class='post_date'><?php 
    		print strftime("%B %d, %Y",strtotime($link['post_date']));
    	?></span></li>
    	<?php } ?>
    	<li><strong>Link last checked :</strong>
    	<span class='check_date'><?php
			$last_check = strtotime($link['last_check']);
    		if ( $last_check < strtotime('-10 years') ){
				echo 'Never';
			} else {
    			echo strftime( "%B %d, %Y", $last_check );
    		}
    	?></span></li>
    	
    	<li><strong>HTTP code :</strong>
    	<span class='http_code'><?php 
    		print $link['http_code']; 
    	?></span></li>
    	
    	<li><strong>Response time :</strong>
    	<span class='request_duration'><?php 
    		printf('%2.3f seconds', $link['request_duration']); 
    	?></span></li>
    	
    	<li><strong>Final URL :</strong>
    	<span class='final_url'><?php 
    		print $link['final_url']; 
    	?></span></li>
    	
    	<li><strong>Redirect count :</strong>
    	<span class='redirect_count'><?php 
    		print $link['redirect_count']; 
    	?></span></li>
    	
    	<li><strong>Instance count :</strong>
    	<span class='instance_count'><?php 
    		print $link['instance_count']; 
    	?></span></li>
    	
    	<?php if ( intval( $link['check_count'] ) > 0 ){ ?>
    	<li><br/>This link has failed 
    	<span class='check_count'><?php
			echo $link['check_count']; 
    		if ( intval($link['check_count'])==1 ){
				echo ' time';
			} else {
				echo ' times';
			}
    	?></span>.</li>
    	<?php } ?>
		</ol>
		<?php
	}
    
  /**
   * ws_broken_link_checker::cleanup_links()
   * Remove orphaned links that have no corresponding instances
   *
   * @param int $link_id (optional) Only check this link
   * @return bool
   */
    function cleanup_links( $link_id = null ){
		global $wpdb;
		
		$q = "DELETE FROM {$wpdb->prefix}blc_links 
				USING {$wpdb->prefix}blc_links LEFT JOIN {$wpdb->prefix}blc_instances 
					ON {$wpdb->prefix}blc_instances.link_id = {$wpdb->prefix}blc_links.link_id
				WHERE
					{$wpdb->prefix}blc_instances.link_id IS NULL";
					
		if ( $link_id !==null ) {
			$q .= " AND {$wpdb->prefix}blc_links.link_id = " . intval( $link_id );
		}
		
		return $wpdb->query( $q );
	}
	
  /**
   * ws_broken_link_checker::cleanup_instances()
   * Remove instances that reference invalid posts or bookmarks
   *
   * @return bool
   */
	function cleanup_instances(){
		global $wpdb;
		
		//Delete all instances that reference non-existent posts
		$q = "DELETE FROM {$wpdb->prefix}blc_instances 
			  USING {$wpdb->prefix}blc_instances LEFT JOIN {$wpdb->posts} ON {$wpdb->prefix}blc_instances.source_id = {$wpdb->posts}.ID
			  WHERE
			    {$wpdb->posts}.ID IS NULL
				AND ( ( {$wpdb->prefix}blc_instances.source_type = 'post' ) OR ( {$wpdb->prefix}blc_instances.source_type = 'custom_field' ) )";
		$rez = $wpdb->query($q);
		
		//Delete all instances that reference non-existant bookmarks
		$q = "DELETE FROM {$wpdb->prefix}blc_instances 
			  USING {$wpdb->prefix}blc_instances LEFT JOIN {$wpdb->links} ON {$wpdb->prefix}blc_instances.source_id = {$wpdb->links}.link_id
			  WHERE
			    {$wpdb->links}.link_id IS NULL
				AND {$wpdb->prefix}blc_instances.source_type = 'blogroll' ";
		$rez2 = $wpdb->query($q);
		
		return $rez and $rez2;
	}
	
	function load_options(){
        $this->options = get_option($this->options_name);
        if(!is_array($this->options)){
            $this->options = $this->defaults;
        } else {
            $this->options = array_merge($this->defaults, $this->options);
        }
	}
	
	function save_options(){
		update_option($this->options_name,$this->options);
	}
	
  /**
   * ws_broken_link_checker::parse_post()
   * Parse a post for links and save them to the DB. 
   *
   * @param string $content Post content
   * @param int $post_id Post ID
   * @return void
   */
	function parse_post($content, $post_id){
		//remove all <code></code> blocks first
		$content = preg_replace('/<code>.+?<\/code>/i', ' ', $content);
		
		//Find links
		if(preg_match_all(blcUtility::link_pattern(), $content, $matches, PREG_SET_ORDER)){
			foreach($matches as $link){
				$url = $link[3];
				$text = strip_tags( $link[5] );
				//FB::log($url, "Found link");
				
				$url = blcUtility::normalize_url($url);
				//Skip invalid links
				if ( !$url || (strlen($url)<6) ) continue; 
			    
			    //Create or load the link
			    $link_obj = new blcLink($url);
			    //Add & save a new instance
				$link_obj->add_instance($post_id, 'post', $text, 'link');
			}
		};
		
		//Find images (<img src=...>)
		if(preg_match_all(blcUtility::img_pattern(), $content, $matches, PREG_SET_ORDER)){
			foreach($matches as $img){
				$url = $img[3];
				//FB::log($url, "Found image");
				
				$url = blcUtility::normalize_url($url);
				if ( !$url || (strlen($url)<6) ) continue; //skip invalid URLs
				
		        //Create or load the link
			    $link = new blcLink($url);
			    //Add & save a new image instance
				$link->add_instance($post_id, 'post', '', 'image');
			}
		};
	}
	
  /**
   * ws_broken_link_checker::parse_post_meta()
   * Parse a post's custom fields for links and save them in the DB.
   *
   * @param id $post_id
   * @return void
   */
	function parse_post_meta($post_id){
		//Get all custom fields of this post 
		$custom_fields = get_post_custom( $post_id );
		//FB::log($custom_fields, "Custom fields loaded");
		
		//Parse the enabled fields
		foreach( $this->options['custom_fields'] as $field ){
			if ( !isset($custom_fields[$field]) ) continue;
			
			//FB::log($field, "Parsing field");
			
			$values = $custom_fields[$field];
			if ( !is_array( $values ) ) $values = array($values);
			
			foreach( $values as $value ){

				//Attempt to parse the $value as URL
				$url = blcUtility::normalize_url($value);
				if ( empty($url) ){
					//FB::warn($value, "Invalid URL in custom field ".$field);
					continue;
				}
				
				//FB::log($url, "Found URL");
				$link = new blcLink( $url );
				//FB::log($link, 'Created/loaded link');
				$inst = $link->add_instance( $post_id, 'custom_field', $field, 'link' );
				//FB::log($inst, 'Created instance');				
			} 
		}
		
	}
	
	function parse_blogroll_link( $the_link ){
		//FB::log($the_link, "Parsing blogroll link");
		
		//Attempt to parse the URL
		$url = blcUtility::normalize_url( $the_link['link_url'] );
		if ( empty($url) ){
			//FB::warn( $the_link['link_url'], "Invalid URL in for a blogroll link".$the_link['link_name'] );
			return false;
		}
		
		//FB::log($url, "Found URL");
		$link = new blcLink( $url );
		return $link->add_instance( $the_link['link_id'], 'blogroll', $the_link['link_name'], 'link' );
	}
	
	function start_timer(){
		$this->execution_start_time = microtime_float();
	}
	
	function execution_time(){
		return microtime_float() - $this->execution_start_time;
	}
	
  /**
   * ws_broken_link_checker::work()
   * The main worker function that does all kinds of things.
   *
   * @return void
   */
	function work(){
		global $wpdb;
		
		if ( !$this->acquire_lock() ){
			//FB::warn("Another instance of BLC is already working. Stop.");
			return false;
		}
		
		$this->start_timer();
		
		$max_execution_time = $this->options['max_execution_time'];
	
		/*****************************************
						Preparation
		******************************************/
		// Check for safe mode
		if( ini_get('safe_mode') ){
		    // Do it the safe mode way
		    $t=ini_get('max_execution_time');
		    if ($t && ($t < $max_execution_time)) 
		    	$max_execution_time = $t-1;
		} else {
		    // Do it the regular way
		    @set_time_limit( $max_execution_time * 2 ); //x2 should be plenty, running any longer would mean a glitch.
		}
		@ignore_user_abort(true);
		
		$check_threshold = date('Y-m-d H:i:s', strtotime('-'.$this->options['check_threshold'].' hours'));
		$recheck_threshold = date('Y-m-d H:i:s', strtotime('-20 minutes'));
		
		$orphans_possible = false;
		
		$still_need_resynch = $this->options['need_resynch'];
		
		/*****************************************
				Parse posts and bookmarks
		******************************************/
		
		if ( $this->options['need_resynch'] ) {
			
			//FB::log("Looking for posts and bookmarks that need parsing...");
			
			$tsynch = $wpdb->prefix.'blc_synch';
			$tposts = $wpdb->posts;
			$tlinks = $wpdb->links;
			
			$synch_q = "SELECT $tsynch.source_id, $tsynch.source_type, $tposts.post_content, $tlinks.link_url, $tlinks.link_id, $tlinks.link_name
	
				FROM 
				 $tsynch LEFT JOIN $tposts 
				   ON ($tposts.id = $tsynch.source_id AND $tsynch.source_type='post')
				 LEFT JOIN $tlinks 
				   ON ($tlinks.link_id = $tsynch.source_id AND $tsynch.source_type='blogroll')
				
				WHERE 
				  $tsynch.synched = 0
				  
				LIMIT 50";
				  
			while ( $rows = $wpdb->get_results($synch_q, ARRAY_A) ) {
				
				//FB::log("Found ".count($rows)." items to analyze.");
				
				foreach ($rows as $row) {
					
					if ( $row['source_type'] == 'post' ){
						
						//FB::log("Parsing post ".$row['source_id']);
						
						//Remove instances associated with this post
						$q = "DELETE FROM {$wpdb->prefix}blc_instances 
							  WHERE source_id = %d AND (source_type = 'post' OR source_type='custom_field')";
						$q = $wpdb->prepare($q, intval($row['source_id']));
						
						//FB::log($q, "Executing query");
				        
				        if ( $wpdb->query( $q ) === false ){
							//FB::error($wpdb->last_error, "Database error");
						}
				        
				        //Gather links and images from the post
				        $this->parse_post( $row['post_content'], $row['source_id'] );
				        //Gather links from custom fields
				        $this->parse_post_meta( $row['source_id'] );
				        
						//Some link records might be orhpaned now 
						$orphans_possible = true;
						
					} else {
						
						//FB::log("Parsing bookmark ".$row['source_id']);
						
						//Remove instances associated with this bookmark
						$q = "DELETE FROM {$wpdb->prefix}blc_instances 
							  WHERE source_id = %d AND source_type = 'blogroll'";
						$q = $wpdb->prepare($q, intval($row['source_id']));
						//FB::log($q, "Executing query");
						
				        if ( $wpdb->query( $q ) === false ){
							//FB::error($wpdb->last_error, "Database error");
						}
						
						//(Re)add the instance and link
						$this->parse_blogroll_link( $row );
						
						//Some link records might be orhpaned now 
						$orphans_possible = true;
						
					}
					
					//Update the table to indicate the item has been parsed
				    $this->mark_synched( $row['source_id'], $row['source_type'] );
				    
				    //Check if we still have some execution time left
					if( $this->execution_time() > $max_execution_time ){
						//FB::log('The alloted execution time has run out');
						$this->cleanup_links();
						$this->release_lock();
						return;
					}
					
				}

			}
			
			//FB::log('No unparsed items found.');
			$still_need_resynch = false;
			
			if ( $wpdb->last_error ){
				//FB::error($wpdb->last_error, "Database error");
			}
			
		} else {
			//FB::log('Resynch not required.');
		}
		
		/******************************************
				    Resynch done?
		*******************************************/
		if ( $this->options['need_resynch'] && !$still_need_resynch ){
			$this->options['need_resynch']  = $still_need_resynch;
			$this->save_options();
		}
		
		/******************************************
				    Remove orphaned links
		*******************************************/
		
		if ( $orphans_possible ) {
			//FB::log('Cleaning up the link table.');
			$this->cleanup_links();
		}
		
		//Check if we still have some execution time left
		if( $this->execution_time() > $max_execution_time ){
			//FB::log('The alloted execution time has run out');
			$this->release_lock();
			return;
		}
		
		/*****************************************
						Check links
		******************************************/
		//FB::log('Looking for links to check (threshold : '.$check_threshold.')...');
		
		//Select some links that haven't been checked for a long time or
		//that are broken and need to be re-checked again.
		
		//Note : This is a slow query, but AFAIK there is no way to speed it up.
		//I could put an index on last_check, but that value is almost certainly unique
		//for each row so it wouldn't be much better than a full table scan.
		$q = "SELECT *, ( last_check < %s ) AS meets_check_threshold
			  FROM {$wpdb->prefix}blc_links
		      WHERE 
			  	( last_check < %s ) 
				OR 
		 	  	( 
					( http_code >= 400 OR http_code < 200 OR timeout = 1) 
					AND check_count < %d 
					AND check_count > 0  
					AND last_check < %s 
				) 
			  ORDER BY last_check ASC
		 	  LIMIT 50";
		$link_q = $wpdb->prepare($q, $check_threshold, $check_threshold, $this->options['recheck_count'], $recheck_threshold);
		//FB::log($link_q);
		
		while ( $links = $wpdb->get_results($link_q, ARRAY_A) ){
		
			//some unchecked links found
			//FB::log("Checking ".count($links)." link(s)");
			
			foreach ($links as $link) {
				$link_obj = new blcLink($link);
				
				//Does this link need to be checked?
        		if ( !$this->is_excluded( $link['url'] ) ) {
        			//Yes, do it
        			//FB::log("Checking link {$link[link_id]}");
					$link_obj->check();
					$link_obj->save();
				} else {
					//Nope, mark it as already checked.
					//FB::info("The URL {$link_obj->url} is excluded, marking link {$link_obj->link_id} as already checked.");
					$link_obj->last_check = date('Y-m-d H:i:s');
					$link_obj->http_code = 200; //Use a fake code so that the link doesn't show up in queries looking for broken links.
					$link_obj->timeout = false;
					$link_obj->request_duration = 0; 
					$link_obj->log = "This link wasn't checked because a matching keyword was found on your exclusion list.";
					$link_obj->save();
				} 
				
				//Check if we still have some execution time left
				if( $this->execution_time() > $max_execution_time ){
					//FB::log('The alloted execution time has run out');
					$this->release_lock();
					return;
				}
			}
		}
		//FB::log('No links need to be checked right now.');
		
		$this->release_lock();
		//FB::log('All done.');
	}
	
	function ajax_full_status( ){
		$status = $this->get_status();
		$text = $this->status_text( $status );
		
		echo json_encode( array(
			'text' => $text,
			'status' => $status, 
		 ) );
		
		die();
	}
	
	function status_text( $status ){
		$text = '';
	
		if( $status['broken_links'] > 0 ){
			$text .= sprintf( "<a href='%stools.php?page=view-broken-links' title='View broken links'><strong>Found %d broken link%s</strong></a>",
			  get_option('wpurl'), $status['broken_links'], ( $status['broken_links'] == 1 )?'':'s' );
		} else {
			$text .= "No broken links found.";
		}
		
		$text .= "<br/>";
		
		if( $status['unchecked_links'] > 0) {
			$text .= sprintf( '%d URL%s in the work queue', $status['unchecked_links'], ($status['unchecked_links'] == 1)?'':'s' );
		} else {
			$text .= "No URLs in the work queue.";
		}
		
		$text .= "<br/>";
		if ( $status['known_links'] > 0 ){
			$text .= sprintf( "Detected %d unique URL%s in %d link%s",
				$status['known_links'], $status['known_links'] == 1 ? '' : 's',
				$status['known_instances'], $status['known_instances'] == 1 ? '' : 's'
			 );
			if ($this->options['need_resynch']){
				$text .= ' and still searching...';
			} else {
				$text .= '.';
			}
		} else {
			if ($this->options['need_resynch']){
				$text .= 'Searching your blog for links...';
			} else {
				$text .= 'No links detected.';
			}
		}
		
		return $text;
	}
	
	function ajax_dashboard_status(){
		//Just display the full status.
		$this->ajax_full_status( false );
		/*
		global $wpdb;
		
		//displays a notification if broken links have been found 
		$q = "SELECT count(*) FROM {$wpdb->prefix}blc_links 
			  WHERE check_count > 0 AND ( http_code < 200 OR http_code >= 400 OR timeout = 1 )";
		$broken_links = $wpdb->get_var($q);
		
		
		if($broken_links>0){
			printf( "<a href='%stools.php?page=view-broken-links' title='View broken links'><strong>Found %d broken link%s</strong></a>",
			  get_option('wpurl'), $broken_links, ($broken_links==1)?'':'s' );
		} else {
			echo "No broken links found.";
		}
		die();
		*/
	}
	
	function get_status(){
		global $wpdb;
		
		$check_threshold=date('Y-m-d H:i:s', strtotime('-'.$this->options['check_threshold'].' hours'));
		$recheck_threshold=date('Y-m-d H:i:s', strtotime('-20 minutes'));
		
		$q = "SELECT count(*) FROM {$wpdb->prefix}blc_links WHERE 1";
		$known_links = $wpdb->get_var($q);
		
		$q = "SELECT count(*) FROM {$wpdb->prefix}blc_instances WHERE 1";
		$known_instances = $wpdb->get_var($q);
		
		$q = "SELECT count(*) FROM {$wpdb->prefix}blc_links 
			  WHERE check_count > 0 AND ( http_code < 200 OR http_code >= 400 OR timeout = 1 )";
		$broken_links = $wpdb->get_var($q);
		
		$q = "SELECT count(*) FROM {$wpdb->prefix}blc_links
		      WHERE 
			  	( ( last_check < '$check_threshold' ) OR 
		 	  	  ( 
					 ( http_code >= 400 OR http_code < 200 ) 
					 AND check_count < 3 
					 AND last_check < '$recheck_threshold' ) 
				  )";
		$unchecked_links = $wpdb->get_var($q);
		
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
	
	function ajax_discard(){
		//TODO:Rewrite to use JSON instead of plaintext		
		if (!current_user_can('edit_others_posts')){
			die( "You're not allowed to do that!" );
		}
		
		if ( isset($_POST['link_id']) ){
			//Load the link
			$link = new blcLink( intval($_POST['link_id']) );
			
			if ( !$link->valid() ){
				die("Oops, I can't find the link ".intval($_POST['link_id']) );
			}
			//Make it appear "not broken"  
			$link->last_check = date('Y-m-d H:i:s');
			$link->http_code =  200;
			$link->timeout = 0;
			$link->check_count = 0;
			$link->log = "This link was manually marked as working by the user.";
			
			//Save the changes
			if ( $link->save() ){
				die("OK");
			} else {
				die("Oops, couldn't modify the link!");
			}
		} else {
			die("Error : link_id not specified");
		}
	}
	
	function ajax_edit(){
		if (!current_user_can('edit_others_posts')){
			die( json_encode( array(
					'error' => "You're not allowed to do that!" 
				 )));
		}
		
		if ( isset($_GET['link_id']) && !empty($_GET['new_url']) ){
			//Load the link
			$link = new blcLink( intval($_GET['link_id']) );
			
			if ( !$link->valid() ){
				die( json_encode( array(
					'error' => "Oops, I can't find the link ".intval($_GET['link_id']) 
				 )));
			}
			
			$new_url = blcUtility::normalize_url($_GET['new_url']);
			if ( !$new_url ){
				die( json_encode( array(
					'error' => "Oops, the new URL is invalid!" 
				 )));
			}
			
			//Try and edit the link
			$rez = $link->edit($new_url);
			
			if ( $rez == false ){
				die( json_encode( array(
					'error' => "An unexpected error occured!"
				 )));
			} else {
				$rez['ok'] = 'OK';
				die( json_encode($rez) );
			}
			
		} else {
			die( json_encode( array(
					'error' => "Error : link_id or new_url not specified"
				 )));
		}
	}
	
	function ajax_unlink(){
		if (!current_user_can('edit_others_posts')){
			die( json_encode( array(
					'error' => "You're not allowed to do that!" 
				 )));
		}
		
		if ( isset($_POST['link_id']) ){
			//Load the link
			$link = new blcLink( intval($_POST['link_id']) );
			
			if ( !$link->valid() ){
				die( json_encode( array(
					'error' => "Oops, I can't find the link ".intval($_POST['link_id']) 
				 )));
			}
			
			//Try and unlink it
			if ( $link->unlink() ){
				die( json_encode( array(
					'ok' => "URL {$link->url} was removed." 
				 )));
			} else {
				die( json_encode( array(
					'error' => "The plugin failed to remove the link." 
				 )));
			}
			
		} else {
			die( json_encode( array(
					'error' => "Error : link_id not specified" 
				 )));
		}
	}
	
	function ajax_link_details(){
		global $wpdb;
		
		if (!current_user_can('edit_others_posts')){
			die("You don't have sufficient privileges to access this information!");
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
			die('Error : link ID not specified');
		}
		
		//Load the link. link_details_rwo needs it as an array, so 
		//we'll have to do this the long way.
		$q = "SELECT 
				 links.*, 
				 COUNT(*) as instance_count
				
			  FROM 
				 {$wpdb->prefix}blc_links AS links, 
				 {$wpdb->prefix}blc_instances as instances
				
			   WHERE
				 links.link_id = %d
				
			   GROUP BY links.link_id";
		
		$link = $wpdb->get_row( $wpdb->prepare($q, $link_id), ARRAY_A );
		if ( is_array($link) ){
			//FB::info($link, 'Link loaded');
			$this->link_details_row($link);
			die();
		} else {
			die ("Failed to load link details (" . $wpdb->last_error . ")");
		}
	}
	
	function ajax_exclude_link(){
		if ( !current_user_can('manage_options') ){
			die( json_encode( array(
					'error' => "You're not allowed to do that!" 
				 )));
		}
		
		if ( isset($_POST['link_id']) ){
			//Load the link
			$link = new blcLink( intval($_POST['link_id']) );
			
			if ( !$link->valid() ){
				die( json_encode( array(
					'error' => "Oops, I can't find the link ".intval($_POST['link_id']) 
				 )));
			}
			
			//Add the URL to the exclusion list
			if ( !in_array( $link->url, $this->options['exclusion_list'] ) ){
				$this->options['exclusion_list'][] = $link->url;
				//Also mark it as already checked so that it doesn't show up with other broken links.
				//FB::info("The URL {$link->url} is excluded, marking link {$link->link_id} as already checked.");
				$link->last_check = date('Y-m-d H:i:s');
				$link->http_code = 200; //Use a fake code so that the link doesn't show up in queries looking for broken links.
				$link->timeout = false;
				$link->request_duration = 0; 
				$link->log = "This link wasn't checked because a matching keyword was found on your exclusion list.";
				$link->save();
			}
				 
			$this->save_options();
			
			die( json_encode( array(
					'ok' => "URL {$link->url} added to the exclusion list" 
				 )));
		} else {
			die( json_encode( array(
					'error' => "Link ID not specified" 
			 )));
		}
	}
	
  /**
   * ws_broken_link_checker::acquire_lock()
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
			//Uh oh, can't generate a lockfile name. Locking will be disabled. 
			return true;
		};
	}
	
  /**
   * ws_broken_link_checker::release_lock()
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
   * ws_broken_link_checker::lockfile_name()
   * Generate system-specific lockfile filename
   *
   * @return string A filename or FALSE on error 
   */
	function lockfile_name(){
		//Try the plugin's directory first.
		if ( is_writable( dirname(__FILE__) ) ){
			return dirname(__FILE__) . '/wp_blc_lock';
		} else {
			//Try to find the temp directory.
			$dummy = tempnam('asdf', 'blc');
			if ( $dummy ) {
				$path = dirname($dummy);
				//Kill the dummy file
				if ( file_exists( $dummy ) ) unlink($dummy);
				return $path . '/wp_blc_lock';
			} else {
				//Try /tmp as a last resort
				if ( is_writable('/tmp') ){
					return '/tmp/wp_blc_lock';
				} else {
					return false;
				}
			}
		}
	}
	
	function hook_add_link( $link_id ){
		$this->mark_unsynched( $link_id, 'blogroll' );
	}
	
	function hook_edit_link( $link_id ){
		$this->mark_unsynched( $link_id, 'blogroll' );
	}
	
	function hook_delete_link( $link_id ){
		global $wpdb;
		//Delete the synch record
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}blc_synch WHERE source_id = %d AND source_type='blogroll'", $link_id ) );
		
		//Get the matching instance record.
		$inst = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}blc_instances WHERE source_id = %d AND source_type = 'blogroll'", $link_id), ARRAY_A );
		
		if ( !$inst ) {
			//No instance record? No problem.
			return;
		}

		//Remove it
		$wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->prefix}blc_instances WHERE instance_id = %d", $inst['instance_id']) );

		//Remove the link that was associated with this instance if it has no more related instances.
		$this->cleanup_links( $inst['link_id'] );
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

}//class ends here

} // if class_exists...

if ( !isset($ws_link_checker) )
	$ws_link_checker = new ws_broken_link_checker();

?>