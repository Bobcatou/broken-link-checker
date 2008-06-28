<?php
/*
	The AJAX-y part of the link checker.
*/
	require_once("../../../wp-config.php");
	require_once("../../../wp-includes/wp-db.php");
	
	//error_reporting(E_ALL);
	
	$execution_start_time=microtime(true);

	function execution_time(){
		global $execution_start_time;
		return microtime(true)-$execution_start_time;
	}
	
	if (!current_user_can('read')) {
		die(__("Error: You can't do that. Access denied.", "broken-link-checker"));
	}
	
	if(!is_object($ws_link_checker)) {
		die(__('Fatal error : undefined object; plugin may not be active.', 'broken-link-checker'));
	};
	
	$url_pattern='/(<a[\s]+[^>]*href\s*=\s*[\"\']?)([^\'\" >]+)([\'\"]+[^<>]*>)((?sU).*)(<\/a>)/i';
	$url_to_replace = '';
	
	$postdata_name=$wpdb->prefix . "blc_postdata";
	$linkdata_name=$wpdb->prefix . "blc_linkdata";
	
	$options=$ws_link_checker->options; //get_option('wsblc_options');
	$max_execution_time=isset($options['max_work_session'])?intval($options['max_work_session']):27;
	
	// Check for safe mode
	if( ini_get('safe_mode') ){
	    // Do it the safe mode way
	    $t=ini_get('max_execution_time');
	    if ($t && ($t < $max_execution_time)) 
	    	$max_execution_time = $t-1;
	} else {
	    // Do it the regular way
	    @set_time_limit(0);
	}
	@ignore_user_abort(true);
	
	$check_treshold=date('Y-m-d H:i:s', strtotime('-'.$options['check_treshold'].' hours'));
	$recheck_treshold=date('Y-m-d H:i:s', strtotime('-20 minutes'));
	
	if (!empty($_POST['action'])){
		$action = $_POST['action'];
	} else {
		$action=isset($_GET['action'])?$_GET['action']:'run_check';
	}
	
	if($action=='dashboard_status'){
		/* displays a notification if broken links have been found */
		$sql="SELECT count(*) FROM $linkdata_name WHERE broken=1";
		$broken_links=$wpdb->get_var($sql);
		if($broken_links>0){
			echo "<div>
				<h3>".__("Broken Links", "broken-link-checker")."</h3>
				<p><a href='".get_option('siteurl')."/wp-admin/edit.php?page=".
				$ws_link_checker->mybasename."' title='".__("View broken links", "broken-link-checker")."'>".__("Found $broken_links broken links", "broken-link-checker")."</a></p>
			</div>";
		};
		
	} else if($action=='full_status'){
		/* give some stats about the current situation */
		$sql="SELECT count(*) FROM $postdata_name WHERE last_check<'$check_treshold'";
		$posts_unchecked=$wpdb->get_var($sql);
		
		$sql="SELECT count(*) FROM $linkdata_name WHERE last_check<'$check_treshold'";
		$links_unchecked=$wpdb->get_var($sql);
		
		$sql="SELECT count(*) FROM $linkdata_name WHERE broken=1";
		$broken_links=$wpdb->get_var($sql);
		
		if($broken_links>0){
			echo "<a href='".get_option('siteurl')."/wp-admin/edit.php?page=".
				$ws_link_checker->mybasename."' title='".__("View broken links", "broken-link-checker")."'><strong>".__("Found $broken_links broken links", "broken-link-checker")."</strong></a>";
		} else {
			echo __("No broken links found.", "broken-link-checker");
		}
		
		echo "<br/>";
		
		if($posts_unchecked || $links_unchecked) {
			echo __("$posts_unchecked posts and $links_unchecked links in the work queue.", "broken-link-checker");
		} else {
			echo __("The work queue is empty.", "broken-link-checker");
		}

		
	} else if($action=='run_check'){
		/* check for posts that haven't been checked for a long time & parse them for links, put the links in queue */
		echo "<!-- run_check  -->";
		
		$sql="SELECT b.* FROM $postdata_name a, $wpdb->posts b 
			  WHERE a.last_check<'$check_treshold' AND a.post_id=b.id ORDER BY a.last_check ASC LIMIT 40";
		
		$rows=$wpdb->get_results($sql, OBJECT);
		if($rows && (count($rows)>0)){
			//some rows found
			echo "<!-- parsing pages (rand : ".rand(1,1000).") -->";
			foreach ($rows as $post) {
				$wpdb->query("DELETE FROM $linkdata_name WHERE post_id=$post->ID");
				gather_and_save_links($post->post_content, $post->ID);
				$wpdb->query("UPDATE $postdata_name SET last_check=NOW() WHERE post_id=$post->ID");
			}
		};
		
		if(execution_time()>$max_execution_time){
			die('<!-- general timeout -->');
		}
		
		/* check the queue and process any links unchecked */
		$sql="SELECT * FROM $linkdata_name WHERE ".
		 " ((last_check<'$check_treshold') OR ".
		 " (broken=1 AND check_count<5 AND last_check<'$recheck_treshold')) ".
		 " LIMIT 100";
		
		$links=$wpdb->get_results($sql, OBJECT);
		if($links && (count($links)>0)){
			//some unchecked links found
			echo "<!-- checking links (rand : ".rand(1,1000).") -->";
			foreach ($links as $link) {
				if( $ws_link_checker->is_excluded($link->url) || page_exists_simple($link->url) ){
					//link OK, remove from queue
					$wpdb->query("DELETE FROM $linkdata_name WHERE id=$link->id");
				} else {
					$wpdb->query("UPDATE $linkdata_name SET broken=1, ".
								" last_check=NOW(), check_count=check_count+1 WHERE id=$link->id");
				};
				
				
				if(execution_time()>$max_execution_time){
					die('<!-- url loop timeout -->');
				}
			}
		};
		
		die('<!-- /run_check -->');
		
	} else if ($action=='discard_link'){
		if (!current_user_can('edit_posts')) {
			die(__("Error: You can't do that. Access denied.", "broken-link-checker"));
		}
		$id=intval($_GET['id']);
		$wpdb->query("DELETE FROM $linkdata_name WHERE id=$id LIMIT 1");
		if($wpdb->rows_affected<1){
			die(__('Error: Couldn\'t remove the link record (DB error).', 'broken-link-checker'));
		}
		die(__('OK: Link discarded', 'broken-link-checker'));
		
	} else if ($action=='remove_link'){
		
		//actually deletes the link from the post
		if (!current_user_can('edit_posts')) {
			die(__("Error: You can't do that. Access denied.", 'broken-link-checker'));
		}
		
		$id=intval($_GET['id']);
		$sql="SELECT * FROM $linkdata_name WHERE id = $id LIMIT 1";
		$the_link=$wpdb->get_row($sql, OBJECT, 0);
		if (!$the_link){
			die(__('Error: Link not found', 'broken-link-checker'));
		}
		$the_post = get_post($the_link->post_id, ARRAY_A);
		if (!$the_post){
			die(__('Error: Post not found', 'broken-link-checker'));
		}
		
		$new_content = unlink_the_link($the_post['post_content'], $the_link->url);
		$new_content = $wpdb->escape($new_content);
		$wpdb->query("UPDATE $wpdb->posts SET post_content = '$new_content' WHERE id = $the_link->post_id");
		if($wpdb->rows_affected<1){
			die(__('Error: Couldn\'t update the post', 'broken-link-checker').' ('.mysql_error().').');
		}
		$wpdb->query("DELETE FROM $linkdata_name WHERE id=$id LIMIT 1");
		if($wpdb->rows_affected<1){
			die(__('Error: Couldn\'t remove the link record (DB error).', 'broken-link-checker'));
		}

		die(__('OK: Link deleted', 'broken-link-checker'));
		
	} else if ($action == 'edit_link'){
		//edits the link's URL inside the post
		if (!current_user_can('edit_posts')) {
			die(__("Error: You can't do that. Access denied.", "broken-link-checker"));
		}
		
		$id = intval($_GET['id']);
		$new_url = $_GET['new_url'];
		
		$sql="SELECT * FROM $linkdata_name WHERE id = $id LIMIT 1";
		$the_link=$wpdb->get_row($sql, OBJECT, 0);
		if (!$the_link){
			die(__('Error: Link not found', 'broken-link-checker'));
		}
		$the_post = get_post($the_link->post_id, ARRAY_A);
		if (!$the_post){
			die(__('Error: Post not found', 'broken-link-checker'));
		}
		
		$new_content = edit_the_link($the_post['post_content'], $the_link->url, $new_url);
		if (function_exists('mysql_real_escape_string')){
			$new_content = mysql_real_escape_string($new_content);
		} else {		
			$new_content = $wpdb->escape($new_content);
		}
		$q = "UPDATE $wpdb->posts SET post_content = '$new_content' WHERE id = $the_link->post_id";
		//@file_put_contents('q.txt', $q);
		$wpdb->query($q);
		if($wpdb->rows_affected<1){
			die('Error: Couldn\'t update the post ('.mysql_error().').');
		}
		$wpdb->query("DELETE FROM $linkdata_name WHERE id=$id LIMIT 1");
		if($wpdb->rows_affected<1){
			die(__('Error: Couldn\'t remove the link record (DB error).', 'broken-link-checker'));
		}

		die(__('OK: Link changed and deleted from the list of broken links.', 'broken-link-checker'));
	};
	
	function parse_link($matches, $post_id){
		global $wpdb, $linkdata_name, $ws_link_checker;
		
		$url=$matches[2];
		
		$url = $ws_link_checker->normalize_url($url);
		if (!$url) return false;
	    
        if(strlen($url)>5){
	        $wpdb->query(
	        	"INSERT INTO $linkdata_name(post_id, url, link_text) 
	        	VALUES($post_id, '".$wpdb->escape($url)."', '".$wpdb->escape(strip_tags($matches[4]))."')"
	        	);
    	};
        
        return true;        
	}
	
	function parse_image($matches, $post_id){
		global $wpdb, $linkdata_name, $ws_link_checker;
		
		$url=$matches[2];
		$url = $ws_link_checker->normalize_url($url);
		if(!$url) return false;
		
        if(strlen($url)>3){
	        $wpdb->query(
	        	"INSERT INTO $linkdata_name(post_id, url, link_text) 
	        	VALUES($post_id, '".$wpdb->escape($url)."', '[image]')"
	        	);
    	};
        
        return true;        
	}
	
	function gather_and_save_links($content, $post_id){
		//gather links (<a href=...>)
		global $url_pattern;
		
		//remove all <code></code> blocks first
		$content = preg_replace('/<code>.+?<\/code>/i', ' ', $content);
		
		if(preg_match_all($url_pattern, $content, $matches, PREG_SET_ORDER)){
			foreach($matches as $link){
				parse_link($link, $post_id);
			}
		};
		
		//gather images (<img src=...>)
		$img_pattern='/(<img[\s]+[^>]*src\s*=\s*[\"\']?)([^\'\" >]+)([\'\"]+[^<>]*>)/i';
		
		if(preg_match_all($img_pattern, $content, $matches, PREG_SET_ORDER)){
			foreach($matches as $img){
				parse_image($img, $post_id);
			}
		};
		
		return $content;
	}
	
	function page_exists_simple($url){
		//echo "Checking $url...<br/>";
		$parts=parse_url($url);
		if(!$parts) return false;
		
		if(!isset($parts['scheme'])) $url='http://'.$url;
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
		//curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress/Broken Link Checker (bot)');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
	
		@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		
		curl_setopt($ch, CURLOPT_FAILONERROR, false);

		$nobody=false;		
		if($parts['scheme']=='https'){
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,  0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		} else {
			$nobody=true;
			curl_setopt($ch, CURLOPT_NOBODY, true);
			//curl_setopt($ch, CURLOPT_RANGE, '0-1023');
		}
		curl_setopt($ch, CURLOPT_HEADER, true);
		
		$response = curl_exec($ch);
		//echo 'Response 1 : <pre>',$response,'</pre>';
		$code=intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		//echo "Code 1 : $code<br/>";
		
		if ( (($code<200) || ($code>=400)) && $nobody) {
			curl_setopt($ch, CURLOPT_NOBODY, false);
			curl_setopt($ch, CURLOPT_HTTPGET, true);
			curl_setopt($ch, CURLOPT_RANGE, '0-2047');
			$response = curl_exec($ch);
			//echo 'Response 2 : <pre>',$response,'</pre>';
			$code=intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
			//echo "Code 2 : $code<br/>";
		}
		
		curl_close($ch);
		
		return (($code>=200) && ($code<400));	
	}
	
	function unlink_the_link($content, $url){
		global $url_pattern, $url_to_replace;
		$url_to_replace = $url;
		$url_pattern='/(<a[\s]+[^>]*href\s*=\s*[\"\']?)([^\'\" >]+)([\'\"]+[^<>]*>)((?sU).*)(<\/a>)/i';
		$content = preg_replace_callback($url_pattern, unlink_link_callback, $content);
		return $content;
	}
	
	function unlink_link_callback($matches){
		global $url_to_replace, $ws_link_checker;
		$url = $ws_link_checker->normalize_url($matches[2]);
		$text = $matches[4];
		
		//echo "$url || $url_to_replace\n";
		if ($url == $url_to_replace){
			//echo "Removed '$text' - '$url'\n";
			return $text;
			//return "<span class='broken_link'>$text</span>";
		} else {
			return $matches[0];
		}
	}	
	
	function edit_the_link($content, $url, $newurl){
		global $url_pattern, $url_to_replace, $new_url;
		$url_to_replace = $url;
		$new_url = $newurl;
		$url_pattern='/(<a[\s]+[^>]*href\s*=\s*[\"\']?)([^\'\" >]+)([\'\"]+[^<>]*>)((?sU).*)(<\/a>)/i';
		$content = preg_replace_callback($url_pattern, edit_link_callback, $content);
		return $content;
	}
	
	function edit_link_callback($matches){
		global $url_to_replace, $new_url, $ws_link_checker;
		$url = $ws_link_checker->normalize_url($matches[2]);
		$text = $matches[4];
		
		//echo "$url || $url_to_replace\n";
		if ($url == $url_to_replace){
			//return $text;
			return $matches[1].$new_url.$matches[3].$text.$matches[5];
		} else {
			return $matches[0];
		}
	}	
?>