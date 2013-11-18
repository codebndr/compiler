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
    private $object_directory;

	function __construct(PreprocessingHandler $preprocHandl, PostprocessingHandler $postprocHandl, UtilityHandler $utilHandl, $objdir)
	{
		$this->preproc = $preprocHandl;
		$this->postproc = $postprocHandl;
		$this->utility = $utilHandl;
        $this->object_directory = $objdir;
	}

	/**
	\brief Processes a compile request.

	\param string $request The body of the POST request.
	\return A message to be JSON-encoded and sent back to the requestor.
	 */
	function main($request, $compiler_config, $initCall)
	{
		error_reporting(E_ALL & ~E_STRICT);

		$this->set_values($compiler_config,
			$CC, $CPP, $AS, $AR, $LD, $CLANG, $OBJCOPY, $SIZE, $CFLAGS, $CPPFLAGS, $ASFLAGS, $ARFLAGS, $LDFLAGS, $LDFLAGS_TAIL,
			$CLANG_FLAGS, $OBJCOPY_FLAGS, $SIZE_FLAGS, $OUTPUT, $ARDUINO_CORES_DIR, $ARDUINO_SKEL);

		$start_time = microtime(true);

		// Step 0: Reject the request if the input data is not valid.
		//TODO: Replace $tmp variable name
		$tmp = $this->requestValid($request);
		if($tmp["success"] == false)
			return $tmp;
		
		/*
			$initCall variable is a flag which is set to true if this instance of the compiler is called
			by the DefaultController. Then the logging parameters need to be set properly if the $request 
			demands so. When the compiler is called by the UtilityHandler to compile core or library files,
			$initCall is set to false
		*/
		if($initCall)	
			$this->setLoggingParams(json_encode($request), $compiler_config);
		
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

		$tmp = $this->doCompile($compiler_config, $files, $dir, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories, $format);
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

		
		//Link all core object files to a core.a library
		$core_dir = "$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core";
        //TODO: Figure out why Symfony needs "@" to suppress mkdir wanring
        if(!file_exists($this->object_directory))
            if(!@mkdir($this->object_directory)){
                return array(
                    "success" => false,
                    "step" => 5,
                    "message" => "Could not create object files directory.");
            }
		$core_name = $this->object_directory ."/". pathinfo(str_replace("/", "__", $core_dir."_"), PATHINFO_FILENAME)."_______"."${mcu}_${f_cpu}_${core}_${variant}".(($variant == "leonardo") ? "_${vid}_${pid}" : "")."_______"."core.a";
		
		if(!file_exists($core_name)){
		
		// Step 5: Create objects for core files.
		//TODO: make it compatible with non-default hardware (variants & cores)
		$core_objects = $this->create_objects($compiler_config, $core_dir, $ARDUINO_SKEL, false, true, array(), $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);
		//TODO: Upgrade this
		if (array_key_exists("success", $core_objects))
			return $core_objects;
		/*
		the line bellow had to be commented so that the core object files will not be linked again to the 
		output file in step 7
		*/
		//$files["o"] = array_merge($files["o"], $core_objects);
		
		
			foreach($core_objects as $core_obj){
					exec("$AR $ARFLAGS $core_name $core_obj.o", $output);
					//Remove object file, since its contents are now linked to the core.a file
					if(file_exists("$core_obj.o"))
                        unlink("$core_obj.o");
					if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'], "$AR $ARFLAGS $core_name $core_obj.o"."\n", FILE_APPEND);
					}
			}
		}
		
		
		// Step 6: Create objects for libraries.
		foreach ($files["dir"] as $directory)
		{
			$library_objects = $this->create_objects($compiler_config, $directory, NULL, true, false, $libraries, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);
			//TODO: Upgrade this
			if (array_key_exists("success", $library_objects))
				return $library_objects;
			$files["o"] = array_merge($files["o"], $library_objects);
		}

		// Step 7: Link all object files and create executable.
		$object_files = "";
		foreach ($files["o"] as $object)
			$object_files .= " ".escapeshellarg("$object.o");

		//Link core.a and every other object file to a .elf binary file
		exec("$LD $LDFLAGS $target_arch $object_files $core_name -o $dir/$OUTPUT.elf $LDFLAGS_TAIL 2>&1", $output, $ret_link);
		if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'], "$LD $LDFLAGS $target_arch $object_files $core_name -o $dir/$OUTPUT.elf $LDFLAGS_TAIL\n", FILE_APPEND);
					}
		if ($ret_link)
			return array(
				"success" => false,
				"step" => 7,
				"message" => implode("\n", $output));

		// Step 8: Convert the output to the requested format and measure its
		// size.
		$tmp = $this->convertOutput($dir, $format, $SIZE, $SIZE_FLAGS, $OBJCOPY, $OBJCOPY_FLAGS, $OUTPUT, $start_time, $compiler_config);
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
			$tmp = $this->utility->extract_files($dir."/libraries/".$library_name, $library_files);
			$files["libs"][] = $tmp["files"];
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

	private function doCompile($compiler_config, &$files, $dir, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories, $format)
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
					{
					exec("$CC $CFLAGS $core_includes $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
					if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'],"$CC $CFLAGS $core_includes $target_arch $include_directories -c -o $file.o $file.$ext\n", FILE_APPEND);
						}
					}
				elseif ($ext == "cpp")
					{
					exec("$CPP $CPPFLAGS $core_includes $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
					if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'],"$CPP $CPPFLAGS $core_includes $target_arch $include_directories -c -o $file.o $file.$ext\n", FILE_APPEND);
						}
					}
				elseif ($ext == "S")
					{
					exec("$AS $ASFLAGS $target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
					if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'],"$AS $ASFLAGS $target_arch $include_directories -c -o $file.o $file.$ext\n", FILE_APPEND);
						}
					}
				if (isset($ret_compile) && $ret_compile)
				{
					$avr_output = implode("\n", $output);
					unset($output);
					exec("$CLANG $CLANG_FLAGS $core_includes $clang_target_arch $include_directories -c -o $file.o $file.$ext 2>&1", $output, $ret_compile);
					if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'],"$CLANG $CLANG_FLAGS $core_includes $clang_target_arch $include_directories -c -o $file.o $file.$ext\n", FILE_APPEND);
						}
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

	private function convertOutput($dir, $format, $SIZE, $SIZE_FLAGS, $OBJCOPY, $OBJCOPY_FLAGS, $OUTPUT, $start_time, $compiler_config)
	{
		if ($format == "elf")
		{
			$ret_objcopy = false;
			exec("$SIZE $SIZE_FLAGS --target=elf32-avr $dir/$OUTPUT.elf | awk 'FNR == 2 {print $1+$2}'", $size, $ret_size); // FIXME
			
			if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'],"$SIZE $SIZE_FLAGS --target=elf32-avr $dir/$OUTPUT.elf | awk 'FNR == 2 {print $1+$2}'\n", FILE_APPEND);
						}
			$content = base64_encode(file_get_contents("$dir/$OUTPUT.elf"));
		}
		elseif ($format == "binary")
		{
			exec("$OBJCOPY $OBJCOPY_FLAGS -O binary $dir/$OUTPUT.elf $dir/$OUTPUT.bin", $dummy, $ret_objcopy);
			if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'],"$OBJCOPY $OBJCOPY_FLAGS -O binary $dir/$OUTPUT.elf $dir/$OUTPUT.bin\n", FILE_APPEND);
						}
						
			exec("$SIZE $SIZE_FLAGS --target=binary $dir/$OUTPUT.bin | awk 'FNR == 2 {print $1+$2}'", $size, $ret_size); // FIXME
			if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'],"$SIZE $SIZE_FLAGS --target=binary $dir/$OUTPUT.bin | awk 'FNR == 2 {print $1+$2}'\n", FILE_APPEND);
						}
			$content = base64_encode(file_get_contents("$dir/$OUTPUT.bin"));
		}
		elseif ($format == "hex")
		{
			exec("$OBJCOPY $OBJCOPY_FLAGS -O ihex $dir/$OUTPUT.elf $dir/$OUTPUT.hex", $dummy, $ret_objcopy);
			if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'],"$OBJCOPY $OBJCOPY_FLAGS -O ihex $dir/$OUTPUT.elf $dir/$OUTPUT.hex\n", FILE_APPEND);
						}
			
			exec("$SIZE $SIZE_FLAGS --target=ihex $dir/$OUTPUT.hex | awk 'FNR == 2 {print $1+$2}'", $size, $ret_size); // FIXME
			if($compiler_config['logging']){
						file_put_contents($compiler_config['logFileName'],"$SIZE $SIZE_FLAGS --target=ihex $dir/$OUTPUT.hex | awk 'FNR == 2 {print $1+$2}'\n", FILE_APPEND);
						}
			
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
	                            &$CC, &$CPP, &$AS, &$AR, &$LD, &$CLANG, &$OBJCOPY, &$SIZE, &$CFLAGS, &$CPPFLAGS,
	                            &$ASFLAGS, &$ARFLAGS, &$LDFLAGS, &$LDFLAGS_TAIL, &$CLANG_FLAGS, &$OBJCOPY_FLAGS, &$SIZE_FLAGS,
	                            &$OUTPUT, &$ARDUINO_CORES_DIR, &$ARDUINO_SKEL)
	{
		// External binaries.
		$CC = $compiler_config["cc"];
		$CPP = $compiler_config["cpp"];
		$AS = $compiler_config["as"];
		$AR = $compiler_config["ar"];
		$LD = $compiler_config["ld"];
		$CLANG = $compiler_config["clang"];
		$OBJCOPY = $compiler_config["objcopy"];
		$SIZE = $compiler_config["size"];
		// Standard command-line arguments used by the binaries.
		$CFLAGS = $compiler_config["cflags"];
		$CPPFLAGS = $compiler_config["cppflags"];
		$ASFLAGS = $compiler_config["asflags"];
		$ARFLAGS = $compiler_config["arflags"];
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
		$vid = ($variant == "leonardo") ? $request->build->vid : "null";
		$pid = ($variant == "leonardo") ? $request->build->pid : "null";
	}
	
	private function setLoggingParams($request, &$compiler_config)
	{
		$temp = json_decode($request,true);
		//Check if $request['logging'] exists and is true, then make the logfile, otherwise set
		//$compiler_config['logdir'] to false and return to caller
		if(array_key_exists('logging', $temp) && $temp['logging'])
		{
			/*
			Generate a random part for the log name based on current date and time,
			in order to avoid naming different Blink projects for which we need logfiles
			*/
			$randPart = date('YmdHis');
			/*
			Then find the name of the arduino file which usually is the project name itself 
			and mix them all together
			*/
			
			foreach($temp['files'] as $file){
				if(strcmp(pathinfo($file['filename'], PATHINFO_EXTENSION), "ino") == 0){$basename = pathinfo($file['filename'], PATHINFO_FILENAME);}
			}
			if(!isset($basename)){$basename="logfile";}
			
			$compiler_config['logging'] = true;
			$directory = $compiler_config['logdir'];
			if(!file_exists($directory)){mkdir($directory);}
			
			$compiler_config['logFileName'] = $directory ."/". $basename ."_". $randPart .".txt";
			
			file_put_contents($compiler_config['logFileName'], '');
		}
		elseif(!array_key_exists('logging', $temp) or !$temp['logging'])
		{
			$compiler_config['logging'] = false;
		}
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

    function create_objects($compiler_config, $directory, $exclude_files, $send_headers, $libc_headers, $libraries, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid)
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
            "libraries" => $libraries,
            "build" => array(
                "mcu" => $mcu,
                "f_cpu" => $f_cpu,
                "core" => $core,
                "variant" => $variant,
                "vid" => $vid,
                "pid" => $pid));

        $object_files = array();
        $sources = $this->utility->get_files_by_extension($directory, array("c", "cpp", "S"));

        if (file_exists("$directory/utility"))
        {
            $utility_sources = $this->utility->get_files_by_extension("$directory/utility", array("c", "cpp", "S"));
            foreach ($utility_sources as &$i)
                $i = "utility/$i";
            unset($i);
            $sources = array_merge($sources, $utility_sources);
        }

        if (file_exists("$directory/avr-libc") && $libc_headers)
        {
            $avr_libc_sources = $this->utility->get_files_by_extension("$directory/avr-libc", array("c", "cpp", "S"));
            foreach ($avr_libc_sources as &$i)
                $i = "avr-libc/$i";
            unset($i);
            $sources = array_merge($sources, $avr_libc_sources);
        }

        foreach ($sources as $filename)
        {
            // Do not proceed if this file should not be compiled.
            //TODO: Check if /tmp/codebender/ fix fucks this up
            if (isset($exclude) && preg_match("/(?:$exclude)/", pathinfo($filename, PATHINFO_BASENAME)))
                continue;

            // For every source file and set of build options there is a
            // corresponding object file. If that object is missing, a new
            // compile request is sent to the service.
            //TODO: Existing Library .o files will probably not be used right now (due to /tmp/compiler.random/ dir)
            //TODO: Investigate security issue
            $object_file = $this->object_directory."/".pathinfo(str_replace("/", "__", $directory."_"), PATHINFO_FILENAME)."_______"."${mcu}_${f_cpu}_${core}_${variant}".(($variant == "leonardo") ? "_${vid}_${pid}" : "")."_______".pathinfo(str_replace("/", "__", "$filename"), PATHINFO_FILENAME);
            if (!file_exists("$object_file.o"))
            {
                // Include any header files in the request.
                if ($send_headers && !array_key_exists("files", $request_template))
                {
                    $request_template["files"] = array();
                    $header_files = $this->utility->get_files_by_extension($directory, array("h", "inc"));

                    if (file_exists("$directory/utility"))
                    {
                        $utility_headers = $this->utility->get_files_by_extension("$directory/utility", array("h", "inc"));
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

                //Include header files from c library ( needed for malloc and realloc )
                if($libc_headers && !array_key_exists("files", $request_template))

                    if (file_exists("$directory/avr-libc"))
                    {
                        $request_template["files"] = array();
                        $header_files = array();

                        $avr_libc_headers = $this->utility->get_files_by_extension("$directory/avr-libc", array("h", "inc"));
                        foreach ($avr_libc_headers as &$i)
                            $i = "avr-libc/$i";
                        unset($i);
                        $header_files = array_merge($header_files, $avr_libc_headers);

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
                //$compiler = new CompilerHandler();
                $reply = $this->main(json_encode($request), $compiler_config, false);

                if ($reply["success"] == false)
                    return array(
                        "success" => false,
                        "step" => 5,
                        "message" => $reply["message"],
                        "debug" => $request);

                //TODO: Figure out why Symfony needs "@" to suppress file_put_contents wanring
                if(!@file_put_contents("$object_file.o", base64_decode($reply["output"]))){
                    return array(
                        "success" => false,
                        "step" => 5,
                        "message" => "Could not create one of the object files.");
                }
            }

            $object_files[] = $object_file;
        }

        // All object files created successfully.
        return $object_files;
    }

}
