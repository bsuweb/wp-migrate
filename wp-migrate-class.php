<?php


class wpMigrate {

	private $disabled_functions = array('__construct','route');
	private $commands = array(
		'retrieve' => array(
			'tag' => 'get database and uploads from specified host into working directory.',
			'example' => 'wp-migrate retrieve <host-origin>',
			'num_args' => 1,
		),
		'transform' => array(
			'tag' => 'prepare data from one host for another host, using files in working directory.',
			'example' => 'wp-migrate transform <host-origin> <host-destination>',
			'num_args' => 2,
		),
		'deliver' => array(
			'tag' => 'upload database and uploads to specified host from working directory.',
			'example' => 'wp-migrate deliver <host-destination>',
			'num_args' => 1,
		),
		'auto' => array(
			'tag' => 'Run retrieve, transform and deliver in sequence using working directory.',
			'example' => 'wp-migrate auto <host-origin> <host-destination>',
			'num_args' => 2,
		),

	);

	private $places_to_look = array(
		'%posts' => array(
			'id'=> 'ID',
			'fields' => array('post_content','guid'),
		),
		'%postmeta' => array(
			'id' => 'meta_id',
			'fields' => array('$meta_value'),
		),
		'%options' => array(
			'id' => 'option_id',
			'fields' => array('$option_value'),
		),
		'wp_blogs' => array(
			'id' => 'blog_id',
			'fields' => array('domain'),
		),
		'wp_site' => array(
			'id' => 'id',
			'fields' => array('domain'),
		),
		'wp_sitemeta' => array(
			'id' => 'meta_id',
			'fields' => array('$meta_value'),
		),
	);





	public function __construct($config, $config_raw) {
		$this->config = $config;
		$this->config_raw = $config_raw;
	}

	public function route() {
		stopwatch::mark('total');
		global $argv;
		if (isset($argv) && array_key_exists(1,$argv)) {
			$command = $this->clean_input($argv[1]);
		}
		else {
			$command = 'splash';
		}

		if (!in_array($command,$this->disabled_functions) &&
			is_callable(array($this,$command)) )
		{
			$args = array();
			$i = 2;
			while(isset($argv) && array_key_exists($i,$argv)) {
				$args[] = $this->clean_input($argv[$i]);
				$i++;
			}	

			if ( count($args) < $this->commands[$command]['num_args']) {
				out();
				out('ERROR: You are missing an argument for this command.'.
					' Here\'s an example: ');
				out("\t".$this->commands[$command]['example']);
				out();
			}
			else {
				$this->header($command);
				call_user_func_array(array($this,$command),$args);
				out();
				out("Total time: ".stopwatch::elapsed('total'). ' seconds');
			}
		}
		else {
			out("wp-migrate: '$command' is not a wp-migrate command.".
				" See 'wp-migrate help'.");
		}
	}


	// ** commands ** 

