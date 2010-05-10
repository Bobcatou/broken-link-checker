<?php

//TODO: Add comments

if ( !class_exists('blcLogger') ):

define('BLC_LEVEL_DEBUG', 0);
define('BLC_LEVEL_INFO', 1);
define('BLC_LEVEL_WARNING', 2);
define('BLC_LEVEL_ERROR', 3);

class blcLogger {
	
	function __construct($param = ''){
		
	}
	
	function blcLogger($param = ''){
		$this->__construct($param);
	}
	
	function log($message, $object = null, $level = BLC_LEVEL_DEBUG){
		
	}
	
	function debug($message, $object = null){
		$this->log($message, $object, BLC_LEVEL_DEBUG);
	}
	
	function info($message, $object = null){
		$this->log($message, $object, BLC_LEVEL_INFO);
	}
	
	function warn($message, $object = null){
		$this->log($message, $object, BLC_LEVEL_WARNING);
	}
	
	function error($message, $object = null){
		$this->log($message, $object, BLC_LEVEL_ERROR);
	}
	
	function get_messages($min_level = BLC_LEVEL_DEBUG){
		return array();
	}
	
	function get_log($min_level = BLC_LEVEL_DEBUG){
		return array();
	}
	
	function clear(){
		
	}
}

class blcOptionLogger extends blcLogger{
	
	var $option_name = '';
	var $filter_level = 0;
	
	function __construct( $option_name = '' ){
		$this->option_name = $option_name;
	}
	
	function log($message, $object = null, $level = BLC_LEVEL_DEBUG){
		$current = get_option($this->option_name);
		$new_entry = array($level, $message, $object);
		
		if ( empty($current) ){
			$current = array( $new_entry );
		} else {
			array_push($current, $new_entry);
		}
		
		update_option($this->option_name, $current);
	}
	
	function get_log($min_level = BLC_LEVEL_DEBUG){
		$log = get_option($this->option_name);
		if ( empty($log) || !is_array($log) ){
			return array();
		}
		
		$this->filter_level = $min_level;
		return array_filter($log, array($this, '_filter_log'));
	}
	
	function _filter_log($entry){
		return ( $entry[0] >= $this->filter_level );
	}
	
	function get_messages($min_level = BLC_LEVEL_DEBUG){
		$messages = $this->get_log($min_level);
		return array_map( array($this, '_get_log_message'), $messages );
	}
	
	function _get_log_message($entry){
		return $entry[1];
	}
	
	function clear(){
		delete_option($this->option_name);
	}
}

class blcMemoryLogger extends blcLogger {
	
	var $log;
	var $name = '';
	var $filter_level = BLC_LEVEL_DEBUG;
	
	
	function __construct($name = ''){
		$this->name = $name;
		$this->log = array();
	}
	
	function log($message, $object = null, $level = BLC_LEVEL_DEBUG){
		$new_entry = array($level, $message, $object);
		array_push($this->log, $new_entry);
	}
	
	function get_log($min_level = BLC_LEVEL_DEBUG){
		$this->filter_level = $min_level;
		return array_filter($this->log, array($this, '_filter_log'));
	}
	
	function _filter_log($entry){
		return ( $entry[0] >= $this->filter_level );
	}
	
	function get_messages($min_level = BLC_LEVEL_DEBUG){
		$messages = $this->get_log($min_level);
		return array_map( array($this, '_get_log_message'), $messages );
	}
	
	function _get_log_message($entry){
		return $entry[1];
	}
	
	function clear(){
		$this->log = array();
	}
}

class blcDummyLogger extends blcLogger {
	
}

endif;



?>