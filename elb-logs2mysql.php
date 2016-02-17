<?php

/**
 * MySQL AWS Load Balancer Log Import
 *
 * This script imports AWS Load Balancer logs into MySQL
 * so you can use standard SQL commands to query your logs.
 * For usage, please run the script with no arguments.
 *
 * Based in part on http://www.startupcto.com/server-tech/apache/importing-apache-httpd-logs-into-mysql
 *
 * @author Art Kay (art -at- artkay.nyc)
 * @requires PHP 5.X
 * @requires MySQL 5.X
 *
 */

define('VERSION', '0.1');

$app = new App;

$app->fire();


class App 
{
	private $options;

	private $db;
	private $log_files;
	private $log_dir;

	private $version;

	public function __construct() 
	{
		$this->version = VERSION;
		print("{$_SERVER['SCRIPT_NAME']} v{$this->version}: Imports an AWS ELB logs into a MySQL database.\n");

		$this->options = getopt('',['dir:', 'host:', 'user:', 'password:', 'db:', 'table:', 'create', 'drop', 'timezone']);

		// directory where to look for the log files
		$this->log_dir = $this->parseOption('dir');

		// database settings
		$db_host = $this->parseOption('host', ini_get("mysqli.default_host"));
		$db_user = $this->parseOption('user', ini_get("mysqli.default_user"));
		$db_pass = $this->parseOption('password', ini_get("mysqli.default_pw"));
		$db_name = $this->parseOption('db');
		$db_table = $this->parseOption('table');

		$create_table = $this->parseOption('create', false, true);
		$drop_table = $this->parseOption('drop', false, true);

		// usually AWS ELB logs are iusing UTC timezone from the timestamps
		$timezone = $this->parseOption('timezone', 'UTC');
		date_default_timezone_set($timezone);

		$this->log_files = array_diff(scandir($this->log_dir), ['.', '..']);

		$this->db = new DB($db_host, $db_user, $db_pass, $db_name, $db_table);

		if ($drop_table) {
			$create_table = true; // --drop implies --create
			$this->db->dropTable();
		}

		if (!$this->db->tableExists()) {
			if($create_table) {
				$this->db->createTable();
			} else {
				die ("Database table {$this->db->table_quoted} does not exist. Please rerun the script with the --create option to create it.\n");
			}
		}		
	}

	public function fire()
	{
		$status = new Status(count($this->log_files));
		$status->printStatus();

		//loop through the log files, load them as CSV and insert into the DB
		foreach($this->log_files as $log_file) {

			$status->currentFile ++;

			if(substr($log_file, -4) !== '.log') {
				continue;
			}

			$log_file = new LogFile("{$this->log_dir}/{$log_file}");

			foreach($log_file->records as $record) {
				$record->user_agent = $this->db->escapeString($record->user_agent);
				$query = $record->getSQLInsertSyntax($this->db->table_safe);
				$this->db->query($query);
				
				$status->currentRecord ++;
				$status->printStatus();
			}
				
		}

		print("\ndone\n");
	}

	private function displayUsage()
	{
		print("Usage: php {$_SERVER['SCRIPT_NAME']} --dir <directory> --table <table name> [options]\n");
		print(" --dir <directory>         Directory with the log files; required\n");
		print(" --db <database name>      The database to use; required\n");
		print(" --table <table name>      The name of the table in which to insert data; required\n");
		print(" --host <host name>        The host to connect to; default is php.ini mysqli default setting\n");
		print(" --user <username>         The user to connect as; default is php.ini mysqli default setting\n");
		print(" --password <password>     The user's password; default is php.ini mysqli default setting\n");
		print(" --create                  Create table if it doesn't exist\n");
		print(" --drop                    Drop the existing table if it exists. Implies --create\n");
		exit;
	}

	private function parseOption($option, $default = null, $existence = false)
	{
		if (isset($this->options[$option]) && $existence == false) {
			return $this->options[$option];
		} elseif (array_key_exists($option, $this->options) && $existence == true) {
			return true;
		} elseif ($default !== null) { 
			return $default;
		} else {
			$this->displayUsage();
		}
	}
}

