<?php
/**
\file
\brief Functions used by the compiler backend.

\author Dimitrios Christidis
\author Vasilis Georgitzikis

\copyright (c) 2012-2013, The Codebender Development Team
\copyright Licensed under the Simplified BSD License
 */

namespace Codebender\CompilerBundle\Handler;

// This file uses mktemp() to create a temporary directory where all the files
// needed to process the compile request are stored.
require_once "System.php";
use System;
use Codebender\CompilerBundle\Handler\MCUHandler;

class CompilerHandler
{
	/**
	\brief Searches for header files in a list of directories.

	\param array $headers A list of headers, without the <b>.h</b> extension.
	\param array $search_paths A list of paths to search for the headers.
	\param array $searched_paths A list of paths searched during previous calls.
	\return A list of directories to be included in the compilation process.

	In Arduino projects, developers do not provide paths for header files. The
	function read_headers() is used to scan files for include directives, then
	add_directories() is called to locate the appropriate paths. These paths should
	be used when calling avr-gcc for compilation and linking. This is a recursive
	function; only the first two parameters should be used.

	The order of $search_paths is important. If a library can be found in multiple
	paths, the first on will be used. This allows to set priorities and override
	libraries.

	The structure of search paths is as follows: each path contains directories,
	one for each library. The name of the directory should match the name of the
	corresponding library. Each directory must contain at least a header file with
	the same name (plus the extension .h), which is the header used by other
	projects.
	 */
	function add_directories($headers, $search_paths, $searched_paths = array())
	{
		$directories = $searched_paths;

		foreach ($headers as $header)
		{
			foreach ($search_paths as $path)
			{
				if (file_exists("$path/$header"))
				{
					// Skip library if it's already scanned.
					if (in_array("$path/$header", $directories))
						break;

					$directories[] = "$path/$header";

					$new_headers = array();
					foreach ($this->get_files_by_extension("$path/$header", array("c", "cpp", "h")) as $file)
						$new_headers = array_merge($new_headers, $this->read_headers(file_get_contents("$path/$header/$file")));
					$new_headers = array_unique($new_headers);

					$directories = array_merge($directories, $this->add_directories($new_headers, $search_paths, $directories));
				}
			}
		}

		// Remove already searched paths to avoid looking for duplicate
		// entries. This improves recursion.
		return array_diff($directories, $searched_paths);
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
	function create_objects($compiler_config, $directory, $exclude_files, $send_headers, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid)
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
			"version" => $version,
			"build" => array(
				"mcu" => $mcu,
				"f_cpu" => $f_cpu,
				"core" => $core,
				"variant" => $variant,
				"vid" => $vid,
				"pid" => $pid));

		$object_files = array();
		$sources = $this->get_files_by_extension($directory, array("c", "cpp"));