	public function retrieve($host) {
		stopwatch::mark('retrieve');
		if (!array_key_exists($host, $this->config['hosts'])) {
			out("The host, '$host', is not configured. Please try again.");
			return;
		}
		$host_config = $this->config['hosts'][$host];


		if ($host_config['connection'] == 'ssh') {
			$ssh = $this->init_ssh($host_config);
			$sftp = $this->init_sftp($host_config);	
		}
		else {
			$ssh = $sftp = null;
		}

		
		out("DATABASE");

		// Get WP config
		$wpconfig = $this->get_wpconfig($sftp,$host_config['wp-path']);

		// Create MySQL dump file.
		$sqldump = sprintf('./%s.bkp.sql',$host);
		$sqldump_bz2 = $sqldump.'.bz2';
		$sqldump_host = sprintf('/tmp/wp-migrate-%s.sql', date('Ymd-His'));
		$sqldump_host_bz2 = $sqldump_host.'.bz2';

		$sqldump_command = sprintf('mysqldump -h %s -u %s -p%s %s > %s',
				$wpconfig['DB_HOST'],
				$wpconfig['DB_USER'],
				$wpconfig['DB_PASSWORD'],
				$wpconfig['DB_NAME'],
				$sqldump_host
			);
		
		if ($ssh===null) {
			out("Moving database archive...",1);
			$sqldump_output = exec( $sqldump_command );
			rename($sqldump_host, $sqldump );
			out(" Done");	
		}
		else {
			out("Creating compressed archive of database on $host ...",1);
			$sqldump_output = $ssh->exec( $sqldump_command );
			$sqldump_compress = $ssh->exec( 'bzip2 '.$sqldump_host );
			// $sqldump_size = $sftp->filesize($sqldump_host_bz2);
			out(" Done");

			out("Downloading database archive ($sqldump_size) ...",1);
			$sftp->get( $sqldump_host_bz2, $sqldump_bz2 );
			out(" Done");	

			out("Uncompressing archive of database locally ...",1);
			exec("bunzip2 --force ".$sqldump_bz2);
			out(" Done");

			// Delete the sql dump file on host
			out("Deleting database archive on $host ...",1);
			$delete_output = $ssh->exec( sprintf('rm %s', $sqldump_host_bz2) );
			out(" Done");
		}
		
		out();
		out("UPLOADS");

		// Create tar.gz file of uploads directory.
		$tarfile = sprintf('./%s.bkp.tar',$host);
		$tarfile_host = sprintf('/tmp/wp-migrate-%s.tar', date('Ymd-His'));
		$tarfile_host_bz2 = $tarfile_host.'.bz2';
	
		out("Creating uploads archive on $host ... ",1);
		$tarfile_command = sprintf('tar -c%sf %s',
								   $host_config['tar-create-options'],
								   $tarfile_host );

		if ($host_config['uploads-folder'] && 
			array_key_exists('uploads-folder', $host_config)) 
		{

			if (!is_array($host_config['uploads-folder'])) {
				$host_config['uploads-folder'] = array($host_config['uploads-folder']);
			}
	 		foreach( $host_config['uploads-folder'] as $folder) {
	 			$tarfile_command .= sprintf(' -C %s %s',
	 										$host_config['wp-path'].'/wp-content',
											$folder );
	 		}
 		}

		if ($ssh===null) {
			$tarfile_output = exec( $tarfile_command );
			$tarfile_size = filesize( $tarfile_host);	
		}
		else {
			$tarfile_output = $ssh->exec( $tarfile_command );	
			// $tarfile_size = $sftp->filesize( $tarfile_host);
		}
		
		out(" Done");

		// out("Compressing uploads archive on $host ... ",1);
		// $compress_tarfile_command = sprintf('bzip2 %s', $tarfile_host);
		// if ($ssh===null) {
		// 	$compress_tarfile_output = exec( $compress_tarfile_command );
		// 	$tarfile_size = filesize($tarfile_host_bz2);
		// }
		// else {
		// 	$compress_tarfile_output = $ssh->exec( $compress_tarfile_command );
		// 	$tarfile_size = $sftp->filesize($tarfile_host_bz2);
		// }
		// out(" Done");

		if ($sftp===null) {
			out("Moving uploads archive...",1);
			rename($tarfile_host, $tarfile );
			out(" Done");
		}
		else {
			// Copy the sql dump from origin to local
			out("Downloading uploads archive ($tarfile_size) ...",1);
			$sftp->get( $tarfile_host, $tarfile );
			out(" Done");
			
			// Delete the tarfile on origin
			out("Deleting uploads archive on $host ...",1);
			$delete_output = $ssh->exec( sprintf('rm %s', $tarfile_host) );
			out(" Done");	
		}
		
		out();
		out("SUMMARY");
		out("Files fetched from $host:");
		out( array($sqldump, $tarfile));
		out("Time to retrieve: ".stopwatch::elapsed('retrieve')." seconds");
	}


