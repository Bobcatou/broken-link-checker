<?php
/*
	The AJAX-y part of the link checker.
*/
	require_once("../../../wp-config.php");
	if(!isset($wpdb)) {
		require_once("../../../wp-includes/wp-db.php");
	}
	
	//error_reporting(E_ALL);
	
	$execution_start_time=microtime(true);

	function execution_time(){
		global $execution_start_time;
		return microtime(true)-$execution_start_time;
	}
	
	if (!current_user_can('read')) {
		die("Error: You can't do that. Access denied.");
	}
	
	if(!is_object($ws_link_checker)) {
		die('Fatal error : undefined object; plugin may not be active.');
	};
	
	//Regexp for HTML links
	//				\1                       \2      \3            \4       \5       \6
	$url_pattern='/(<a[\s]+[^>]*href\s*=\s*)([\"\']+)([^\'\">]+)\2([^<>]*>)((?sU).*)(<\/a>)/i';
	//Regexp for IMG tags
	//             \1                         \2       \3           \4 
	$img_pattern='/(<img[\s]+[^>]*src\s*=\s*)([\"\']?)([^\'\">]+)\2([^<>]*>)/i';
	
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
				<h3>Broken Links</h3>
				<p><a href='".get_option('siteurl')."/wp-admin/edit.php?page=".
				$ws_link_checker->mybasename."' title='View broken links'>Found $broken_links broken links</a></p>
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
				$ws_link_checker->mybasename."' title='View broken links'><strong>Found $broken_links broken links</strong></a>";
		} else {
			echo "No broken links found.";
		}
		
		echo "<br/>";
		
		if($posts_unchecked || $links_unchecked) {
			echo "$posts_unchecked posts and $links_unchecked links in the work queue.";
		} else {
			echo "The work queue is empty.";
		}

		
	} else if($action=='run_check'){
		/* check for posts that haven't been checked for a long time & parse them for links, put the links in queue */
		echo "<!-- run_check  -->";
		
		$sql="SELECT b.* FROM $postdata_name a, $wpdb->posts b 
			  WHERE a.last_check<'$check_treshold' AND a.post_id=b.id 
			  		AND b.post_status = 'publish' AND b.post_type IN ('post', 'page') 
			  ORDER BY a.last_check ASC LIMIT 40";
		
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
			echo "<!-- checking ".count($links)." links (rand : ".rand(1,1000).") -->";
			foreach ($links as $link) {
				$ws_link_checker->check_link($link);
				
				if(execution_time()>$max_execution_time){
					die('<!-- url loop timeout -->');
				}
			}
		};
		
		die('<!-- /run_check -->');
		
	} else if ($action=='discard_link'){
		if (!current_user_can('edit_posts')) {
			die("Error: You can't do that. Access denied.");
		}
		$id=intval($_GET['id']);
		$wpdb->query("DELETE FROM $linkdata_name WHERE id=$id LIMIT 1");
		if($wpdb->rows_affected<1){
			die('Error: Couldn\'t remove the link record (DB error).');
		}
		die('OK: Link discarded');
		
	} else if ($action=='remove_link'){
		
		//actually deletes the link from the post
		if (!current_user_can('edit_posts')) {
			die("Error: You can't do that. Access denied.");
		}
		
		$id=intval($_GET['id']);
		$sql="SELECT * FROM $linkdata_name WHERE id = $id LIMIT 1";
		$the_link=$wpdb->get_row($sql, OBJECT, 0);
		if (!$the_link){
			die('Error: Link not found');
		}
		$the_post = get_post($the_link->post_id, ARRAY_A);
		if (!$the_post){
			die('Error: Post not found');
		}
		
		//Remove a link or an image from the post HTML
		if ($the_link->type == 'link')
			$new_content = unlink_the_link($the_post['post_content'], $the_link->url);
		elseif ($the_link->type == 'image')
			$new_content = unlink_image($the_post['post_content'], $the_link->url);
		
		//Update database
		$new_content = $wpdb->escape($new_content);
		$wpdb->query("UPDATE $wpdb->posts SET post_content = '$new_content' WHERE id = $the_link->post_id");
		if($wpdb->rows_affected<1){
			die('Error: Couldn\'t update the post ('.mysql_error().').');
		}
		$wpdb->query("DELETE FROM $linkdata_name WHERE id=$id LIMIT 1");
		if($wpdb->rows_affected<1){
			die('Error: Couldn\'t remove the link record (DB error).');
		}

		die('OK: Link deleted');
		
	} else if ($action == 'edit_link'){
		//edits the link's URL inside the post
		if (!current_user_can('edit_posts')) {
			die("Error: You can't do that. Access denied.");
		}
		
		$id = intval($_GET['id']);
		$new_url = $_GET['new_url'];
		
		$sql="SELECT * FROM $linkdata_name WHERE id = $id LIMIT 1";
		$the_link=$wpdb->get_row($sql, OBJECT, 0);
		if (!$the_link){
			die('Error: Link not found');
		}
		$the_post = get_post($the_link->post_id, ARRAY_A);
		if (!$the_post){
			die('Error: Post not found');
		}
		
		if ($the_link->type == 'link')
			$new_content = edit_the_link($the_post['post_content'], $the_link->url, $new_url);
		elseif ($the_link->type == 'image')
			$new_content = edit_image($the_post['post_content'], $the_link->url, $new_url);
		
		
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
			die('Error: Couldn\'t remove the link record (DB error).');
		}

		die('OK: Link changed and deleted from the list of broken links.');
	};
	
	function parse_link($matches, $post_id){
		global $wpdb, $linkdata_name, $ws_link_checker;
		
		$url = $matches[3];
		$text = $matches[5];
		
		$url = $ws_link_checker->normalize_url($url);
		if (!$url) return false;
	    
        if(strlen($url)>5){
	        $wpdb->query(
	        	"INSERT INTO $linkdata_name(post_id, url, link_text, type, final_url) 
	        	VALUES($post_id, '".$wpdb->escape($url)."', 
						'".$wpdb->escape(strip_tags($text))."', 'link',
						'".$wpdb->escape($url)."')"
	        	);
    	};
        
        return true;        
	}
	
	function parse_image($matches, $post_id){
		global $wpdb, $linkdata_name, $ws_link_checker;
		
		$url=$matches[3];
		$url = $ws_link_checker->normalize_url($url);
		if(!$url) return false;
		
        if(strlen($url)>3){
	        $wpdb->query(
	        	"INSERT INTO $linkdata_name(post_id, url, link_text, type, final_url) 
	        	VALUES($post_id, '".$wpdb->escape($url)."', '[image]', 'image','".$wpdb->escape($url)."')"
	        	);
    	};
        
        return true;        
	}
	
	function gather_and_save_links($content, $post_id){
		//gather links (<a href=...>)
		global $url_pattern, $img_pattern;
		
		//remove all <code></code> blocks first
		$content = preg_replace('/<code>.+?<\/code>/i', ' ', $content);
		
		//echo "Analyzing post $post_id<br>Content = ".htmlspecialchars($content)."<br>";
		
		if(preg_match_all($url_pattern, $content, $matches, PREG_SET_ORDER)){
			foreach($matches as $link){
				//echo "Found link : ".print_r($link,true)."<br>";
				parse_link($link, $post_id);
			}
		};
		
		//gather images (<img src=...>)
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
		
		if(!isset($parts['scheme'])) {
			$url='http://'.$url;
			$parts['scheme'] = 'http';
		}
		
		//Only HTTP links are checked. All others are automatically considered okay.
		if ( ($parts['scheme'] != 'http') && ($parts['scheme'] != 'https') ) 
			return true;
		
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
		
		/*"Good" response codes are anything in the 2XX range (e.g "200 OK") and redirects  - the 3XX range.
		  HTTP 401 Unauthorized is a special case that is considered OK as well. Other errors - the 4XX range
		  are treated as "page doesn't exist'". */
		return (($code>=200) && ($code<400)) || ($code == 401);
	}
	
	function unlink_image($content, $url){
		global $img_pattern, $url_to_replace;
		$url_to_replace = $url;
		//$url_pattern='/(<a[\s]+[^>]*href\s*=\s*[\"\']?)([^\'\">]+)([\'\"]+[^<>]*>)((?sU).*)(<\/a>)/i';
		$content = preg_replace_callback($img_pattern, unlink_image_callback, $content);
		return $content;
	}
	
	function unlink_image_callback($matches){
		global $url_to_replace, $ws_link_checker;
		$url = $ws_link_checker->normalize_url($matches[3]);
		
		if ($url == $url_to_replace){
			return ''; //completely remove the IMG tag
		} else {
			return $matches[0];
		}
	}
	
	function unlink_the_link($content, $url){
		global $url_pattern, $url_to_replace;
		$url_to_replace = $url;
		//$url_pattern='/(<a[\s]+[^>]*href\s*=\s*[\"\']?)([^\'\">]+)([\'\"]+[^<>]*>)((?sU).*)(<\/a>)/i';
		$content = preg_replace_callback($url_pattern, unlink_link_callback, $content);
		return $content;
	}
	
	function unlink_link_callback($matches){
		global $url_to_replace, $ws_link_checker;
		$url = $ws_link_checker->normalize_url($matches[3]);
		$text = $matches[5];
		
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
		//$url_pattern='/(<a[\s]+[^>]*href\s*=\s*[\"\']?)([^\'\" >]+)\2([^<>]*>)((?sU).*)(<\/a>)/i';
		$content = preg_replace_callback($url_pattern, edit_link_callback, $content);
		return $content;
	}
	
	function edit_link_callback($matches){
		global $url_to_replace, $new_url, $ws_link_checker;
		$url = $ws_link_checker->normalize_url($matches[3]);
		$text = $matches[5];
		
		//echo "$url || $url_to_replace\n";
		if ($url == $url_to_replace){
			//return $text;
			//     \<a..       \",'                 \",'       \...>              \</a>  
			return $matches[1].$matches[2].$new_url.$matches[2].$matches[4].$text.$matches[6];
		} else {
			return $matches[0];
		}
	}
	
	function edit_image($content, $url, $newurl){
		global $img_pattern, $url_to_replace, $new_url;
		$url_to_replace = $url;
		$new_url = $newurl;
		$content = preg_replace_callback($img_pattern, edit_link_callback, $content);
		return $content;
	}
	
	function edit_image_callback($matches){
		global $url_to_replace, $new_url, $ws_link_checker;
		$url = $ws_link_checker->normalize_url($matches[3]);
		
		if ($url == $url_to_replace){
			//     \<img...    \",'        \url     \",'       \...>  
			return $matches[1].$matches[2].$new_url.$matches[3].$matches[4];
		} else {
			return $matches[0];
		}
	}	
?>