		foreach ($sources as $filename)
		{
			// Do not proceed if this file should not be compiled.
			if (isset($exclude) && preg_match("/(?:$exclude)/", pathinfo($filename, PATHINFO_BASENAME)))
				continue;

			// For every source file and set of build options there is a
			// corresponding object file. If that object is missing, a new
			// compile request is sent to the service.
			$object_file = "$directory/${mcu}_${f_cpu}_${core}_${variant}".(($variant == "leonardo") ? "_${vid}_${pid}" : "")."__".pathinfo($filename, PATHINFO_FILENAME);
			if (!file_exists("$object_file.o"))
			{
				// Include any header files in the request.
				if ($send_headers && !array_key_exists("files", $request_template))
				{
					$request_template["files"] = array();
					$header_files = $this->get_files_by_extension($directory, "h");

					foreach ($header_files as $header_filename)
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
				$reply = $this->main(json_encode($request), $compiler_config);
//				$ch = curl_init();
//				curl_setopt($ch, CURLOPT_URL, $this->current_page_url());
//				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
//				curl_setopt($ch, CURLOPT_POST, true);
//				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//				$reply = json_decode(curl_exec($ch));

				if ($reply["success"] == false)
					return array(
						"success" => false,
						"step" => 5,
						"message" => $reply["message"]);

				file_put_contents("$object_file.o", base64_decode($reply["output"]));
//				curl_close($ch);
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
		return "http".(array_key_exists("HTTPS", $_SERVER) ? "s" : "")."://".$_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
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

			if (file_put_contents("$directory/$filename", $content) === false)
				return array(
					"success" => false,
					"step" => 1,
					"message" => "Failed to extract file '$filename'.");

			if (preg_match($REGEX, $filename, $matches))
				$files[$matches[2]][] = "$directory/$matches[1]";
			else
				error_log(__FUNCTION__."(): Unhandled file extension '$filename' in ".__FILE__);
		}

		// All files were extracted successfully.
		return $files;
	}

	/**
	\brief Searches for files with specific extensions in a directory.

	\param string $directory The directory to search for files.
	\param mixed $extensions An array of strings, the extensions to look for.
	\return A list of files that have the appropriate extension.
	 */
	function get_files_by_extension($directory, $extensions)
	{
		if (is_string($extensions))
			$extensions = array($extensions);

		$files = array();
		foreach (scandir($directory) as $entry)
			if (is_file("$directory/$entry") && in_array(pathinfo("$directory/$entry", PATHINFO_EXTENSION), $extensions))
				$files[] = $entry;

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
	beneath that. This is required to avoid compiler errors regarding undefined
	functions.

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
				$new_code .= $matches[0].";\n";

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
	function main($request, $compiler_config)
	{

		// External binaries.
		$CC = $compiler_config["cc"];
		$CPP = $compiler_config["cpp"];
		$LD = $compiler_config["ld"];
		$CLANG = $compiler_config["clang"];
		$OBJCOPY = $compiler_config["objcopy"];
		$SIZE = $compiler_config["size"];
		// Standard command-line arguments used by the binaries.
		$CFLAGS = $compiler_config["cflags"];
		$CPPFLAGS = $compiler_config["cppflags"];
		$LDFLAGS = $compiler_config["ldflags"];
		$LDFLAGS_TAIL = $compiler_config["ldflags_tail"];
		$CLANG_FLAGS = $compiler_config["clang_flags"];
		$INCLUDE_FLAGS = $compiler_config["include_flags"];
		$OBJCOPY_FLAGS = $compiler_config["objcopy_flags"];
		$SIZE_FLAGS = $compiler_config["size_flags"];
		// The default name of the output file.
		$OUTPUT = $compiler_config["output"];
		// Path to arduino-files repository.
		$ROOT = $compiler_config["root"];
		// The name of the Arduino skeleton file.
		$ARDUINO_SKEL = $compiler_config["arduino_skel"];

		$start_time = microtime(true);

		// Step 0: Reject the request if the input data is not valid.
		$request = $this->validate_input($request);
		if (!$request)
			return array(
				"success" => false,
				"step" => 0,
				"message" => "Invalid input.");

		// Extract the request options for easier access.
		$format = $request->format;
		$version = $request->version;
		$mcu = $request->build->mcu;
		$f_cpu = $request->build->f_cpu;
		$core = $request->build->core;
		$variant = $request->build->variant;

		// Set the appropriate variables for vid and pid (Leonardo).
		$vid = ($variant == "leonardo") ? $request->build->vid : "";
		$pid = ($variant == "leonardo") ? $request->build->pid : "";

		// Create a temporary directory to place all the files needed to process
		// the compile request. This directory is created in $TMPDIR or /tmp by
		// default and is automatically removed upon execution completion.

		error_reporting(E_ALL & ~E_STRICT);

		$dir = System::mktemp("-t /tmp/ -d compiler.");

		if (!$dir)
			return array(
				"success" => false,
				"step" => 1,
				"message" => "Failed to create temporary directory.");

		// Step 1: Extract the files included in the request.
		$files = $this->extract_files($dir, $request->files);
		if (array_key_exists("success", $files))
			return $files;

		//TODO: remove the compiler-stuff dir when you remove the lib handling
		//TODO: make it compatible with non-default hardware (variants & cores)
		$files["dir"] = array("$ROOT/compiler-stuff/v$version/hardware/arduino/cores/$core", "$ROOT/compiler-stuff/v$version/hardware/arduino/variants/$variant");

		// Step 2: Preprocess Arduino source files.
		foreach ($files["ino"] as $file)
		{
			//TODO: remove the compiler-stuff dir when you remove the lib handling
			//TODO: make it compatible with non-default hardware (variants & cores)
			if (!isset($skel) && ($skel = file_get_contents("$ROOT/compiler-stuff/v$version/hardware/arduino/cores/$core/$ARDUINO_SKEL")) === false)
				return array(
					"success" => false,
					"step" => 2,
					"message" => "Failed to open Arduino skeleton file.");

			$code = file_get_contents("$file.ino");
			$new_code = $this->ino_to_cpp($skel, $code, "$file.ino");
			$ret = file_put_contents("$file.cpp", $new_code);

			if ($code === false || !$new_code || !$ret)
				return array(
					"success" => false,
					"step" => 2,
					"message" => "Failed to preprocess file '$file.ino'.");

			$files["cpp"][] = array_shift($files["ino"]);
		}

		$target_arch = "-mmcu=$mcu -DARDUINO=$version -DF_CPU=$f_cpu -DUSB_VID=$vid -DUSB_PID=$pid";
		$clang_target_arch = "-D".MCUHandler::$MCU[$mcu]." -DARDUINO=$version -DF_CPU=$f_cpu";

		if ($format == "syntax")
		{
			$CFLAGS .= " -fsyntax-only";
			$CPPFLAGS .= " -fsyntax-only";
		}

		// Scan files for headers and locate the corresponding include paths.
		$headers = array();
		foreach (array("c", "cpp", "h") as $ext)
		{
			foreach ($files[$ext] as $file)
			{
				$code = file_get_contents("$file.$ext");
				$headers = array_merge($headers, $this->read_headers($code));
			}
		}
		$headers = array_unique($headers);
		$new_directories = $this->add_directories($headers, array("$ROOT/libraries", "$ROOT/external-libraries"));
		$files["dir"] = array_merge($files["dir"], $new_directories);
		$include_directories = "";
		foreach ($files["dir"] as $directory)
			$include_directories .= " -I$directory";

		// Step 3, 4: Syntax-check and compile source files.
		$libraries = array();
		foreach (array("c", "cpp") as $ext)
		{
			foreach ($files[$ext] as $file)
			{
				// From hereon, $file is shell escaped and thus should only be used in calls
				// to exec().
				$file = escapeshellarg($file);

				//replace exec() calls with $this->debug_exec() for debugging
				if ($ext == "c")
					exec("$CC $CFLAGS $INCLUDE_FLAGS $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
				elseif ($ext == "cpp")
					exec("$CPP $CPPFLAGS $INCLUDE_FLAGS $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
				if ($ret_compile)
				{
					unset($output);
					exec("$CLANG $CLANG_FLAGS $INCLUDE_FLAGS $clang_target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
					$output = str_replace("$dir/", "", $output); // XXX
					$output = $this->ansi_to_html(implode("\n", $output));
					return array(
						"success" => false,
						"step" => 4,
						"message" => $output);
				}
				unset($output);

				$files["o"][] = array_shift($files[$ext]);
			}
		}

		if ($format == "syntax")
			return array(
				"success" => true,
				"time" => microtime(true) - $start_time);

		//TODO: return objects if more than one file??
		if ($format == "object")
		{
			$content = base64_encode(file_get_contents($files["o"][0].".o"));
			if (count($files["o"]) != 1 || !$content)
				return array(
					"success" => false,
					"step" => -1,
					"message" => "");
			else
				return array(
					"success" => true,
					"time" => microtime(true) - $start_time,
					"output" => $content);
		}

		// Step 5: Create objects for core files.
		//TODO: remove the compiler-stuff dir when you remove the lib handling
		//TODO: make it compatible with non-default hardware (variants & cores)
		$core_objects = $this->create_objects($compiler_config, "$ROOT/compiler-stuff/v$version/hardware/arduino/cores/$core", $ARDUINO_SKEL, false, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);
		if (array_key_exists("success", $core_objects))
			return $core_objects;
		$files["o"] = array_merge($files["o"], $core_objects);

		array_shift($files["dir"]);
		array_shift($files["dir"]);

		// Step 6: Create objects for libraries.
		foreach ($files["dir"] as $directory)
		{
			$library_objects = $this->create_objects($compiler_config, $directory, NULL, true, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);
			if (array_key_exists("success", $library_objects))
				return $library_objects;
			$files["o"] = array_merge($files["o"], $library_objects);
		}

		// Step 7: Link all object files and create executable.
		$object_files = "";
		foreach ($files["o"] as $object)
			$object_files .= " ".escapeshellarg("$object.o");
		exec("$LD $LDFLAGS $target_arch $object_files -o $dir/$OUTPUT.elf $LDFLAGS_TAIL 2>&1", $output, $ret_link);
		if ($ret_link)
			return array(
				"success" => false,
				"step" => 7,
				"message" => implode("\n", $output));

		// Step 8: Convert the output to the requested format and measure its
		// size.
		if ($format == "elf")
		{
			$ret_objcopy = false;
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
		if ($ret_objcopy || $ret_size || $content === false)
			return array(
				"success" => false,
				"step" => 8,
				"message" => "");
		else
			return array(
				"success" => true,
				"time" => microtime(true) - $start_time,
				"size" => $size[0],
				"output" => $content);
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
		// Request must contain certain entities.
		if (!(array_key_exists("format", $request)
			&& array_key_exists("version", $request)
			&& array_key_exists("build", $request)
			&& array_key_exists("files", $request)
			&& is_object($request->build)
			&& array_key_exists("mcu", $request->build)
			&& array_key_exists("f_cpu", $request->build)
			&& array_key_exists("core", $request->build)
			&& array_key_exists("variant", $request->build)
			&& is_array($request->files))
		)
			return NULL;

		// Leonardo-specific flags.
		if ($request->build->variant == "leonardo")
			if (!(array_key_exists("vid", $request->build)
				&& array_key_exists("pid", $request->build))
			)
				return NULL;

		// Values used as command-line arguments may not contain any special
		// characters. This is a serious security risk.
		foreach (array("mcu", "f_cpu", "core", "variant", "vid", "pid") as $i)
			if (isset($request->build->$i) && escapeshellcmd($request->build->$i) != $request->build->$i)
				return NULL;

		// Request is valid.
		return $request;
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
			0 => NULL, // reset modes to default
			1 => "b", // bold
			3 => "i", // italics
			4 => "u", // underline
			9 => "del", // strikethrough
			30 => "black", // foreground colors
			31 => "red",
			32 => "green",
			33 => "yellow",
			34 => "blue",
			35 => "purple",
			36 => "cyan",
			37 => "white",
			40 => "black", // background colors
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
					case 1:
					case 3:
					case 4:
					case 9:
						$replacement .= "<$FORMAT[$mode]>";
						array_push($stack, "</$FORMAT[$mode]>");
						break;
					case 30:
					case 31:
					case 32:
					case 33:
					case 34:
					case 35:
					case 36:
					case 37:
						$replacement .= "<font style=\"color: $FORMAT[$mode]\">";
						array_push($stack, "</font>");
						break;
					case 40:
					case 41:
					case 42:
					case 43:
					case 44:
					case 45:
					case 46:
					case 47:
						$replacement .= "<font style=\"background-color: $FORMAT[$mode]\">";
						array_push($stack, "</font>");
						break;
					default:
						error_log(__FUNCTION__."(): Unhandled ANSI code '$mode' in ".__FILE__);
						break;
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
			if (preg_match($REGEX, $line, $matches))
				$headers[] = $matches[1];

		return $headers;
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
	function debug_exec($command, &$output, &$retval)
	{
		echo "$ $command\n";
		passthru("$command 2>&1");
	}

}
