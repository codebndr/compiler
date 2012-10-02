<?php

function dothat($filename, $cmd)
{
	exec($cmd, $out, $ret); 
	$return_val = false;
	if($ret)
	{
		cleanDir($filename);
		$return_val = true;
	}
	return array("error" => $return_val, "cmd" => $cmd, "output" => $out);
}

function doit($cmd, &$out, &$ret)
{
	 
}


function config_output($output, $filename,  &$lines, &$output_string)
{
	$output_string = "";
	$lines = array();
	foreach($output as $i)
	{
		$fat1 = "build/".$filename.":";
		$fat2 = "build/core/";
		$i = str_replace($fat1, "", $i);
		$i = str_replace($fat2, "", $i);
		
		$i = str_replace("tempfiles/".$filename.":", "", $i)."\n";
		// $i = $i."\n<br />";
		$output_string .= $i;
		$colon = strpos($i, ":");
		$number = intval(substr($i, 0, $colon));
		$j = 0;
		for($j = 0; $j < $colon ; $j++)
		{
			if(!(strpos("1234567890", $i{$j}) === FALSE))
				break;
		}
		if(!($colon === FALSE) && $j < $colon)
		{
			$lines[] = $number;
		}
		
	}
}
function do_compile($filename,  $LIBBSOURCES, $LIBB)
{
	$path = "tempfiles/";
	$BUILD_PATH = "build/";
	$SOURCES_PATH = $BUILD_PATH."core/";
	$CLANG_INCL_PATH = "clang/include";
	
	$filename = $path.$filename;

	// General flags. Theese are common for all projects. Should be moved to a higher-level configuration.
	// Got these from original SConstruct. Get a monkey to check them?
	$CPPFLAGS = "-ffunction-sections -fdata-sections -fno-exceptions -funsigned-char -funsigned-bitfields -fpack-struct -fshort-enums -Os";
	$LDFLAGS = "-Os -Wl,--gc-sections";

	// die($LIBB);

	// This is temporary too :(
	$CPPFLAGS .= " -I".$BUILD_PATH."variants/standard";

	// Append project-specific stuff.
	$CPPFLAGS .= " -mmcu=atmega328p -DARDUINO=100 -DF_CPU=16000000L";
	$LDFLAGS .= " -mmcu=atmega328p";

	// Where to places these? How to compile them?
	$SOURCES = $SOURCES_PATH."wiring_shift.o ".$SOURCES_PATH."wiring_pulse.o ".$SOURCES_PATH."wiring_digital.o ".$SOURCES_PATH."wiring_analog.o ".$SOURCES_PATH."WInterrupts.o ".$SOURCES_PATH."wiring.o ".$SOURCES_PATH."Tone.o ".$SOURCES_PATH."WMath.o ".$SOURCES_PATH."HardwareSerial.o ".$SOURCES_PATH."Print.o ".$SOURCES_PATH."WString.o ".$SOURCES_PATH."IPAddress.o ".$SOURCES_PATH."Stream.o";

	$CLANG_FLAGS = "-fsyntax-only -Os -I".$CLANG_INCL_PATH." -I".$BUILD_PATH."variants/standard -I".$SOURCES_PATH." -D__AVR_ATmega328P__ -DARDUINO=100 -DF_CPU=16000000L -Wno-unknown-attributes -Wno-attributes -Wno-missing-declarations -Wno-deprecated-writable-strings -fcolor-diagnostics";
	
	// Handle object files from libraries. Different CFLAGS? HELP!
	// Different error code, depending where it failed?

	$skel_fh = fopen("build/core/main.cpp", "r");
	$skel_contents = fread($skel_fh, filesize("build/core/main.cpp"));
	$input_fh = fopen($filename, "r");
	$input_contents = fread($input_fh, filesize($filename));
	$output_fh = fopen($filename . ".cpp", "w");
	$output_contents = ino_to_cpp($skel_contents, $input_contents, $filename);
	fwrite($output_fh, $output_contents);
	fclose($skel_fh);
	fclose($input_fh);
	fclose($output_fh);

	exec("clang $LIBB $CLANG_FLAGS $filename.cpp 2>&1", $compiler_output, $ret);	
	exec("avr-g++ $LIBB $CLANG_INCL_PATH $CPPFLAGS -c -o $filename.o $filename.cpp -I".$SOURCES_PATH." 2>&1", $compiler_output2, $ret); // *.cpp -> *.o
	if($compiler_output == "" && $compiler_output2 != "")
		$compiler_output = $compiler_output2;
	
	$compiler_success = !$ret;
	if($compiler_success)
	{
		$output = dothat($filename, "avr-gcc $LDFLAGS -o $filename.elf $filename.o $SOURCES $LIBBSOURCES -lm 2>&1"); // *.o -> *.elf
		if($output["error"])
			return $output;
		$output = dothat($filename, "objcopy -O ihex -R .eeprom $filename.elf $filename.hex 2>&1"); // *.elf -> *.hex
		if($output["error"])
			return $output;
		$output = dothat($filename, "avr-size --target=ihex $filename.elf | awk 'FNR == 2 {print $1+$2}' 2>&1"); // We should be checking this.
		if($output["error"])
			return $output;
		$size = $output["output"][0];
		$output["size"] = $size;
	}
	$output["compiler_success"] = $compiler_success;
	$output["compiler_output"] = $compiler_output;
	cleanDir($filename);
	return $output;
}

