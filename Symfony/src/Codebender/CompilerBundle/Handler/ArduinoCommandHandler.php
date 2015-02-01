<?php
/**
\file
\brief Make compiler calls to the Arduino 1.6 Command Line interface.

\author Ethan Green

\copyright (c) 2015, The Codebender Development Team
\copyright Licensed under the Simplified BSD License
 */

namespace Codebender\CompilerBundle\Handler;

class ArduinoCommandHandler
{

    function main($request, $params)
    {

        return array("request" => $request, "params" => $params);

    }
}