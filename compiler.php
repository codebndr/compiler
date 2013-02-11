<?php

/**
\file
\brief Functions used by the compiler backend.

\author Dimitrios Christidis
\author Vasilis Georgitzikis

\copyright (c) 2012-2013, The Codebender Development Team
\copyright Licensed under the Simplified BSD License
*/

// This file uses mktemp() to create a temporary directory where all the files
// needed to process the compile request are stored.
require_once "System.php";

/**
\brief Searches for header files in a list of directories.

\param array $headers A list of headers, without the <b>.h</b> extension.
\param array $search_paths A list of paths to search for the headers.
\param array $searched_paths A list of paths not to be recursed over.
\return A list of directories to be included in the compilation process.
*/
function add_directories($headers, $search_paths, $searched_paths = array())
{
	$directories = $searched_paths;

	foreach ($headers as $header)
	{
		foreach ($search_paths as $path)
		{
			if (file_exists("$path/$header") && array_search("$path/$header", $directories) === FALSE)
			{
				$directories[] = "$path/$header";

				$new_headers = read_headers(file_get_contents("$path/$header/$header.h"));
				$directories = array_merge($directories, add_directories($new_headers, $search_paths, $directories));
			}
		}
	}

	return array_diff($directories, $searched_paths);
}

/**
\brief Converts text with ANSI color codes to HTML.

\param string $text The string to convert.
\return A string with HTML tags.

Takes a string with ANSI color codes and converts them to HTML tags. Can be
useful for displaying the output of terminal commands on a web page. Handles
codes that modify the color (foreground and background) as well as the format
(bold, italics, underline and strikethrough). Other codes are ignored.

An ANSI escape sequence begins with the characters <b>^[</b> (hex 0x1B) and
<b>[</b>, and ends with <b>m</b>. The color code is placed in between. Multiple
color codes can be included, separated by semicolon.
*/
function ansi_to_html($text)
{
	$FORMAT = array(
		0 => NULL,			// reset modes to default
		1 => "b",			// bold
		3 => "i",			// italics
		4 => "u",			// underline
		9 => "del",			// strikethrough
		30 => "black",			// foreground colors
		31 => "red",
		32 => "green",
		33 => "yellow",
		34 => "blue",
		35 => "purple",
		36 => "cyan",
		37 => "white",
		40 => "black",			// background colors
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

	$text = htmlspecialchars($text);
	$stack = array();

	// ANSI escape sequences are located in the input text. Each color code
	// is replaced with the appropriate HTML tag. At the same time, the
	// corresponding closing tag is pushed on to the stack. When the reset
	// code '0' is found, it is replaced with all the closing tags in the
	// stack (LIFO order).
	while (preg_match($REGEX, $text, $matches))
	{
		$replacement = "";
		foreach (explode(";", $matches[1]) as $mode)
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

/**
\brief Creates objects for every source file in a directory.

\param string $directory The directory where the sources are located.
\param mixed $exclude_files An array of files to exclude from the compilation.
\param bool $send_headers <b>TRUE</b> if this directory contains a library.
\param string $mcu <b>mcu</b> build flag.
\param string $f_cpu <b>f_cpu</b> build flag.
\param string $core <b>core</b> build flag.
\param string $variant <b>variant</b> build flag.
\param string $vid <b>vid</b> build flag (Leonardo).
\param string $pid <b>pid</b> build flag (Leonardo).
\return An array of object files or a reply message in case of error.

In case of error, the return value is an array that has a key <b>success</b>
and contains the response to be sent back to the user.
*/
function create_objects($directory, $exclude_files, $send_headers, $mcu, $f_cpu, $core, $variant, $vid, $pid)
{
	if ($exclude_files)
	{
		if (is_string($exclude_files))
			$exclude = $exclude_files;
		elseif (is_array($exclude_files))
			$exclude = implode("|", $exclude_files);
	}

	$request_template = array(
		"format" => "object",
		"build" => array(
			"mcu" => $mcu,
			"f_cpu" => $f_cpu,
			"core" => $core,
			"variant" => $variant,
			"vid" => $vid,
			"pid" => $pid));

	$object_files = array();
	exec("ls $directory | grep -E '\.(c|cpp)$'", $sources); // FIXME

	foreach ($sources as $filename)
	{
		// Do not proceed if this file should not be compiled.
		if (isset($exclude) && preg_match("/(?:$exclude)/", pathinfo($filename, PATHINFO_BASENAME)))
			continue;

		// For every source file and set of build options there is a
		// corresponding object file. If that object is missing, a new
		// compile request is sent to the service.
		$object_file = "$directory/${mcu}_${f_cpu}_${core}_${variant}" . (($variant == "leonardo") ? "_${vid}_${pid}" : "") . "__" . pathinfo($filename, PATHINFO_FILENAME);
		if (!file_exists("$object_file.o"))
		{
			// Include any header files in the request.
			if ($send_headers && !array_key_exists("files", $request_template))
			{
				$request_template["files"] = array();
				exec("ls $directory | grep -E '\.(h)$'", $header_files); // FIXME

				foreach($header_files as $header_filename)
				{
					$request_template["files"][] = array(
						"filename" => pathinfo($header_filename, PATHINFO_BASENAME),
						"content" => file_get_contents("$directory/$header_filename"));
				}
			}

			// Include the source file.
			$request = $request_template;
			$request["files"][] = array(
				"filename" => pathinfo($filename, PATHINFO_BASENAME),
				"content" => file_get_contents("$directory/$filename"));

			// Perform a new compile request.
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, current_page_url());
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			$reply = json_decode(curl_exec($ch));

			if ($reply->success == FALSE)
				return array(
					"success" => FALSE,
					"step" => 5,
					"message" => $reply->message);

			file_put_contents("$object_file.o", base64_decode($reply->output));
			curl_close($ch);
		}

		$object_files[] = $object_file;
	}

	// All object files created successfully.
	return $object_files;
}

/**
\brief Returns the current page's URL, as requested by the client.

\return The current page's URL.

The compiler backend makes a request to itself to create the core and library
object files. This creates some overhead, but simplifies the flow of execution.

This function returns the URL of the current page. This way it is more robust
and independent of the server the compiler is used on.
*/
function current_page_url()
{
	return "http" . (array_key_exists("HTTPS", $_SERVER) ? "s" : "") . "://" . $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
}

/**
\brief Executes a command and displays the command itself and its output.

\param string $command The command to be executed.

Simplifies the creation and debugging of pages that rely on multiple external
programs by "emulating" the execution of the requested command in a terminal
emulator. Can be useful during early stages of development. Replace with
<b>exec()</b> afterwards.

To perform the command execution, <b>passthru()</b> is used. The string
<b>2\>&1</b> is appended to the command to ensure messages sent to standard
error are not lost.

\warning It is not possible to redirect the standard error output to a file.
*/
function do_this($command)
{
	echo "$ $command\n";
	passthru("$command 2>&1");
}

/**
\brief Extracts the files included in a compile request.

\param string $directory The directory to extract the files to.
\param array $request_files The files structure, as taken from the JSON request.
\return A list of files or a reply message in case of error.

Takes the files structure from a compile request and creates each file in a
specified directory. Also creates a new structure where each key is the file
extension and the associated value an array containing the absolute paths of
the file, minus the extension.

In case of error, the return value is an array that has a key <b>success</b>
and contains the response to be sent back to the user.
*/
function extract_files($directory, $request_files)
{
	// File extensions used by Arduino projects. They are put in a string,
	// separated by "|" to be used in regular expressions. They are also
	// used as keys in an array that will contain the paths of all the
	// extracted files.
	$EXTENSIONS = array("c", "cpp", "h", "ino", "o");
	$files = array();
	foreach ($EXTENSIONS as $ext)
		$files[$ext] = array();
	$EXTENSIONS = implode("|", $EXTENSIONS);
	// Matches filename that end with an appropriate extension. The name
	// without the extension is stored in registerd 1, the extension itself
	// in register 2.
	//
	// Examples: foo.c bar.cpp
	$REGEX = "/(.*)\.($EXTENSIONS)$/";

	foreach ($request_files as $file)
	{
		$filename = $file->filename;
		$content = $file->content;

		if (file_put_contents("$directory/$filename", $content) === FALSE)
			return array(
				"success" => FALSE,
				"step" => 1,
				"message" => "Failed to extract file '$filename'.");

		if (preg_match($REGEX, $filename, $matches))
			$files[$matches[2]][] = "$directory/$matches[1]";
		else
			error_log(__FUNCTION__ . "(): Unhandled file extension '$filename' in " . __FILE__);
	}

	// All files were extracted successfully.
	return $files;
}

/**
\brief Generates valid C++ code from Arduino source code.

\param string $skel The contents of the Arduino skeleton file.
\param string $code The input source code.
\param string $filename (optional) The name of the input file.
\return Valid C++ code, the result of processing the input.

Arduino source code files are simplified C++ files. Thus, some preprocessing has
to be done to convert them to valid C++ code for the compiler to read. Some of
these "simplifications" include:
  - lack of a <b>main()</b> function
  - lack of function prototypes

A skeleton file is provided in the Arduino core files that contains a
<b>main()</b> function. Its contents have to be at the top of the output file.
The prototypes of the functions defined in the input file should be added
beneath that. This is to avoid compiler complaints regarding references to
undefined functions.

The programmer is not aware of this modifications to his code. In case of a
compiler error, the line numbering would be wrong. To avoid this issue, a
<b>\#line</b> preprocessor directive is used. Thus it is ensured that the line
numbering in the output file will be the same as the input file.

A regular expression is used to match function definitions in the input file.
Consequently this process will never be as sophisticated as a lexical analyzer.
Thus, some valid constructs cannot be matched. These include:
  - definitions that are split across multiple lines
  - definitions for variadic functions
  - typedefs for the return value or the parameters
  - pointers to functions
  - arrays, structs, and unions
*/
function ino_to_cpp($skel, $code, $filename = NULL)
{
	// Supported primitives for parameters and return values. They are put
	// in a string, separated by "|" to be used in regular expressions.
	// Type "void" is put in its own variable to be more readable later on
	// in $REGEX.
	$VOID = "void";
	$TYPES = array($VOID, "int", "char", "word", "short", "long", "float",
		"byte", "boolean", "uint8_t", "uint16_t", "uint32_t", "int8_t",
		"int16_t", "int32_t");
	$TYPES = implode("|", $TYPES);
	// Type qualifiers for declarators.
	$QUALS = array("const", "volatile");
	$QUALS = implode("|", $QUALS);
	// Type specifiers for declarators.
	$SPECS = array("signed", "unsigned");
	$SPECS = implode("|", $SPECS);
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
	// int *foo(const int bar)
	// int *foo(volatile int *bar, int baz)
	$REGEX = "/^\s*((?:$SPECS)\s*)*(?:$TYPES)\s*\**\s*\w+\s*\((?:\s*(?:$VOID|((?:$QUALS)\s*)*((?:$SPECS)\s*)*(?:$TYPES)\s*\**\s*\w+\s*,?)\s*)*\)/";

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

/**
\brief Processes a compile request.

\param string $request The body of the POST request.
\return A message to be JSON-encoded and sent back to the requestor.
*/
function main($request)
{
	// External binaries.
	$CC = "/usr/bin/avr-gcc";
	$CPP = "/usr/bin/avr-g++";
	$LD = "/usr/bin/avr-gcc";
	$CLANG = "/usr/bin/clang";
	$OBJCOPY = "/usr/bin/avr-objcopy";
	$SIZE = "/usr/bin/avr-size";
	// Standard command-line arguments used by the binaries.
	$CFLAGS = "-Os -ffunction-sections -fdata-sections";
	$CPPFLAGS = "-Os -ffunction-sections -fdata-sections -fno-exceptions";
	$LDFLAGS = "-Os -Wl,--gc-sections";
	$LDFLAGS_TAIL = "-lm -lc";
	$CLANG_FLAGS = "-w -fsyntax-only -fcolor-diagnostics -I/usr/lib/avr/include";
	$OBJCOPY_FLAGS = "-R .eeprom";
	$SIZE_FLAGS = "";
	// The default name of the output file.
	$OUTPUT = "output";
	// Path to arduino-files repository.
	$ROOT = "/mnt/codebender_libraries";
	// The name of the Arduino skeleton file.
	$ARDUINO_SKEL = "main.cpp";
	// The version of the Arduino files.
	$ARDUINO_VERSION = "103";

	$start_time = microtime(TRUE);

	// Step 0: Reject the request if the input data is not valid.
	$request = validate_input($request);
	if (!$request)
		return array(
			"success" => FALSE,
			"step" => 0,
			"message" => "Invalid input.");

	// Extract the request options for easier access.
	$format = $request->format;
	$mcu = $request->build->mcu;
	$f_cpu = $request->build->f_cpu;
	$core = $request->build->core;
	$variant = $request->build->variant;

	// Set the appropriate variables for vid and pid (Leonardo).
	$vid = "";
	$pid = "";

	if(isset($request->build->vid))
		$vid = $request->build->vid;
	if(isset($request->build->pid))
		$pid = $request->build->pid;

	// Create a temporary directory to place all the files needed to process
	// the compile request. This directory is created in $TMPDIR or /tmp by
	// default and is automatically removed upon execution completion.
	$dir = System::mktemp("-d compiler.");
	if (!$dir)
		return array(
			"success" => FALSE,
			"step" => 1,
			"message" => "Failed to create temporary directory.");

	// Step 1: Extract the files included in the request.
	$files = extract_files($dir, $request->files);
	if (array_key_exists("success", $files))
		return $files;

	$files["dir"] = array("$ROOT/$core/core", "$ROOT/$core/variants/$variant");

	// Step 2: Preprocess Arduino source files.
	foreach ($files["ino"] as $file)
	{
		if (!isset($skel) && ($skel = file_get_contents("$ROOT/$core/core/$ARDUINO_SKEL")) === FALSE)
			return array(
				"success" => FALSE,
				"step" => 2,
				"message" => "Failed to open Arduino skeleton file.");

		$code = file_get_contents("$file.ino");
		$new_code = ino_to_cpp($skel, $code, "$file.ino");
		$ret = file_put_contents("$file.cpp", $new_code);

		if ($code === FALSE || !$new_code || !$ret)
			return array(
				"success" => FALSE,
				"step" => 2,
				"message" => "Failed to preprocess file '$file.ino'.");

		$files["cpp"][] = array_shift($files["ino"]);
	}

	include "mcu.php";
	$target_arch = "-mmcu=$mcu -DARDUINO=$ARDUINO_VERSION -DF_CPU=$f_cpu -DUSB_VID=$vid -DUSB_PID=$pid";
	$clang_target_arch = "-D$MCU[$mcu] -DARDUINO=$ARDUINO_VERSION -DF_CPU=$f_cpu";

	// Step 3, 4: Syntax-check and compile source files.
	$libraries = array();
	foreach(array("c", "cpp") as $ext)
	{
		foreach($files[$ext] as $file)
		{
			$code = file_get_contents("$file.$ext");
			$headers = read_headers($code);
			$file_directories = add_directories($headers, array("$ROOT/libraries", "$ROOT/external-libraries"));

			// From hereon, $file is shell escaped and thus should only be used in calls
			// to exec().
			$file = escapeshellarg($file);

			$include_directories = "";
			foreach ($files["dir"] as $directory)
				$include_directories .= " -I$directory";
			foreach ($file_directories as $directory)
				$include_directories .= " -I$directory";

			if ($ext == "c")
				exec("$CC $CFLAGS $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
			elseif ($ext == "cpp")
				exec("$CPP $CPPFLAGS $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
			if ($ret_compile)
			{
				unset($output);
				exec("$CLANG $CLANG_FLAGS $clang_target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
				$output = str_replace("$dir/", "", $output); // XXX
				$output = ansi_to_html(implode("\n", $output));
				return array(
					"success" => FALSE,
					"step" => 4,
					"message" => $output);
			}
			unset($output);

			$files["o"][] = array_shift($files[$ext]);
			$libraries = array_merge($libraries, $file_directories);
		}
	}
	$files["dir"] = array_merge($files["dir"], array_unique($libraries));

	if ($format == "syntax")
		return array(
			"success" => TRUE,
			"time" => microtime(TRUE) - $start_time);

	if ($format == "object")
	{
		$content = base64_encode(file_get_contents($files["o"][0] . ".o"));
		if (count($files["o"]) != 1 || !$content)
			return array(
				"success" => FALSE,
				"step" => -1,
				"message" => "");
		else
			return array(
				"success" => TRUE,
				"time" => microtime(TRUE) - $start_time,
				"output" => $content);
	}

	// Step 5: Create objects for core files.
	$core_objects = create_objects("$ROOT/$core/core", $ARDUINO_SKEL, FALSE, $mcu, $f_cpu, $core, $variant, $vid, $pid);
	if (array_key_exists("success", $core_objects))
		return $core_objects;
	$files["o"] = array_merge($files["o"], $core_objects);

	array_shift($files["dir"]);
	array_shift($files["dir"]);

	// Step 6: Create objects for libraries.
	foreach ($files["dir"] as $directory)
	{
		$library_objects = create_objects($directory, NULL, TRUE, $mcu, $f_cpu, $core, $variant, $vid, $pid);
		if (array_key_exists("success", $library_objects))
			return $library_objects;
		$files["o"] = array_merge($files["o"], $library_objects);
	}

	// Step 7: Link all object files and create executable.
	$object_files = "";
	foreach ($files["o"] as $object)
		$object_files .= " " . escapeshellarg("$object.o");
	exec("$LD $LDFLAGS $target_arch $object_files -o $dir/$OUTPUT.elf $LDFLAGS_TAIL 2>&1", $output, $ret_link);
	if ($ret_link)
		return array(
			"success" => FALSE,
			"step" => 7,
			"message" => implode("\n", $output));

	// Step 8: Convert the output to the requested format and measure its
	// size.
	if ($format == "elf")
	{
		$ret_objcopy = FALSE;
		exec("$SIZE $SIZE_FLAGS --target=elf32-avr $dir/$OUTPUT.elf | awk 'FNR == 2 {print $1+$2}'", $size, $ret_size); // FIXME
		$content = base64_encode(file_get_contents("$dir/$OUTPUT.elf"));
	}
	elseif ($format == "binary")
	{
		exec("$OBJCOPY $OBJCOPY_FLAGS -O binary $dir/$OUTPUT.elf $dir/$OUTPUT.bin", $dummy, $ret_objcopy);
		exec("$SIZE $SIZE_FLAGS --target=binary $dir/$OUTPUT.bin | awk 'FNR == 2 {print $1+$2}'", $size, $ret_size); // FIXME
		$content = base64_encode(file_get_contents("$dir/$OUTPUT.bin"));
	}
	elseif ($format == "hex")
	{
		exec("$OBJCOPY $OBJCOPY_FLAGS -O ihex $dir/$OUTPUT.elf $dir/$OUTPUT.hex", $dummy, $ret_objcopy);
		exec("$SIZE $SIZE_FLAGS --target=ihex $dir/$OUTPUT.hex | awk 'FNR == 2 {print $1+$2}'", $size, $ret_size); // FIXME
		$content = file_get_contents("$dir/$OUTPUT.hex");
	}

	// If everything went well, return the reply to the caller.
	if ($ret_objcopy || $ret_size || $content === FALSE)
		return array(
			"success" => FALSE,
			"step" => 8,
			"message" => "");
	else
		return array(
			"success" => TRUE,
			"time" => microtime(TRUE) - $start_time,
			"size" => $size[0],
			"output" => $content);
}

/**
\brief Extracts included headers from source code.

\param string $code The program's source code.
\return An array of headers.

Takes a string containing the source code of a C/C++ program, parses the
preprocessor directives and makes a list of header files to include. The
postfix <b>.h</b> is removed from the header names.
*/
function read_headers($code)
{
	// Matches preprocessor include directives, has high tolerance to
	// spaces. The actual header (without the postfix .h) is stored in
	// register 1.
	//
	// Examples:
	// #include<stdio.h>
	// # include "proto.h"
	$REGEX = "/^\s*#\s*include\s*[<\"]\s*(\w*)\.h\s*[>\"]/";

	$headers = array();
	foreach (explode("\n", $code) as $line)
		if(preg_match($REGEX, $line, $matches))
			$headers[] = $matches[1];

	return $headers;
}

/**
\brief Decodes and performs validation checks on input data.

\param string $request The JSON-encoded compile request.
\return The value encoded in JSON in appropriate PHP type or <b>NULL</b>.
*/
function validate_input($request)
{
	$request = json_decode($request);

	// Request must be successfully decoded.
	if ($request === NULL)
		return NULL;

	// Values used as command-line arguments may not contain any special
	// characters. This is a serious security risk.
	foreach (array("mcu", "f_cpu", "core", "variant", "vid", "pid") as $i)
		if (escapeshellcmd($request->build->$i) != $request->build->$i)
			return NULL;

	// Request is valid.
	return $request;
}

?>