	public function transform($origin, $dest) {
		stopwatch::mark('transform');
		// 0 SETUP
		if (!array_key_exists($origin, $this->config['hosts'])) {
			out("The originating host, '$origin', is not configured.".
				" Please try again.");
			return;
		}
		if (!array_key_exists($dest, $this->config['hosts'])) {
			out("The destination host, '$dest', is not configured.".
				" Please try again.");
			return;
		}
		$origin_config = $this->config['hosts'][$origin];
		$dest_config = $this->config['hosts'][$dest];

		$sqlfile = $origin.'.bkp.sql';
		$tarfile = $origin.'.bkp.tar';


		if (!file_exists($sqlfile) || !file_exists($tarfile)) {
			out("Files generated by the `retrieve` command are not found:");
			out(array($sqlfile,$tarfile));
			out("Run `wp-migrate retrieve $origin` to generate the files.");
			return;	
		}

		out("Transforming $origin to $dest.");
		out();

		$things_to_search_for = array(
			array($origin_config['domain-name'],$dest_config['domain-name']),
			array($origin_config['wp-path'],$dest_config['wp-path']),
		);


		// Set Temp database to a blank database.
		out("Setup clean slate in temp database...  ",1);
		$dbsetup_command = sprintf("echo 'drop database if exists %s; create database %s' | mysql -h %s -u %s -p%s 2> /dev/null ",
			$this->config['temp_db']['database_name'],
			$this->config['temp_db']['database_name'],
			$this->config['temp_db']['hostname'],
			$this->config['temp_db']['username'],
			$this->config['temp_db']['password']
			);
		//echo $dbsetup_command;
		exec($dbsetup_command);
		out("Done");


		// 1 LOAD DATA IN TEMP DATABASE
		out("Importing data into temporary database for processing... ",1);
		$import_command = sprintf("mysql -h %s -u %s -p%s %s < %s 2> /dev/null",
				$this->config['temp_db']['hostname'],
				$this->config['temp_db']['username'],
				$this->config['temp_db']['password'],
				$this->config['temp_db']['database_name'],
				$sqlfile
			);
		// echo $import_command;
		exec($import_command);
		out("Done");


		// 2 SEARCH AND REPLACE IN TEMP DATABASE
		out("Processing the data in the temporary database... ");
		$this->processTempDb($things_to_search_for);
		out();
		out();

		// 3 DUMP DATA FROM TEMP DATABASE
		out("Creating file dump from temporary database... ",1);
		$sqlfile_out = sprintf('./%s.transformed.sql',$dest);
		$sqldump_command = sprintf('mysqldump -h %s -u %s -p%s %s > %s 2> /dev/null',
								   $this->config['temp_db']['hostname'],
								   $this->config['temp_db']['username'],
								   $this->config['temp_db']['password'],
								   $this->config['temp_db']['database_name'],
								   $sqlfile_out );
		$sqldump_output = exec( $sqldump_command );
		out("Done");
		out();

		// 4 CREATE 'TRANSFORMED' TARFILE
		out("Renaming uploads tar for delivering... ",1);
		$tarfile_out = $dest.'.transformed.tar';
		$tarfile_cp_command = sprintf('cp %s %s', $tarfile, $tarfile_out);
		$tarfile_cp_output = exec( $tarfile_cp_command );
		out("Done");

		out();
		out("SUMMARY");
		out("Time to transform: ".stopwatch::elapsed('transform')." seconds");

	}

