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

	//Return output in reply
	$reply = $tempfiles['files'];
	$handle = fopen($tempfiles['files'],"r+");
	fclose($handle);
	unlink($tempfiles['files']);
        return array("success" => "false","step" =>"0", "message" => $reply);

    }

    private function extractFiles($request)
    {
	// Extract the file from the array and save to /tmp
	// Return the file path

	$filename = $request['files'];
	

	$tempfile = tempnam(sys_get_temp_dir(),$filename);
	$tempfiles = array("files" => $tempfile);
	return $tempfiles;
    }
}







