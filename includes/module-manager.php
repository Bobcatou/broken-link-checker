<?php

class blcModuleManager {
	
	var $plugin_conf;
	var $module_dir = '';
	
	var $_plugin_cache;
	
	var $loaded;
	var $instances;
	var $default_active_modules;
	
	
	
	/**
	 * Class constructor.
	 * 
	 * @param array $default_active_modules An array of module ids specifying which modules are active by default.
	 * @return void
	 */
	function blcModuleManager($default_active_modules = null){
		$this->module_dir = realpath(dirname(__FILE__) . '/../modules');
		
		$this->plugin_conf = & blc_get_configuration();
		$this->default_active_modules = $default_active_modules;
		
		$this->loaded = array();
		$this->instances = array();
		
		add_filter('extra_plugin_headers', array(&$this, 'extra_plugin_headers'));
	}
	
	/**
	 * Get an instance of the module manager.
	 * 
	 * @param array|null $default_active_modules
	 * @return object
	 */
	function &getInstance($default_active_modules = null){
		static $instance = null;
		if ( is_null($instance) ){
			$instance = new blcModuleManager($default_active_modules);
		}
		return $instance;
	}
	
	/**
	 * Retrieve a list of all installed BLC modules.
	 * 
	 * This is essentially a slightly modified copy of get_plugins().
	 * 
	 * @return array An associative array of module headers indexed by module ID.
	 */
	function get_modules(){
		if ( isset($this->_plugin_cache) ){
			return $this->_plugin_cache;
		}
		
		$relative_path = '/' . plugin_basename($this->module_dir);
		$this->_plugin_cache = get_plugins( $relative_path );
		
		return $this->_plugin_cache;
	}
	
	/**
	 * Add BLC-module specific headers to the list of allowed plugin headers. This
	 * lets us use get_plugins() to retrieve the list of BLC modules.
	 * 
	 * @param array $headers Currently known plugin headers.
	 * @return array New plugin headers.
	 */
	function inject_module_headers($headers){
		return $headers;
	}	
}

?>