<?php

	/*
	 * 		MOT = Modes of Transport
	 */
	
	$cmd 		= "php -f route_permutation_generator.php";
	$rpg_dir  	= "/home/pi/WebSpace/Route_Generator/RPG_Files";
	$debug_file = "rpg_debug.log";

	// Remove existing files
	if(!is_dir($rpg_dir)) mkdir($rpg_dir);
	else
	{
		$files = glob("{$rpg_dir}/*.*");
		foreach($files as $file) if(is_file($file)) unlink($file);
	}

	exec(sprintf("%s > %s 2>&1", $cmd, $rpg_dir . "/" . $debug_file));
