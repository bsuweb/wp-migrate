<?php
	
	if (is_callable('cli_set_process_title')) {
		cli_set_process_title ("wp-migrate");	
	}

	require "wp-migrate-class.php";
	require "vendor/autoload.php";

	// LOAD CONFIG
	// Option 1. Local directory in "./wp-migrate.json" file
	// Option 2. In "~/.wp-migrate.json"
	

	$local_config = "./wp-migrate.json";
	$home_config = "~/.wp-migrate.json";

	if (file_exists($local_config)) {
		$config_file = 'local_config';
		$config_raw = file_get_contents($local_config);
	}
	elseif (file_exists($home_config)) {
		$config_file = 'home_config';
		$config_raw = file_get_contents($home_config);
	}

	if ($config_raw !== false) {
		$config = json_decode($config_raw,true);
	}
	else {
		out("Could not load a config file. Config should be at one of the following locations:");
		out( array($local_config,$home_config));
		die();
	}

	if (!$config) {
		out("The wp-migrate config file, '".${$config_file}."', is invalid. Check syntax and try again.");
		die();
	}


	// INSTANTIATE WP-MIGRATE OBJECT
	
	$wpMigrate = new wpMigrate($config,$config_raw);


	// UTILITY FUNCTIONS AND CLASSES

	function out($text="",$partial=false) {
		if (is_array($text)) {
			foreach($text as $line) {
				out(' * '.$line);
			}
		}
		else {
			echo $text.($partial?"":"\n");
		}
	}
	
	class stopwatch {

		static public $tags = array();
		static public $general;

		static function mark($tag=null) {
			if (is_null($tag)) {
				$out = self::elapsed();
				self::$general = microtime(true);
			}
			else {
				$out = self::elapsed($tag);
				self::$tags[$tag] = microtime(true);
			}
			return $out;
		}

		static function elapsed($tag=null) {
			if (is_null($tag)) {
				if (is_null(self::$general))
					$elapsed = 0;
				else 
					$elapsed = microtime(true) - self::$general;					
			}
			else {
				if ( !array_key_exists($tag, self::$tags) )
					$elapsed = 0;
				else 
					$elapsed = microtime(true) - self::$tags[$tag];	
			}
			return round($elapsed,3);
		}

	}