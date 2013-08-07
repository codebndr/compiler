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
	private $preproc;
	private $postproc;
	private $utility;

	function __construct()
	{
		$this->preproc = new PreprocessingHandler();
		$this->postproc = new PostprocessingHandler();
		$this->utility = new UtilityHandler();
	}

	/**
	\brief Processes a compile request.

	\param string $request The body of the POST request.
	\return A message to be JSON-encoded and sent back to the requestor.
	 */
	function main($request, $compiler_config)
	{
		error_reporting(E_ALL & ~E_STRICT);

		$this->set_values($compiler_config,
			$CC, $CPP, $AS, $LD, $CLANG, $OBJCOPY, $SIZE, $CFLAGS, $CPPFLAGS, $ASFLAGS, $LDFLAGS, $LDFLAGS_TAIL,
			$CLANG_FLAGS, $OBJCOPY_FLAGS, $SIZE_FLAGS, $OUTPUT, $ARDUINO_CORES_DIR, $ARDUINO_SKEL);

		$start_time = microtime(true);

		// Step 0: Reject the request if the input data is not valid.
		//TODO: Replace $tmp variable name
		$tmp = $this->requestValid($request);
		if($tmp["success"] == false)
			return $tmp;

		$this->set_variables($request, $format, $libraries, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);

		$target_arch = "-mmcu=$mcu -DARDUINO=$version -DF_CPU=$f_cpu -DUSB_VID=$vid -DUSB_PID=$pid";
		$clang_target_arch = "-D".MCUHandler::$MCU[$mcu]." -DARDUINO=$version -DF_CPU=$f_cpu";

		// Step 1: Extract the files included in the request.
		$tmp = $this->extractFiles($request, $dir, $files);
		if ($tmp["success"] == false)
			return $tmp;

		// Step 2: Preprocess Arduino source files.
		$tmp = $this->preprocessIno($files, $ARDUINO_CORES_DIR, $ARDUINO_SKEL, $version, $core);
		if ($tmp["success"] == false)
			return $tmp;

		// Step 3: Preprocess Header includes.
		$tmp = $this->preprocessHeaders($files, $libraries, $include_directories, $dir, $ARDUINO_CORES_DIR, $version, $core, $variant);
		if ($tmp["success"] == false)
			return $tmp;

		// Step 4: Syntax-check and compile source files.
		//Use the include paths for the AVR headers that are bundled with each Arduino SDK version
		$core_includes = " -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include-fixed -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/avr/include ";

		$tmp = $this->doCompile($files, $dir, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories, $format);
		if ($tmp["success"] == false)
			return $tmp;

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
		$core_objects = $this->utility->create_objects($compiler_config, "$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core", $ARDUINO_SKEL, false, array(), $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);
		//TODO: Upgrade this
		if (array_key_exists("success", $core_objects))
			return $core_objects;
		$files["o"] = array_merge($files["o"], $core_objects);

		// Step 6: Create objects for libraries.
		foreach ($files["dir"] as $directory)
		{
			$library_objects = $this->utility->create_objects($compiler_config, $directory, NULL, true, $libraries, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);
			//TODO: Upgrade this
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
		$tmp = $this->convertOutput($dir, $format, $SIZE, $SIZE_FLAGS, $OBJCOPY, $OBJCOPY_FLAGS, $OUTPUT, $start_time);
		return $tmp;

	}

	private function requestValid(&$request)
	{
		$request = $this->preproc->validate_input($request);
		if (!$request)
			return array(
				"success" => false,
				"step" => 0,
				"message" => "Invalid input.");
		else return array("success" => true);
	}

	private function extractFiles($request, &$dir, &$files)
	{
		// Create a temporary directory to place all the files needed to process
		// the compile request. This directory is created in $TMPDIR or /tmp by
		// default and is automatically removed upon execution completion.
		$dir = System::mktemp("-t /tmp/ -d compiler.");

		if (!$dir)
			return array(
				"success" => false,
				"step" => 1,
				"message" => "Failed to create temporary directory.");

		$response = $this->utility->extract_files($dir, $request->files);
		if ($response["success"] === false)
			return $response;
		$files = $response["files"];

		if (!file_exists($dir."/libraries"))
			mkdir($dir."/libraries/", 0777, true);
		//TODO: check if it succeeded
		$files["libs"] = array();
		foreach($request->libraries as $library_name => $library_files)
		{
			//TODO: check if it succeeded
			if (!file_exists($dir."/libraries".$library_name))
				mkdir($dir."/libraries/".$library_name, 0777, true);
			$files["libs"][] = $this->utility->extract_files($dir."/libraries/".$library_name, $library_files)["files"];
		}

		return array("success" => true);
	}

	private function preprocessIno(&$files, $ARDUINO_CORES_DIR, $ARDUINO_SKEL, $version, $core)
	{
		foreach ($files["ino"] as $file)
		{
			//TODO: make it compatible with non-default hardware (variants & cores)
			if (!isset($skel) && ($skel = file_get_contents("$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core/$ARDUINO_SKEL")) === false)
				return array(
					"success" => false,
					"step" => 2,
					"message" => "Failed to open Arduino skeleton file.");

			$code = file_get_contents("$file.ino");
			$new_code = $this->preproc->ino_to_cpp($skel, $code, "$file.ino");
			$ret = file_put_contents("$file.cpp", $new_code);

			if ($code === false || !$new_code || !$ret)
				return array(
					"success" => false,
					"step" => 2,
					"message" => "Failed to preprocess file '$file.ino'.");

			$files["cpp"][] = array_shift($files["ino"]);
		}

		return array("success" => true);
	}

	public function preprocessHeaders(&$files, &$libraries, &$include_directories, $dir, $ARDUINO_CORES_DIR, $version, $core, $variant)
	{
		try
		{
			// Create command-line arguments for header search paths. Note that the
			// current directory is added to eliminate the difference between <>
			// and "" in include preprocessor directives.
			//TODO: make it compatible with non-default hardware (variants & cores)
			$include_directories = "-I$dir -I$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core -I$ARDUINO_CORES_DIR/v$version/hardware/arduino/variants/$variant";

			//TODO: The code that rests on the main website looks for headers in all files, not just c, cpp and h. Might raise a security issue
			$files["dir"] = array();
			foreach($libraries as $library_name => $library_files)
			{
				$files["dir"][] = $dir."/libraries/".$library_name;
			}

			// Add the libraries' paths in the include paths in the command-line arguments
			if (file_exists("$dir/utility"))
				$include_directories .= " -I$dir/utility";
			foreach ($files["dir"] as $directory)
				$include_directories .= " -I$directory";
		}
		catch(\Exception $e)
		{
			return array("success" => false, "step" => 3, "message" => "Unknown Error:\n".$e->getMessage());
		}

		return array("success" => true);
	}

	private function doCompile(&$files, $dir, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories, $format)
	{
		if ($format == "syntax")
		{
			$CFLAGS .= " -fsyntax-only";
			$CPPFLAGS .= " -fsyntax-only";
		}

		foreach (array("c", "cpp", "S") as $ext)
		{
			foreach ($files[$ext] as $file)
			{
				// From hereon, $file is shell escaped and thus should only be used in calls
				// to exec().
				$file = escapeshellarg($file);

				//replace exec() calls with $this->utility->debug_exec() for debugging
				if ($ext == "c")
					exec("$CC $CFLAGS $core_includes $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
				elseif ($ext == "cpp")
					exec("$CPP $CPPFLAGS $core_includes $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
				elseif ($ext == "S")
					exec("$AS $ASFLAGS $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
				if (isset($ret_compile) && $ret_compile)
				{
					$avr_output = implode("\n", $output);
					unset($output);
					exec("$CLANG $CLANG_FLAGS $core_includes $clang_target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
					$output = str_replace("$dir/", "", $output); // XXX
					$output = $this->postproc->ansi_to_html(implode("\n", $output));
					return array(
						"success" => false,
						"step" => 4,
						"message" => $output,
						"debug" => $avr_output);
				}
				unset($output);

				$files["o"][] = array_shift($files[$ext]);
			}
		}

		return array("success" => true);
	}

	private function convertOutput($dir, $format, $SIZE, $SIZE_FLAGS, $OBJCOPY, $OBJCOPY_FLAGS, $OUTPUT, $start_time)
	{
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
				"message" => "There was a problem while generating the your binary file");
		else
			return array(
				"success" => true,
				"time" => microtime(true) - $start_time,
				"size" => $size[0],
				"output" => $content);

	}

	private function set_values($compiler_config,
	                            &$CC, &$CPP, &$AS, &$LD, &$CLANG, &$OBJCOPY, &$SIZE, &$CFLAGS, &$CPPFLAGS,
	                            &$ASFLAGS, &$LDFLAGS, &$LDFLAGS_TAIL, &$CLANG_FLAGS, &$OBJCOPY_FLAGS, &$SIZE_FLAGS,
	                            &$OUTPUT, &$ARDUINO_CORES_DIR, &$ARDUINO_SKEL)
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
	}

	private function set_variables($request, &$format, &$libraries, &$version, &$mcu, &$f_cpu, &$core, &$variant, &$vid, &$pid)
	{
		// Extract the request options for easier access.
		$format = $request->format;
		$libraries = $request->libraries;
		$version = $request->version;
		$mcu = $request->build->mcu;
		$f_cpu = $request->build->f_cpu;
		$core = $request->build->core;
		$variant = $request->build->variant;

		// Set the appropriate variables for vid and pid (Leonardo).
		$vid = ($variant == "leonardo") ? $request->build->vid : "";
		$pid = ($variant == "leonardo") ? $request->build->pid : "";
	}
}