	public function deliver($host) {
		stopwatch::mark('deliver');
		if (!array_key_exists($host, $this->config['hosts'])) {
			out("The host, '$host', is not configured. Please try again.");
			return;
		}
		$host_config = $this->config['hosts'][$host];

		if ($host_config['connection'] == 'ssh') {
			$ssh = $this->init_ssh($host_config);
			$sftp = $this->init_sftp($host_config);	
		}
		else {
			$ssh = $sftp = null;
		}

		$wpconfig = $this->get_wpconfig($sftp,$host_config['wp-path']);

		$backup_sql = $host.".bkp.sql";
		$backup_tar = $host.".bkp.tar";

		$sqlfile = $host.'.transformed.sql';
		$sqlfile_bz2 = $sqlfile.'.bz2';
		$sqlfile_host = sprintf('/tmp/wp-migrate-%s.transformed.sql', date('Ymd-His'));
		$sqlfile_host_bz2 = $sqlfile_host.'.bz2';
		$tarfile = $host.'.transformed.tar';
		$tarfile_host = sprintf('/tmp/wp-migrate-%s.transformed.tar', date('Ymd-His'));
		//$tarfile_host_bz2 = $tarfile_host . '.bz2';

		if (!file_exists($sqlfile) || !file_exists($tarfile)) {
			out("Files generated by the `transform` command are not found:");
			out(array($sqlfile,$tarfile));
			out("Run `wp-migrate transform <origin> $host` to generate the files.");
			return;	
		}

		if (!file_exists($backup_sql) || !file_exists($backup_tar)) {
			out("Backup files not found. You should have a recent backup before using 'deliver': ");
			out(array($backup_sql,$backup_tar));
			out("Run `wp-migrate retrieve $host` to get a backup.");
			return;	
		}

		// 1. IMPORT UPLOADS TO HOST


		if ($sftp===null) {

			// 1.A. Upload tar file
			out("Copying uploads archive to temp folder (size)... ",1);
			copy($tarfile,$tarfile_host);
			out("Done");

			// // 1.B. Uncompress archive
			// out("Uncompressing uploads archive to temp folder (size)... ",1);
			// exec('bunzip2 '.$tarfile_host_bz2);
			// out("Done");

			// 1.C. Untar archive
			out("Untar uploads archive into destination (size)... ",1);
			$tarfile_command = sprintf('tar -xpf %s -C %s',
									   $tarfile_host,
									   $host_config['wp-path'].'/wp-content');
			$tarfile_command_output = exec( $tarfile_command );
			out("Done");

		}
		else {
			// 1.A. Upload tar file
			out("Uploading uploads archive to destination (size)... ");
			// out( $tarfile_host);
			// out( $tarfile);
			$sftp->put( $tarfile_host, $tarfile, NET_SFTP_LOCAL_FILE );
			out("Done");

			// // 1.B. Uncompress archive
			// out("Uncompress uploads archive on destination (size)... ",1);
			// $tarfile_uncompress_command = 'bunzip2 '. $tarfile_host_bz2;
			// $tarfile_uncompress_command_out = $ssh->exec( $tarfile_uncompress_command );
			// out("Done");

			// 1.C. Untar archive
			out("Untar uploads archive into destination (size)... ",1);
			$tarfile_command = sprintf('cd %s; tar -xpf %s',
				 					   $host_config['wp-path'].'/wp-content',
									   $tarfile_host);
			out($tarfile_command);
			// $tarfile_command_output = $ssh->exec( $tarfile_command );
			out("Done");

		}


		// 2. IMPORT DATABASE TO HOST


		if ($sftp===null) {

			// 2.D. Import file into the destination
			out("Importing MySQL on host... ",1);
			$import_command = sprintf("mysql -h %s -u %s -p%s %s < %s 2> /dev/null",
									  $wpconfig['DB_HOST'],
									  $wpconfig['DB_USER'],
									  $wpconfig['DB_PASSWORD'],
									  $wpconfig['DB_NAME'],
									  $sqlfile);
			$import_output = exec( $import_command );
			out("Done");

		}
		else {

			// 2.A. Compress SQL.
			out("Creating compressed archive of database... ",1);
			$sqldump_compress = exec( 'bzip2 '.$sqlfile );
			out("Done");

			// 2.B. Upload SQL file
			out("Uploading processed database to destination (size)... ",1);
			$sftp->put( $sqlfile_host_bz2, $sqlfile_bz2, NET_SFTP_LOCAL_FILE );
			out("Done");

			// 2.C. Uncompress the SQL file on the destination.
			out("Uncompressing database archive of database on $host... ",1);
			$sqldump_compress = $ssh->exec( 'bunzip2 --force '.$sqlfile_host_bz2 );
			// $sqldump_size = $sftp->filesize($sqlfile_host);
			out("Done");

			// 2.D. Import file into the destination
			out("Importing MySQL on host... ",1);
			$import_command = sprintf("mysql -h %s -u %s -p%s %s < %s 2> /dev/null",
									  $wpconfig['DB_HOST'],
									  $wpconfig['DB_USER'],
									  $wpconfig['DB_PASSWORD'],
									  $wpconfig['DB_NAME'],
									  $sqlfile_host);
			out($import_command);
			//$import_output = $ssh->exec( $import_command );
			out("Done");

		}


		if ($sftp!==null) {

			out("Deleting temporary files on $host ...",1);
			// $rm1 = $ssh->exec( 'rm '.$sqlfile_host );
			// $rm2 = $ssh->exec( 'rm '.$tarfile_host );		
			out(" Done");

		}

		out();
		out("SUMMARY");
		out("Time to deliver: ".stopwatch::elapsed('deliver')." seconds");

	}


	public function auto($origin, $dest) {

		if (!array_key_exists($origin, $this->config)) {
			out("The originating host, '$origin', is not configured.".
				" Please try again.");
			return;
		}
		if (!array_key_exists($dest, $this->config)) {
			out("The destination host, '$dest', is not configured. ".
				"Please try again.");
			return;
		}

		$this->retrieve($origin);
		$this->retrieve($dest);
		$this->transform($origin,$dest);
		$this->deliver($dest);

	}


	public function splash() {
		$this->help('', false);
	}


