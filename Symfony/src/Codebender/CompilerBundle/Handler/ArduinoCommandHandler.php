<?php
/**
\file
\brief Make compiler calls to the Arduino 1.6 Command Line interface.

\author Ethan Green

\copyright (c) 2015, The Codebender Development Team
\copyright Licensed under the Simplified BSD License
 */

namespace Codebender\CompilerBundle\Handler;


require_once("System.php");
use System;

class ArduinoCommandHandler
{

    function main($request)
    {
	//Todo: Test Input Validiy

	//Todo: Extract files from request
	$tempfiles = $this->extractFiles($request);

	
	$reply = $request;	
        return array("success" => "false","step" =>"0", "message" => $reply);

    }

    private function extractFiles($request)
    {
	// Extract the file from the array and save to /tmp
	// Return the file path

	$tempfiles = array();

	foreach ($request['files'] as $key => $val) {

	if ($key == 'filename') {
		$filename = tempnam(sys_get_temp_dir(),$val);
		array_push($tempfiles,$filename);
	} elseif ($key == 'content') {

		$tempfile = end($files);
		$handle = fopen($tempfile,"r+");
		fwrite($handle,$val);
		fclose($handle);
	}
	}
	return $tempfiles;
    }
}







