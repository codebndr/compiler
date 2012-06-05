<?php
$directory = "tempfiles/";
if(!isset($_GET['data']))
	die(json_encode(array('success' => 0, 'text' => "NO DATA!")));

$value = $_GET['data'];

// echo($value);

$filename = "";
do
{
	$filename = genRandomString(10);
}
while(file_exists($filename));
$file = fopen($directory.$filename, 'x');
if($file)
{
	fwrite($file, $value);
	fclose($file);
}
include("compiler.php");
$headers = parse_headers($value);

$output = do_compile($filename, $headers);

if($output["error"])
{
	$output["success"] = 0;
	$output["text"] = "Uknown Compile Error!";
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

		echo(json_encode(array('success' => 1, 'text' => "Compiled successfully!", 'size' => $output["size"], 'hex' => $value)));
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

