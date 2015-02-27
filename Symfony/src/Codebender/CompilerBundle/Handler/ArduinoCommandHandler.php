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

	//Todo: Escape output and harden security

	$tempfiles = $this->extractFiles($request);	

	$this->deleteFiles($tempfiles);
        return array("success" => "true","step" =>"0", "message" => $tempfiles);

    }

    private function extractFiles($request)
    {
	// Extract the file from the array and save to /tmp
	// Return the file path

	$tempfiles = array();

	foreach ($request['files'] as $file => $contents) {
	    foreach ($contents as $key => $val) {

    	    if ($key == 'filename') {
		    $extension = pathinfo($key,PATHINFO_EXTENSION);
		    $filename = tempnam(sys_get_temp_dir(),$val);
		    $extfilename = $filename . '.' . $extension;
		    if(!rename($filename,$extfilename)) {
			return false;
			}
	            array_push($tempfiles,$newfilename);
		} elseif ($key == 'content') {

		    $tempfile = end($files);
		    $handle = fopen($tempfile,"r+");
		    fwrite($handle,$val);
		    fclose($handle);
		}
	}
	return $tempfiles;
    }

    private function deleteFiles($fileList) {
	foreach ($filelist as $file) {
	    unlink($file);	
	}

    }
}