	public function help($command="",$full=true) {
		global $argv;

		$header = true;
		if ($command) {
			$header = false;
		}
		else {
			if (isset($argv) && array_key_exists(2,$argv)) {
				$command = $argv[2];
				$full = true;
				$header = true;
			}
		}

		if ($header)
			$this->header();

		$max = 0;
		foreach (array_keys($this->commands) as $c) {
			$max = max($max, strlen($c) );
		}
		$max = $max + 3;

		if ($command) {
			$command_info = sprintf('  %-'.$max.'s %s',
									$command, 
									$this->commands[$command]['tag']);
			out( $command_info );
			if ($full) {
				if (is_array($this->commands[$command]['example'])) {
					foreach($this->commands[$command]['example'] as $ex) {
						out( '  '. str_repeat(' ',$max) . "ex: $ex");	
					}
				}
				else {
					$ex = $this->commands[$command]['example'];
					out( '  '. str_repeat(' ',$max) . " ex: $ex");
				}	
			}
		}
		else {
			out("The following commands are available:");
			foreach($this->commands as $command => $details) {
				$this->help($command,$full);
			}
			out();
			if (!$full) {
				out("See 'wp-migrate help [<command>]' for more information.");
			}
		}
	}


	/* Private Functions */

	/**
	 * This function will connect to the temporary database (using config vals)	
	 * and will search and replace for given strings in the tables and fields
	 * defined in self::places_to_look.
	 *
	 * @param $things_to_search_for array List of strings to find and replace.
	 */
	private function processTempDb($things_to_search_for) {
		$places_to_look = $this->places_to_look;
		$tmp = $this->config['temp_db'];

		try {
			$dsn = sprintf('mysql:host=%s;dbname=%s',$tmp['hostname'],
						   $tmp['database_name']);
		    $db = new PDO($dsn, $tmp['username'], $tmp['password']);
		} 
		catch (PDOException $e) {
		    print "Error!: " . $e->getMessage() . "<br/>";
		    die();
		}

		$find_tables = $db->prepare("show tables like ?");

		foreach($places_to_look as $table_id => $table_info) {
	
			$find_tables->execute(array($table_id));
			$tables = $find_tables->fetchAll(PDO::FETCH_COLUMN);

			foreach($tables as $table) {

				$row_match_clauses = array();
				foreach($table_info['fields'] as $field) {
					$field = str_replace('$', '', $field);
					foreach($things_to_search_for as $match) {
						$row_match_clauses[] = "$field LIKE '%{$match[0]}%'";
					}
				}
				$row_match_q = "select * from $table where " . 
							   implode(' OR ', $row_match_clauses);
				$row_match = $db->query($row_match_q);
				$rows = $row_match->fetchAll(PDO::FETCH_ASSOC);

				if ($rows) {
					foreach( $rows as $row) {
						
						$update_params = array();

						$update_clauses = array();
						foreach ( $table_info['fields'] as $field ) {
							$val = $row[str_replace('$','',$field)];
							$serialized_search = false;
							if (strpos($field,'$') !== FALSE) {
								$val_unserialized = @unserialize($val);
								if ($val_unserialized !== FALSE) {
									echo "serialized data";
									$serialized_search = true;
								}
							}
							if ($serialized_search) {
								print_r($val_unserialized);
								$this->object_find_replace(
											$val_unserialized,
											$things_to_search_for);
								print_r($val_unserialized);
								$val = serialize($val_unserialized);
							}
							else {
								foreach($things_to_search_for as $match) {
									$val = str_replace($match[0], $match[1], 
													   $val);
								}
							}
							$update_clauses[] = str_replace('$','',$field)." = ? ";
							$update_params[] = $val;
						}

						$update_q = "update $table 
							set ".implode(' , ', $update_clauses)."
							where {$table_info['id']} = ? ";
						$update_params[] = $row[$table_info['id']];

						$update_row = $db->prepare($update_q);
						$update_row->execute($update_params);

						echo $update_row->rowCount() . " row updated.\n";

					}	
				}
				

			}

		}

		$domain_mapping_q = "update wp_domain_mapping 
							set active = '0' ";
		$domain_mapping_result = $db->exec($domain_mapping_q);
		
	}


