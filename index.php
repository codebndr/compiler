<?php

/**
\file
\brief Entry point for the compiler.

Passes the POST request body to the main() function and outputs its reply.

\author Dimitrios Christidis
\author Vasilis Georgitzikis

\copyright (c) 2012, The Codebender Development Team
\copyright Licensed under the Simplified BSD License
*/

//TODO: We might wanna change that with the rearchitecture
header("Access-Control-Allow-Origin: *");

require_once "compiler.php";

$request = file_get_contents("php://input");
$reply = main($request);
echo json_encode($reply);

?>
