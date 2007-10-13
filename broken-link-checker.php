<?php
/*
Plugin Name: Broken Link Checker
Plugin URI: http://wordpress.org/extend/plugins/broken-link-checker/
Description: Checks your posts for broken links and missing images and notifies you on the dashboard if any are found.
Version: 0.2
Author: Janis Elsts
Author URI: http://w-shadow.com/blog/
*/

/*
Created by Janis Elsts (email : whiteshadow@w-shadow.com) 
*/

if (!class_exists('ws_broken_link_checker')) {

class ws_broken_link_checker {
	var $options;
	var $options_name='wsblc_options';
	var $postdata_name;
	var $linkdata_name;
	var $version='0.2';
	var $myfile='';
	var $myfolder='';
	var $mybasename='';
	

	function ws_broken_link_checker() {
		global $wpdb;
		
		$this->postdata_name=$wpdb->prefix . "blc_postdata";
		$this->linkdata_name=$wpdb->prefix . "blc_linkdata";
		$this->options=get_option($this->options_name);
		
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
  		wp_print_scripts( array( 'prototype' ) );
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
		
		$sql="INSERT INTO ".$this->postdata_name."( post_id, last_check )
				SELECT id, '00-00-0000 00:00:00'
				FROM $wpdb->posts b
				WHERE NOT EXISTS (
					SELECT post_id
					FROM ".$this->postdata_name." a
					WHERE a.post_id = b.id
				)";
		$wpdb->query($sql);
	}
	
	function activation(){
		global $wpdb;
		
		if(!is_array($this->options)){
			
			//set default options
			$this->options=array(
				'version' => $this->version,
				'max_work_session' => 27,
				'check_treshold' => 72
			);	
			
			update_option($this->options_name, $this->options);
		};
		
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
				
				update_option($this->options_name,$this->options);
			}
			
		}
		echo $reminder;
		?>
		<div class="wrap"><h2>Broken Link Checker Options</h2>
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
		
		</table> 
		
		<p class="submit"><input type="submit" name="Submit" value="Update Options &raquo;" /></p>
		</form>
		</div>
		<?php 
	}
	
	function broken_links_page(){
		global $wpdb;
		$sql="SELECT count(*) FROM $this->linkdata_name WHERE broken=1 AND hidden=0";
		$broken_links=$wpdb->get_var($sql);
		
		?>
<div class="wrap">
<h2><?php
	echo ($broken_links>0)?"$broken_links Broken Links":"No broken links found";
?></h2>
<br style="clear:both;" />
<?php
		$sql="SELECT b.post_title, a.* FROM $this->linkdata_name a, $wpdb->posts b
			 WHERE a.post_id=b.id AND a.broken=1 AND a.hidden=0 ORDER BY a.last_check DESC";
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
			
				<th scope="col"></th>
				<th scope="col"></th>
				<th scope="col"></th>
			
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
				<td><a href='$link->url'>".$this->mytruncate($link->url)."</a></td>
				<td><a href='".get_option('siteurl')."?p=$link->post_id' class='edit'>View</a></td>

				<td><a href='post.php?action=edit&amp;post=$link->post_id' class='edit'>Edit Post</a></td>
				<td><a href='javascript:void(0);' class='delete' 
				onclick='discardLinkMessage($link->id);return false;' );' title='Discard This Message'>Discard</a></td>
				</tr>";
				
			}
			
			echo '</tbody></table>';
		};
?>

<script type='text/javascript'>
	function discardLinkMessage(link_id){
		new Ajax.Request('<?php
		echo get_option( "siteurl" ).'/wp-content/plugins/'.$this->myfolder.'/wsblc_ajax.php?'; 
		?>action=discard_link&id='+link_id, 
						{ method:'get' });
		$('link-'+link_id).hide();
	}
</script>
</div>
		<?php
	}

}//class ends here

} // if class_exists...

$ws_link_checker = new ws_broken_link_checker();

?>