/**
\brief Extracts included headers from source code.

Takes a string containing the source code of a C/C++ program, parses the
preprocessor directives and makes a list of header files to include.

\param code The program's source code.
\return An array of headers.

Example usage:
\code
$headers = parse_headers($code);
\endcode

\warning Currently the postfix <b>.h</b> is removed from the header files. This
behavior might change in the future.
*/
function parse_headers($code)
{
	// Matches preprocessor include directives, has high tolerance to
	// spaces. The actual header (without the postfix .h) is stored in
	// register 1.
	//
	// Examples:
	// #include<stdio.h>
	// # include "proto.h"
	$REGEX = "/^\s*#\s*include\s*[<\"]\s*(\w*)\.h\s*[>\"]/";

	$code = explode("\n", $code);
	$headers = array();
	foreach ($code as $line)
		if(preg_match($REGEX, $line, $matches))
			$headers[] = $matches[1];

	return $headers;
}

// function add_libraries($LIBS_PATH, &$headers)
// {
// 	$LIBBSOURCES = "";
// 	$allowed=array("o");
// 	foreach ($headers as $key=>&$i)
// 	{
// 		try {
// 			$it = new RecursiveDirectoryIterator($LIBS_PATH."$i/");
// 			foreach(new RecursiveIteratorIterator($it) as $file) 
// 			{
// 			    if(in_array(substr($file, strrpos($file, '.') + 1),$allowed))
// 				{
// 			        // echo $file ."\n";
// 					$LIBBSOURCES .= "$file ";
// 					if(isset($headers[$key])) unset($headers[$key]);
// 			    }
// 			}
// 		} catch (Exception $e)
// 		{
// 		    // return array("success"=> false, "text" => "Library Not Found: $i", "cmd" => 'Caught exception: '.$e->getMessage()."\n", "lines" => array(0));
// 		}
//
// 	}
// 	return array("success"=> true, "output"=>$LIBBSOURCES);
// }

function add_paths($LIBS_PATH, &$headers)
{
	$LIBB = "";
	foreach ($headers as $key=>$dirname)
	{
		if(file_exists($LIBS_PATH.$dirname))
		{
			$LIBB.=" -I".$LIBS_PATH.$dirname;
			unset($headers[$key]);
		}
	}

	return $LIBB;
}

function cleanDir($filename)
{
	
	if(file_exists($filename)) unlink($filename);	
	if(file_exists($filename.".o")) unlink($filename.".o");	
	if(file_exists($filename.".cpp")) unlink($filename.".cpp");	
	if(file_exists($filename.".elf")) unlink($filename.".elf");	
	// Remeber to suggest a cronjob, in case something goes wrong...
	// find $path -name $filename.{o,cpp,elf,hex} -mtime +1 -delete
}


function add_libraries($LIBS_PATH, $headers)
{
	$LIBBSOURCES = "";
	foreach ($headers as $key=>$dirname)
	{
		$path = realpath($LIBS_PATH."/".$dirname);
		if(is_dir($path))
		{
			$array = recurse_dir($path);
			foreach($array as $file)
			{
				$LIBBSOURCES .= "$file ";
				if(isset($headers[$key])) unset($headers[$key]);
			}
		}
	}
	return $LIBBSOURCES;
}

