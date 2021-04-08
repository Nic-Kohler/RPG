<?php

	/************************************************************************************/
	/*																					*/
	/*		Copyright (C) Unscarred Technology - All Rights Reserved					*/
	/*		Unauthorized copying of this file, via any medium is strictly prohibited	*/
	/*		Proprietary and confidential												*/
	/*		Written by Nicholas Kohler <nic@unscarredtechnology.co.za>, August 2019		*/
	/*																					*/
	/************************************************************************************/

	mysqli_report(MYSQLI_REPORT_STRICT);

	function postgresql_exception_handler($errno, $errstr, $errfile, $errline)
	{
		throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
	}

	set_error_handler("postgresql_exception_handler");

	class Database
	{
		private $Server;
		private $Server_Type;
		private $Server_Port;
		private $User;
		private $Password;
		private $Database_Name;
		private $Connection;
		private $Insert_ID;

		function __construct()
		{
			$this->Connection 		= false;
			$this->Insert_ID 		= -1;

			$config_loaded = true;

			if(func_num_args()) $this->Load_Config(func_get_args()); else  $config_loaded = $this->Load_Config_File();
			
			if($config_loaded) $this->Verify_Server();
		}

		private function Set_Server_Type($type)		{ $this->Server_Type 	= $type; }
		private function Set_Server($host)			{ $this->Server 		= $host; }
		private function Set_Server_Port($port)		{ $this->Server_Port 	= $port; }
		private function Set_User($user)			{ $this->User 			= $user; }
		private function Set_Password($password)	{ $this->Password 		= $password; }
		private function Set_Database_Name($name)	{ $this->Database_Name 	= $name; }
		private function Get_Database_Name()		{ return $this->Database_Name; }

		private function Load_Config($function_parameters)
		{
			for($i = 0; $i < count($function_parameters); $i++)
			{
				switch($i)
				{
					case 0: if($function_parameters[$i]) $this->Server_Type 	= $function_parameters[$i]; break;
					case 1: if($function_parameters[$i]) $this->Server 			= $function_parameters[$i]; break;
					case 2: if($function_parameters[$i]) $this->Server_Port 	= $function_parameters[$i]; break;
					case 3: if($function_parameters[$i]) $this->User 			= $function_parameters[$i]; break;
					case 4: if($function_parameters[$i]) $this->Password 		= $function_parameters[$i]; break;
					case 5: if($function_parameters[$i]) $this->Database_Name 	= $function_parameters[$i]; break;
				}
			}
		}

		private function Load_Config_File()
		{
			$path = "./includes/database.cfg";

			if(file_exists($path))
			{
				$config = json_decode(file_get_contents($path, "r"));

				if($config)
				{
					$this->Server 			= $config->Server;
					$this->User     		= $config->User;
					$this->Password 		= $config->Password;
					$this->Database_Name 	= $config->Database_Name;
					$this->Server_Port 		= $config->Server_Port;
					$this->Server_Type 		= $config->Server_Type;

					return true;
				}
				else
				{
					echo "\n\tFile not formatted correctly: 'database.cfg'\n";

					return false;
				}
			}
			else
			{
				echo "\n\tFile not found: 'database.cfg'\n";

				return false;
			}
		}

		private function Verify_Server()
		{
			try
			{
				if(strtolower($this->Server) != "localhost" &&
				   strtolower($this->Server) != "http://localhost" &&
				   strtolower($this->Server) != "https://localhost")
				{
					$url = $this->Server;

					if(substr($url, 0, 4) != "http") $url = "http://" . $url;

					//$connection = get_headers($url);
				}

				$this->Verify_Server_Port();
			}
			catch(Exception $exception)
			{
				echo "\nFailed to Connect to the {$this->Server_Type} Server. Verify the following Parameter:\n";
				echo "\tServer: '{$this->Server}'\n";
			}

			$connection = null;
		}

		private function Verify_Server_Port()
		{
			try
			{
				$connection = fsockopen($this->Server, $this->Server_Port);

				switch(strtolower($this->Server_Type))
				{
					case "mysql": 		$this->Verify_MySql_Account(); 			break;
					case "postgresql": 	$this->Verify_PostgreSQL_Account(); 	break;
				}
			}
			catch(Exception $exception)
			{
				echo "\nFailed to Connect to the {$this->Server_Type} Server. Verify the following Parameter:\n";
				echo "\tServer Port: '{$this->Server_Port}'\n\n";
			}

			$connection = null;
		}

		private function Verify_MySql_Account()
		{
			try
			{
				$connection = new mysqli($this->Server, $this->User, $this->Password, null, $this->Server_Port);

				if($this->Database_Name) $this->Set_Database($this->Database_Name);
			}
			catch(MySQLi_SQL_Exception $exception)
			{
				echo "\nFailed to Connect to the MySQL Server. Verify the following Parameters:\n";
				echo "\tUser:     '{$this->User}'\n";
				echo "\tPassword: '{$this->Password}'\n\n";
			}

			$connection = null;
		}

		private function Verify_PostgreSQL_Account()
		{
			try
			{
				$connection = pg_connect("host={$this->Server} port={$this->Server_Port} user={$this->User} password={$this->Password} dbname='{$this->Database_Name}'");
			}
			catch(Exception $exception)
			{
				echo "\nFailed to Connect to the PostgreSQL Server. Verify the following Parameters:\n";
				echo "\tUser:          '{$this->User}'\n";
				echo "\tPassword:      '{$this->Password}'\n";
				echo "\tDatabase_Name: '{$this->Database_Name}'\n\n";
			}

			$connection = null;
		}

		public function Set_Database($database_name)
		{
			switch(strtolower($this->Server_Type))
			{
				case "mysql": 		$this->Verify_MySql_Database($database_name); 		break;
				case "postgresql": 	$this->Verify_PostgreSQL_Database($database_name); 	break;
			}
		}

		private function Verify_MySql_Database($database_name)
		{
			try
			{
				$connection = new mysqli($this->Server, $this->User, $this->Password, $database_name, $this->Server_Port);

				$this->Database_Name = $database_name;

				$connection = null;
				
				return true;
			}
			catch(MySQLi_SQL_Exception $exception)
			{
				echo "\nFailed to Connect to the MySQL Database. Verify the following Parameter:\n";
				echo "\tDatabase: '{$database_name}'\n";

				$connection = null;
				
				return false;
			}
		}

		private function Verify_PostgreSQL_Database($database_name)
		{
			$success = false;

			if($this->Database_Name)
			{
				$connection = pg_connect("host={$this->Server} port={$this->Server_Port} user={$this->User} password={$this->Password} dbname='{$this->Database_Name}'");

				$sql = "SELECT COUNT(datname) FROM pg_catalog.pg_database WHERE datname = '{$database_name}';";

				if(pg_send_query($connection, $sql))
				{
					$result = $this->Fetch_Array(pg_get_result($connection));
					$state 	= pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);

					if($state)
					{
						echo "\nPostgreSQL Error Code: {$state}\n";

						$success = false;
					}
					else if($result["count"] == 0)
					{
						echo "\nFailed to Connect to the PostgreSQL Database. Verify the following Parameter:\n";
						echo "\tDatabase_Name:   	  '{$database_name}'\n";

						$success = false;
					}
					else if(($result["count"] == 1))
					{
						$this->Database_Name = $database_name;

						$success = true;
					}
				}
				else
				{
					echo "\nDatabase: Could Not Run SQL Query: {$sql}\n";

					$success = false;
				}

				pg_close($connection);
			}

			return $success;
		}

		public function Create_Database($db_name)
		{
			$connection = null;
			$success 	= false;

			switch(strtolower($this->Server_Type))
			{
				case "mysql":
					$connection = new mysqli($this->Server, $this->User, $this->Password, null, $Server_Port);
					
					if(!$connection->select_db($db_name))
					{
						$sql 		= "CREATE DATABASE " . $db_name . ";";
						$result 	= $connection->query($sql);
						$success 	= true;
					}

					$connection = null;
					
					$this->Set_Database_Name($db_name);
				break;
				
				case "postgresql":
					$connection = pg_connect("host={$this->Server} port={$this->Server_Port} user={$this->User} password={$this->Password} dbname='{$this->Database_Name}'");

					$sql = "SELECT COUNT(datname) FROM pg_catalog.pg_database WHERE datname = '{$db_name}';";

					if(pg_send_query($connection, $sql))
					{
						$result = $this->Fetch_Array(pg_get_result($connection));
						$state 	= pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);

						if($state)
						{
							$error_lines = explode("\n", pg_result_error($result));

							echo "\nSQL Error Code: {$state}";
							echo "\nSQL Error Message:\n";

							for($i = 0; $i < count($error_lines); $i++) echo "\t" . $error_lines[$i] . "\n";
						}
						else
						{ 
							if($result["count"] == 0) pg_query($connection, "CREATE DATABASE " . $db_name . ";");

							$connection = null;
							$success 	= true;


							$this->Set_Database_Name($db_name);
						}
					}
					else echo "\nDatabase: Could Not Run SQL Query: {$sql}\n";
				break;
			}

			return $success;
		}

		private function Connect()
		{
			if(!$this->Connection)
			{
				switch(strtolower($this->Server_Type))
				{
					case "mysql": 		$this->Connect_MySQL(); 		break;
					case "postgresql": 	$this->Connect_PostfreSQL(); 	break;
				}
			}
		}

		private function Connect_MySQL()
		{
			$this->Connection = mysqli_connect(	$this->Server,
												$this->User,
												$this->Password,
												$this->Database_Name,
												$this->Server_Port);

			if(!$this->Connection) echo "Failed to connect to MySQL: " . mysqli_connect_error();
		}

		private function Connect_PostfreSQL()
		{
			$connect_parameters = "";
			$connect_parameters	.= "host={$this->Server} ";
			$connect_parameters	.= "port={$this->Server_Port} ";
			$connect_parameters	.= "dbname={$this->Database_Name} ";
			$connect_parameters	.= "user={$this->User} ";
			$connect_parameters	.= "password={$this->Password}";

			$this->Connection = pg_connect($connect_parameters);

			if(!$this->Connection) echo "Failed to connect to PostgreSQL Server: " . $this->Database_Name;
		}

		private function Disconnect()
		{
			if($this->Connection)
			{
				switch(strtolower($this->Server_Type))
				{
					case "mysql": 		mysqli_close($this->Connection); 	break;
					case "postgresql": 	pg_close($this->Connection); 		break;
				}

				$this->Connection = false;
			}
		}

		public function is_Connected()
		{
			$this->Connect();

			$result = false;

			switch(strtolower($this->Server_Type))
			{
				case "mysql": 		$result = mysqli_ping($this->Connection); 	break;
				case "postgresql": 	$result = pg_ping($this->Connection); 		break;
			}

			$this->Disconnect();

			return $result;
		}

		private function PostgreSQL_Query($sql)
		{
			$result = false;

			if(pg_send_query($this->Connection, $sql))
			{
				$result = pg_get_result($this->Connection);
				$state 	= pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);

				if($state)
				{
					$error_lines = explode("\n", pg_result_error($result));

					echo "\nSQL Error Code: {$state}";
					echo "\nSQL Error Message:\n";

					for($i = 0; $i < count($error_lines); $i++) echo "\t" . $error_lines[$i] . "\n";

					var_dump($sql);
				}
			}
			else echo "\nDatabase: Could Not Run SQL Query: {$sql}\n";

			return $result;
		}

		private function Query($sql)
		{
			$this->Connect();

			$result = null;

			switch(strtolower($this->Server_Type))
			{
				case "mysql": 		$result = mysqli_query($this->Connection, $sql); 	break;
				case "postgresql": 	$result = $this->PostgreSQL_Query($sql); 			break;
			}

			if($result && substr($sql, 0, 11) == "INSERT INTO")
				$this->Insert_ID = $this->Connection->insert_id;
			else
				$this->Insert_ID = -1;

			$this->Disconnect();

			if($result) return $result; else return null;
		}

		private function Fetch_Array($query_result)
		{
			$record = null;

			switch(strtolower($this->Server_Type))
			{
				case "mysql": 		$record = mysqli_fetch_array($query_result, MYSQLI_ASSOC); 	break;
				case "postgresql": 	$record = pg_fetch_array($query_result, 0, PGSQL_ASSOC);	break;
			}

			return $record;
		}

		public function Get_Record($sql)
		{
			$result = $this->Query($sql);

			if($result)
			{
				$record = $this->Fetch_Array($result);

				return $record;
			}
			else return null;
		}

		public function Get_Recordset($sql)
		{
			$result = $this->Query($sql);

			if($result && intval($result->num_rows) > 0)
			{
				$recordset = array();

				while($record = $this->Fetch_Array($result)){ $recordset[] = $record; }

				return $recordset;
			}
			else return null;
		}

		public function Get_Insert_ID(){ return $this->Insert_ID; }

		public function Run_Query($sql)
		{
			$sql = trim(preg_replace('/\s\s+/', ' ', $sql));
			$sql = str_replace("\t", "", $sql);
			$sql = str_replace("\n", "", $sql);

			$query_result = $this->Query($sql);

			if($query_result) return true; else return false;
		}

		public function Count($sql)
		{
			$query_result = $this->Query($sql);

			if($query_result)
			{
				$record 			= $this->Fetch_Array($query_result);
				$sql_count_field 	= $this->Get_SQL_Count_Field($sql);
				$count 				= 0;
				$count_text 		= "COUNT";
				
				if(strpos($sql, "count") !== false) $count_text = "count";
				if(strpos($sql, "Count") !== false) $count_text = "Count";

				if($record[$count_text . "(" . $sql_count_field . ")"]) $count = $record[$count_text . "(" . $sql_count_field . ")"];

				return $count;
			}
			else return null;
		}

		private function Get_SQL_Count_Field($sql)
		{
			$sql_count_field = "";

			$position_start = strpos(strtolower($sql), "count(") + 6;
			$position_end 	= strpos(strtolower($sql), ")");

			$sql_count_field = substr($sql, $position_start, $position_end - $position_start);

			return $sql_count_field;
		}
	}
?>
