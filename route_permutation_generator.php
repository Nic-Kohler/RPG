<?php
	ini_set('max_execution_time', 0);
	ini_set('display_errors', 1);
	error_reporting(E_ALL ^ E_NOTICE);
	ignore_user_abort(1);

	require_once("./includes/functions.php");
	require_once("./includes/database.php");
	require_once("./includes/server_socket.php");


	class Route_Permutation_Stack
	{
		private $DB;
		private $SS;
		private $p_id;

		private $running_stop_combinations;
		private $mot_combinations;
		private $routes;
		private $incoming_message_queue;

		private $dir;
		private $progress_file;

		private $progress_total;
		private $running_total;
		private $running_stop_combination_limit;

		function __construct()
		{
			date_default_timezone_set('Africa/Johannesburg');

			$this->DB				= new Database();
			$this->SS				= new Server_Socket();
			$this->p_id				= getmypid();
			$this->dir				= "/home/pi/WebSpace/Route_Generator/RPG_Files";
			$this->progress_file 	= "{$this->dir}/rpg_progress.log";

			$route_stream = fopen("{$this->dir}/rpg_pid.log", "w") or die("Unable to open file!");
			fwrite($route_stream, $this->p_id);
			fclose($route_stream);

			$this->DB->Run_Query("DELETE FROM Stop_Combinations");
			$this->DB->Run_Query("DELETE FROM Route_Permutations");
			$this->DB->Run_Query("ALTER TABLE Stop_Combinations AUTO_INCREMENT = 1");
			$this->DB->Run_Query("ALTER TABLE Route_Permutations AUTO_INCREMENT = 1");

			$this->Set_MOT_Combinations();

			$this->running_stop_combinations 		= array();
			$this->progress_total 					= $this->Get_Progress_Total();
			$this->running_total 					= 0;
			$this->running_stop_combination_limit 	= 3;
			$this->incoming_message_queue 			= array();

			$this->Run();
			$this->Finalize();

			$output_str  = "";
			$output_str .= "****************\r\n";
			$output_str .= "* !!! DONE !!! *\r\n";
			$output_str .= "****************\r\n";

			echo "\r\n";
			echo $output_str;

			$route_stream = fopen($this->progress_file, "w");
			fwrite($route_stream, $output_str);
			fclose($route_stream);

			$this->Kill();
		}

/*
 * Metrorail    - Heathfield:
 *      array("mot_id"          => 2,
 *         	  "route_id"        => 16,
 *            "stop_id"         => 167,
 *            "stop_name"       => "Heathfield",
 *            "stop_latitude"   => -34.045982563764,
 *            "stop_longitude"  => 18.465447938658,
 *            "stop_order"      => 13,
 *            "route_direction" => 0);
 *
 * Metrorail    - Diep River:
 *      array("mot_id"          => 2,
 *         	  "route_id"        => 16,
 *            "stop_id"         => 149,
 *            "stop_name"       => "Diep River",
 *            "stop_latitude"   => -34.035413152942,
 *            "stop_longitude"  => 18.466969764384,
 *            "stop_order"      => 14,
 *            "route_direction" => 0);
 *
 * Metrorail    - Cape Town:
 *      array("mot_id"          => 2,
 *         	  "route_id"        => 16,
 *            "stop_id"         => 141,
 *            "stop_name"       => "Cape Town",
 *            "stop_latitude"   => -33.922150338532,
 *            "stop_longitude"  => 18.425065411045,
 *            "stop_order"      => 29,
 *            "route_direction" => 0);
 *
 *
 * MyCiTi       - Upper Portswood:
 *      array("mot_id"          => 3,
 *         	  "route_id"        => 161,
 *            "stop_id"         => 117,
 *            "stop_name"       => "Upper Portswood",
 *            "stop_latitude"   => -33.909124,
 *            "stop_longitude"  => 18.413032,
 *            "stop_order"      => 40,
 *            "route_direction" => 0);
 *
 * MyCiTi       - Granger Bay:
 *      array("mot_id"          => 3,
 *         	  "route_id"        => 155,
 *            "stop_id"         => 181,
 *            "stop_name"       => "Granger Bay",
 *            "stop_latitude"   => -33.901285,
 *            "stop_longitude"  => 18.412945,
 *            "stop_order"      => 13,
 *            "route_direction" => 0);
 */

		private function Run()
		{
			$mot_combination_count = count($this->mot_combinations);

			for($h = 0; $h < $mot_combination_count; $h++)
			{
				$this->routes = $this->DB->Get_Recordset("SELECT route_id FROM Routes WHERE mot_id IN ({$this->Get_Stop_Combination_String($h)})");
				$routes_count = count($this->routes);

				for($i = 0; $i < $routes_count; $i++)
				{
					for($k = 0; $k < 2; $k++)
					{
						$route_i = $this->Get_Route_Details($this->routes[$i]["route_id"], $k);
						$route_i_count = count($route_i);

						for($m = 0; $m < $route_i_count; $m++)
						{
							for($j = 0; $j < $routes_count; $j++)
							{
								for($l = 0; $l < 2; $l++)
								{
									$route_j = $this->Get_Route_Details($this->routes[$j]["route_id"], $l);
									$route_j_count = count($route_j);

									for($n = 0; $n < $route_j_count; $n++)
									{
										$this->Save_Progress_to_File();

										/*
										if($k == 0) $stop_order_k = 13;
										else $stop_order_k = 17;

										if($l == 0) $stop_order_l = 40;
										else $stop_order_l = 5;
										$stop_1 = array("mot_id"          => 2,
														"route_id"        => 16,
														"stop_id"         => 167,
														"stop_name"       => "Heathfield",
														"stop_latitude"   => -34.045982563764,
														"stop_longitude"  => 18.465447938658,
														"stop_order"      => $stop_order_k,
														"route_direction" => $k);

										$stop_2 = array("mot_id"          => 3,
														"route_id"        => 161,
														"stop_id"         => 117,
														"stop_name"       => "Upper Portswood",
														"stop_latitude"   => -33.909124,
														"stop_longitude"  => 18.413032,
														"stop_order"      => $stop_order_l,
														"route_direction" => $l);
										*/
										
										$mot_combination 		 = implode($this->mot_combinations[$h]);
										$stop_combination_exists = $this->Stop_Combination_Exists($route_i[$m], $route_i[$n], $mot_combination);
										//$stop_combination_exists = $this->Stop_Combination_Exists($stop_1, $stop_2, $mot_combination);

										if((!$stop_combination_exists || ($stop_combination_exists && $l == 0)) && $route_i[$m]["stop_id"] != $route_j[$n]["stop_id"])
											$this->Add_New_Stop_Combination($route_i[$m], $route_j[$n], $mot_combination);

										//if(!$stop_combination_exists || ($stop_combination_exists && $l == 0)) $this->Add_New_Stop_Combination($stop_1, $stop_2, $mot_combination);

										$this->SS->Process_Requests();
										
										$this->Add_New_Incoming_Messages_To_Queue($this->SS->Get_Pending_Messages());
										$this->Message_Queue_Handler();

										$this->Process_Running_Stop_Combinations();
									}
								}
							}
						}
					}
				}
			}
		}

		private function Add_New_Incoming_Messages_To_Queue($new_messages)
		{
			if($new_messages)
			{
				for($i = 0; $i < count($new_messages); $i++) $this->incoming_message_queue[] = $new_messages[$i];

				unset($new_messages);
			}
		}

		private function Message_Queue_Handler()
		{
			for($i = 0; $i < count($this->incoming_message_queue); $i++)
			{
				switch($this->incoming_message_queue[$i]["action"])
				{
					case "Identify_Socket":                     $this->Identify_Socket($i);                     break;
					case "Prepare_Incoming_Sockets":            $this->Prepare_Incoming_Sockets($i);            break;
					case "Socket_Completed":	                $this->Socket_Completed($i);                    break;
					case "Save_Successful_Route_Distance":	    $this->Save_Successful_Route_Distance($i);      break;
					case "Update_Route_Permutation_Progress":	$this->Update_Route_Permutation_Progress($i);   break;
					case "Execute_New_Route_Permutation":	    $this->Execute_New_Route_Permutation($i);       break;
				}
			}

			for($j = (count($this->incoming_message_queue) - 1); $j > -1; $j--) if($this->incoming_message_queue[$j] == null) unset($this->incoming_message_queue[$j]);
			$this->incoming_message_queue = array_values($this->incoming_message_queue);

			$this->SS->Process_Requests();
		}

		private function Find_Stop_Combination_Index_From_Message_Index($message_index)
		{
			$stop_combination_not_found = true;
			$stop_combination_index = -1;

			for($i = 0; $i < count($this->running_stop_combinations) && $stop_combination_not_found; $i++)
			{
				if($this->running_stop_combinations[$i]["stop_combination_id"] == $this->incoming_message_queue[$message_index]["stop_combination_id"])
				{
					$stop_combination_not_found = false;
					$stop_combination_index = $i;
				}
			}

			if($stop_combination_not_found) return false;
			else return $stop_combination_index;
		}

		private function Find_Stop_Combination_Index_From_ID($stop_combination_id)
		{
			$stop_combination_not_found = true;
			$stop_combination_index = -1;

			for($i = 0; $i < count($this->running_stop_combinations) && $stop_combination_not_found; $i++)
			{
				if($this->running_stop_combinations[$i]["stop_combination_id"] == $stop_combination_id)
				{
					$stop_combination_not_found = false;
					$stop_combination_index = $i;
				}
			}

			if($stop_combination_not_found) return false;
			else return $stop_combination_index;
		}

		private function Identify_Socket($message_index)
		{
			$stop_combination_index = $this->Find_Stop_Combination_Index_From_Message_Index($message_index);

			if($stop_combination_index || $stop_combination_index == 0)
			{
				$place_holder_not_found = true;

				for($i = 0; $i < count($this->running_stop_combinations[$stop_combination_index]["sockets"]) && $place_holder_not_found; $i++)
				{
					if($this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["socket_id"] == null &
					   $this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["status"] == "Pending" &&
					   $this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["parent"] == $this->incoming_message_queue[$message_index]["parent"])
					{
						$now 	 = new DateTime("now");
						$started = $now->format("Y-m-d H:i:s");

						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["socket_id"]                  = $this->incoming_message_queue[$message_index]["socket_id"];
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["status"]                     = "Running";
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["started"]                    = $started;
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["stops_to_investigate_count"] = $this->incoming_message_queue[$message_index]["stops_to_investigate_count"];
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["stop_being_investigated"]    = 0;

						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["route_id"]         = $this->incoming_message_queue[$message_index]["route_id"];
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["route_direction"]  = $this->incoming_message_queue[$message_index]["route_direction"];
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["origin_mot_id"]    = $this->incoming_message_queue[$message_index]["origin_mot_id"];
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["origin_stop_id"]   = $this->incoming_message_queue[$message_index]["origin_stop_id"];
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["origin_stop_name"] = $this->incoming_message_queue[$message_index]["origin_stop_name"];

						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["current_stop_id"]   = $this->incoming_message_queue[$message_index]["origin_stop_id"];
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["current_stop_name"] = $this->incoming_message_queue[$message_index]["origin_stop_name"];

						$average_successful_route_distance = $this->Get_Average_Successful_Route_Distance($stop_combination_index);

						$this->SS->Queue_Outgoing_Message($this->incoming_message_queue[$message_index]["socket_id"],
						                                  array("action"           => "Run",
						                                        "average_distance" => $average_successful_route_distance));

						$this->incoming_message_queue[$message_index] = null;

						$place_holder_not_found = false;
					}
				}
			}
		}

		private function Prepare_Incoming_Sockets($message_index)
		{
			$stop_combination_index = $this->Find_Stop_Combination_Index_From_Message_Index($message_index);

			if($this->incoming_message_queue[$message_index] && $stop_combination_index || $stop_combination_index == 0)
			{
				$this->running_stop_combinations[$stop_combination_index]["sockets"][] = array("socket_id"  => null,
				                                                                               "status"     => "Pending",
				                                                                               "level"      => $this->incoming_message_queue[$message_index]["level"],
				                                                                               "parent"     => $this->incoming_message_queue[$message_index]["socket_id"],
				                                                                               "successful" => false);

				$this->running_stop_combinations[$stop_combination_index]["argument_file_ticket"]++;

				$this->SS->Queue_Outgoing_Message($this->incoming_message_queue[$message_index]["socket_id"],
				                                  array("action"               => "Argument_File_Ticket",
				                                        "argument_file_ticket" => $this->running_stop_combinations[$stop_combination_index]["argument_file_ticket"]));
				
				$this->SS->Process_Requests();

				$this->incoming_message_queue[$message_index] = null;
			}
		}

		private function Socket_Completed($message_index)
		{
			echo "*** Socket_Completed:\r\n";

			$stop_combination_index = $this->Find_Stop_Combination_Index_From_Message_Index($message_index);

			if($this->incoming_message_queue[$message_index] && ($stop_combination_index || $stop_combination_index == 0))
			{
				$socket_not_found = true;

				for($i = 0; $i < count($this->running_stop_combinations[$stop_combination_index]["sockets"]) && $socket_not_found; $i++)
				{
					if($this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["socket_id"] == $this->incoming_message_queue[$message_index]["socket_id"])
					{
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["status"] = "Done";

						$this->incoming_message_queue[$message_index] = null;

						$socket_not_found = false;

						echo "    Route Permutation Complete: {$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["socket_id"]}\r\n";
					}
				}

				$completed_socket_count = 0;
				$total_socket_count = count($this->running_stop_combinations[$stop_combination_index]["sockets"]);

				for($i = 0; $i < $total_socket_count; $i++)
					if($this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["status"] == "Done")
						$completed_socket_count++;

				if($completed_socket_count == $total_socket_count)
				{
					echo "    Stop Combination Closed: {$this->running_stop_combinations[$stop_combination_index]["stop_combination_id"]}\r\n";

					unset($this->running_stop_combinations[$stop_combination_index]);
					$this->running_stop_combinations = array_values($this->running_stop_combinations);
				}
			}
		}

		private function Save_Successful_Route_Distance($message_index)
		{
			$stop_combination_index = $this->Find_Stop_Combination_Index_From_Message_Index($message_index);

			if($stop_combination_index || $stop_combination_index == 0)
			{
				$socket_not_found = true;

				for($i = 0; $i < count($this->running_stop_combinations[$stop_combination_index]["sockets"]) && $socket_not_found; $i++)
				{
					if($this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["socket_id"] == $this->incoming_message_queue[$message_index]["socket_id"])
					{
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["successful"] = true;
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["distance"]   = $this->incoming_message_queue[$message_index]["distance"];

						$this->incoming_message_queue[$message_index] = null;

						$socket_not_found = false;

						echo "*** Saving Successful Route Distance\r\n";
					}
				}

				if(!$socket_not_found) $this->Broadcast_Average_Successful_Route_Distance($stop_combination_index);
			}
		}

		private function Update_Route_Permutation_Progress($message_index)
		{

			echo "*** Update_Route_Permutation_Progress.\r\n";

			$stop_combination_index = $this->Find_Stop_Combination_Index_From_Message_Index($message_index);

			if($stop_combination_index || $stop_combination_index == 0)
			{
				for($i = 0; $i < count($this->running_stop_combinations[$stop_combination_index]["sockets"]); $i++)
				{
					if($this->incoming_message_queue[$message_index] && $this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["socket_id"] == $this->incoming_message_queue[$message_index]["socket_id"])
					{
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["stop_being_investigated"] = $this->incoming_message_queue[$message_index]["stop_being_investigated"];
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["current_stop_id"]         = $this->incoming_message_queue[$message_index]["current_stop_id"];
						$this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["current_stop_name"]       = $this->incoming_message_queue[$message_index]["current_stop_name"];

						$this->incoming_message_queue[$message_index] = null;
					}
				}
			}
		}

		private function Execute_New_Route_Permutation($message_index)
		{

			echo "*** Execute New Route Permutation.\r\n";

			$exec_args = urlencode(serialize(array( "level"                => $this->incoming_message_queue[$message_index]["level"],
													"parent"               => $this->incoming_message_queue[$message_index]["parent"],
													"stop_combination_id"  => $this->incoming_message_queue[$message_index]["stop_combination_id"],
													"argument_file_ticket" => $this->incoming_message_queue[$message_index]["argument_file_ticket"])));

			//Windows
			//$WshShell = new COM("WScript.Shell");
			//$WshShell->Run("php -f route_permutation.php \"{$exec_args}\"", 0, false);

			//Linux
			exec("php -f route_permutation.php \"{$exec_args}\" >/dev/null 2>/dev/null &");
			echo "\r\n";

			$this->incoming_message_queue[$message_index] = null;
		}

		private function Get_Average_Successful_Route_Distance($stop_combination_index)
		{
			$average_successful_route_distance = 0;
			$successful_route_distance_count = 0;

			for($i = 0; $i < count($this->running_stop_combinations[$stop_combination_index]["sockets"]); $i++)
			{
				if($this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["successful"])
				{
					$average_successful_route_distance += $this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["distance"];
					$successful_route_distance_count++;
				}
			}

			if($successful_route_distance_count > 0) $average_successful_route_distance /= $successful_route_distance_count;

			return $average_successful_route_distance;
		}

		private function Broadcast_Average_Successful_Route_Distance($stop_combination_index)
		{
			$average_successful_route_distance = $this->Get_Average_Successful_Route_Distance($stop_combination_index);

			for($i = 0; $i < count($this->running_stop_combinations[$stop_combination_index]["sockets"]); $i++)
			{
				if($this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["status"] == "Running")
				{
					$this->SS->Queue_Outgoing_Message($this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["socket_id"],
					                                  array("action"           => "Update_Average_Successful_Route_Distance",
					                                        "average_distance" => $average_successful_route_distance));
				}
			}
		}

		private function Process_Running_Stop_Combinations()
		{
			while(count($this->running_stop_combinations) >= $this->running_stop_combination_limit)
			{
				sleep(10);

				$this->SS->Process_Requests();
				$this->Add_New_Incoming_Messages_To_Queue($this->SS->Get_Pending_Messages());
				$this->Message_Queue_Handler();

				$array_changed = false;

				for($i = 0; $i < count($this->running_stop_combinations); $i++)
				{
					$stop_combination_complete = true;

					for($j = 0; $j < count($this->running_stop_combinations[$i]["sockets"]) && $stop_combination_complete; $j++)
						if($this->running_stop_combinations[$i]["sockets"][$j]["status"] != "Done") $stop_combination_complete = false;

					if($stop_combination_complete)
					{
						echo "*** Stop Combination {$i} is Complete.\r\n";

						for($j = (count($this->running_stop_combinations[$i]["sockets"]) - 1); $j > -1; $j--) unset($this->running_stop_combinations[$i]["sockets"][$j]);

						$this->running_stop_combinations[$i] = null;

						$array_changed = true;
					}
				}

				if($array_changed)
				{
					for($i = (count($this->running_stop_combinations) - 1); $i > -1; $i--)
						if(empty($this->running_stop_combinations[$i])) unset($this->running_stop_combinations[$i]);

					$this->running_stop_combinations = array_values($this->running_stop_combinations);
				}
			}
		}

		private function Add_New_Stop_Combination($stop_1, $stop_2, $mot_combination)
		{
			echo "*** Add_New_Stop_Combination:\r\n";
			$this->running_total++;

			$stop_1_position = new GPS_Position($stop_1["stop_latitude"], $stop_1["stop_longitude"]);
			$stop_2_position = new GPS_Position($stop_2["stop_latitude"], $stop_2["stop_longitude"]);
			$distance_from_origin_to_destination = $this->Get_Distance($stop_1_position, $stop_2_position);

			$allowed_routes = array();
			for($a = 0; $a < count($this->routes); $a++) $allowed_routes[] = $this->routes[$a]["route_id"];
			$allowed_routes = $this->Remove_Exceptions_From_Routes($allowed_routes, $stop_1["route_id"]);

			if($this->Stop_Combination_Exists($stop_1, $stop_2, $mot_combination))
			{
				$stop_combination_id 	= $this->Get_Stop_Combination_ID($stop_1, $stop_2, $mot_combination);
				$stop_combination_index = $this->Find_Stop_Combination_Index_From_ID($stop_combination_id);

				$this->running_stop_combinations[$stop_combination_index]["argument_file_ticket"]++;
				$this->running_stop_combinations[$stop_combination_index]["sockets"][] = array("socket_id"  => null,
				                                                                               "status"     => "Pending",
				                                                                               "level"      => 1,
				                                                                               "parent"     => $this->p_id,
				                                                                               "successful" => false);
			}
			else
			{
				$stop_combination_id = $this->Create_Stop_Combination_ID($stop_1, $stop_2, $mot_combination);

				$this->running_stop_combinations[] = array("stop_combination_id"   => $stop_combination_id,
				                                           "argument_file_ticket"  => 0,
				                                           "mot_combination"       => $mot_combination,
				                                           "origin_mot_id"         => $stop_1["mot_id"],
				                                           "origin_stop_id"        => $stop_1["stop_id"],
				                                           "origin_stop_name"      => $stop_1["stop_name"],
				                                           "destination_mot_id"    => $stop_2["mot_id"],
				                                           "destination_stop_id"   => $stop_2["stop_id"],
				                                           "destination_stop_name" => $stop_2["stop_name"],
				                                           "sockets"               => array(array("socket_id"  => null,
				                                                                                  "status"     => "Pending",
				                                                                                  "level"      => 1,
				                                                                                  "parent"     => $this->p_id,
				                                                                                  "successful" => false)));
				$stop_combination_index = count($this->running_stop_combinations) - 1;
			}

			// Create Route Permutation Argument File
			$process_args = serialize(array("route_permutation"   => array($stop_1),
			                                "allowed_routes"      => $allowed_routes,
			                                "route_distance"      => 0,
			                                "destination"         => $stop_2,
			                                "distance_from_origin_to_destination" => $distance_from_origin_to_destination));

			$argument_file = "{$this->dir}/{$stop_combination_id}_{$this->running_stop_combinations[$stop_combination_index]["argument_file_ticket"]}.args";

			$argument_file_ticket_stream = fopen($argument_file, "w") or die("Unable to open file!");
			fwrite($argument_file_ticket_stream, $process_args);
			fclose($argument_file_ticket_stream);

			//Create Route Permutation
			$exec_args = urlencode(serialize(array("level"                => 1,
			                                       "parent"               => $this->p_id,
			                                       "stop_combination_id"  => $stop_combination_id,
			                                       "argument_file_ticket" => $this->running_stop_combinations[$stop_combination_index]["argument_file_ticket"])));

			//*** Windows
			//$WshShell = new COM("WScript.Shell");
			//$WshShell->Run("php -f route_permutation.php \"{$exec_args}\"", 0, false);

			//*** Linux
			exec("php -f route_permutation.php \"{$exec_args}\" >/dev/null 2>/dev/null &");
			echo "\r\n";
	}

		private function Save_Progress_to_File()
		{
			$percent_completed = round((($this->running_total / $this->progress_total) * 100), 2);
			$output_str  = "\r\n\r\n";
			$output_str .= "    *************\r\n";
			$output_str .= "    ** Summary **\r\n";
			$output_str .= "    *************\r\n";
			$output_str .= "\r\n";
			$output_str .= "    Percent Completed: {$percent_completed}%\r\n";
			$output_str .= "    Master P_ID:       {$this->p_id}\r\n";

			for($i = 0; $i < count($this->running_stop_combinations); $i++)
			{
				$running_permutation_count = count($this->running_stop_combinations[$i]["sockets"]);

				$output_str .= "\r\n";
				$output_str .= "Stop Combination {$i}:\r\n";
				$output_str .= "=====================\r\n";
				$output_str .= "Combination ID:        {$this->running_stop_combinations[$i]["stop_combination_id"]}\r\n";
				$output_str .= "MOT Combination:       {$this->running_stop_combinations[$i]["mot_combination"]}\r\n";
				$output_str .= "Running Permutations:  {$running_permutation_count}\r\n";
				$output_str .= "\r\n";
				$output_str .= "Origin MOT ID:         {$this->running_stop_combinations[$i]["origin_mot_id"]}\r\n";
				$output_str .= "Origin Stop ID:        {$this->running_stop_combinations[$i]["origin_stop_id"]}\r\n";
				$output_str .= "Origin Stop Name:      {$this->running_stop_combinations[$i]["origin_stop_name"]}\r\n";
				$output_str .= "\r\n";
				$output_str .= "Destination MOT ID:    {$this->running_stop_combinations[$i]["destination_mot_id"]}\r\n";
				$output_str .= "Destination Stop ID:   {$this->running_stop_combinations[$i]["destination_stop_id"]}\r\n";
				$output_str .= "Destination Stop Name: {$this->running_stop_combinations[$i]["destination_stop_name"]}\r\n";
				$output_str .= "\r\n";

				echo $output_str;

				$progress_file_stream = fopen($this->progress_file, "w");
				fwrite($progress_file_stream, $output_str);
				fclose($progress_file_stream);

				$running_permutation_count = count($this->running_stop_combinations[$i]["sockets"]);

				for($j = 0; $j < $running_permutation_count; $j++)
				{
					if($this->running_stop_combinations[$i]["sockets"][$j]["level"] == 1)
					{
						$this->Print_Route_Permutation_Progress($j, $this->running_stop_combinations[$i]["sockets"][$j]);

						$this->Find_Child_Route_Permutations_To_Print($i, 1, $this->running_stop_combinations[$i]["sockets"][$j]["socket_id"]);
					}
				}
			}
		}

		private function Find_Child_Route_Permutations_To_Print($stop_combination_index, $level, $parent)
		{
			$current_level = $level + 1;

			for($i = 0; $i < count($this->running_stop_combinations[$stop_combination_index]["sockets"]); $i++)
			{
				if($parent == $this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["parent"])
				{
					$this->Print_Route_Permutation_Progress($i, $this->running_stop_combinations[$stop_combination_index]["sockets"][$i]);

					$this->Find_Child_Route_Permutations_To_Print($stop_combination_index,
					                                              $current_level,
					                                              $this->running_stop_combinations[$stop_combination_index]["sockets"][$i]["socket_id"]);
				}
			}
		}

		private function Print_Route_Permutation_Progress($index, $route_permutation_summary)
		{
			$tab  = "    ";
			$tabs = ""; for($t = 0; $t < $route_permutation_summary["level"]; $t++) $tabs .= $tab;

			$started_at = (new \DateTime())->modify('-24 hours');
			if(array_key_exists("started", $route_permutation_summary)) $started_at = new DateTime($route_permutation_summary["started"]);

			$running_for = $started_at->diff(new DateTime("now"));
			
			$ellipse 	 = ""; if($route_permutation_summary["status"] == "Pending") $ellipse = "...";

			$output_str  = "{$tabs}Route Permutation {$index}:\r\n";
			$output_str .= "{$tabs}---------------------\r\n";
			$output_str .= "{$tabs}Socket ID:            {$route_permutation_summary["socket_id"]}\r\n";
			$output_str .= "{$tabs}Parent ID:            {$route_permutation_summary["parent"]}\r\n";
			$output_str .= "{$tabs}Status:               {$route_permutation_summary["status"]}{$ellipse}\r\n";
			$output_str .= "{$tabs}Level:                {$route_permutation_summary["level"]}\r\n";

			if($route_permutation_summary["status"] == "Running")
			{
				if($running_for->format('%a') > 0) $hour1 = $running_for->format('%a') * 24; else $hour1 = 0;
				if($running_for->format('%h') > 0) $hour2 = $running_for->format('%h'); 	 else $hour2 = 0;

				$hours = $hour1 + $hour2;
				$stop_number = $route_permutation_summary["stop_being_investigated"];
				$of_stops = $route_permutation_summary["stops_to_investigate_count"];

				$output_str .= "{$tabs}Started At:           {$route_permutation_summary["started"]}\r\n";
				$output_str .= "{$tabs}Running For:          {$hours} Hours {$running_for->format("%i Minutes %s Seconds")}\r\n";
				$output_str .= "\r\n";
				$output_str .= "{$tabs}MOT ID:               {$route_permutation_summary["origin_mot_id"]}\r\n";
				$output_str .= "{$tabs}Route ID:             {$route_permutation_summary["route_id"]}\r\n";
				$output_str .= "{$tabs}Route Direction:      {$route_permutation_summary["route_direction"]}\r\n";
				$output_str .= "{$tabs}Stops To Investigate: {$route_permutation_summary["stops_to_investigate_count"]}\r\n";
				$output_str .= "\r\n";
				$output_str .= "{$tabs}Initial Stop ID:   {$route_permutation_summary["origin_stop_id"]}\r\n";
				$output_str .= "{$tabs}Initial Stop Name: {$route_permutation_summary["origin_stop_name"]}\r\n";
				$output_str .= "\r\n";
				$output_str .= "{$tabs}Stop Number:       {$stop_number} of {$of_stops}\r\n";
				$output_str .= "{$tabs}Current Stop ID:   {$route_permutation_summary["current_stop_id"]}\r\n";
				$output_str .= "{$tabs}Current Stop Name: {$route_permutation_summary["current_stop_name"]}\r\n";
			}

			if($route_permutation_summary["status"] == "Done")
			{
				if($route_permutation_summary["successful"])
					$output_str .= "{$tabs}Successful:           True\r\n";
				else
					$output_str .= "{$tabs}Successful:           False\r\n";
			}

			echo $output_str;
			echo "\r\n";

			$progress_file_stream = fopen($this->progress_file, "w") or die("Unable to open file!");
			fwrite($progress_file_stream, $output_str);
			fclose($progress_file_stream);
		}

		private function Get_Progress_Total()
		{
			/*
			 * 		Get_Progress_Total() calculates the total number of records that will be processed to provide a
			 * 		percentage of progress while the algorithm is running. 
			 */
		
			$mot_combination_count = count($this->mot_combinations);
			$total = 0;

			for($h = 0; $h < $mot_combination_count; $h++)
			{
				$mot_combination_string = "";

				if(count($this->mot_combinations[$h]) == 1)
				{
					$mot_combination_string = $this->mot_combinations[$h][0];
				}
				else
				{
					for($ii = 0; $ii < count($this->mot_combinations[$h]); $ii ++)
					{
						$mot_combination_string .= $this->mot_combinations[$h][$ii];

						if($ii != (count($this->mot_combinations[$h]) - 1)) $mot_combination_string .= ",";
					}
				}

				$routes = $this->DB->Get_Recordset("SELECT route_id FROM Routes WHERE mot_id IN ({$mot_combination_string})");
				
				for($i = 0; $i < 3 /* count($routes) */; $i++)
				{
					for($k = 0; $k < 2; $k++)
					{
						$total += $this->Count_Route_Details($routes[$i]["route_id"], $k);
					}
				}
			}

			return $total;
		}

		private function Get_Stop_Combination_String($h)
		{
			$mot_combination_string = "";

			if(count($this->mot_combinations[$h]) == 1)
			{
				$mot_combination_string = $this->mot_combinations[$h][0];
			}
			else
			{
				for($ii = 0; $ii < count($this->mot_combinations[$h]); $ii++)
				{
					$mot_combination_string .= $this->mot_combinations[$h][$ii];

					if($ii != (count($this->mot_combinations[$h]) - 1)) $mot_combination_string .= ",";
				}
			}

			return $mot_combination_string;
		}

		private function Set_MOT_Combinations()
		{
			/*
			 * 		Set_MOT_Combinations() gets a list of MOT ID's and generates all possible combinations without duplicates.
			 * 		i.e.	An array of MOT ID's 'array(1, 2, 3)' will generate the following MOT combinations:
			 *
			 * 		Array(	[0] => Array( [0] => 1 ),
			 * 				[1] => Array( [0] => 1, [1] => 2 ),
			 * 				[2] => Array( [0] => 1, [1] => 2, [2] => 3 ),
			 * 				[3] => Array( [0] => 1, [1] => 3 ),
			 * 				[4] => Array( [0] => 2 ),
			 * 				[5] => Array( [0] => 2, [1] => 3 ),
			 * 				[6] => Array( [0] => 3 )
			 * 			 )
			 */
			 
			$mot_array 		= array();
			$mot_array_raw 	= $this->DB->Get_Recordset("SELECT mot_id FROM MOT_Definitions ORDER BY mot_id ASC");

			for($i = 0; $i < count($mot_array_raw); $i++) $mot_array[] = $mot_array_raw[$i]["mot_id"];

			$mot_array = array(1, 2, 3);

			$mot_combinations = array();
			generate_mot_combinations($mot_array, $mot_combinations);
			$this->mot_combinations = remove_duplicate_mot_combinations(mot_combinations_to_arrays($mot_combinations));

			print_r($this->mot_combinations);
		}

		private function Get_Stop_Combination_ID($stop_1, $stop_2, $mot_combination)
		{
			$record = $this->DB->Get_Record("SELECT stop_combination_id FROM Stop_Combinations
											 WHERE stop_1_id 	   = {$stop_1["stop_id"]} AND
											       stop_1_mot_id   = {$stop_1["mot_id"]} AND
											       stop_2_id 	   = {$stop_2["stop_id"]} AND
											       stop_2_mot_id   = {$stop_2["mot_id"]} AND
												   mot_combination = '{$mot_combination}'");

			return $record["stop_combination_id"];
		}

		private function Create_Stop_Combination_ID($stop_1, $stop_2, $mot_combination)
		{
			$sql = "INSERT INTO Stop_Combinations (stop_1_id, stop_1_mot_id, stop_2_id, stop_2_mot_id, mot_combination)
								  VALUES ({$stop_1["stop_id"]}, {$stop_1["mot_id"]}, {$stop_2["stop_id"]}, {$stop_2["mot_id"]}, '{$mot_combination}')";

			$this->DB->Run_Query($sql);

			$stop_combination_id = $this->DB->Get_Insert_ID();

			return $stop_combination_id;
		}

		private function Stop_Combination_Exists($stop_1, $stop_2, $mot_combination)
		{
			$stop_combination_id = null;

			$record_count = $this->DB->Count("SELECT COUNT(*) FROM Stop_Combinations
											  WHERE stop_1_id 	    = {$stop_1["stop_id"]} AND
											  		stop_1_mot_id   = {$stop_1["mot_id"]} AND
											  		stop_2_id 	    = {$stop_2["stop_id"]} AND
											  		stop_2_mot_id   = {$stop_2["mot_id"]} AND
													mot_combination = '{$mot_combination}'");

			if($record_count == 0) return false; else return true;
		}

		private function Get_Route_Details($route_id, $route_direction)
		{
			$stop_table_name = null;
			$route_direction_table_name = null;

			$route_details = $this->DB->Get_Record("SELECT mot_id FROM Routes WHERE route_id = {$route_id}");

			switch($route_details["mot_id"])
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

			$sql = "SELECT 	Routes.mot_id,
							Route_Definitions.route_id,
							Route_Definitions.stop_id,
							{$stop_table_name}.stop_name,
							{$stop_table_name}.stop_latitude,
							{$stop_table_name}.stop_longitude,
							{$route_direction_table_name}.stop_order,
							{$route_direction_table_name}.route_direction
					FROM 	Routes
						JOIN	Route_Definitions
							ON	Route_Definitions.route_id = Routes.route_id
						JOIN	{$stop_table_name}
							ON	{$stop_table_name}.stop_id = Route_Definitions.stop_id
						LEFT JOIN {$route_direction_table_name}
							ON	{$route_direction_table_name}.route_definition_id = Route_Definitions.route_definition_id
					WHERE	Route_Definitions.route_id = {$route_id} AND
							{$route_direction_table_name}.route_direction = {$route_direction}
					ORDER BY	Routes.route_id,
								{$route_direction_table_name}.route_direction,
								{$route_direction_table_name}.stop_order";

			return $this->DB->Get_Recordset($sql);
		}

		private function Count_Route_Details($route_id, $route_direction)
		{
			$stop_table_name = null;
			$route_direction_table_name = null;

			$route_details = $this->DB->Get_Record("SELECT mot_id FROM Routes WHERE route_id = {$route_id}");

			switch($route_details["mot_id"])
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

			$sql = "SELECT 	COUNT(*)
					FROM 	Routes
						JOIN	Route_Definitions
							ON	Route_Definitions.route_id = Routes.route_id
						JOIN	{$stop_table_name}
							ON	{$stop_table_name}.stop_id = Route_Definitions.stop_id
						LEFT JOIN {$route_direction_table_name}
							ON	{$route_direction_table_name}.route_definition_id = Route_Definitions.route_definition_id
					WHERE	Route_Definitions.route_id = {$route_id} AND
							{$route_direction_table_name}.route_direction = {$route_direction}
					ORDER BY	Routes.route_id,
								{$route_direction_table_name}.route_direction,
								{$route_direction_table_name}.stop_order";

			return $this->DB->Count($sql);
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

		private function Remove_Exceptions_From_Routes($allowed_routes, $route_id_exception)
		{
			for($i = 0; $i < count($allowed_routes); $i++)
				if($allowed_routes[$i] == $route_id_exception)
					unset($allowed_routes[$i]);

			return array_values($allowed_routes);
		}

		private function Finalize()
		{
			echo "*** Finalize:\r\n";

			while(count($this->running_stop_combinations) > 0)
			{
				$this->Save_Progress_to_File();

				sleep(5);

				$this->SS->Process_Requests();
				$this->Add_New_Incoming_Messages_To_Queue($this->SS->Get_Pending_Messages());
				$this->Message_Queue_Handler();

				$array_changed = false;

				for($i = 0; $i < count($this->running_stop_combinations); $i++)
				{
					$stop_combination_complete = true;

					for($j = 0; $j < count($this->running_stop_combinations[$i]["sockets"]) && $stop_combination_complete; $j++)
						if($this->running_stop_combinations[$i]["sockets"][$j]["status"] != "Done") $stop_combination_complete = false;

					if($stop_combination_complete)
					{
						for($j = (count($this->running_stop_combinations[$i]["sockets"]) - 1); $j > -1; $j--) unset($this->running_stop_combinations[$i]["sockets"][$j]);

						$this->running_stop_combinations[$i] = null;

						$array_changed = true;
					}
				}

				if($array_changed)
				{
					for($i = (count($this->running_stop_combinations) - 1); $i > -1; $i--)
						if($this->running_stop_combinations[$i] == null) unset($this->running_stop_combinations[$i]);

					$this->running_stop_combinations = array_values($this->running_stop_combinations);
				}
			}
		}

		private function Kill()
		{
			unset($this->mot_combinations);
			unset($this->routes);
		}
	}

	$satan = 666;
	if($satan == 666)
	{
		$satan = 13;
		$RPS = new Route_Permutation_Stack();
	}