	/**
	 * This is a recursive function to descend into an object by reference
	 * and apply the searches. The $searches variable should be an array of
	 * matches. Each match should be an array of at least two items. The first
	 * item refers to the search pattern, the second refers to the text to
	 * replace it with.
	 *
	 * Note: because $object is passed by reference, the object is not 'returned'
	 * as it is updated in place by this function.
	 *
	 * @param mixed &$object Usually an array or object.
	 * @param array $searches 
	 */
	function object_find_replace(&$object, $searches) {
		if (!is_array($object) AND !is_object($object)) return FALSE;
		foreach($object as $key => $value) {
			if (is_string($value)) {
				foreach($searches as $match) {
					echo $value;
					$value = str_replace($match[0],$match[1],$value);
					echo $value;
				}
			}
			else if (is_array($value) OR is_object($value)) {
				$this->object_find_replace($value,$searches);
			}

			foreach( $searches as $match ) {
				$key = str_replace($match[0],$match[1],$key);
			}
			if (is_object($object)) {
				$object->{$key} = $value;
			}
			else if (is_array($object)) {
				$object[$key] = $value;
			}
		}
	}


	/**
	 * Initialize SSH connection given host configuration.
	 */
	private function init_ssh(&$host) {
		$ssh = new Net_SSH2($host['domain-name']);
		if (!$ssh->login($host['username'], $host['password'])) {
			out("SSH connection to $host failed.");
			die();
		}
		return $ssh;
	}

	/**
	 * Initialize SFTP connection given host configuration.
	 */
	private function init_sftp(&$host) {
		$ssh = new Net_SFTP($host['domain-name']);
		if (!$ssh->login($host['username'], $host['password'])) {
		    out("SFTP connection to $host failed.");
			die();
		}
		return $ssh;
	}

	/**
	 * Gets the config from a wp-config.php file given an SFTP connection and 
	 * path to the WordPress installation.
	 *
	 * @param $sftp object An instantiated NET_SFTP object
	 * @param $wp_path string Path to the WP installation
	 */
	function get_wpconfig($sftp,$wp_path) {
		$wp_config_string = $this->retrieve_wpconfig($sftp,$wp_path);
		if (!$wp_config_string) {
			return null;
		}
		return $this->parse_wpconfig($wp_config_string);
	}

	/**
	 * Parses a wp-config file to gather desired configurations.
	 *
	 * @param $wp_config_string string Contents of a wp-config.php file.
	 */
	function parse_wpconfig($wp_config_string) {
		$constants = array('DB_NAME','DB_USER','DB_PASSWORD','DB_HOST',
						   'DB_CHARSET','DB_COLLATE','SUNRISE','MULTISITE',
						   'SUBDOMAIN_INSTALL');
		$config = array();

		foreach($constants as $constant) {
			$pattern = "/(\s*)define(\s*)\('" . $constant .
					   "'(\s*),(\s*)(.+)(\s*)\)/";
//echo $constant."\n";
//$pattern = "/(define.*)/";
//echo $pattern."\n";
			if ( preg_match($pattern,$wp_config_string,$matches) ) {
				$val = trim($matches[5]);
				if (strlen($val) >= 2) {
					if ($val === 'true') {
						$val = true;
					}
					else if ($val === 'false') {
						$val = false;
					}
					else if (substr($val,0,1) == '"' || 
							 substr($val,0,1) == "'") 
					{
						$val = substr($val,1,strlen($val)-2);
					}	
				}
				$config[$constant] = $val;
			}
			else {
				$config[$constant] = null;
			}
		}
		return $config;
	}

	/**
	 * Uses an already-instantiated NET_SFTP object to retrieve the wp-config
	 * file given the path to the WP installation. Will check up a directory if it 
	 * doesn't find the config in the root directory. This mimics WP's ability to
	 * keep the wp-config.php file in the root file of the installation or one
	 * directory above the installation.
	 *
	 * @param $sftp object An instantiated NET_SFTP object
	 * @param $wp_path string Path to the WP installation
	 */
	private function retrieve_wpconfig($sftp, $wp_path) {
		$wp_config_path = $wp_path . '/wp-config.php';

		if ($sftp === null) {
			$wp_config = file_get_contents($wp_config_path);
		}
		else {
			$wp_config = $sftp->get($wp_config_path);
		}

		if (!$wp_config) { // Not found, so search parent directory.
			$wp_config_path = basename($wp_path) . '/wp-config.php';
			if ($sftp === null) {
				$wp_config = file_get_contents($wp_config_path);
			}
			else {
				$wp_config = $sftp->get($wp_config_path);	
			}
		}
		return $wp_config;
	}


	private function header($command=null) {
		$include = ($command ? $command .' ':'');
		out();
		out("=========[ wp-migrate $include]=================================");
		out();
	}

	private function clean_input($string) {
		return preg_replace('/[^A-Za-z0-9\._-]/', '', $string);
	}

}