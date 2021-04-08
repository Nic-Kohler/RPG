<?php

	define("MAIL_HOST", "mail.blueskies03.dedicated.co.za");
	define("MAIL_PORT", "25");
	define("MAIL_AUTH", true);
	define("MAIL_USER", "info@blueskies03.dedicated.co.za");
	define("MAIL_PASS", "/CMBH@6~fa:dqLxp");
	define("MAIL_SYSADDR", "info@blueskies03.dedicated.co.za");

	define("EMAIL_FROM_ADDRESS", "info@blueskies03.dedicated.co.za");
	define("EMAIL_FROM_NAME", "RushIT");
	define("SMTP_HOST", "mail.blueskies03.dedicated.co.za");
	define("SMTP_USERNAME", "info@blueskies03.dedicated.co.za");
	define("SMTP_PASSWORD", "/CMBH@6~fa:dqLxp");
	define("SMTP_PORT", 25);

	define("MAX_WALKING_DIST", 835);

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

	function encrypt($pure_string, $encryption_key)
	{
		$iv_size = mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
		$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
		$encrypted_string = mcrypt_encrypt(MCRYPT_BLOWFISH, $encryption_key, utf8_encode($pure_string), MCRYPT_MODE_ECB, $iv);

		return base64_encode($encrypted_string);
	}

	function decrypt($encrypted_string, $encryption_key)
	{
		$decoded_base64_str = base64_decode($encrypted_string);
		$iv_size = mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
		$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
		$decrypted_string = mcrypt_decrypt(MCRYPT_BLOWFISH, $encryption_key, $decoded_base64_str, MCRYPT_MODE_ECB, $iv);

		return $decrypted_string;
	}

	function generateRandomString($length)
	{
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ=';
		$randomString = '';

		for($i = 0; $i < $length; $i++) $randomString .= $characters[rand(0, strlen($characters) - 1)];

		return $randomString;
	}

	function format_route_permutations_array($routes, GPS_Position $origin, GPS_Position $destination)
	{
		$restructured_routes_array = array();

		for($i = 0; $i < count($routes); $i++)
		{
			$restructured_route_array = array();
			$restructured_route_leg_array = array();

			$restructured_route_array[] = array(array("stop_name"      => "Route Origin",
			                                          "route_id"       => 0,
			                                          "mot_id"         => 0,
			                                          "stop_latitude"  => $origin->Get_Latitude(),
			                                          "stop_longitude" => $origin->Get_Longitude()),
			                                    array("stop_name"      => $routes[$i][0]["stop_name"] . get_stop_type($routes[$i][0]["mot_id"]),
			                                          "route_id"       => 0,
			                                          "mot_id"         => 0,
			                                          "stop_latitude"  => $routes[$i][0]["stop_latitude"],
			                                          "stop_longitude" => $routes[$i][0]["stop_longitude"]));

			$current_route_id = $routes[$i][0]["route_id"];

			for($j = 0; $j < count($routes[$i]); $j ++)
			{
				if($current_route_id != $routes[$i][$j]["route_id"])
				{
					$restructured_route_array[] = $restructured_route_leg_array;

					$restructured_route_array[] = array(array("stop_name"      => $routes[$i][$j - 1]["stop_name"] . get_stop_type($routes[$i][$j - 1]["mot_id"]),
					                                          "route_id"       => 0,
					                                          "mot_id"         => 0,
					                                          "stop_latitude"  => $routes[$i][$j - 1]["stop_latitude"],
					                                          "stop_longitude" => $routes[$i][$j - 1]["stop_longitude"]),
					                                    array("stop_name"      => $routes[$i][$j]["stop_name"] . get_stop_type($routes[$i][$j]["mot_id"]),
					                                          "route_id"       => 0,
					                                          "mot_id"         => 0,
					                                          "stop_latitude"  => $routes[$i][$j]["stop_latitude"],
					                                          "stop_longitude" => $routes[$i][$j]["stop_longitude"]));

					$restructured_route_leg_array = array();
					$current_route_id = $routes[$i][$j]["route_id"];
				}

				$restructured_route_leg_array[] = array("stop_name"      => $routes[$i][$j]["stop_name"] . get_stop_type($routes[$i][$j]["mot_id"]),
				                                        "route_id"       => $routes[$i][$j]["route_id"],
				                                        "mot_id"         => $routes[$i][$j]["mot_id"],
				                                        "stop_latitude"  => $routes[$i][$j]["stop_latitude"],
				                                        "stop_longitude" => $routes[$i][$j]["stop_longitude"]);
			}

			$restructured_route_array[] = $restructured_route_leg_array;

			$restructured_route_array[] = array(array("stop_name"      => $routes[$i][$j - 1]["stop_name"] . get_stop_type($routes[$i][$j - 1]["mot_id"]),
			                                          "route_id"       => 0,
			                                          "mot_id"         => 0,
			                                          "stop_latitude"  => $routes[$i][$j - 1]["stop_latitude"],
			                                          "stop_longitude" => $routes[$i][$j - 1]["stop_longitude"]),
			                                    array("stop_name"      => "Route Destination",
			                                          "route_id"       => 0,
			                                          "mot_id"         => 0,
			                                          "stop_latitude"  => $destination->Get_Latitude(),
			                                          "stop_longitude" => $destination->Get_Longitude()));

			$restructured_routes_array[] = $restructured_route_array;
		}

		return $restructured_routes_array;
	}

	function calculate_route_leg_distances($routes)
	{
		for($i = 0; $i < count($routes); $i++)
		{
			for($j = 0; $j < count($routes[$i]); $j++)
			{
				$distance = 0;

				for($k = 0; $k < count($routes[$i][$j]) - 1; $k++)
				{
					$distance += get_distance(new GPS_Position($routes[$i][$j][$k]["stop_latitude"], $routes[$i][$j][$k]["stop_longitude"]),
					                          new GPS_Position($routes[$i][$j][$k + 1]["stop_latitude"], $routes[$i][$j][$k + 1]["stop_longitude"]));
				}

				$routes[$i][$j] = array("distance" => $distance, "route_segment_array" => $routes[$i][$j]);
			}
		}

		return $routes;
	}

	function get_distance(GPS_Position $origin, GPS_Position $destination)
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

	function get_stop_type($mot_id)
	{
		$stop_type = null;

		switch($mot_id)
		{
			case 1: $stop_type = " Bus Stop";    break;
			case 2: $stop_type = " Station";     break;
			case 3: $stop_type = " Bus Stop";    break;
		}

		return $stop_type;
	}

	function get_mot_name($mot_id)
	{
		global $DB;

		$mot_type = $DB->Get_Record("SELECT mot_name FROM MOT_Definitions WHERE mot_id = {$mot_id}");

		return $mot_type["mot_name"];
	}

	function get_mot_id($mot_id)
	{
		global $DB;

		$mot_type = $DB->Get_Record("SELECT mot_id FROM MOT_Definitions WHERE mot_id = {$mot_id}");

		return $mot_type["mot_id"];
	}

	function get_route_details($route_id, $route_direction)
	{
		global $DB;

		$stop_table_name = null;
		$route_direction_table_name = null;

		$route_details = $DB->Get_Record("SELECT * FROM Routes WHERE route_id = {$route_id}");

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

		return $DB->Get_Recordset($sql);
	}

	function generate_mot_combinations(&$set, &$results)
	{
		for($i = 0; $i < count($set); $i++)
		{
			$results[] = $set[$i];
			$temp_set = $set;

			array_splice($temp_set, $i, 1);
			$temp_results = array();

			generate_mot_combinations($temp_set, $temp_results);

			foreach($temp_results as $res)
			{
				$results[] = $set[$i] . $res;
			}
		}
	}

	function mot_combinations_to_arrays($results)
	{
		for($i = 0; $i < count($results); $i++) $results[$i] = str_split($results[$i]);

		return $results;
	}

	function remove_duplicate_mot_combinations($results)
	{
		for($i = (count($results) - 1); $i > -1 ; $i--)
		{
			$match_not_found = true;

			for($j = (count($results) - 1); $j > -1  && $match_not_found; $j--)
			{
				$element_match_count = 0;

				for($k = 0; $k < count($results[$i]); $k++)
					for($l = 0; $l < count($results[$j]); $l++)
						if($results[$i][$k] == $results[$j][$l] && $i != $j && count($results[$i]) == count($results[$j]))
							$element_match_count++;

				if($element_match_count == count($results[$i])) $match_not_found = false;
			}

			if(!$match_not_found) array_splice($results, $i, 1);
		}

		return $results;
	}