class LogRecordModel
{
	public $properties = null;

	public function __construct()
	{
		$this->properties = [
			'time' => [
				'sql_type' => 'TIMESTAMP',
				'csv_field' => 'time',
				'csv_value' => function ($value)
				{
					$result = date("Y-m-d H:i:s", strtotime($value));

					return $result;
				},
			],
			'elb_name' => [
				'sql_type' => 'VARCHAR(255)',
			],
			'request_ip' => [
				'sql_type' => 'VARCHAR(15)',
				'csv_field' => 'request_ip_port',
				'csv_value' => function($value) {
					$array = explode(':', $value);
					$result = $array[0];

					return $result;
				},
			],
			'request_port' => [
				'sql_type' => 'VARCHAR(6)',
				'csv_field' => 'request_ip_port',
				'csv_value' => function($value) {
					$array = explode(':', $value);
					if(!array_key_exists(1, $array)) {
						$result = '-';
					} else {
						$result = $array[1];
					}

					return $result;
				},
			],
			'backend_ip' => [
				'sql_type' => 'VARCHAR(15)',
				'csv_field' => 'backend_ip_port',
				'csv_value' => function($value) {
					$array = explode(':', $value);
					$result = $array[0];

					return $result;
				},
			],
			'backend_port' => [
				'sql_type' => 'VARCHAR(6)',
				'csv_field' => 'backend_ip_port',
				'csv_value' => function($value) {
					$array = explode(':', $value);
					if(!array_key_exists(1, $array)) {
						$result = '-';
					} else {
						$result = $array[1];
					}

					return $result;
				},
			],
			'request_processing_time' => [
				'sql_type' => 'DOUBLE',
			],
			'backend_processing_time' => [
				'sql_type' => 'DOUBLE',
			],
			'client_response_time' => [
				'sql_type' => 'DOUBLE',
			],
			'elb_response_code' => [
				'sql_type' => 'SMALLINT',
			],
			'backend_response_code' => [
				'sql_type' => 'SMALLINT',
			],
			'bytes_received' => [
				'sql_type' => 'BIGINT UNSIGNED',
			],
			'bytes_sent' => [
				'sql_type' => 'BIGINT UNSIGNED',
			],
			'request_method' => [
				'sql_type' => 'VARCHAR(10)',
				'csv_field' => 'method_url',
				'csv_value' => function($value) {
					$array = explode(' ', $value);
					$result = $array[0];
					
					return $result;
				},
			],
			'request_url' => [
				'sql_type' => 'VARCHAR(1024)',
				'csv_field' => 'method_url',
				'csv_value' => function($value) {
					$array = explode(' ', $value);
					$result = $array[1];
					
					return $result;
				},
			],
			'user_agent' => [
				'sql_type' => 'VARCHAR(2048)',
			],
			'cipher' => [
				'sql_type' => 'VARCHAR(255)',
			],
			'protocol' => [
				'sql_type' => 'VARCHAR(50)',
			],
		];
	}

	public function getSQLCreateSyntax($table) 
	{
		$query = "CREATE TABLE {$table} (";

		foreach($this->properties as $column => $settings) {
			$query .= "`{$column}` {$settings['sql_type']},\n";
		}

		$query .= " `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`));";

		return $query;
	}
}

class LogRecord 
{
	protected $model = null;

	public function __construct() 
	{
		$this->model = new LogRecordModel;

		foreach ($this->model->properties as $property => $settngs) {
			$this->{$property} = null;
		}
	}


	public function fillFromCSVArray($array) 
	{
		foreach($this->model->properties as $property => $settings) {
			if(array_key_exists('csv_field', $settings)) {
				$csv_key = $settings['csv_field'];
				$function = $settings['csv_value'];
				$value = $array[$csv_key];
				$this->{$property} = $function($value);
			} else {
				$this->{$property} = $array[$property];
			}
		}
	}

