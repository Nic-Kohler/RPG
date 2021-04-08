<?php

	ini_set('max_execution_time', 0);
	ini_set('display_errors', 1);
	error_reporting(E_ALL ^ E_NOTICE);
	ignore_user_abort(1);

	define("MAX_WALKING_DIST", 835);

	require_once("./includes/database.php");
	require_once("./includes/client_socket.php");


	class GPS_Position
	{
		private $latitude;
		private $longitude;

		function __construct($lat, $long)
		{
			$this->latitude = $lat;
			$this->longitude = $long;
		}

		public function Get_Latitude(){ return $this->latitude; }
		public function Get_Longitude(){ return $this->longitude; }
	}

	class Route_Permutation
	{
		private $DB;
		private $CS;

		private $level;
		private $parent;

		private $stop_combination_id;
		private $route_permutation;
		private $allowed_routes;
		private $route_distance;
		private $distance_from_origin_to_destination;
		private $destination;
		private $stops_to_add;
		private $average_successful_route_distance;

		private $p_id;
		private $progress_file_stream;
		private $working_dir;

		function __construct($args)
		{
			date_default_timezone_set('Africa/Johannesburg');

			$this->DB = new Database();
			$this->CS = new Client_Socket();

			$this->p_id = getmypid();
			$this->working_dir = "/home/pi/WebSpace/Route_Generator";

			$arg_array = unserialize(urldecode($args));

			$this->level  = $arg_array["level"];
			$this->parent = $arg_array["parent"];
			$this->stop_combination_id = $arg_array["stop_combination_id"];

			$this->progress_file_stream = fopen("{$this->working_dir}/RPG_Files/rpg_progress_{$this->stop_combination_id}_{$this->p_id}.log", "w");
			$this->CS->Set_Debug_File_Stream($this->progress_file_stream);

			$str = "argument_file_ticket: {$this->working_dir}/RPG_Files/{$this->stop_combination_id}_{$arg_array["argument_file_ticket"]}.args\r\n";
			fwrite($this->progress_file_stream, $str);

			$args_file = unserialize(file_get_contents("{$this->working_dir}/RPG_Files/{$this->stop_combination_id}_{$arg_array["argument_file_ticket"]}.args"));
			unlink("{$this->working_dir}/RPG_Files/{$this->stop_combination_id}_{$arg_array["argument_file_ticket"]}.args");
			//unlink("{$this->working_dir}/RPG_Files/{$this->stop_combination_id}_{$arg_array["argument_file_ticket"]}.bat");

			$this->route_permutation   = $args_file["route_permutation"];
			$this->allowed_routes      = $args_file["allowed_routes"];
			$this->route_distance      = $args_file["route_distance"];
			$this->destination         = $args_file["destination"];
			$this->distance_from_origin_to_destination = $args_file["distance_from_origin_to_destination"];
			$this->stops_to_add = array();
			$this->average_successful_route_distance = 0;

			unset($args);
			unset($arg_array);

			if($this->CS->Is_Connected())
			{
				$route_permutation_initial_stop = $this->route_permutation[count($this->route_permutation) - 1];
				$stops_to_investigate_count = $this->Get_Stops_on_Route_Count($route_permutation_initial_stop);

				$message = array("action"                     => "Identify_Socket",
				                 "socket_id"                  => $this->p_id,
				                 "parent"                     => $this->parent,
				                 "stop_combination_id"        => $this->stop_combination_id,
				                 "stops_to_investigate_count" => $stops_to_investigate_count,
				                 "route_id"                   => $route_permutation_initial_stop["route_id"],
				                 "route_direction"            => $route_permutation_initial_stop["route_direction"],
				                 "origin_mot_id"              => $route_permutation_initial_stop["mot_id"],
								 "origin_stop_id"             => $route_permutation_initial_stop["stop_id"],
								 "origin_stop_name"           => $route_permutation_initial_stop["stop_name"]);

				$this->CS->Queue_Outgoing_Message($message);
				$this->CS->Process_Requests();

				$str =  "*** Route Permutation Created: {$this->p_id}\r\n";
				$str .= "\r\n";
				fwrite($this->progress_file_stream, $str);

				while($this->CS->Is_Connected())
				{
					$str  =  "****************************\r\n";
					$str .=  "*** MAIN LOOP IS RUNNING ***\r\n";
					$str .=  "****************************\r\n";
					$str .= "\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);

					sleep(5);

					$this->CS->Process_Requests();
					$this->Incoming_Message_Handler();

					if(file_exists("{$this->working_dir}/RPG_Files/kill.file")) $this->Socket_Competed();
				}
			}
		}

		private function Incoming_Message_Handler()
		{
			$message = $this->CS->Get_Pending_Message();

			while($message)
			{
				switch($message["action"])
				{
					case "Run":
						$this->average_successful_route_distance = $message["average_distance"];

						$str =  "*** Route Permutation Running: {$this->p_id}\r\n";
						$str .= "\r\n";
						fwrite($this->progress_file_stream, $str);

						$this->Run();

						break;

					case "Update_Average_Successful_Route_Distance":

						$str = "*** Route Permutation Average Successful Route Distance Received: {$this->p_id}\r\n";
						$str .= "\r\n";
						fwrite($this->progress_file_stream, $str);

						$this->average_successful_route_distance = $message["average_distance"];

						break;

					case "Argument_File_Ticket":

						$str  = "*** Argument File Ticket Received:\r\n";
						$str .= "    Parent ID:            {$this->p_id}\r\n";
						$str .= "    Argument File Ticket: {$message["argument_file_ticket"]}\r\n";
						fwrite($this->progress_file_stream, $str);

						$this->Add_New_Route_Permutation($message["argument_file_ticket"]);

						$count = count($this->stops_to_add);
						$str =  "    Stops to Add Count:   {$count}\r\n";
						fwrite($this->progress_file_stream, $str);

						if(count($this->stops_to_add) == 0) $this->Socket_Competed();

						break;
				}

				if($this->CS->Is_Connected()) $message = $this->CS->Get_Pending_Message(); else $message = false;
			}

			$this->CS->Process_Requests();
		}

		private function Run()
		{
			$route_end_not_reached = true;
			$is_in_range_of_destination = false;
			$stop_investigated_count = 0;
			$initial_stop_on_route = $this->route_permutation[count($this->route_permutation) - 1];

			$str =  "*** Initial Stop:\r\n";
			$str .= print_r($initial_stop_on_route, true);
			$str .= "\r\n";
			$str .= "\r\n";
			fwrite($this->progress_file_stream, $str);

			$current_stop_on_route = $this->Get_Next_Stop_on_Route($initial_stop_on_route["stop_order"],
			                                                       $initial_stop_on_route["route_id"],
			                                                       $initial_stop_on_route["route_direction"],
			                                                       $initial_stop_on_route["mot_id"]);

			while($current_stop_on_route && $route_end_not_reached)
			{
				$str =  "*** Investigating Stop:\r\n";
				$str .= print_r($current_stop_on_route, true);
				$str .= "\r\n";
				$str .= "\r\n";
				fwrite($this->progress_file_stream, $str);

				$stop_investigated_count++;

				$message = array("action"                     => "Update_Route_Permutation_Progress",
				                 "socket_id"                  => $this->p_id,
				                 "stop_combination_id"        => $this->stop_combination_id,
				                 "stop_being_investigated"    => $stop_investigated_count,
				                 "current_stop_id"            => $current_stop_on_route["stop_id"],
				                 "current_stop_name"          => $current_stop_on_route["stop_name"]);

				$this->CS->Queue_Outgoing_Message($message);
				$this->CS->Process_Requests();

				$this->route_permutation[] = $current_stop_on_route;

				$previous_stop_on_route = $this->route_permutation[count($this->route_permutation) - 2];
				$this->route_distance += $this->Get_Distance(new GPS_Position($previous_stop_on_route["stop_latitude"],
				                                                              $previous_stop_on_route["stop_longitude"]),
				                                             new GPS_Position($current_stop_on_route["stop_latitude"],
				                                                              $current_stop_on_route["stop_longitude"]));


				if($current_stop_on_route["stop_id"] == $this->destination["stop_id"]) $is_in_range_of_destination = true;

				if($is_in_range_of_destination)
				{
					$str = "*** Is In Range of Destination.\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);

					$this->Save_Distance_of_Successful_Routes($this->route_distance);

					for($i = 0; $i < count($this->route_permutation); $i++)
					{
						$this->DB->Run_Query("INSERT INTO Route_Permutations (stop_combination_id,
																			  route_permutation_id,
																			  stop_name,
																			  route_id,
																			  mot_id,
																			  stop_id,
																			  stop_latitude,
																			  stop_longitude)
											  VALUES ({$this->stop_combination_id},
													  {$this->p_id},
													  '{$this->route_permutation[$i]["stop_name"]}',
													  {$this->route_permutation[$i]["route_id"]},
													  {$this->route_permutation[$i]["mot_id"]},
													  {$this->route_permutation[$i]["stop_id"]},
													  {$this->route_permutation[$i]["stop_latitude"]},
													  {$this->route_permutation[$i]["stop_longitude"]})");
					}

					$route_end_not_reached = false;
				}

				if($route_end_not_reached && $this->average_successful_route_distance != 0)
				{
					if($this->route_distance > (1.15 * $this->average_successful_route_distance))
					{
						$str = "*** Route Distance is more than Successful Average Route Distance.\r\n";
						$str .= "\r\n";
						fwrite($this->progress_file_stream, $str);

						$route_end_not_reached = false;
					}
				}

				if($this->route_distance > (2 * $this->distance_from_origin_to_destination))
				{
					$str = "*** Route Distance is more than Double the Distance from Origin to Destination.\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);

					$route_end_not_reached = false;
				}

				if($route_end_not_reached)
				{
					$str = "*** Route End Not Reached.\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);

					$stops_in_range_of_target = $this->Find_Stops_in_Range_of_Target($current_stop_on_route, $this->allowed_routes);
					$stops_in_range_of_target_count = count($stops_in_range_of_target);

					$str = "*** stops_in_range_of_target:\r\n";
					$str .= "   stops_in_range_of_target_count: {$stops_in_range_of_target_count}\r\n";
					$str .= print_r($stops_in_range_of_target, true);
					$str .= "\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);
					
					if($stops_in_range_of_target_count)
					{
						for($k = 0; $k < count($stops_in_range_of_target); $k++)
						{
							if($stops_in_range_of_target[$k])
							{
								$this->allowed_routes = $this->Remove_Exceptions_From_Routes($this->allowed_routes, $stops_in_range_of_target[$k]["route_id"]);

								$closest_joining_stops_on_route = $this->Get_Closest_Joining_Stops_on_Routes($current_stop_on_route, $stops_in_range_of_target[$k]);
								
								if($closest_joining_stops_on_route || count($closest_joining_stops_on_route) != 0)
								{
									$this->stops_to_add[] = $closest_joining_stops_on_route;
									
									$str = "*** closest_joining_stops_on_route:\r\n";
									$str .= print_r($closest_joining_stops_on_route, true);
									$str .= "\r\n";
									$str .= "\r\n";
									fwrite($this->progress_file_stream, $str);
								}
							}
						}
					}
				}
				else
				{
					$str = "*** Route End Reached.\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);
				}
				
				$current_stop_on_route = $this->Get_Next_Stop_on_Route($current_stop_on_route["stop_order"],
				                                                       $current_stop_on_route["route_id"],
				                                                       $current_stop_on_route["route_direction"],
				                                                       $current_stop_on_route["mot_id"]);
			}

			if(!$is_in_range_of_destination)
			{
				$str = "*** Is Not In Range of Destination.\r\n";
				$str .= "\r\n";
				fwrite($this->progress_file_stream, $str);
			}

			$this->Prepare_New_Route_Permutations();
		}

		private function Remove_Exceptions_From_Routes($allowed_routes, $route_id_exception)
		{
			for($i = 0; $i < count($allowed_routes); $i++)
				if($allowed_routes[$i] == $route_id_exception)
					unset($allowed_routes[$i]);

			return array_values($allowed_routes);
		}

		private function Prepare_New_Route_Permutations()
		{
			$stops_to_add_count = count($this->stops_to_add);

			$str = "*** Stops To Add Count: {$stops_to_add_count}\r\n";
			$str .= "\r\n";
			fwrite($this->progress_file_stream, $str);

			if($stops_to_add_count > 0)
			{
				for($i = 0; $i < $stops_to_add_count; $i++)
				{
					$str  = "*** Stop To Add: {$i}\r\n";
					$str .= print_r($this->stops_to_add[$i], true);
					$str .= "\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);

					$level = $this->level + 1;

					$this->CS->Queue_Outgoing_Message(array("action"              => "Prepare_Incoming_Sockets",
					                                        "socket_id"           => $this->p_id,
					                                        "stop_combination_id" => $this->stop_combination_id,
					                                        "level"               => $level));

					$this->CS->Process_Requests();
				}
			}
			else $this->Socket_Competed();
		}

		private function Add_New_Route_Permutation($argument_file_ticket)
		{
			$temp_route_permutation = array();
			$temp_route_distance = 0;
			$compile_data = true;

			for($j = 0; $j < count($this->route_permutation) && $compile_data; $j++)
			{
				$temp_route_permutation[] = $this->route_permutation[$j];

				if($j < (count($this->route_permutation) - 2))
				{
					$next_stop_on_route = $this->route_permutation[$j + 1];

					$temp_route_distance += $this->Get_Distance(new GPS_Position($this->route_permutation[$j]["stop_latitude"],
					                                                             $this->route_permutation[$j]["stop_longitude"]),
					                                            new GPS_Position($next_stop_on_route["stop_latitude"],
					                                                             $next_stop_on_route["stop_longitude"]));
				}

				if($this->route_permutation[$j]["stop_id"] == $this->stops_to_add[0]["stop_on_target_route"]["stop_id"])
				{
					$str = "*** Adding new Route Permutation.\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);

					$temp_route_permutation[] = $this->stops_to_add[0]["stop_on_route_in_range"];

					$str = "    temp_route_permutation:\r\n";
					$str .= print_r($temp_route_permutation, true);
					$str .= "\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);

					$new_route_distance = $temp_route_distance + $this->stops_to_add[0]["distance_between_stops"];

					$level = $this->level + 1;

					$process_args = serialize(array("route_permutation"   => $temp_route_permutation,
					                                "allowed_routes"      => $this->allowed_routes,
					                                "route_distance"      => $new_route_distance,
					                                "destination"         => $this->destination,
					                                "distance_from_origin_to_destination" => $this->distance_from_origin_to_destination));

					$argument_file = "{$this->working_dir}/RPG_Files/{$this->stop_combination_id}_{$argument_file_ticket}.args";

					$str = "    argument_file: {$argument_file}\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);

					$argument_file_ticket_stream = fopen($argument_file, "w") or die("Unable to open file!");
					fwrite($argument_file_ticket_stream, $process_args);
					fclose($argument_file_ticket_stream);

					$exec_args = serialize(array("level"                => $level,
					                             "parent"               => $this->p_id,
					                             "stop_combination_id"  => $this->stop_combination_id,
					                             "argument_file_ticket" => $argument_file_ticket));

					$str = "    exec_args:\r\n";
					$str .= print_r(unserialize($exec_args), true);
					$str .= "\r\n";
					$str .= "\r\n";
					fwrite($this->progress_file_stream, $str);

					$this->CS->Queue_Outgoing_Message(array("action"               => "Execute_New_Route_Permutation",
					                                        "stop_combination_id"  => $this->stop_combination_id,
					                                        "level"                => $level,
					                                        "parent"               => $this->p_id,
					                                        "argument_file_ticket" => $argument_file_ticket));
					$this->CS->Process_Requests();

					unset($this->stops_to_add[0]);
					$this->stops_to_add = array_values($this->stops_to_add);
					$compile_data = false;
				}
			}
		}

		private function Socket_Competed()
		{
			$this->CS->Queue_Outgoing_Message(array("action"              => "Socket_Completed",
			                                        "stop_combination_id" => $this->stop_combination_id,
			                                        "socket_id"           => $this->p_id));
			$this->CS->Process_Requests();

			$str = "*** Socket_Competed.\r\n";
			$str .= "\r\n";
			fwrite($this->progress_file_stream, $str);

			$this->CS->Kill();
			$this->Kill();
		}

		private function Get_Distance(GPS_Position $origin, GPS_Position $destination)
		{
			$origin_latitude = $origin->Get_Latitude();
			$origin_longitude = $origin->Get_Longitude();
			$destination_latitude = $destination->Get_Latitude();
			$destination_longitude = $destination->Get_Longitude();

			$_equatorial_earth_radius = 6378.1370;
			$_degree_to_radian = (M_PI / 180);

			$delta_longitude = ($destination_longitude - $origin_longitude) * $_degree_to_radian;
			$delta_latitude = ($destination_latitude - $origin_latitude) * $_degree_to_radian;
			$a = pow(sin($delta_latitude / 2), 2) +
			     cos($origin_latitude * $_degree_to_radian) *
			     cos($destination_latitude * $_degree_to_radian) *
			     pow(sin($delta_longitude / 2), 2);
			$c = 2 * atan2(sqrt($a), sqrt(1 - $a));
			$d = $_equatorial_earth_radius * $c;

			unset($origin);
			unset($destination);

			return round(1000 * $d, 4); //Meters
		}

		private function Get_MOT_Table_Names($mot_id)
		{
			$stop_table_name = null;
			$route_direction_table_name = null;

			switch($mot_id)
			{
				case 1:
					$stop_table_name = "Golden_Arrow_Bus_Stops";
					$route_direction_table_name = "Golden_Arrow_Route_Direction_Info";
					break;

				case 2:
					$stop_table_name = "Metrorail_Train_Stations";
					$route_direction_table_name = "Metrorail_Route_Direction_Info";
					break;

				case 3:
					$stop_table_name = "MyCiti_Bus_Stops";
					$route_direction_table_name = "MyCiti_Route_Direction_Info";
					break;
			}

			return array("stop_table_name" => $stop_table_name, "route_direction_table_name" => $route_direction_table_name);
		}

		private function Get_Next_Stop_on_Route($stop_order, $route_id, $route_direction, $mot_id)
		{
			$route_direction_table_name = null;
			$stop_table_name = null;

			$mot_table_names = $this->Get_MOT_Table_Names($mot_id);

			$sql = "SELECT	Routes.mot_id,
							Route_Definitions.route_id,
							Route_Definitions.stop_id,
							{$mot_table_names["stop_table_name"]}.stop_name,
							{$mot_table_names["stop_table_name"]}.stop_latitude,
							{$mot_table_names["stop_table_name"]}.stop_longitude,
							{$mot_table_names["route_direction_table_name"]}.stop_order,
							{$mot_table_names["route_direction_table_name"]}.route_direction
					FROM 	Routes
						JOIN	Route_Definitions
							ON	Route_Definitions.route_id = Routes.route_id
						JOIN	{$mot_table_names["stop_table_name"]}
							ON	{$mot_table_names["stop_table_name"]}.stop_id = Route_Definitions.stop_id
						LEFT JOIN {$mot_table_names["route_direction_table_name"]}
							ON	{$mot_table_names["route_direction_table_name"]}.route_definition_id = Route_Definitions.route_definition_id
					WHERE	Route_Definitions.route_id = {$route_id} AND
							{$mot_table_names["route_direction_table_name"]}.route_direction = {$route_direction} AND
							{$mot_table_names["route_direction_table_name"]}.stop_order = " . intval($stop_order + 1);

			return $this->DB->Get_Record($sql);
		}

		private function Get_Stops_on_Route_Count($current_stop)
		{
			$route_direction_table_name = null;
			$stop_table_name = null;

			$route_details = $this->Get_Route_Details($current_stop["route_id"], $current_stop["route_direction"]);
			$route_details_count = count($route_details);

			$current_stop_position = -1;

			for($i = 0; $i < $route_details_count && $current_stop_position < 0; $i++)
				if($current_stop["stop_id"] == $route_details[$i]["stop_id"])
					$current_stop_position = $i + 1;

			return $route_details_count - $current_stop_position;
		}

		private function Save_Distance_of_Successful_Routes($route_distance)
		{
			$str = "*** Saving Distance of Successful Route.\r\n";
			$str .= "\r\n";
			fwrite($this->progress_file_stream, $str);

			$this->CS->Queue_Outgoing_Message(array("action"              => "Save_Successful_Route_Distance",
			                                        "stop_combination_id" => $this->stop_combination_id,
			                                        "socket_id"           => $this->p_id,
			                                        "distance"            => $route_distance));
			$this->CS->Process_Requests();

		}

		private function Find_Stops_in_Range_of_Target($target_stop, $allowed_routes)
		{
			$route_ids = "{$allowed_routes[0]}";
			for($i = 1; $i < count($allowed_routes); $i++) $route_ids .= ", {$allowed_routes[$i]}";

			$proximity_target_stop = $this->DB->Get_Record("SELECT proximity_target_stop_id FROM Proximity_Target_Stops
														    WHERE stop_id = {$target_stop["stop_id"]} AND
														          route_id = {$target_stop["route_id"]} AND
														          route_direction = {$target_stop["route_direction"]}");

			$proximity_stops_in_range = null;
			
			if($proximity_target_stop)
			{
				$proximity_stops_in_range = $this->DB->Get_Recordset("SELECT * FROM Proximity_Stops_in_Range
																	  WHERE proximity_target_stop_id = {$proximity_target_stop["proximity_target_stop_id"]} AND
																			route_id IN ({$route_ids})");
			}

			if($proximity_stops_in_range == null) $proximity_stops_in_range = array();
			
			$stops_in_range_of_target = array();
			$stop_table_name = null;
			$route_direction_table_name = null;

			for($i = 0; $i < count($proximity_stops_in_range); $i++)
			{
				$route_details = $this->DB->Get_Record("SELECT mot_id FROM Routes WHERE route_id = {$proximity_stops_in_range[$i]["route_id"]}");

				$mot_table_names = $this->Get_MOT_Table_Names($route_details["mot_id"]);

				$sql = "SELECT 	Routes.mot_id,
								Route_Definitions.route_id,
								Route_Definitions.stop_id,
								{$mot_table_names["stop_table_name"]}.stop_name,
								{$mot_table_names["stop_table_name"]}.stop_latitude,
								{$mot_table_names["stop_table_name"]}.stop_longitude,
								{$mot_table_names["route_direction_table_name"]}.stop_order,
								{$mot_table_names["route_direction_table_name"]}.route_direction
						FROM 	Routes
							JOIN	Route_Definitions
								ON	Route_Definitions.route_id = Routes.route_id
							JOIN	{$mot_table_names["stop_table_name"]}
								ON	{$mot_table_names["stop_table_name"]}.stop_id = Route_Definitions.stop_id
							LEFT JOIN {$mot_table_names["route_direction_table_name"]}
								ON	{$mot_table_names["route_direction_table_name"]}.route_definition_id = Route_Definitions.route_definition_id
						WHERE 	Route_Definitions.route_id = {$proximity_stops_in_range[$i]["route_id"]} AND
								Route_Definitions.stop_id = {$proximity_stops_in_range[$i]["stop_id"]} AND
								{$mot_table_names["route_direction_table_name"]}.route_direction = {$proximity_stops_in_range[$i]["route_direction"]}
						ORDER BY	Routes.route_id,
									{$mot_table_names["route_direction_table_name"]}.route_direction,
									{$mot_table_names["route_direction_table_name"]}.stop_order";

				$stops_in_range_of_target[] = $this->DB->Get_Record($sql);
			}
			
			if(count($stops_in_range_of_target) > 0) return $stops_in_range_of_target; else return array();
		}

		public function Get_Route_Details($route_id, $route_direction)
		{
			$stop_table_name = null;
			$route_direction_table_name = null;

			$route_details = $this->DB->Get_Record("SELECT mot_id FROM Routes WHERE route_id = {$route_id}");

			$mot_table_names = $this->Get_MOT_Table_Names($route_details["mot_id"]);

			$sql = "SELECT 	Routes.mot_id,
							Route_Definitions.route_id,
							Route_Definitions.stop_id,
							{$mot_table_names["stop_table_name"]}.stop_name,
							{$mot_table_names["stop_table_name"]}.stop_latitude,
							{$mot_table_names["stop_table_name"]}.stop_longitude,
							{$mot_table_names["route_direction_table_name"]}.stop_order,
							{$mot_table_names["route_direction_table_name"]}.route_direction
					FROM 	Routes
						JOIN	Route_Definitions
							ON	Route_Definitions.route_id = Routes.route_id
						JOIN	{$mot_table_names["stop_table_name"]}
							ON	{$mot_table_names["stop_table_name"]}.stop_id = Route_Definitions.stop_id
						LEFT JOIN {$mot_table_names["route_direction_table_name"]}
							ON	{$mot_table_names["route_direction_table_name"]}.route_definition_id = Route_Definitions.route_definition_id
					WHERE	Route_Definitions.route_id = {$route_id} AND
							{$mot_table_names["route_direction_table_name"]}.route_direction = {$route_direction}
					ORDER BY	Routes.route_id,
								{$mot_table_names["route_direction_table_name"]}.route_direction,
								{$mot_table_names["route_direction_table_name"]}.stop_order";

			return $this->DB->Get_Recordset($sql);
		}

		private function Get_Closest_Joining_Stops_on_Routes($target_stop, $stop_in_range_of_target)
		{
			$shortest_distance = 9999999;
			$closest_joining_stops = null;
			$available_target_stop = $target_stop;

			while($available_target_stop)
			{
				$available_stop_in_range_of_target = $stop_in_range_of_target;

				while($available_stop_in_range_of_target)
				{
					$distance_between_target_and_stop_in_range = $this->Get_Distance(new GPS_Position($available_target_stop["stop_latitude"],
					                                                                                  $available_target_stop["stop_longitude"]),
					                                                                 new GPS_Position($available_stop_in_range_of_target["stop_latitude"],
					                                                                                  $available_stop_in_range_of_target["stop_longitude"]));

					if($distance_between_target_and_stop_in_range <= $shortest_distance)
					{
						$shortest_distance = $distance_between_target_and_stop_in_range;

						$next_stop_on_route = $this->Get_Next_Stop_on_Route($available_stop_in_range_of_target["stop_order"],
						                                                    $available_stop_in_range_of_target["route_id"],
						                                                    $available_stop_in_range_of_target["route_direction"],
						                                                    $available_stop_in_range_of_target["mot_id"]);

						if($next_stop_on_route)
						{
							$closest_joining_stops = array("stop_on_target_route"   => $available_target_stop,
							                               "stop_on_route_in_range" => $available_stop_in_range_of_target,
							                               "distance_between_stops" => $shortest_distance);
						}
						else $closest_joining_stops = null;
					}

					$available_stop_in_range_of_target = $this->Get_Next_Stop_on_Route($available_stop_in_range_of_target["stop_order"],
					                                                                   $available_stop_in_range_of_target["route_id"],
					                                                                   $available_stop_in_range_of_target["route_direction"],
					                                                                   $available_stop_in_range_of_target["mot_id"]);
				}
				
				$available_target_stop = $this->Get_Next_Stop_on_Route($available_target_stop["stop_order"],
				                                                       $available_target_stop["route_id"],
				                                                       $available_target_stop["route_direction"],
				                                                       $available_target_stop["mot_id"]);

				if($closest_joining_stops["stop_on_target_route"]["stop_id"] == $closest_joining_stops["stop_on_route_in_range"]["stop_id"])
					$available_target_stop = null;
			}
			
			return $closest_joining_stops;
		}

		private function Kill()
		{
			$this->DB->Kill();

			fclose($this->progress_file_stream);

			unset($this->route_permutation);
			unset($this->allowed_routes);
			unset($this->destination);
		}
	}

	$route_permutation = new Route_Permutation($argv[1]);

