<?php

include("config.php");
$time = microtime(TRUE);

$directory = "tempfiles/";
if(!isset($_REQUEST['data']))
	die(json_encode(array('success' => 0, 'text' => "NO DATA!")));

$value = $_REQUEST['data'];

// echo($value);

$filename = "";
do
{
	$filename = genRandomString(10);
}
while(file_exists($directory.$filename));
$file = fopen($directory.$filename, 'x');
if($file)
{
	fwrite($file, $value);
	fclose($file);
}
include("compiler.php");
// echo microtime(TRUE)."<br />\n";
$headers = parse_headers($value);


$tempheaders = $headers;
// $output = add_libraries(getenv("ARDUINO_LIBS_DIR"), $tempheaders);
// if(!$output["success"])
// die(json_encode($output));
// $LIBBSOURCES = $output["output"];
$LIBBSOURCES = add_libraries(getenv("ARDUINO_LIBS_DIR"), $tempheaders);

// $output = add_libraries(getenv("ARDUINO_EXTRA_LIBS_DIR"), $tempheaders);
// if(!$output["success"])
// die(json_encode($output));

// $LIBBSOURCES .= $output["output"];
$LIBBSOURCES .= add_libraries(getenv("ARDUINO_EXTRA_LIBS_DIR"), $tempheaders);

$tempheaders = $headers;
$LIBB = add_paths(getenv("ARDUINO_LIBS_DIR"), $tempheaders);
$LIBB .= add_paths(getenv("ARDUINO_EXTRA_LIBS_DIR"), $tempheaders);

$output = do_compile($filename, $LIBBSOURCES, $LIBB);

if($output["error"])
{
	$output["success"] = 0;
	$output["text"] = "Uknown Compile Error!";
	$output["lines"] = array(0);
	echo(json_encode($output));
}
else
{
	if($output["compiler_success"])
	{
		$file = fopen($directory.$filename.".hex", 'r');
		$value = fread($file, filesize($directory.$filename.".hex"));
		fclose($file);
		unlink($directory.$filename.".hex");

		echo(json_encode(array('success' => 1, 'text' => "Compiled successfully!", 'size' => $output["size"], 'time'=> microtime(TRUE)-$time, 'hex' => $value)));
	}
	else
	{
		config_output($output["compiler_output"], $filename, $lines, $output_string);
		$output_string = htmlspecialchars($output_string);
		echo(json_encode(array('success' => 0, 'text' => $output_string, 'lines' => $lines)));
	}
}

function genRandomString($length)
{
    // $length = 10;
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $string = "";    
    for ($p = 0; $p < $length; $p++)
	{
        $string .= $characters{mt_rand(0, strlen($characters)-1)};
    }
    return $string;
}

?>