	public function getSQLInsertSyntax($table)
	{
		$values = [];
		foreach($this->model->properties as $property => $settings) {
			$type = explode('(', $settings['sql_type']);
			$type = strtolower($type[0]);

			if($type == 'varchar' || $type == 'timestamp') {
				$values[] = "'{$this->{$property}}'";
			} else {
				$values[] = $this->{$property};
			}
		}

		$query = "INSERT INTO {$table} (" . implode(', ', array_keys($this->model->properties)) . ') VALUES (' . implode(', ', $values) . ');';

		return $query;

	}
}

class LogFile
{
	protected $csvHeader = ['time', 'elb_name', 'request_ip_port', 'backend_ip_port', 'request_processing_time', 'backend_processing_time', 'client_response_time', 'elb_response_code', 'backend_response_code', 'bytes_received', 'bytes_sent',  'method_url', 'user_agent', 'cipher', 'protocol'];
	public $records = [];

	public function __construct($filename)
	{
		if (!file_exists($filename)) {
			die("File '{$filename}' does not exist");
		}

		if (!is_readable($filename)) {
			die("File '{$filename}' is not readable");
		}

		if (($handle = fopen($filename, 'r')) !== FALSE) {
			while (($row = fgetcsv($handle, 4096, ' ')) !== FALSE) {
				$data = array_combine($this->csvHeader, $row);
				$record = new LogRecord();
				$record->fillFromCsvArray($data);
				$this->records[] = $record;
			}
			fclose($handle);
		}
	}
}

class Status
{
	protected $totalFiles = 0;
	public $currentFile = 0;
	public $currentRecord = 0;

	private $status = '';

	public function __construct($total_files)
	{
		$this->totalFiles = $total_files;
		$this->refreshStatus();
		$this->printStatus();
	}

	private function refreshStatus() 
	{
		$this->status = "file {$this->currentFile} of {$this->totalFiles}, {$this->currentRecord} records imported";
	}

	public function printStatus() 
	{
		$eraser = str_repeat(chr(0x08),strlen($this->status));
		$this->refreshStatus();
		print($eraser . $this->status);
	}

}

class DB
{
	public $table;
	public $table_quoted;
	public $table_safe;

	private $connection;

	public function __construct($host, $user, $password, $database, $table)
	{
		$this->connection = new mysqli($host, $user, $password, $database);

		if ($this->connection->connect_error) {
			die('Database connection failed (' . $this->connection->connect_errno . ') ' . $this->connection->connect_error);
		}

		$this->table = $table;
		$this->table_quoted = $this->safeValue($table, true);
		$this->table_safe = $this->safeIdentifier($table);
	} 

	public function query($query)
	{
		$result = $this->connection->query($query);

		if(!$result) {
			die('Database error ('. $this->connection->errno . ') ' . $this->connection->error);
		}

		return $result;
	}

	public function createTable()
	{

		$record_model = new LogRecordModel();

		$query = $record_model->getSQLCreateSyntax($this->table_safe);

		$this->query($query);
	}

	public function dropTable()
	{
		$sql = "DROP TABLE IF EXISTS {$this->table_safe}";

		$this->query($query);
	}

	public function tableExists() 
	{
		$result = $this->query("SHOW TABLES LIKE {$this->table_quoted}");

		return ($result->num_rows > 0);
	}

	public function safeIdentifier($identifier)
	{
		return '`' . $this->connection->escape_string($identifier) . '`';
	}

	public function safeValue($value, $always_quote = false)
	{
		if(!$always_quote) {
			if (is_null($value)) {
				return 'NULL';
			} elseif (is_bool($value)) {
				return $value ? 'TRUE' : 'FALSE';
			} elseif (is_numeric($value)) {
				return $value;
			}
		}		

		return "'" . $this->connection->escape_string($value) . "'";
	}

	public function escapeString($value)
	{
		return $this->connection->real_escape_string($value);
	}

}