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
    function main($request, $compiler_config)
    {
        error_reporting(E_ALL & ~E_STRICT);

        $this->set_values($compiler_config,
            $CC, $CPP, $AS, $AR, $LD, $CLANG, $OBJCOPY, $SIZE, $CFLAGS, $CPPFLAGS, $ASFLAGS, $ARFLAGS, $LDFLAGS, $LDFLAGS_TAIL,
            $CLANG_FLAGS, $OBJCOPY_FLAGS, $SIZE_FLAGS, $OUTPUT, $ARDUINO_CORES_DIR, $TEMP_DIR);

        $start_time = microtime(true);

        // Step 0: Reject the request if the input data is not valid.
        //TODO: Replace $tmp variable name
        $tmp = $this->requestValid($request);
        if($tmp["success"] === false)
            return $tmp;

        $this->set_variables($request, $format, $libraries, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);

        $target_arch = "-mmcu=$mcu -DARDUINO=$version -DF_CPU=$f_cpu -DUSB_VID=$vid -DUSB_PID=$pid";
        $clang_target_arch = "-D".MCUHandler::$MCU[$mcu]." -DARDUINO=$version -DF_CPU=$f_cpu";

        // Step 1(part 1): Extract the project files included in the request.
        $files = array();
        $tmp = $this->extractFiles($request["files"], $TEMP_DIR, $compiler_dir, $files["sketch_files"], "files");

        if ($tmp["success"] == false)
            return $tmp;

        // Step 1(part 2): Extract the library files included in the request.
        $files["libs"] = array();
        foreach($libraries as $library => $library_files){

            $tmp = $this->extractFiles($library_files, $TEMP_DIR, $compiler_dir, $files["libs"][$library], "libraries/$library", true);
            if ($tmp["success"] == false)
                return $tmp;
        }

        //Set logging to true if requested, and create the directory where logfiles are stored.
        //TODO: Replace $tmp variable name
        $tmp = $this->setLoggingParams($request, $compiler_config, $TEMP_DIR, $compiler_dir);
        if($tmp["success"] === false)
            return $tmp;

        // Step 2: Preprocess Arduino source files.
        $tmp = $this->preprocessIno($files["sketch_files"]);
        if ($tmp["success"] == false)
            return $tmp;

        // Step 3: Preprocess Header includes.
        $tmp = $this->preprocessHeaders($libraries, $include_directories, $compiler_dir, $ARDUINO_CORES_DIR, $version, $core, $variant);
        if ($tmp["success"] == false)
            return $tmp;

        // Step 4: Syntax-check and compile source files.
        //Use the include paths for the AVR headers that are bundled with each Arduino SDK version
        $core_includes = " -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include-fixed -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/avr/include ";

        //handleCompile sets any include directories needed and calls the doCompile function, which does the actual compilation
        $ret = $this->handleCompile("$compiler_dir/files", $files["sketch_files"], $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories["main"], $format);

        $log_content = (($compiler_config['logging'] === true) ? @file_get_contents($compiler_config['logFileName']) : "");
        if ($compiler_config['logging'] === true){
            if ($log_content !== false)
                $ret["log"] = $log_content;
            else
                return array("success" => "false", "message" => "Failed to access logfile.");
        }
        if (!$ret["success"])
            return $ret;

        if ($format == "syntax")
            return array(
                "success" => true,
                "time" => microtime(true) - $start_time);

        //Keep all object files urls needed for linking.
        $objects_to_link = $files["sketch_files"]["o"];

        //TODO: return objects if more than one file??
        if ($format == "object")
        {
            $content = base64_encode(file_get_contents($files["sketch_files"]["o"][0].".o"));
            if (count($files["sketch_files"]["o"]) != 1 || !$content){
                if ($compiler_config['logging'] === false)
                    return array(
                        "success" => false,
                        "step" => -1, //TODO: Fix this step?
                        "message" => "");
                else
                    return array(
                        "success" => false,
                        "step" => -1, //TODO: Fix this step?
                        "message" => "",
                        "log" => $log_content);
            }
            else{
                if ($compiler_config['logging'] === false)
                    return array(
                        "success" => true,
                        "time" => microtime(true) - $start_time,
                        "output" => $content);
                else
                    return array(
                        "success" => true,
                        "time" => microtime(true) - $start_time,
                        "output" => $content,
                        "log" => $log_content);
            }
        }

        // Step 5: Create objects for core files (if core file does not already exist)
        //Link all core object files to a core.a library.
        $core_dir = "$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core";
        //TODO: Figure out why Symfony needs "@" to suppress mkdir wanring
        if(!file_exists($this->object_directory))
            if(!@mkdir($this->object_directory)){
                if ($compiler_config['logging'] === false)
                    return array(
                        "success" => false,
                        "step" => 5,
                        "message" => "Could not create object files directory.");
                else
                    return array(
                        "success" => false,
                        "step" => 5,
                        "message" => "Could not create object files directory.",
                        "log" => $log_content);
            }
        //Generate full pathname of the cores library and then check if the library exists.
        $core_library = $this->object_directory ."/". pathinfo(str_replace("/", "__", $core_dir."_"), PATHINFO_FILENAME)."_______"."${mcu}_${f_cpu}_${core}_${variant}".(($variant == "leonardo") ? "_${vid}_${pid}" : "")."_______"."core.a";

        if(!file_exists($core_library)){

            //makeCoresTmp scans the core files directory and return list including the urls of the files included there.
            $tmp = $this->makeCoresTmp($core_dir, $TEMP_DIR, $compiler_dir, $files);

            if(!$tmp["success"]){
                if ($compiler_config['logging'] === false)
                    return $tmp;
                else{
                    $tmp["log"] = $log_content;
                    return $tmp;
                }
            }

            $ret = $this->handleCompile("$compiler_dir/core", $files["core"], $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories["core"], "object");

            $log_content = (($compiler_config['logging'] === true) ? @file_get_contents($compiler_config['logFileName']) : "");

            if(!$ret["success"]){
                if ($compiler_config['logging'] === true){
                    if ($log_content !== false){
                        $ret["log"] = $log_content;
                    }
                    else
                        return array("success" => "false", "message" => "Failed to access logfile.");
                }
                return $ret;
            }

            foreach($files["core"]["o"] as $core_object){
                //Link object file to library.
                exec("$AR $ARFLAGS $core_library $core_object.o", $output);

                if($compiler_config['logging'])
                    file_put_contents($compiler_config['logFileName'], "$AR $ARFLAGS $core_library $core_object.o"."\n", FILE_APPEND);
            }
        }


        // Step 6: Create objects for libraries.
        foreach ($files["libs"] as $library_name => $library_files){

            //The elements of the "build" array are needed to build the unique name of every library object file.
            $lib_object_naming_params = $request["build"];
            $lib_object_naming_params["library"] = $library_name;

            $ret = $this->handleCompile("$compiler_dir/libraries/$library_name", $files["libs"][$library_name], $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories["main"], $format, true, $lib_object_naming_params);

            $log_content = (($compiler_config['logging'] === true) ? @file_get_contents($compiler_config['logFileName']) : "");
            if ($compiler_config['logging'] === true){
                if ($log_content !== false)
                    $ret["log"] = $log_content;
                else
                    return array("success" => "false", "message" => "Failed to access logfile.");
            }

            if(!$ret["success"])
                return $ret;

            $objects_to_link = array_merge($objects_to_link, $files["libs"][$library_name]["o"]);
        }

        // Step 7: Link all object files and create executable.
        $object_files = "";
        foreach ($objects_to_link as $object)
            $object_files .= " ".escapeshellarg("$object.o");

        //Link core.a and every other object file to a .elf binary file
        exec("$LD $LDFLAGS $target_arch $object_files $core_library -o $compiler_dir/files/$OUTPUT.elf $LDFLAGS_TAIL 2>&1", $output, $ret_link);
        if($compiler_config['logging']){
            file_put_contents($compiler_config['logFileName'], "$LD $LDFLAGS $target_arch $object_files $core_library -o $compiler_dir/files/$OUTPUT.elf $LDFLAGS_TAIL\n", FILE_APPEND);
        }

        if ($ret_link){
            $returner =array(
                "success" => false,
                "step" => 7,
                "message" => implode("\n", $output));
            if ($compiler_config['logging'] === false)
                return $returner;
            else{
                $log_content = @file_get_contents($compiler_config['logFileName']);
                if (!$log_content)
                    return array("success" => "false", "message" => "Failed to access logfile.");
                else
                    return array_merge($returner, array("log" => $log_content));
            }
        }

        // Step 8: Convert the output to the requested format and measure its
        // size.
        $tmp = $this->convertOutput("$compiler_dir/files", $format, $SIZE, $SIZE_FLAGS, $OBJCOPY, $OBJCOPY_FLAGS, $OUTPUT, $start_time, $compiler_config);

        if ($compiler_config['logging'] === false)
            return $tmp;
        else{
            $log_content = @file_get_contents($compiler_config['logFileName']);
            if (!$log_content)
                return array("success" => "false", "message" => "Failed to access logfile.");
            else
                return array_merge($tmp, array("log" => $log_content));
        }

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

    private function extractFiles($request, $temp_dir, &$dir, &$files, $suffix, $lib_extraction = false)
    {
        // Create a temporary directory to place all the files needed to process
        // the compile request. This directory is created in $TMPDIR or /tmp by
        // default and is automatically removed upon execution completion.
        if(!$dir)
            $dir = System::mktemp("-t $temp_dir/ -d compiler.");

        if (!$dir)
            return array(
                "success" => false,
                "step" => 1,
                "message" => "Failed to create temporary directory.");

        $response = $this->utility->extract_files("$dir/$suffix", $request, $lib_extraction);

        if ($response["success"] === false)
            return $response;
        $files = $response["files"];

        return array("success" => true);
    }

    private function preprocessIno(&$files)
    {
        foreach ($files["ino"] as $file)
        {
            $code = file_get_contents("$file.ino");
            $new_code = $this->preproc->ino_to_cpp($code, "$file.ino");
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

    public function preprocessHeaders($libraries, &$include_directories, $dir, $ARDUINO_CORES_DIR, $version, $core, $variant)
    {
        try
        {
            // Create command-line arguments for header search paths. Note that the
            // current directory is added to eliminate the difference between <>
            // and "" in include preprocessor directives.
            //TODO: make it compatible with non-default hardware (variants & cores)
            $include_directories = array();
            $include_directories["core"] = " -I$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core -I$ARDUINO_CORES_DIR/v$version/hardware/arduino/variants/$variant";

            $include_directories["main"] = $include_directories["core"];
            foreach ($libraries as $library_name => $library_files)
                $include_directories["main"] .= " -I$dir/libraries/$library_name";
        }
        catch(\Exception $e)
        {
            return array("success" => false, "step" => 3, "message" => "Unknown Error:\n".$e->getMessage());
        }

        return array("success" => true);
    }

    private function doCompile($compiler_config, &$files, $dir, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories, $format, $caching = false, $name_params = null)
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
                if($caching){
                    $object_filename = "$this->object_directory/${name_params['mcu']}_${name_params['f_cpu']}_${name_params['core']}_${name_params['variant']}".(($name_params['variant'] == "leonardo") ? "_${name_params['vid']}_${name_params['pid']}" : "")."______${name_params['library']}_______".((pathinfo(pathinfo($file, PATHINFO_DIRNAME), PATHINFO_FILENAME) == "utility") ? "utility_______" : "") .pathinfo($file, PATHINFO_FILENAME);
                    $object_file = $object_filename;
                }
                else
                    $object_file = $file;
                if(!file_exists("$object_file.o")){
                    // From hereon, $file is shell escaped and thus should only be used in calls
                    // to exec().
                    $file = escapeshellarg($file);
                    $object_file = escapeshellarg($object_file);

                    //replace exec() calls with $this->utility->debug_exec() for debugging
                    if ($ext == "c")
                    {
                        exec("$CC $CFLAGS $core_includes $target_arch $include_directories -c -o $object_file.o $file.$ext 2>&1", $output, $ret_compile);
                        if($compiler_config['logging']){
                            file_put_contents($compiler_config['logFileName'],"$CC $CFLAGS $core_includes $target_arch $include_directories -c -o $object_file.o $file.$ext\n", FILE_APPEND);
                        }
                    }
                    elseif ($ext == "cpp")
                    {
                        exec("$CPP $CPPFLAGS $core_includes $target_arch -MMD $include_directories -c -o $object_file.o $file.$ext 2>&1", $output, $ret_compile);
                        if($compiler_config['logging']){
                            file_put_contents($compiler_config['logFileName'],"$CPP $CPPFLAGS $core_includes $target_arch -MMD $include_directories -c -o $object_file.o $file.$ext\n", FILE_APPEND);
                        }
                    }
                    elseif ($ext == "S")
                    {
                        exec("$AS $ASFLAGS $target_arch $include_directories -c -o $object_file.o $file.$ext 2>&1", $output, $ret_compile);
                        if($compiler_config['logging']){
                            file_put_contents($compiler_config['logFileName'],"$AS $ASFLAGS $target_arch $include_directories -c -o $object_file.o $file.$ext\n", FILE_APPEND);
                        }
                    }
                    if (isset($ret_compile) && $ret_compile)
                    {
                        $avr_output = implode("\n", $output);
                        unset($output);
                        exec("$CLANG $CLANG_FLAGS $core_includes $clang_target_arch $include_directories -c -o $object_file.o $file.$ext 2>&1", $output, $ret_compile);
                        if($compiler_config['logging']){
                            file_put_contents($compiler_config['logFileName'],"$CLANG $CLANG_FLAGS $core_includes $clang_target_arch $include_directories -c -o $object_file.o $file.$ext\n", FILE_APPEND);
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
                }

                if(!$caching){
                    $files["o"][] = array_shift($files[$ext]);
                }
                else{
                    $files["o"][] = $object_filename;
                    array_shift($files[$ext]);
                }
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
                                &$OUTPUT, &$ARDUINO_CORES_DIR, &$TEMP_DIR)
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
        // The tmp folder where logfiles and object files are placed
        $TEMP_DIR = $compiler_config["temp_dir"];
        // Path to arduino-core-files repository.
        $ARDUINO_CORES_DIR = $compiler_config["arduino_cores_dir"];
    }

    private function set_variables($request, &$format, &$libraries, &$version, &$mcu, &$f_cpu, &$core, &$variant, &$vid, &$pid)
    {
        // Extract the request options for easier access.
        $format = $request["format"];
        $libraries = $request["libraries"];
        $version = $request["version"];
        $mcu = $request["build"]["mcu"];
        $f_cpu = $request["build"]["f_cpu"];
        $core = $request["build"]["core"];
        $variant = $request["build"]["variant"];

        // Set the appropriate variables for vid and pid (Leonardo).
        $vid = ($variant == "leonardo") ? $request["build"]["vid"] : "null";
        $pid = ($variant == "leonardo") ? $request["build"]["pid"] : "null";
    }

    private function setLoggingParams($request, &$compiler_config, $temp_dir, $compiler_dir)
    {
        //Check if $request['logging'] exists and is true, then make the logfile, otherwise set
        //$compiler_config['logdir'] to false and return to caller
        if(array_key_exists('logging', $request) && $request['logging'])
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

            foreach($request['files'] as $file){
                if(strcmp(pathinfo($file['filename'], PATHINFO_EXTENSION), "ino") == 0){$basename = pathinfo($file['filename'], PATHINFO_FILENAME);}
            }
            if(!isset($basename)){$basename="logfile";}

            $compiler_config['logging'] = true;
            $directory = $temp_dir."/".$compiler_config['logdir'];
            if(!file_exists($directory))
                if(!@mkdir($directory, 0777, true))
                    return array("success"=>false, "message"=>"Failed to create logfiles directory.");

            $compiler_part = str_replace(".", "_", substr($compiler_dir, strpos($compiler_dir, "compiler")));

            $compiler_config['logFileName'] = $directory ."/". $basename ."_".$compiler_part."_". $randPart .".txt";

            file_put_contents($compiler_config['logFileName'], '');
        }
        elseif(!array_key_exists('logging', $request) or !$request['logging'])
            $compiler_config['logging'] = false;

        return array("success"=>true);
    }


    /**
    \brief Reads all core files from the respective directory and passes their contents to extractFiles function
    which then rights them to the compiler temp directory


    \param string $core_files_directory The directory containing the core files.
    \param string $tmp_compiler The tmp directory where the actual compilation process takes place.
    \return array An array containing the function results.
     */
    private function makeCoresTmp($core_files_directory, $temp_directory, $tmp_compiler, &$files){

        $core = array();
        if(false === ($scanned_files = @scandir($core_files_directory)))
            return array( "success"=>false, "step"=>5, "message"=>"Failed to read core files." );

        // Get the contents of the core files
        foreach ($scanned_files as $core_file)
            if(!is_dir("$core_files_directory/$core_file"))
                $core[] = array("filename" => $core_file, "content" => file_get_contents("$core_files_directory/$core_file"));

        // Check if the version of the core files includes an avr-libc directory and scan
        if(file_exists("$core_files_directory/avr-libc")){
            if(false === ($scanned_avr_files = @scandir("$core_files_directory/avr-libc")))
                return array( "success"=>false, "step"=>5, "message"=>"Failed to read core files." );
            foreach($scanned_avr_files as $avr_file)
                if(!is_dir("$core_files_directory/avr-libc/$avr_file"))
                    $core[] = array("filename" => "avr-libc/$avr_file", "content" => file_get_contents("$core_files_directory/avr-libc/$avr_file"));
        }


        $tmp = $this->extractFiles($core, $temp_directory, $tmp_compiler, $files["core"], "core");
        if($tmp["success"] === false)
            return array( "success"=>false, "step"=>5, "message"=>$tmp["message"] );

        return array('success' => true);
    }


    private function handleCompile($compile_directory, &$files_array, $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories, $format, $caching = false, $name_params = null){

        //Add any include directories needed
        $include_directories .= " -I$compile_directory ";

        if(file_exists("$compile_directory/utility"))
            $include_directories .= " -I$compile_directory/utility ";

        if (file_exists("$compile_directory/avr-libc"))
            $include_directories .= " -I$compile_directory/avr-libc ";


        //Call doCompile, which will do the actual compilation.
        $compile_res = $this->doCompile($compiler_config, $files_array, $compile_directory, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories, $format, $caching, $name_params);

        if($compile_res['success'])
            return array("success" => true);

        return $compile_res;
    }

}
