<?php
/*
Plugin Name: Broken Link Checker
Plugin URI: http://w-shadow.com/blog/2007/08/05/broken-link-checker-for-wordpress/
Description: Checks your posts for broken links and missing images and notifies you on the dashboard if any are found.
Version: 0.3.9
Author: Janis Elsts
Author URI: http://w-shadow.com/blog/
*/

/*
Created by Janis Elsts (email : whiteshadow@w-shadow.com) 
MySQL 4.0 compatibility by Jeroen (www.yukka.eu)
*/

if (!class_exists('ws_broken_link_checker')) {

class ws_broken_link_checker {
	var $options;
	var $options_name='wsblc_options';
	var $postdata_name;
	var $linkdata_name;
	var $version='0.3.9';
	var $myfile='';
	var $myfolder='';
	var $mybasename='';
	var $siteurl;
	var $defaults;
	
	function ws_broken_link_checker() {
		global $wpdb;
		
		//set default options
		$this->defaults = array(
			'version' => $this->version,
			'max_work_session' => 27,
			'check_treshold' => 72,
			'mark_broken_links' => true,
			'broken_link_css' => ".broken_link, a.broken_link {\n\ttext-decoration: line-through;\n}",
			'exclusion_list' => array(),
			'delete_post_button' => false
		);
		//load options
		$this->options=get_option($this->options_name);
		if(!is_array($this->options)){
			$this->options = $this->defaults;				
		} else {
			$this->options = array_merge($this->defaults, $this->options);
		}
		
		$this->postdata_name=$wpdb->prefix . "blc_postdata";
		$this->linkdata_name=$wpdb->prefix . "blc_linkdata";
		$this->siteurl = get_option('siteurl');
		
		$my_file = str_replace('\\', '/',__FILE__);
		$my_file = preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', $my_file);
		add_action('activate_'.$my_file, array(&$this,'activation'));
		$this->myfile=$my_file;
		$this->myfolder=basename(dirname(__FILE__));
		$this->mybasename=plugin_basename(__FILE__);

		add_action('admin_menu', array(&$this,'options_menu'));
		
		add_action('delete_post', array(&$this,'post_deleted'));
		add_action('save_post', array(&$this,'post_saved'));
		add_action('admin_footer', array(&$this,'admin_footer'));
		add_action('admin_print_scripts', array(&$this,'admin_print_scripts'));
		add_action('activity_box_end', array(&$this,'activity_box'));
		
		if (!empty($this->options['mark_broken_links'])){
			add_filter('the_content', array(&$this,'the_content'));
			if (!empty($this->options['broken_link_css'])){
				add_action('wp_head', array(&$this,'header_css'));
			}
		}
	}
	
	function admin_footer(){
		?>
		<!-- wsblc admin footer -->
		<div id='wsblc_updater_div'></div>
		<script type='text/javascript'>
			new Ajax.PeriodicalUpdater('wsblc_updater_div', '<?php 
				echo get_option( "siteurl" ).'/wp-content/plugins/'.$this->myfolder.'/wsblc_ajax.php?action=run_check' ; ?>', 
			{
				method: 'get',
				frequency: <?php echo ($this->options['max_work_session']-1); ?>,
				decay: 1.3
			});
		</script>		
		<!-- /wsblc admin footer -->
		<?php
	}
	
	function header_css(){
		echo '<style type="text/css">',$this->options['broken_link_css'],'</style>';
	}
	
	function the_content($content){
		global $post, $wpdb;
		if (empty($post)) return $content;
		
		$sql="SELECT url from $this->linkdata_name WHERE post_id = $post->ID AND broken<>0";
		$rows=$wpdb->get_results($sql, ARRAY_A);
		if($rows && (count($rows)>0)){
			//some rows found
			$this->links_to_remove = array_map(
				create_function('$elem', 'return $elem["url"];'),
			    $rows);
			$url_pattern='/(<a[\s]+[^>]*href\s*=\s*[\"\']?)([^\'\" >]+)([\'\"]+[^<>]*)(>)((?sU).*)(<\/a>)/i';
			$content = preg_replace_callback($url_pattern, array(&$this,'mark_broken_links'), $content);
		};
		
		//print_r($post);
		return $content;
	}
	
	function mark_broken_links($matches){
		$url = $this->normalize_url(html_entity_decode($matches[2])) ;
		if(in_array($url, $this->links_to_remove)){
			return $matches[1].$matches[2].$matches[3].' class="broken_link"'.$matches[4].
				$matches[5].$matches[6];	
		} else {
			return $matches[0];
		}
	}
	
	function normalize_url($url){
		$parts=@parse_url($url);
		if(!$parts) return false;
		
		$url = html_entity_decode($url);
		$url=preg_replace(
	    	array('/([\?&]PHPSESSID=\w+)$/i', 
				  '/(#[^\/]*)$/', 
				  '/&amp;/',
				  '/^(javascript:.*)/i',
				  '/([\?&]sid=\w+)$/i'
				  ),
	    	array('','','&','',''),
	    	$url);
	    $url=trim($url);
	    
	    if (strpos($url, 'mailto:')!==false) return false;
	    if($url=='') return false;
	    
        // turn relative URLs into absolute URLs
        $url = $this->relative2absolute($this->siteurl, $url);
        return $url;
	}
	
	function relative2absolute($absolute, $relative) {
        $p = @parse_url($relative);
        if(!$p) {
	        //WTF? $relative is a seriously malformed URL
	        return false;
        }
        if(isset($p["scheme"])) return $relative;
        
        $parts=(parse_url($absolute));
        
        if(substr($relative,0,1)=='/') {
            $cparts = (explode("/", $relative));
            array_shift($cparts);
        } else {
	        if(isset($parts['path'])){
	        	$aparts=explode('/',$parts['path']);
        		array_pop($aparts);
        		$aparts=array_filter($aparts);
    		} else {
	    		$aparts=array();
    		}
            
        	$rparts = (explode("/", $relative));
            
            $cparts = array_merge($aparts, $rparts);
            foreach($cparts as $i => $part) {
                if($part == '.') {
                    unset($cparts[$i]);
                } else if($part == '..') {
                    unset($cparts[$i]);
                    unset($cparts[$i-1]);
                }
            }
        }
        $path = implode("/", $cparts);
        
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
	
	function is_excluded($url){
		if (!is_array($this->options['exclusion_list'])) return false;
		foreach($this->options['exclusion_list'] as $excluded_word){
			if (stristr($url, $excluded_word)){
				return true;
			}
		}
		return false;
	}
	
	function activity_box(){
		?>
		<!-- wsblc activity box -->
		<div id='wsblc_activity_box'></div>
		<script type='text/javascript'>
			new Ajax.Updater('wsblc_activity_box', '<?php 
				echo get_option( "siteurl" ).'/wp-content/plugins/'.$this->myfolder.'/wsblc_ajax.php?action=dashboard_status' ; ?>');
		</script>		
		<!-- /wsblc activity box -->
		<?php
	}
	
	function admin_print_scripts(){
		// use JavaScript Prototype library for AJAX
		wp_enqueue_script('prototype');
	}
	
	function post_deleted($post_id){
		global $wpdb;
		$sql="DELETE FROM ".$this->linksdata_name." WHERE post_id=$post_id";
		$wpdb->query($sql);
		$sql="DELETE FROM ".$this->postdata_name." WHERE post_id=$post_id";
		$wpdb->query($sql);
	}
	
	function post_saved($post_id){
		global $wpdb;
		
		$found=$wpdb->get_var("SELECT post_id FROM $this->postdata_name WHERE post_id=$post_id LIMIT 1");
		if($found===NULL){
			//this post hasn't been saved previously, save the additional data now
			$wpdb->query("INSERT INTO $this->postdata_name (post_id, last_check) VALUES($post_id, '00-00-0000 00:00:00')");
		} else {
			//mark the post as not checked 
			$wpdb->query("UPDATE $this->postdata_name SET last_check='00-00-0000 00:00:00' WHERE post_id=$post_id");
			//delete the previously extracted links - they are possibly no longer in the post
			$wpdb->query("DELETE FROM $this->linkdata_name WHERE post_id=$post_id");
		}
	}
	
	
	function sync_posts_to_db(){
		global $wpdb;
		
		/* JHS: This query does not work on mySQL 4.0 (4.0 does not support subqueries). 
		// However, this one is faster, so I'll leave it here (forward compatibility)
		$sql="INSERT INTO ".$this->postdata_name."( post_id, last_check )
				SELECT id, '00-00-0000 00:00:00'
				FROM $wpdb->posts b
				WHERE NOT EXISTS (
					SELECT post_id
					FROM ".$this->postdata_name." a
					WHERE a.post_id = b.id
				)";  */
		//JHS: This one also works on mySQL 4.0:	
		$sql="INSERT INTO ".$this->postdata_name."(post_id, last_check) 
				SELECT ".$wpdb->posts.".id, '00-00-0000 00:00:00' FROM ".$wpdb->posts."
  			LEFT JOIN ".$this->postdata_name." ON ".$wpdb->posts.".id=".$this->postdata_name.".post_id
  			WHERE ".$this->postdata_name.".post_id IS NULL";
		$wpdb->query($sql);
	}
	
	//JHS: Clears all blc tables and initiates a new fresh recheck
	function recheck_all_posts(){
		global $wpdb;
		
		//Empty blc_linkdata		
		$sql="TRUNCATE TABLE ".$this->linkdata_name; 
		$wpdb->query($sql);
		
		//Empty table [aggressive approach]
		$sql="TRUNCATE TABLE ".$this->postdata_name; 
		//Reset all dates to zero [less aggressive approach, I like the above one better, it's cleaner ;)]
		//$sql="UPDATE $this->postdata_name SET last_check='00-00-0000 00:00:00' WHERE 1"; 
		
		$wpdb->query($sql);
		
		$this->sync_posts_to_db();
	}
	
	function activation(){
		global $wpdb;
		
		//option default were already set in the constructor
		update_option($this->options_name, $this->options);
		
		require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
		
		if (($wpdb->get_var("show tables like '".($this->postdata_name)."'") != $this->postdata_name)
		     || ($this->options['version'] != $this->version ) ) {
			$sql="CREATE TABLE ".$this->postdata_name." (
					post_id BIGINT( 20 ) NOT NULL ,
					last_check DATETIME NOT NULL ,
					UNIQUE KEY post_id (post_id)
				);";
			
      		dbDelta($sql);
		}
		
		if (($wpdb->get_var("show tables like '".($this->linkdata_name)."'") != $this->linkdata_name) 
		     || ($this->options['version'] != $this->version ) ) {
			$sql="CREATE TABLE ".$this->linkdata_name." (
					id BIGINT( 20 ) UNSIGNED NOT NULL AUTO_INCREMENT ,
					post_id BIGINT( 20 ) NOT NULL ,
					url TEXT NOT NULL ,
					link_text VARCHAR( 50 ) NOT NULL ,
					broken TINYINT( 1 ) UNSIGNED DEFAULT '0' NOT NULL,
					last_check DATETIME NOT NULL ,
					check_count TINYINT( 2 ) UNSIGNED DEFAULT '0' NOT NULL, 
					PRIMARY KEY id (id)
				);";
			
      		dbDelta($sql);
		}
		
		$this->sync_posts_to_db();
	}
	
	function options_menu(){
		add_options_page('Link Checker Settings', 'Link Checker', 'manage_options',
			__FILE__,array(&$this, 'options_page'));
		add_management_page('View Broken Links', 'Broken Links', 'manage_options',
			__FILE__,array(&$this, 'broken_links_page'));	
	}
	
	function mytruncate($str, $max_length=50){
		if(strlen($str)<=$max_length) return $str;
		return (substr($str, 0, $max_length-3).'...');
	}
	
	function options_page(){
		
		$this->options = get_option('wsblc_options');
		$reminder = '';
		//JHS: recheck all posts if asked for:
		if (isset($_GET['recheck']) && ($_GET['recheck'] == 'true')) {
			$this->recheck_all_posts();
		}
		if (isset($_GET['updated']) && ($_GET['updated'] == 'true')) {
			if(isset($_POST['Submit'])) {
				
				$new_session_length=intval($_POST['max_work_session']);
				if( $new_session_length >0 ){
					$this->options['max_work_session']=$new_session_length;
				}
				
				$new_check_treshold=intval($_POST['check_treshold']);
				if( $new_check_treshold > 0 ){
					$this->options['check_treshold']=$new_check_treshold;
				}
				
				$new_broken_link_css = trim($_POST['broken_link_css']);
				$this->options['broken_link_css'] = $new_broken_link_css;
				
				$this->options['mark_broken_links'] = !empty($_POST['mark_broken_links']);
				
				$this->options['delete_post_button'] = !empty($_POST['delete_post_button']);
				
				$this->options['exclusion_list']=array_filter(preg_split('/[\s,\r\n]+/', 
					$_POST['exclusion_list']));
				
				update_option($this->options_name,$this->options);
			}
			
		}
		echo $reminder;
		?>
		<div class="wrap"><h2>Broken Link Checker Options</h2>
		<?php if(!function_exists('curl_init')){ ?>
		<strong>Error: <a href='http://curl.haxx.se/libcurl/php/'>CURL library</a>
		 is not installed. This plugin won't work.</strong><br/>
		<?php }; ?>
		<form name="link_checker_options" method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=<?php echo plugin_basename(__FILE__); ?>&amp;updated=true"> 
		<p class="submit"><input type="submit" name="Submit" value="Update Options &raquo;" /></p>
		
		<table class="optiontable"> 
		
		<tr valign="top"> 
		<th scope="row">Status:</th> 
		<td>
		
		
		<div id='wsblc_full_status'>
			<br/><br/>
		</div>
		<script type='text/javascript'>
			new Ajax.PeriodicalUpdater('wsblc_full_status', '<?php 
				echo get_option( "siteurl" ).'/wp-content/plugins/'.$this->myfolder.'/wsblc_ajax.php?action=full_status' ; ?>', 
			{
				method: 'get',
				frequency: 10,
				decay: 2
			});
		</script>
		<?php //JHS: Recheck all posts link: ?>
		<p><input class="button" type="button" name="recheckbutton" value="Re-check all pages" onclick="location.replace('<?php echo $_SERVER['PHP_SELF']; ?>?page=<?php echo plugin_basename(__FILE__); ?>&amp;recheck=true')" /></p>
		</td> 
		</tr> 
		
		<tr valign="top"> 
		<th scope="row">Check Every Post:</th> 
		<td>
		
		Every <input type="text" name="check_treshold" id="check_treshold" 
			value="<?php echo $this->options['check_treshold']; ?>" size='5' maxlength='3'/> 
		hours
		<br/>
		Links in old posts will be re-checked this often. New posts will be usually checked ASAP.

		</td> 
		</tr> 
		
		<tr valign="top"> 
		<th scope="row">Broken Link CSS:</th> 
		<td>
		<input type="checkbox" name="mark_broken_links" id="mark_broken_links" 
			<?php if ($this->options['mark_broken_links']) echo ' checked="checked"'; ?>/>
			<label for='mark_broken_links'>Apply <em>class="broken_link"</em> to broken links</label><br/>
		<textarea type="text" name="broken_link_css" id="broken_link_css" cols='40' rows='4'/><?php
			if( isset($this->options['broken_link_css']) ) 
				echo $this->options['broken_link_css']; 
		?></textarea>	

		</td> 
		</tr>
		
		<tr valign="top"> 
		<th scope="row">Exclusion list:</th> 
		<td>Don't check links where the URL contains any of these words (one per line):<br/> 
		<textarea type="text" name="exclusion_list" id="exclusion_list" cols='40' rows='4'/><?php
			if( isset($this->options['exclusion_list']) ) 
				echo implode("\n", $this->options['exclusion_list']); 
		?></textarea>	

		</td> 
		</tr>
		
		<tr valign="top"> 
		<th scope="row">Work Session Length:</th> 
		<td>
		
		<input type="text" name="max_work_session" id="max_work_session" 
			value="<?php echo $this->options['max_work_session']; ?>" size='5' maxlength='3'/> 
		seconds
		<br/>
		The link checker does its work in short "sessions" while any page of the WP admin panel is open.
		Typically you won't need to change this value.

		</td> 
		</tr> 
		
		<tr valign="top"> 
		<th scope="row">"Delete Post" option:</th> 
		<td>
		
		<input type="checkbox" name="delete_post_button" id="delete_post_button" 
		<?php if ($this->options['delete_post_button']) echo " checked='checked'"; ?>/> 
		<label for='delete_post_button'>
		Display a "Delete Post" link in every row at the broken link list 
		(<em>Manage -&gt; Broken Links</em>). Not recommended.</label>

		</td> 
		</tr> 
		
		</table> 
		
		<p class="submit"><input type="submit" name="Submit" value="Update Options &raquo;" /></p>
		</form>
		</div>
		<?php 
	}
	
	function broken_links_page(){
		global $wpdb;
		$sql="SELECT count(*) FROM $this->linkdata_name WHERE broken=1";
		$broken_links=$wpdb->get_var($sql);
		
		?>
<div class="wrap">
<h2><?php
	echo ($broken_links>0)?"<span id='broken_link_count'>$broken_links</span> Broken Links":
			"No broken links found";
?></h2>
<br style="clear:both;" />
<?php
		$sql="SELECT b.post_title, a.*, b.guid FROM $this->linkdata_name a, $wpdb->posts b
			 WHERE a.post_id=b.id AND a.broken=1 ORDER BY a.last_check DESC";
		$links=$wpdb->get_results($sql, OBJECT);
		if($links && (count($links)>0)){
			?>
			<table class="widefat">
				<thead>
				<tr>
			
				<th scope="col"><div style="text-align: center">#</div></th>
			
				<th scope="col">Post</th>
				<th scope="col">Link Text</th>
				<th scope="col">URL</th>
			
				<th scope="col" colspan='<?php echo ($this->options['delete_post_button'])?'5':'4';x ?>'>Action</th>
			
				</tr>
				</thead>
				<tbody id="the-list">
			<?php
			
			$rownumber=0;
			foreach ($links as $link) {
				$rownumber++;
				echo "<tr id='link-$link->id' class='alternate'>
				<th scope='row' style='text-align: center'>$rownumber</th>
				<td>$link->post_title</td>

				<td>$link->link_text</td>
				<td>
					<a href='$link->url'>".$this->mytruncate($link->url)."</a>
					| <a href='javascript:editBrokenLink($link->id, \"$link->url\")' 
					id='link-editor-button-$link->id'>Edit</a>
					<br />
					<input type='text' size='50' id='link-editor-$link->id' value='$link->url' 
						class='link-editor' style='display:none' />
				</td>
				<td><a href='".(get_permalink($link->post_id))."' class='edit'>View</a></td>

				<td><a href='post.php?action=edit&amp;post=$link->post_id' class='edit'>Edit Post</a></td>";
				
				//the ""Delete Post"" button - optional
				if ($this->options['delete_post_button']){
					$deletion_url = "post.php?action=delete&post=$link->post_id";
					$deletion_url = wp_nonce_url($deletion_url, "delete-post_$link->post_id");
					echo "<td><a href='$deletion_url'>Delete Post</a></td>";
				}
				
				echo "<td><a href='javascript:void(0);' class='delete' id='discard_button-$link->id' 
				onclick='discardLinkMessage($link->id);return false;' );' title='Discard This Message'>Discard</a></td>
				
				<td><a href='javascript:void(0);' class='delete' id='unlink_button-$link->id'
				onclick='removeLinkFromPost($link->id);return false;' );' title='Remove the link from the post'>Unlink</a></td>
				</tr>";
				
			}
			
			echo '</tbody></table>';
		};
?>
<style type='text/css'>
.link-editor {
	font-size: 1em;
}
</style>

<script type='text/javascript'>
	function alterLinkCounter(factor){
		cnt = parseInt($('broken_link_count').innerHTML);
		cnt = cnt + factor;
		$('broken_link_count').innerHTML = cnt;
	}
	
	function discardLinkMessage(link_id){
		$('discard_button-'+link_id).innerHTML = 'Wait...';
		new Ajax.Request('<?php
		echo get_option( "siteurl" ).'/wp-content/plugins/'.$this->myfolder.'/wsblc_ajax.php?'; 
		?>action=discard_link&id='+link_id, 
			{ 
				method:'get',
				onSuccess: function(transport){
					var re = /OK:.*/i
      				var response = transport.responseText || "";
      				if (re.test(response)){
	      				$('link-'+link_id).hide();
	      				alterLinkCounter(-1);
      				} else {
	      				$('discard_button-'+link_id).innerHTML = 'Discard';
	      				alert(response);
      				}
    			}
			}
		);
		
	}
	function removeLinkFromPost(link_id){
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
	      				alterLinkCounter(-1);
      				} else {
	      				$('unlink_button-'+link_id).innerHTML = 'Unlink';
	      				alert(response);
      				}
    			}
			}
		);
	}
	
	function editBrokenLink(link_id, orig_link){
		if ($('link-editor-button-'+link_id).innerHTML == 'Edit'){
			$('link-editor-'+link_id).show();
			$('link-editor-button-'+link_id).innerHTML = 'Save';
		} else {
			$('link-editor-'+link_id).hide();
			new_url = $('link-editor-'+link_id).value;
			if (new_url != orig_link){
				//Save the changed link
				new Ajax.Request(			
					'<?php
				echo get_option( "siteurl" ).'/wp-content/plugins/'.$this->myfolder.'/wsblc_ajax.php?'; 
				?>action=edit_link&id='+link_id+'&new_url='+escape(new_url), 
					{ 
						method:'post',
						onSuccess: function(transport){
							var re = /OK:.*/i
		      				var response = transport.responseText || "";
		      				if (re.test(response)){
			      				$('link-'+link_id).hide();
			      				alterLinkCounter(-1);
			      				//alert(response);
		      				} else {
			      				alert(response);
		      				}
		    			}
					}
				);
				
			}
			$('link-editor-button-'+link_id).innerHTML = 'Edit';
		}
	}
</script>
</div>
		<?php
	}

}//class ends here

} // if class_exists...

$ws_link_checker = new ws_broken_link_checker();

?>