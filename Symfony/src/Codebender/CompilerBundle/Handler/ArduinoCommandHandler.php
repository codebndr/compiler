<?php
/**
\file
\brief Make compiler calls to the Arduino 1.6 Command Line interface.

\author Ethan Green

\copyright (c) 2015, The Codebender Development Team
\copyright Licensed under the Simplified BSD License
 */

namespace Codebender\CompilerBundle\Handler;

use System;

class ArduinoCommandHandler
{

    function main($request, $params)
    {

	$reply = $request;

	//$eFile = extractFiles($request);

	//$reply = fopen($eFile);

        return array("status" => "success", "returnfile" => $reply["test"]);

    }

    private function extractFiles($request, $params)
    {
	// Extract the file from the array and save to /tmp
	// Return the file path


    }
}
