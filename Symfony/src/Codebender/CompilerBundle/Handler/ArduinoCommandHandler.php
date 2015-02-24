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
	$eFile = $this->extractFiles($request);

	//Return output in reply
	$reply = $eFile;

        return array("status" => "success", "returnfile" => $reply);

    }

    private function extractFiles()
    {
	// Extract the file from the array and save to /tmp
	// Return the file path
	
	//$temp_dir = sys_get_temp_dir();
	//$filename = "temp1";
	//$tempfile = tempnam($temp_dir, $filename);

	$tempfile = "Filename";
	return $tempfile;
    }
}
