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
	paths, the first one will be used. This allows to set priorities and override
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
		$sources = $this->get_files_by_extension($directory, array("c", "cpp", "S"));

		if (file_exists("$directory/utility"))
		{
			$utility_sources = $this->get_files_by_extension("$directory/utility", array("c", "cpp", "S"));
			foreach ($utility_sources as &$i)
				$i = "utility/$i";
			unset($i);
			$sources = array_merge($sources, $utility_sources);
		}

		foreach ($sources as $filename)
		{
			// Do not proceed if this file should not be compiled.
			if (isset($exclude) && preg_match("/(?:$exclude)/", pathinfo($filename, PATHINFO_BASENAME)))
				continue;

			// For every source file and set of build options there is a
			// corresponding object file. If that object is missing, a new
			// compile request is sent to the service.
			$object_file = pathinfo("$directory/$filename", PATHINFO_DIRNAME)."/${mcu}_${f_cpu}_${core}_${variant}".(($variant == "leonardo") ? "_${vid}_${pid}" : "")."__".pathinfo($filename, PATHINFO_FILENAME);
			if (!file_exists("$object_file.o"))
			{
				// Include any header files in the request.
				if ($send_headers && !array_key_exists("files", $request_template))
				{
					$request_template["files"] = array();
					$header_files = $this->get_files_by_extension($directory, array("h", "inc"));

					if (file_exists("$directory/utility"))
					{
						$utility_headers = $this->get_files_by_extension("$directory/utility", array("h", "inc"));
						foreach ($utility_headers as &$i)
							$i = "utility/$i";
						unset($i);
						$header_files = array_merge($header_files, $utility_headers);
					}

					foreach ($header_files as $header_filename)
					{
						$request_template["files"][] = array(
							"filename" => $header_filename,
							"content" => file_get_contents("$directory/$header_filename"));
					}
				}

				// Include the source file.
				$request = $request_template;
				$request["files"][] = array(
					"filename" => $filename,
					"content" => file_get_contents("$directory/$filename"));

				// Perform a new compile request.
				$reply = $this->main(json_encode($request), $compiler_config);

				if ($reply["success"] == false)
					return array(
						"success" => false,
						"step" => 5,
						"message" => $reply["message"]);

				//TODO: Make a check here and fail gracefully
				file_put_contents("$object_file.o", base64_decode($reply["output"]));
//				curl_close($ch);
			}

			$object_files[] = $object_file;
		}

		// All object files created successfully.
		return $object_files;
	}

	/**
	\brief Extracts the files included in a compile request.

	\param string $directory The directory to extract the files to.
	\param array $request_files The files structure, as taken from the JSON request.
	\return A list of files or a reply message in case of error.

	Takes the files structure from a compile request and creates each file in a
	specified directory. If requested, it may create additional directories and
	have the files placed inside them accordingly.

	Also creates a new structure where each key is the file extension and the
	associated value is an array containing the absolute paths of the file, minus
	the extension.

	In case of error, the return value is an array that has a key <b>success</b>
	and contains the response to be sent back to the user.
	 */
	function extract_files($directory, $request_files)
	{
		// File extensions used by Arduino projects. They are put in a string,
		// separated by "|" to be used in regular expressions. They are also
		// used as keys in an array that will contain the paths of all the
		// extracted files.
		$EXTENSIONS = array("c", "cpp", "h", "inc", "ino", "o", "S");
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

			$failure_response = array(
				"success" => false,
				"step" => 1,
				"message" => "Failed to extract file '$filename'.");

			// Filenames may not use the special directory "..". This is a
			// serious security risk.
			$directories = explode("/", "$directory/$filename");
			if (in_array("..", $directories))
				return $failure_response;

			if (strpos($filename, DIRECTORY_SEPARATOR))
			{
				$new_directory = pathinfo($filename, PATHINFO_DIRNAME);
				if (!file_exists("$directory/$new_directory"))
					mkdir("$directory/$new_directory", 0777, true);
				// There is no reason to check whether mkdir()
				// succeeded, given that the call to
				// file_put_contents() that follows would fail
				// as well.
			}

			if (file_put_contents("$directory/$filename", $content) === false)
				return $failure_response;

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
	\brief Processes a compile request.

	\param string $request The body of the POST request.
	\return A message to be JSON-encoded and sent back to the requestor.
	 */
	function main($request, $compiler_config)
	{

		// External binaries.
		$CC = $compiler_config["cc"];
		$CPP = $compiler_config["cpp"];
		$AS = $compiler_config["as"];
		$LD = $compiler_config["ld"];
		$CLANG = $compiler_config["clang"];
		$OBJCOPY = $compiler_config["objcopy"];
		$SIZE = $compiler_config["size"];
		// Standard command-line arguments used by the binaries.
		$CFLAGS = $compiler_config["cflags"];
		$CPPFLAGS = $compiler_config["cppflags"];
		$ASFLAGS = $compiler_config["asflags"];
		$LDFLAGS = $compiler_config["ldflags"];
		$LDFLAGS_TAIL = $compiler_config["ldflags_tail"];
		$CLANG_FLAGS = $compiler_config["clang_flags"];
		$OBJCOPY_FLAGS = $compiler_config["objcopy_flags"];
		$SIZE_FLAGS = $compiler_config["size_flags"];
		// The default name of the output file.
		$OUTPUT = $compiler_config["output"];
		// Path to arduino-core-files repository.
		$ARDUINO_CORES_DIR = $compiler_config["arduino_cores_dir"];
		// The name of the Arduino skeleton file.
		$ARDUINO_SKEL = $compiler_config["arduino_skel"];
		// Path to arduino-library-files repository.
		$ARDUINO_LIBS_DIR = $compiler_config["arduino_libs_dir"];

		$preproc = new PreprocessingHandler();
		$postproc = new PostprocessingHandler();

		$start_time = microtime(true);

		// Step 0: Reject the request if the input data is not valid.
		$request = $preproc->validate_input($request);
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

		//Use the include paths for the AVR headers that are bundled with each Arduino SDK version
		$core_includes = " -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include-fixed -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/avr/include ";

		error_reporting(E_ALL & ~E_STRICT);

		// Create a temporary directory to place all the files needed to process
		// the compile request. This directory is created in $TMPDIR or /tmp by
		// default and is automatically removed upon execution completion.
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

		//TODO: make it compatible with non-default hardware (variants & cores)
		$files["dir"] = array("$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core", "$ARDUINO_CORES_DIR/v$version/hardware/arduino/variants/$variant");

		// Step 2: Preprocess Arduino source files.
		foreach ($files["ino"] as $file)
		{
			//TODO: make it compatible with non-default hardware (variants & cores)
			if (!isset($skel) && ($skel = file_get_contents("$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core/$ARDUINO_SKEL")) === false)
				return array(
					"success" => false,
					"step" => 2,
					"message" => "Failed to open Arduino skeleton file.");

			$code = file_get_contents("$file.ino");
			$new_code = $preproc->ino_to_cpp($skel, $code, "$file.ino");
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
		$new_directories = $this->add_directories($headers, array("$ARDUINO_LIBS_DIR/libraries", "$ARDUINO_LIBS_DIR/external-libraries"));
		$files["dir"] = array_merge($files["dir"], $new_directories);

		// Create command-line arguments for header search paths. Note that the
		// current directory is added to eliminate the difference between <>
		// and "" in include preprocessor directives.
		$include_directories = "-I$dir";
		if (file_exists("$dir/utility"))
			$include_directories .= " -I$dir/utility";
		foreach ($files["dir"] as $directory)
			$include_directories .= " -I$directory";

		// Step 3, 4: Syntax-check and compile source files.
		$libraries = array();
		foreach (array("c", "cpp", "S") as $ext)
		{
			foreach ($files[$ext] as $file)
			{
				// From hereon, $file is shell escaped and thus should only be used in calls
				// to exec().
				$file = escapeshellarg($file);

				//replace exec() calls with $this->debug_exec() for debugging
				if ($ext == "c")
					exec("$CC $CFLAGS $core_includes $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
				elseif ($ext == "cpp")
					exec("$CPP $CPPFLAGS $core_includes $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
				elseif ($ext == "S")
					exec("$AS $ASFLAGS $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
				if ($ret_compile)
				{
					unset($output);
					exec("$CLANG $CLANG_FLAGS $core_includes $clang_target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
					$output = str_replace("$dir/", "", $output); // XXX
					$output = $postproc->ansi_to_html(implode("\n", $output));
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
					"step" => -1, //TODO: Fix this step?
					"message" => "");
			else
				return array(
					"success" => true,
					"time" => microtime(true) - $start_time,
					"output" => $content);
		}

		// Step 5: Create objects for core files.
		//TODO: make it compatible with non-default hardware (variants & cores)
		$core_objects = $this->create_objects($compiler_config, "$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core", $ARDUINO_SKEL, false, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);
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
