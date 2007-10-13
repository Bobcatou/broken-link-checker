<?php
/*
	The AJAX-y part of the link checker.
*/
	require_once("../../../wp-config.php");
	require_once("../../../wp-includes/wp-db.php");
	
	error_reporting(E_ALL);
	
	$execution_start_time=microtime(true);

	function execution_time(){
		global $execution_start_time;
		return microtime(true)-$execution_start_time;
	}
	
	@set_time_limit(0);
	@ignore_user_abort(true);
	
	$url_pattern='/(<a[\s]+[^>]*href\s*=\s*[\"\']?)([^\'\" >]+)([\'\"]+[^<>]*>)((?sU).*)(<\/a>)/i';
	
	$postdata_name=$wpdb->prefix . "blc_postdata";
	$linkdata_name=$wpdb->prefix . "blc_linkdata";
	
	$options=$ws_link_checker->options; //get_option('wsblc_options');
	$siteurl=get_option('siteurl');
	$max_execution_time=isset($options['max_work_session'])?intval($options['max_work_session']):27;
	
	$check_treshold=date('Y-m-d H:i:s', strtotime('-'.$options['check_treshold'].' hours'));
	$recheck_treshold=date('Y-m-d H:i:s', strtotime('-20 minutes'));
	
	$action=isset($_GET['action'])?$_GET['action']:'run_check';
	
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
			  WHERE a.last_check<'$check_treshold' AND a.post_id=b.id ORDER BY a.last_check ASC LIMIT 20";
		
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
				if(page_exists_simple($link->url)){
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
		$id=intval($_GET['id']);
		$wpdb->query("DELETE FROM $linkdata_name WHERE id=$id LIMIT 1");
	};
	
	
	function parse_link($matches, $post_id){
		global $wpdb, $siteurl, $linkdata_name;
		
		$url=$matches[2];
		
		$parts=@parse_url($url);
		
		if(!$parts) return false;
		
		$url=preg_replace(
	    	array('/([\?&]PHPSESSID=\w+)$/i','/(#[^\/]*)$/i', '/&amp;/','/^(javascript:.*)/i','/([\?&]sid=\w+)$/i'),
	    	array('','','&','',''),
	    	$url);

	    $url=trim($url);
	    if($url=='') return false;
	    
        // turn relative URLs into absolute URLs
        $url = relative2absolute($siteurl, $url);    
        
        if(strlen($url)>5){
	        $wpdb->query(
	        	"INSERT INTO $linkdata_name(post_id, url, link_text) 
	        	VALUES($post_id, '".$wpdb->escape($url)."', '".$wpdb->escape(strip_tags($matches[4]))."')"
	        	);
    	};
        
        return true;        
	}
	
	function parse_image($matches, $post_id){
		global $wpdb, $siteurl, $linkdata_name;
		
		$url=$matches[2];
		
		$parts=@parse_url($url);
		
		if(!$parts) return false;
		
		$url=preg_replace(
	    	array('/([\?&]PHPSESSID=\w+)$/i','/(#[^\/]*)$/i', '/&amp;/','/^(javascript:.*)/i','/([\?&]sid=\w+)$/i'),
	    	array('','','&','',''),
	    	$url);

	    $url=trim($url);
	    if($url=='') return false;
	    
        // turn relative URLs into absolute URLs
        $url = relative2absolute($siteurl, $url);    
        
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
		$url_pattern='/(<a[\s]+[^>]*href\s*=\s*[\"\']?)([^\'\" >]+)([\'\"]+[^<>]*>)((?sU).*)(<\/a>)/i';
		
		if(preg_match_all($url_pattern, $content, $matches, PREG_SET_ORDER)){
			foreach($matches as $link){
				parse_link($link, $post_id);
			}
		};
		
		//gather images (<img src=...>)
		$url_pattern='/(<img[\s]+[^>]*src\s*=\s*[\"\']?)([^\'\" >]+)([\'\"]+[^<>]*>)/i';
		
		if(preg_match_all($url_pattern, $content, $matches, PREG_SET_ORDER)){
			foreach($matches as $img){
				parse_image($img, $post_id);
			}
		};
		
		return $content;
	}
	
	function page_exists_simple($url){
		$parts=parse_url($url);
		if(!$parts) return false;
		
		if(!isset($parts['scheme'])) $url='http://'.$url;
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
	
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, 25);
		
		curl_setopt($ch, CURLOPT_FAILONERROR, false);
		
		if($parts['scheme']=='https'){
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,  0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		} else {
			curl_setopt($ch, CURLOPT_NOBODY, true);
		}
		curl_setopt($ch, CURLOPT_HEADER, true);
		
		$response = curl_exec($ch);
		$code=intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		
		curl_close($ch);
		
		return (($code>=200) && ($code<400));	
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
	
?>