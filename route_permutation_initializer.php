<?php
	ini_set('display_errors', 1);
	error_reporting(E_ALL ^ E_NOTICE);

	define("MAX_WALKING_DIST", 835);

	require_once("./includes/database.php");

	class Route_Permutation_Initializer
	{
		private $DB;

		private $user_id;
		private $origin;
		private $destination;
		private $mot_array;
		private $stops_in_range_of_destination;
		private $distance_from_origin_to_destination;

		function __construct($user_id, GPS_Position $origin, GPS_Position $destination, $mot_array)
		{
			$this->DB = new Database();

			if($this->DB->Count("SELECT COUNT(*) FROM User_Route_Thread_Counters WHERE user_id = {$user_id}") == 0)
			{
				// External Parameters:

				$this->user_id = $user_id;
				$this->origin = $origin;
				$this->destination = $destination;
				$this->mot_array = $mot_array;


				// Acquired Values:

				$this->distance_from_origin_to_destination = $this->Get_Distance($this->origin, $this->destination);
				$routes_in_range_of_center_point           = $this->Find_Routes_in_Range_of_Center_Point();
				$this->stops_in_range_of_destination       = $this->Find_Stops_in_Range_of_Target($this->destination, $routes_in_range_of_center_point);
				$route_permutations_init                   = $this->Find_Stops_in_Range_of_Target($this->origin, $routes_in_range_of_center_point);

				$route_permutation_count = count($route_permutations_init);

				$allowed_routes = $routes_in_range_of_center_point;
				for($i = 0; $i < $route_permutation_count; $i++)
					$allowed_routes = $this->Remove_Exceptions_From_Routes($allowed_routes, $route_permutations_init[$i]["route_id"]);


				//Main Process:

				$this->DB->Run_Query("INSERT INTO User_Route_Thread_Counters (user_id, thread_count, completed_thread_count)
									  VALUES ({$this->user_id}, {$route_permutation_count}, 0)");

				for($i = 0; $i < $route_permutation_count; $i ++)
				{
					$args = urlencode(gzcompress(serialize(array("user_id"                             => $this->user_id,
					                                             "route_permutation"                   => array($route_permutations_init[$i]),
					                                             "allowed_routes"                      => $allowed_routes,
					                                             "route_distance"                      => 0,
					                                             "distance_from_origin_to_destination" => $this->distance_from_origin_to_destination,
					                                             "stops_in_range_of_destination"       => $this->stops_in_range_of_destination)),
					                             9));

					exec("php -f route_permutation.php \"{$args}\" > /dev/null &");
				}
			}

			$this->Kill();
		}

		private function Get_Distance(GPS_Position $origin, GPS_Position $destination)
		{
			$origin_latitude 		= $origin->Get_Latitude();
			$origin_longitude 		= $origin->Get_Longitude();
			$destination_latitude 	= $destination->Get_Latitude();
			$destination_longitude 	= $destination->Get_Longitude();

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

		private function Find_Routes_in_Range_of_Center_Point()
		{
			$routes_in_range = array();
			$center_point = new GPS_Position(($this->origin->Get_Latitude() + $this->destination->Get_Latitude()) / 2,
			                                 ($this->origin->Get_Longitude() + $this->destination->Get_Latitude()) / 2);
			$radius = $this->Get_Distance($center_point, $this->origin);
			$radius += 2000;

			$sql = "SELECT route_id FROM Routes WHERE mot_id = {$this->mot_array[0]}";
			for($i = 1; $i < count($this->mot_array); $i++) $sql .= " OR mot_id = {$this->mot_array[$i]}";

			$routes = $this->DB->Get_Recordset($sql);

			for($i = 0; $i < count($routes); $i++)
			{
				$route_is_not_in_range = true;

				for($k = 0; $k < 2 && $route_is_not_in_range; $k++)
				{
					$route = $this->Get_Route_Details($routes[$i]["route_id"], $k);

					for($j = 0; $j < count($route) && $route_is_not_in_range; $j++)
					{
						$distance_from_center_point = $this->Get_Distance($center_point, new GPS_Position($route[$j]["stop_latitude"], $route[$j]["stop_longitude"]));

						if($distance_from_center_point < $radius) $route_is_not_in_range = false;
					}
				}

				if(!$route_is_not_in_range) $routes_in_range[] = $routes[$i]["route_id"];
			}

			unset($routes);
			unset($center_point);

			return $routes_in_range;
		}

		private function Find_Stops_in_Range_of_Target(GPS_Position $target, $routes)
		{
			$routes_in_range_of_target = array();
			$direction_info = null;
			$shortest_distance_from_target = 9999999;

			for($i = 0; $i < count($routes); $i++)
			{
				for($k = 0; $k < 2; $k++)
				{
					$route = $this->Get_Route_Details($routes[$i], $k);

					for($j = 0; $j < count($route); $j++)
					{
						$distance_from_target = $this->Get_Distance($target, new GPS_Position($route[$j]["stop_latitude"], $route[$j]["stop_longitude"]));

						if($distance_from_target < $shortest_distance_from_target) $shortest_distance_from_target = $distance_from_target;

						if($distance_from_target <= MAX_WALKING_DIST)
						{
							$route_not_found = true;

							for($l = 0; $l < count($routes_in_range_of_target); $l++)
							{
								if($route[$j]["route_id"] == $routes_in_range_of_target[$l]["route_id"] &&
								   $route[$j]["route_direction"] == $routes_in_range_of_target[$l]["route_direction"])
								{
									$route_not_found = false;

									$current_distance = $this->Get_Distance($target, new GPS_Position($routes_in_range_of_target[$l]["stop_latitude"],
									                                                                  $routes_in_range_of_target[$l]["stop_longitude"]));

									if($distance_from_target < $current_distance) $routes_in_range_of_target[$l] = $route[$j];
								}
							}

							if($route_not_found) $routes_in_range_of_target[] = $route[$j];
						}
					}
				}
			}

			if(count($routes_in_range_of_target) == 0)
			{
				for($i = 0; $i < count($routes); $i++)
				{
					for($k = 0; $k < 2; $k++)
					{
						$route = $this->Get_Route_Details($routes[$i], $k);

						for($j = 0; $j < count($route); $j++)
						{
							$distance_from_target = $this->Get_Distance($target, new GPS_Position($route[$j]["stop_latitude"], $route[$j]["stop_longitude"]));

							if($distance_from_target <= ($shortest_distance_from_target + MAX_WALKING_DIST))
							{
								$route_not_found = true;

								for($l = 0; $l < count($routes_in_range_of_target); $l++)
								{
									if($route[$j]["route_id"] == $routes_in_range_of_target[$l]["route_id"] &&
									   $route[$j]["route_direction"] == $routes_in_range_of_target[$l]["route_direction"])
									{
										$route_not_found = false;

										$current_distance = $this->Get_Distance($target, new GPS_Position($routes_in_range_of_target[$l]["stop_latitude"],
										                                                                  $routes_in_range_of_target[$l]["stop_longitude"]));

										if($distance_from_target < $current_distance) $routes_in_range_of_target[$l] = $route[$j];
									}
								}

								if($route_not_found) $routes_in_range_of_target[] = $route[$j];
							}
						}
					}
				}
			}

			return $routes_in_range_of_target;
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

		private function Remove_Exceptions_From_Routes($allowed_routes, $route_id_exception)
		{
			for($i = 0; $i < count($allowed_routes); $i++)
				if($allowed_routes[$i] == $route_id_exception)
					unset($allowed_routes[$i]);

			return array_values($allowed_routes);
		}

		private function Kill()
		{
			$this->DB->Kill();

			unset($this->user_id);
			unset($this->origin);
			unset($this->destination);
			unset($this->mot_array);
			unset($this->stops_in_range_of_destination);
			unset($this->distance_from_origin_to_destination);
		}
	}