function recurse_dir($directory)
{
	$array = iterate_dir($directory);
	foreach($array as $key => $dir)
	{
		if(is_dir($dir))
		{
			unset($array[$key]);
			if(strpos($dir, "examples") === FALSE)
			{
				$new_array=recurse_dir($dir);
				$array = array_merge($array, $new_array);
			}
		}
		else if(strpos($dir, ".o") === FALSE)
		{
			unset($array[$key]);
		}
	}
	return $array;
}

function iterate_dir($directory)
{
	$dir = opendir($directory);
	$iter = readdir($dir);
	$array = array();
	while(!($iter === FALSE))
	{
		$array[] = $iter;
		$iter = readdir();
	}
	foreach($array as $key => $value)
	{
		if($value == "." || $value == "..")
			unset($array[$key]);
		else
			$array[$key] = realpath($directory."/".$value);
	}

	sort($array);
	closedir($dir);
	return $array;
}

/**
\brief Generates valid C++ code from Arduino source code.

\param skel The contents of the Arduino skeleton file.
\param code The input source code.
\param filename (optional) The name of the input file.
\return Valid C++ code, the result of processing the input.

Arduino source code files are simplified C++ files. Thus, some preprocessing has
to be done to convert them to valid C++ code for the compiler to read. Some of
these "simplifications" include:
  - lack of a main() function
  - lack of function prototypes

A skeleton file is provided in the Arduino core files that contains a main()
function. Its contents have to be at the top of the output file. The prototypes
of the functions defined in the input file should be added beneath that. This
is to avoid compiler complaints regarding references to undefined functions.

The programmer is not aware of this modifications to his code. In case of a
compiler error, the line numbering would be wrong. To avoid this issue, a #line
preprocessor directive is used. Thus it is ensured that the line numbering in
the output file will be the same as the input file.

A regular expression is used to match function definitions in the input file.
Consequently this process will never be as sophisticated as a lexical analyzer.
Thus, some valid constructs cannot be matched. These include:
  - definitions that are split across multiple lines
  - definitions for variadic functions
  - typedefs for the return value or the parameters
  - pointers to functions
  - arrays, structs, and unions (might be supported in the future)

Example usage:
\code
$skel_fh = fopen($ARDUINO_SKEL, "r");
$skel_contents = fread($skel_fh, filesize($ARDUINO_SKEL));
$input_fh = fopen($INPUT_FILE, "r+");
$input_contents = fread($input_fh, filesize($INPUT_FILE));
$new_code = ino_to_cpp($skel_contents, $input_contents, $INPUT_FILE);
fwrite($input_fh, $new_code);
fclose($skel_fh);
fclose($input_fh);
\endcode

\todo Support arrays, structs and unions.
*/
function ino_to_cpp($skel, $code, $filename = NULL)
{
	// Supported primitives for parameters and return values. They are put
	// in a string, separated by "|" to be used in regular expressions.
	// Type "void" is put in its own variable to be more readable later on
	// in $REGEX.
	$VOID = "void";
	$TYPES = array($VOID, "int", "char", "word", "long", "float", "byte",
		"boolean", "uint8_t", "uint16_t", "uint32_t", "int8_t",
		"int16_t", "int32_t");
	$TYPES = implode("|", $TYPES);
	// Matches C/C++ function definitions, has high tolerance to whitespace
	// characters. Grouping constructs are used but no value is stored in
	// the registers.
	//
	// The limitations of this regular expression are described in the
	// comments above the function definition.
	//
	// Examples:
	// int foo()
	// int foo(void)
	// int foo(int bar)
	// int *foo(int bar)
	// int *foo(int *bar, int baz)
	$REGEX = "/^\s*(?:$TYPES)\s*\**\s*\w+\s*\((?:\s*(?:$VOID|(?:$TYPES)\s*\**\s*\w+\s*,?)\s*)*\)/";

	$new_code = "";

	// Firstly, include the contents of the skeleton file.
	$new_code .= $skel;

	// Secondly, generate and add the function prototypes.
	foreach (explode("\n", $code) as $line)
		if (preg_match($REGEX, $line, $matches))
			$new_code .= $matches[0] . ";\n";

	// Thirdly, add a preprocessor directive for line numbering.
	if ($filename)
		$new_code .= "#line 1 \"$filename\"\n";
	else
		$new_code .= "#line 1\n";

	// Lastly, include the input source code.
	$new_code .= $code;

	return $new_code;
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

/**
\brief Converts text with ANSI color codes to HTML.

\param text The string to convert.
\return A string with HTML tags.

Takes a string with ANSI color codes and converts them to HTML tags. Can be
useful for displaying the output of terminal commands on a web page. Handles
codes that modify the color (foreground and background) as well as the format
(bold, italics, underline and strikethrough). Other codes are ignored.

An ANSI escape sequence begins with the characters <b>^[</b> (hex 0x1B) and
<b>[</b>, and ends with <b>m</b>. The color code is placed in between. Multiple
color codes can be included, separated by the character <b>;</b>.

Example usage:
\code
$my_string = ansi2HTML($my_string);
\endcode
*/
function ansi2HTML($text)
{
	$FORMAT = array(
		0 => NULL,			// reset modes to default
		1 => "b",			// bold
		3 => "i",			// italics
		4 => "u",			// underline
		9 => "del",			// strikethrough
		30 => "black",			// foreground color
		31 => "red",
		32 => "green",
		33 => "yellow",
		34 => "blue",
		35 => "purple",
		36 => "cyan",
		37 => "white",
		40 => "black",			// background color
		41 => "red",
		42 => "green",
		43 => "yellow",
		44 => "blue",
		45 => "purple",
		46 => "cyan",
		47 => "white");
	// Matches ANSI escape sequences, starting with ^[[ and ending with m.
	// Valid characters inbetween are numbers and single semicolons. These
	// characters are stored in register 1.
	//
	// Examples: ^[[1;31m ^[[0m
	$REGEX = "/\x1B\[((?:\d+;?)*)m/";

	$stack = array();

	// ANSI escape sequences are located in the input text. Each color code
	// is replaced with the appropriate HTML tag. At the same time, the
	// corresponding closing tag is pushed on to the stack. When the reset
	// code '0' is found, it is replaced with all the closing tags in the
	// stack (LIFO order).
	while (preg_match($REGEX, $text, $matches))
	{
		$replacement = "";
		$sub = explode(";", $matches[1]);
		foreach ($sub as $mode)
		{
			switch ($mode)
			{
			case 0:
				while ($stack)
					$replacement .= array_pop($stack);
				break;
			case 1: case 3: case 4: case 9:
				$replacement .= "<$FORMAT[$mode]>";
				array_push($stack, "</$FORMAT[$mode]>");
				break;
			case 30: case 31: case 32: case 33:
			case 34: case 35: case 36: case 37:
				$replacement .= "<font style=\"color: $FORMAT[$mode]\">";
				array_push($stack, "</font>");
				break;
			case 40: case 41: case 42: case 43:
			case 44: case 45: case 46: case 47:
				$replacement .= "<font style=\"background-color: $FORMAT[$mode]\">";
				array_push($stack, "</font>");
				break;
			default:
				error_log(__FUNCTION__ . "(): Unhandled ANSI code '$mode' in " . __FILE__);
			}
		}
		$text = preg_replace($REGEX, $replacement, $text, 1);
	}

	// Close any tags left in the stack, in case the input text didn't.
	while ($stack)
		$text .= array_pop($stack);

	return $text;
}

putenv("ARDUINO_FILES_DIR=/mnt/codebender-testing/arduino-files-static");
putenv("ARDUINO_LIBS_DIR=arduino-files/libraries/");
putenv("ARDUINO_EXTRA_LIBS_DIR=".getenv("ARDUINO_FILES_DIR")."/extra-libraries/");
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

# Assertions:
#     - Source file exists and is a valid *.pde file
#     - Source file uses only core libraries
#     - Source file does NOT have an *.pde extension
#     - Core libraries are already compiled in build/core

# Where is this included?

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
		$output_string = ansi2HTML($output_string);
		echo(json_encode(array('success' => 0, 'text' => $output_string, 'lines' => $lines)));
	}
}

?>

