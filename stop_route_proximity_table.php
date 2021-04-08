<?php
	header("Content-Type: text/plain");
	ini_set('max_execution_time', 0);
	ini_set('display_errors', 1);
	error_reporting(E_ALL ^ E_NOTICE);

	require_once("./includes/database.php");

	$DB = new Database();

	require_once("./includes/functions.php");

	$DB->Run_Query("DELETE FROM Proximity_Target_Stops");
	$DB->Run_Query("DELETE FROM Proximity_Stops_in_Range");
	$DB->Run_Query("ALTER TABLE Proximity_Target_Stops AUTO_INCREMENT = 1");
	$DB->Run_Query("ALTER TABLE Proximity_Stops_in_Range AUTO_INCREMENT = 1");

	$target_stops_added = 0;
	$target_stops_failed = 0;
	$in_range_stops_added = 0;
	$in_range_stops_failed = 0;
	$routes = $DB->Get_Recordset("SELECT * FROM Routes");
	//$routes = $DB->Get_Recordset("SELECT * FROM Routes WHERE route_id IN (4, 161, 155, 15, 16, 11, 28)");

	echo "All Routes: (" . count($routes) . ")\n";
	echo "===========\n";

	for($i = 0; $i < count($routes); $i++) echo "    " . $routes[$i]["route_id"] . "\n";
	echo "\n";

	$routes_count = count($routes);

	for($i = 0; $i < $routes_count; $i++)
	{
		for($k = 0; $k < 2; $k++)
		{
			$route = get_route_details($routes[$i]["route_id"], $k);
			$route_count = count($route);

			echo "        Current Route: (" . $i . ")\n";
			echo "        ==============" . "\n";
			echo "            route_id:        " . $routes[$i]["route_id"] . "\n";
			echo "            mot_id:          " . $routes[$i]["mot_id"] . "\n";
			echo "            route_direction: " . $k . "\n";
			echo "\n";

			for($j = 0;  $j < $route_count; $j++)
			{
				echo "            Current Stop:" . "\n";
				echo "            =============" . "\n";
				echo "                Stop ID:         {$route[$j]["stop_id"]}\n";
				echo "                Stop Name:       {$route[$j]["stop_name"]}\n";
				echo "                Stop Index:      {$route[$j]["stop_order"]}\n";
				echo "                MOT ID:          {$route[$j]["mot_id"]}\n";
				echo "                Route ID:        {$route[$j]["route_id"]}\n";
				echo "                Route Direction: {$route[$j]["route_direction"]}\n";
				echo "\n";

				//======== Compare List of all route/stops above to all stops below.

				$stops_in_proximity = array();

				for($m = 0; $m < $routes_count; $m++)
				{
					echo "                Route {$i} of {$routes_count}, Direction {$k}, Stop {$j} of {$route_count} ... Comparing Stops on Route {$m} of {$routes_count}\r\n";

					for($o = 0; $o < 2; $o++)
					{
						$route_to_test_for_proximity = get_route_details($routes[$m]["route_id"], $o);

						for($n = 0; $n < count($route_to_test_for_proximity); $n++)
						{
							$distance_between_stops = get_distance(new GPS_Position($route[$j]["stop_latitude"],
							                                                        $route[$j]["stop_longitude"]),
							                                       new GPS_Position($route_to_test_for_proximity[$n]["stop_latitude"],
							                                                        $route_to_test_for_proximity[$n]["stop_longitude"]));

							if($distance_between_stops <= MAX_WALKING_DIST)
							{
								$stop_not_entered = true;

								for($x = 0; $x < count($stops_in_proximity); $x++)
								{
									if($stops_in_proximity[$x]["route_id"] == $route_to_test_for_proximity[$n]["route_id"] &&
									   $stops_in_proximity[$x]["route_direction"] == $route_to_test_for_proximity[$n]["route_direction"])
									{
										$current_shortest_distance = get_distance(new GPS_Position($stops_in_proximity[$x]["stop_latitude"],
										                                                           $stops_in_proximity[$x]["stop_longitude"]),
										                                          new GPS_Position($route_to_test_for_proximity[$n]["stop_latitude"],
										                                                           $route_to_test_for_proximity[$n]["stop_longitude"]));

										if($distance_between_stops < $current_shortest_distance) $stops_in_proximity[$x] = $route_to_test_for_proximity[$n];

										$stop_not_entered = false;
									}
								}

								if($stop_not_entered && $route[$j]["route_id"] != $route_to_test_for_proximity[$n]["route_id"])
									$stops_in_proximity[] = $route_to_test_for_proximity[$n];
							}
						}
					}
				}

				if(count($stops_in_proximity))
				{
					$sql = "INSERT INTO Proximity_Target_Stops (stop_id, route_id, route_direction)
							VALUES ({$route[$j]["stop_id"]},
									{$route[$j]["route_id"]},
									{$route[$j]["route_direction"]})";

					if($DB->Run_Query($sql)) $target_stops_added++; else $target_stops_failed++;

					$proximity_target_stop_id = $DB->Get_Insert_ID();

					for($x = 0; $x < count($stops_in_proximity); $x++)
					{
						echo "                Stop in Proximity:" . "\n";
						echo "                ==================" . "\n";
						echo "                    Stop ID:         {$stops_in_proximity[$x]["stop_id"]}\n";
						echo "                    Stop Name:       {$stops_in_proximity[$x]["stop_name"]}\n";
						echo "                    Stop Index:      {$stops_in_proximity[$x]["stop_order"]}\n";
						echo "                    MOT ID:          {$stops_in_proximity[$x]["mot_id"]}\n";
						echo "                    Route ID:        {$stops_in_proximity[$x]["route_id"]}\n";
						echo "                    Route Direction: {$stops_in_proximity[$x]["route_direction"]}\n";

						$sql = "INSERT INTO Proximity_Stops_in_Range (proximity_target_stop_id, stop_id, route_id, route_direction)
						VALUES ({$proximity_target_stop_id},
								{$stops_in_proximity[$x]["stop_id"]},
								{$stops_in_proximity[$x]["route_id"]},
								{$stops_in_proximity[$x]["route_direction"]})";

						if($DB->Run_Query($sql)) $in_range_stops_added++; else $in_range_stops_failed++;
					}

					echo "\n";
				}
			}
		}
	}

	$DB->Kill();

	echo "\n";
	echo "SUMMARY:\n";
	echo "========\n";
	echo "    Target Routes Added:   {$target_stops_added}\n";
	echo "    Target Failures:       {$target_stops_failed}\n";
	echo "    In Range Routes Added: {$in_range_stops_added}\n";
	echo "    In Range Failures:     {$in_range_stops_failed}\n";

	for($i = 0; $i < 10; $i++) echo "======================================================================\n";
