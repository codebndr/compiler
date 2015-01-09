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
use Doctrine\Tests\ORM\Functional\ManyToManyBidirectionalAssociationTest;
use System;
use Codebender\CompilerBundle\Handler\MCUHandler;
use Symfony\Bridge\Monolog\Logger;

class CompilerHandler
{
    private $preproc;
    private $postproc;
    private $utility;
    private $compiler_logger;
    private $object_directory;
    private $logger_id;

    function __construct(PreprocessingHandler $preprocHandl, PostprocessingHandler $postprocHandl, UtilityHandler $utilHandl, Logger $logger, $objdir)
    {
        $this->preproc = $preprocHandl;
        $this->postproc = $postprocHandl;
        $this->utility = $utilHandl;
        $this->compiler_logger = $logger;
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
            $BINUTILS, $CLANG, $CFLAGS, $CPPFLAGS, $ASFLAGS, $ARFLAGS, $LDFLAGS, $LDFLAGS_TAIL,
            $CLANG_FLAGS, $OBJCOPY_FLAGS, $SIZE_FLAGS, $OUTPUT, $ARDUINO_CORES_DIR, $EXTERNAL_CORES_DIR,
			$TEMP_DIR, $ARCHIVE_DIR, $AUTOCC_DIR, $PYTHON, $AUTOCOMPLETER);

        $start_time = microtime(true);

        // Step 0: Reject the request if the input data is not valid.
        //TODO: Replace $tmp variable name
        $tmp = $this->requestValid($request);
        if($tmp["success"] === false)
            return $tmp;

        $this->set_variables($request, $format, $libraries, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid, $compiler_config);

        $this->set_avr($version, $ARDUINO_CORES_DIR, $BINUTILS, $CC, $CPP, $AS, $AR, $LD, $OBJCOPY, $SIZE);

        $target_arch = "-mmcu=$mcu -DARDUINO=$version -DF_CPU=$f_cpu -DUSB_VID=$vid -DUSB_PID=$pid";
        $clang_target_arch = "-D".MCUHandler::$MCU[$mcu]." -DARDUINO=$version -DF_CPU=$f_cpu";
		$autocc_clang_target_arch = "-D".MCUHandler::$MCU[$mcu]." -DARDUINO=$version -DF_CPU=$f_cpu -DUSB_VID=$vid -DUSB_PID=$pid";

        // Step 1(part 1): Extract the project files included in the request.
        $files = array();
        $tmp = $this->extractFiles($request["files"], $TEMP_DIR, $compiler_dir, $files["sketch_files"], "files");

        if ($tmp["success"] === false)
            return $tmp;

        // Add the compiler temp directory to the compiler_config struct.
        $compiler_config["compiler_dir"] = $compiler_dir;

        // Step 1(part 2): Extract the library files included in the request.
        $files["libs"] = array();
        foreach($libraries as $library => $library_files){

            $tmp = $this->extractFiles($library_files, $TEMP_DIR, $compiler_dir, $files["libs"][$library], "libraries/$library", true);
            if ($tmp["success"] === false)
                return $tmp;
        }

        if (!array_key_exists("archive", $request) || ($request["archive"] !== false && $request["archive"] !== true))
            $ARCHIVE_OPTION = false;
        else
            $ARCHIVE_OPTION = $request["archive"];
        //return array("success" => false, "archive option" => $ARCHIVE_OPTION);
        if ($ARCHIVE_OPTION === true){
            $arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
            if ($arch_ret["success"] === false)
                return $arch_ret;
        }

        //Set logging to true if requested, and create the directory where logfiles are stored.
        //TODO: Replace $tmp variable name
        $tmp = $this->setLoggingParams($request, $compiler_config, $TEMP_DIR, $compiler_dir);
        if($tmp["success"] === false)
            return array_merge($tmp, ($ARCHIVE_OPTION ===true) ? array("archive" => $ARCHIVE_PATH) : array());

        // Step 2: Preprocess Arduino source files.
        $tmp = $this->preprocessIno($files["sketch_files"]);
        if ($tmp["success"] == false)
            return array_merge($tmp, ($ARCHIVE_OPTION ===true) ? array("archive" => $ARCHIVE_PATH) : array());

        // Step 3: Preprocess Header includes and determine which core files directory(CORE_DIR) will be used.
        $tmp = $this->preprocessHeaders($libraries, $include_directories, $compiler_dir, $ARDUINO_CORES_DIR, $EXTERNAL_CORES_DIR, $CORE_DIR, $CORE_OVERRIDE_DIR, $version, $core, $variant);
        if ($tmp["success"] === false)
            return array_merge($tmp, ($ARCHIVE_OPTION ===true) ? array("archive" => $ARCHIVE_PATH) : array());

        // Log the names of the project files and the libraries used in it.
        if ($format != "autocomplete") {
            $user_id = $sketch_id = "null";
            $req_elements = array("Files: ");

            foreach ($request["files"] as $file) {
                $req_elements[] = $file["filename"];
                if (strpos($file["filename"], ".txt") !== false) {
                    if (preg_match('/(?<=user_)[\d]+/', $file['filename'], $match)) $user_id = $match[0];
                    if (preg_match('/(?<=project_)[\d]+/', $file['filename'], $match)) $sketch_id = $match[0];

                }
            }

            if ($request["libraries"]) {
                $req_elements[] = "Libraries: ";
                foreach ($request["libraries"] as $libname => $libfiles) {
                    foreach ($libfiles as $libfile)
                        $req_elements[] = $libname . "/" . $libfile["filename"];
                }
            }

            $this->logger_id = microtime(true) . "_" . substr($compiler_config['compiler_dir'], -6) . "_user:$user_id" . "_project:$sketch_id";

            $this->compiler_logger->addInfo($this->logger_id . " - " . implode(" ", $req_elements));
            if ($ARCHIVE_OPTION === true)
                $this->compiler_logger->addInfo($this->logger_id . " - " . "Archive file: $ARCHIVE_PATH");
        }

        // Step 4: Syntax-check and compile source files.
        //Use the include paths for the AVR headers that are bundled with each Arduino SDK version
        //These may differ between linux and MAC OS versions of the Arduino core files, so check before including
        $core_includes = "";
        if (file_exists("$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include"))
            $core_includes .= " -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include";
        if (file_exists("$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include-fixed"))
            $core_includes .= " -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/gcc/avr/4.3.2/include-fixed";
        if (file_exists("$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/avr/include"))
            $core_includes .= " -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/avr/include ";
        elseif (file_exists("$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/avr/include"))
            $core_includes .= " -I$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/lib/avr/include ";

		if ($format == "autocomplete"){
			$autocompleteRet = $this->handleAutocompletion($ARDUINO_CORES_DIR, "$compiler_dir/files", $include_directories["main"], $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $core_includes, $autocc_clang_target_arch, $TEMP_DIR, $AUTOCC_DIR, $PYTHON, $AUTOCOMPLETER);

			if ($ARCHIVE_OPTION === true){
				$arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
				if ($arch_ret["success"] === false)
					return $arch_ret;
			}
			return array_merge($autocompleteRet, array("total_compiler_exec_time" => microtime(true) - $start_time));
		}

        //handleCompile sets any include directories needed and calls the doCompile function, which does the actual compilation
        $ret = $this->handleCompile("$compiler_dir/files", $files["sketch_files"], $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories["main"], $format);

        $log_content = (($compiler_config['logging'] === true) ? @file_get_contents($compiler_config['logFileName']) : "");
        if ($compiler_config['logging'] === true){
            if ($log_content !== false) {
                $ret["log"] = $log_content;
                file_put_contents($compiler_config["compiler_dir"] . "/log", $log_content);
            }
            else
                $ret["log"] = "Failed to access logfile.";
        }

        if ($ARCHIVE_OPTION === true){
            $arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
            if ($arch_ret["success"] === false)
                $ret["archive"] = $arch_ret["message"];
            else
                $ret["archive"] = $ARCHIVE_PATH;
        }

        if (!$ret["success"])
            return $ret;

        if ($format == "syntax")
            return array_merge(array(
                    "success" => true,
                    "time" => microtime(true) - $start_time),
                    ($ARCHIVE_OPTION ===true) ? array("archive" => $ret["archive"]) : array(),
                    ($compiler_config['logging'] === true) ? array("log" => $ret["log"]) : array());

        //Keep all object files urls needed for linking.
        $objects_to_link = $files["sketch_files"]["o"];

        //TODO: return objects if more than one file??
        if ($format == "object")
        {
            $content = base64_encode(file_get_contents($files["sketch_files"]["o"][0].".o"));
            if (count($files["sketch_files"]["o"]) != 1 || !$content){
                return array_merge(array(
                        "success" => false,
                        "step" => -1, //TODO: Fix this step?
                        "message" => ""),
                        ($ARCHIVE_OPTION ===true) ? array("archive" => $ret["archive"]) : array(),
                        ($compiler_config['logging'] === true) ? array("log" => $ret["log"]) : array());
            }
            else
                return array_merge(array(
                        "success" => true,
                        "time" => microtime(true) - $start_time,
                        "output" => $content),
                        ($ARCHIVE_OPTION ===true) ? array("archive" => $ret["archive"]) : array(),
                        ($compiler_config['logging'] === true) ? array("log" => $ret["log"]) : array());
        }

        // Step 5: Create objects for core files (if core file does not already exist)
        //Link all core object files to a core.a library.

        //TODO: Figure out why Symfony needs "@" to suppress mkdir wanring
        if(!file_exists($this->object_directory)){
            //The code below was added to ensure that no error will be returned because of multithreaded execution.
            $make_dir_success = @mkdir($this->object_directory, 0777, true);
            if (!$make_dir_success && !is_dir($this->object_directory)) {
                usleep(rand( 5000 , 10000 ));
                $make_dir_success = @mkdir($this->object_directory, 0777, true);
            }
            if(!$make_dir_success){
                    return array_merge(array(
                            "success" => false,
                            "step" => 5,
                            "message" => "Could not create object files directory."),
                            ($ARCHIVE_OPTION ===true) ? array("archive" => $ret["archive"]) : array(),
                            ($compiler_config['logging'] === true) ? array("log" => $ret["log"]) : array());
            }
        }

        //Generate full pathname of the cores library and then check if the library exists.
        $core_library = $this->object_directory ."/". pathinfo(str_replace("/", "__", $CORE_DIR."_"), PATHINFO_FILENAME)."_______"."${mcu}_${f_cpu}_${core}_${variant}_${vid}_${pid}_______"."core.a";

        $lock = fopen("$core_library.LOCK", "w");

        flock($lock, LOCK_EX);
        if (!file_exists($core_library)){
            //makeCoresTmp scans the core files directory and return list including the urls of the files included there.
            $tmp = $this->makeCoresTmp($CORE_DIR, $CORE_OVERRIDE_DIR, $TEMP_DIR, $compiler_dir, $files);

            if(!$tmp["success"]){
                return array_merge($tmp,
                    ($ARCHIVE_OPTION ===true) ? array("archive" => $ret["archive"]) : array(),
                    ($compiler_config['logging'] === true) ? array("log" => $ret["log"]) : array());
            }

            $ret = $this->handleCompile("$compiler_dir/core", $files["core"], $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories["core"], "object");

            $log_content = (($compiler_config['logging'] === true) ? @file_get_contents($compiler_config['logFileName']) : "");

            if ($compiler_config['logging'] === true){
                if ($log_content !== false){
                    $ret["log"] = $log_content;
                    file_put_contents($compiler_config["compiler_dir"] . "/log", $log_content);
                }
                else
                    $ret["log"] = "Failed to access logfile.";
            }

            if ($ARCHIVE_OPTION === true){
                $arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
                if ($arch_ret["success"] === false)
                    $ret["archive"] = $arch_ret["message"];
                else
                    $ret["archive"] = $ARCHIVE_PATH;
            }

            if (!$ret["success"])
                return $ret;

            foreach ($files["core"]["o"] as $core_object){
                //Link object file to library.
                exec("$AR $ARFLAGS $core_library $core_object.o", $output);

                if ($compiler_config['logging'])
                    file_put_contents($compiler_config['logFileName'], "$AR $ARFLAGS $core_library $core_object.o"."\n", FILE_APPEND);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        else{
            flock($lock, LOCK_UN);
            fclose($lock);
            if($compiler_config['logging'])
                file_put_contents($compiler_config['logFileName'],"\nUsing previously compiled version of $core_library\n", FILE_APPEND);
        }

        // Step 6: Create objects for libraries.
        // The elements of the "build" array are needed to build the unique name of every library object file.
        $lib_object_naming_params = $request["build"];
        if (!array_key_exists("variant", $request["build"]))
            $lib_object_naming_params["variant"] = "";
        $lib_object_naming_params["vid"] = $vid;
        $lib_object_naming_params["pid"] = $pid;

        foreach ($files["libs"] as $library_name => $library_files){

            $lib_object_naming_params["library"] = $library_name;

            $ret = $this->handleCompile("$compiler_dir/libraries/$library_name", $files["libs"][$library_name], $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories["main"], $format, true, $lib_object_naming_params);

            $log_content = (($compiler_config['logging'] === true) ? @file_get_contents($compiler_config['logFileName']) : "");
            if ($compiler_config['logging'] === true){
                if ($log_content !== false) {
                    $ret["log"] = $log_content;
                    file_put_contents($compiler_config["compiler_dir"] . "/log", $log_content);
                }
                else
                    $ret["log"] = "Failed to access logfile.";
            }

            if ($ARCHIVE_OPTION === true){
                $arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
                if ($arch_ret["success"] === false)
                    $ret["archive"] = $arch_ret["message"];
                else
                    $ret["archive"] = $ARCHIVE_PATH;
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

            // Log the fact that an error occurred during linking
            $this->compiler_logger->addInfo($this->logger_id . " - An error occurred during linking: " . json_encode(implode("\n", $output)));

            $returner = array(
                "success" => false,
                "step" => 7,
                "message" => implode("\n", $output));

            if ($compiler_config['logging'] === true) {
                $log_content = @file_get_contents($compiler_config['logFileName']);
                if (!$log_content)
                    $returner["log"] = "Failed to access logfile.";
                else {
                    file_put_contents($compiler_config["compiler_dir"] . "/log", $log_content);
                    $returner["log"] = $log_content;
                }
            }

            if ($ARCHIVE_OPTION === true){
                $arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
                if ($arch_ret["success"] === false)
                    $returner["archive"] = $arch_ret["message"];
                else
                    $returner["archive"] = $ARCHIVE_PATH;
            }
            return $returner;
        }

        // Step 8: Convert the output to the requested format and measure its
        // size.
        $tmp = $this->convertOutput("$compiler_dir/files", $format, $SIZE, $SIZE_FLAGS, $OBJCOPY, $OBJCOPY_FLAGS, $OUTPUT, $start_time, $compiler_config);

        if ($compiler_config['logging'] === true) {
            $log_content = @file_get_contents($compiler_config['logFileName']);
            if (!$log_content)
                $tmp["log"] = "Failed to access logfile.";
            else {
                file_put_contents($compiler_config["compiler_dir"] . "/log", $log_content);
                $tmp["log"] = $log_content;
            }
        }

        if ($ARCHIVE_OPTION === true){
            $arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
            if ($arch_ret["success"] === false)
                $tmp["archive"] = $arch_ret["message"];
            else
                $tmp["archive"] = $ARCHIVE_PATH;
        }
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

    private function createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, &$ARCHIVE_PATH)
    {
        if (!file_exists($ARCHIVE_PATH)){
            // Create a directory in tmp folder and store archive files there
            if (!file_exists("$TEMP_DIR/$ARCHIVE_DIR")){
                //The code below was added to ensure that no error will be returned because of multithreaded execution.
                $make_dir_success = @mkdir("$TEMP_DIR/$ARCHIVE_DIR", 0777, true);
                if (!$make_dir_success && !is_dir("$TEMP_DIR/$ARCHIVE_DIR")) {
                    usleep(rand( 5000 , 10000 ));
                    $make_dir_success = @mkdir("$TEMP_DIR/$ARCHIVE_DIR", 0777, true);
                }
                if (!$make_dir_success)
                    return array("success" => false, "message" => "Failed to create archive directory.");
            }

            do{
                $tar_random_name = uniqid(rand(), true) . '.tar.gz';
            }while (file_exists("$TEMP_DIR/$ARCHIVE_DIR/$tar_random_name"));
            $ARCHIVE_PATH = "$TEMP_DIR/$ARCHIVE_DIR/$tar_random_name";
        }

        // The archive files include all the files of the project and the libraries needed to compile it
        exec("tar -zcvf $ARCHIVE_PATH -C $TEMP_DIR/ ". pathinfo($compiler_dir, PATHINFO_BASENAME), $output, $ret_var);

        if ($ret_var !=0)
            return array("success" => false, "message" => "Failed to archive project files.");
        return array("success" => true);
    }

    private function extractFiles($request, $temp_dir, &$dir, &$files, $suffix, $lib_extraction = false)
    {
        // Create a temporary directory to place all the files needed to process
        // the compile request. This directory is created in $TMPDIR or /tmp by
        // default and is automatically removed upon execution completion.
        $cnt = 0;
        if (!$dir)
            do {
                $dir = @System::mktemp("-t $temp_dir/ -d compiler.");
                $cnt++;
            } while (!$dir && $cnt <= 2);

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

    public function preprocessHeaders($libraries, &$include_directories, $dir, $ARDUINO_CORES_DIR, $EXTERNAL_CORES_DIR, &$CORE_DIR, &$CORE_OVERRIDE_DIR, $version, &$core, &$variant)
    {
        try
        {
            // Create command-line arguments for header search paths. Note that the
            // current directory is added to eliminate the difference between <>
            // and "" in include preprocessor directives.

            // Check if the core or variant contains a semicolon.
            // When a semicolon exists both the core folder and the core itself are specified.
            // The same applies to the variant specification.

            $core_specs = array('folder' => "arduino", 'name' => $core, 'modified' => false);
            $variant_specs = array('folder' => "arduino", 'name' => $variant, 'modified' => false);

            $tmp = explode(":", $core);
            if (count($tmp) == 2){
                $core_specs = array('folder' => $tmp[0], 'name' => $tmp[1], 'modified' => true);
                $core = str_replace(":", "_", $core); //core name is used for object file naming, so it shouldn't contain any semicolons
            }
            elseif (count($tmp) != 1)
                return array("success" => false, "step" => 3, "message" => "Invalid core specifier.");

            $tmp = explode(":", $variant);
            if (count($tmp) == 2){
                $variant_specs = array('folder' => $tmp[0], 'name' => $tmp[1], 'modified' => true);
                $variant = str_replace(":", "_", $variant); //variant name is used for object file naming, so it shouldn't contain any semicolons
            }
            elseif (count($tmp) != 1)
                return array("success" => false, "step" => 3, "message" => "Invalid variant specifier.");



            $include_directories = array();

            // Try to locate the core files
            if (file_exists("$ARDUINO_CORES_DIR/v$version/hardware/".$core_specs['folder']."/cores/".$core_specs['name'])){
                $CORE_DIR = "$ARDUINO_CORES_DIR/v$version/hardware/".$core_specs['folder']."/cores/".$core_specs['name'];
            }
            elseif (is_dir($EXTERNAL_CORES_DIR)){
                if ($core_specs['modified'] === true){
                    if (file_exists("$EXTERNAL_CORES_DIR/".$core_specs['folder']."/cores/".$core_specs['name']))
                        $CORE_DIR = "$EXTERNAL_CORES_DIR/".$core_specs['folder']."/cores/".$core_specs['name'];
                }
                elseif (false !== ($externals = @scandir($EXTERNAL_CORES_DIR))){
                    foreach ($externals as $dirname)
                        if (is_dir("$EXTERNAL_CORES_DIR/$dirname/") && $dirname != "." && $dirname != ".." && file_exists("$EXTERNAL_CORES_DIR/$dirname/cores/".$core_specs['name'])){
                            $CORE_DIR = "$EXTERNAL_CORES_DIR/$dirname/cores/".$core_specs['name'];
                            break;
                        }
                    }
            }

            if (empty($CORE_DIR))
                return array("success" => false, "step" => 3, "message" => "Failed to detect core files.");

            // Try to locate the variant
            if ($variant != ""){
                if (file_exists("$ARDUINO_CORES_DIR/v$version/hardware/".$variant_specs['folder']."/variants/".$variant_specs['name']))
                    $variant_dir = "$ARDUINO_CORES_DIR/v$version/hardware/".$variant_specs['folder']."/variants/".$variant_specs['name'];
                else {
                    if (is_dir($EXTERNAL_CORES_DIR)){
                        if ($variant_specs['modified'] === true){
                            if (file_exists("$EXTERNAL_CORES_DIR/".$variant_specs['folder']."/variants/".$variant_specs['name']))
                                $variant_dir = "$EXTERNAL_CORES_DIR/".$variant_specs['folder']."/variants/".$variant_specs['name'];
                        }
                        elseif (false !== ($externals = @scandir($EXTERNAL_CORES_DIR))){
                            foreach ($externals as $dirname)
                                if (is_dir("$EXTERNAL_CORES_DIR/$dirname") && $dirname != "." && $dirname != "..")
                                    if ($variant != "" && file_exists("$EXTERNAL_CORES_DIR/$dirname/variants/".$variant_specs['name'])){
                                        $variant_dir = "$EXTERNAL_CORES_DIR/$dirname/variants/".$variant_specs['name'];
                                        break;
                                    }
                        }
                    }
                }
            }

            if (!empty($variant) && empty($variant_dir))
                return array("success" => false, "step" => 3, "message" => "Failed to detect variant.");

			// Check the override file directories for files that override the requested cores
			if (is_dir("$EXTERNAL_CORES_DIR/override_cores/".$core_specs['name']."/") )
				$CORE_OVERRIDE_DIR = "$EXTERNAL_CORES_DIR/override_cores/".$core_specs['name']."/";
			else
				$CORE_OVERRIDE_DIR = "";


			$include_directories["core"] = ((!empty($CORE_OVERRIDE_DIR)) && ($CORE_OVERRIDE_DIR != "")) ? " -I$CORE_OVERRIDE_DIR" : "";
			$include_directories["core"] .=  " -I$CORE_DIR";
			$include_directories["core"] .= (!empty($variant_dir)) ? " -I$variant_dir" : "";

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
                    $name_params['core'] = str_replace(":", "_", $name_params['core']);
                    $name_params['variant'] = str_replace(":", "_", $name_params['variant']);
                    $object_filename = "$this->object_directory/${name_params['mcu']}_${name_params['f_cpu']}_${name_params['core']}_${name_params['variant']}_${name_params['vid']}_${name_params['pid']}______${name_params['library']}_______".((pathinfo(pathinfo($file, PATHINFO_DIRNAME), PATHINFO_FILENAME) == "utility") ? "utility_______" : "") .pathinfo($file, PATHINFO_FILENAME);
                    $object_file = $object_filename;
                    //Lock the file so that only one compiler instance (thread) will compile every library object file
                    $lock = fopen("$object_file.o.LOCK", "w");
                    $lock_check = flock($lock, LOCK_EX);
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

                        $output = $this->postproc->ansi_to_html(implode("\n", $output));

                        $resp = array(
                            "success" => false,
                            "step" => 4,
                            "debug" => $avr_output);

                        /**
                         * When an error occurs, compare the output of both avr-gcc and clang
                         * and if significant differences are detected, return a modified version of the clang output.
                         */
                        $clangElements = $this->getClangErrorFileList ($output);
                        $this->compiler_logger->addInfo($this->logger_id . " - Clang reported files: " . implode(" | ", array_keys($clangElements)));
                        $gccElements = $this->getGccErrorFileList ($avr_output);
                        $this->compiler_logger->addInfo($this->logger_id . " - Gcc reported files: " . implode(" | ", array_keys($gccElements)));

                        if (array_diff(array_keys($clangElements), array_keys($gccElements))) {

                            $resp["old_message"] = $output;
                            $this->compiler_logger->addInfo($this->logger_id . " - Mismatch between clang and gcc output found.");

                            $next_clang_output = $this->cleanUpClangOutput ($output, $compiler_config, "asm");

                            $clangElements = $this->getClangErrorFileList ($next_clang_output);
                            $this->compiler_logger->addInfo($this->logger_id . " - Clang reported files after removing asm: " . implode(" | ", array_keys($clangElements)));

                            if (array_diff(array_keys($clangElements), array_keys($gccElements))) {
                                $this->compiler_logger->addInfo($this->logger_id . " - Mismatch between clang and gcc output found after removing assembly messages.");
                                $final_clang_output = $this->cleanUpClangOutput ($next_clang_output, $compiler_config, "non_asm");

                                $clangElements = $this->getClangErrorFileList ($final_clang_output);
                                if (array_diff(array_keys($clangElements), array_keys($gccElements))) {
                                    $this->compiler_logger->addInfo($this->logger_id . " - Mismatch between clang and gcc output found after removing assembly/library/core messages.");
                                }else {
                                    $this->compiler_logger->addInfo($this->logger_id . " - Clang and gcc issue solved. Both report same files with errors.");
                                }
                                $this->compiler_logger->addInfo($this->logger_id . " - Gcc output: " . json_encode($avr_output));
                                $this->compiler_logger->addInfo($this->logger_id . " - Clang initial output: " . json_encode($output));
                                $this->compiler_logger->addInfo($this->logger_id . " - Clang reformated output: " . json_encode($final_clang_output));
                                $final_clang_output = $this->pathRemover ($final_clang_output, $compiler_config);
                                $resp["message"] = $final_clang_output;
                                if ($resp["message"] == "")
                                    $resp["message"] = $this->pathRemover ($output, $compiler_config);
                                return $resp;
                            }else {
                                $this->compiler_logger->addInfo($this->logger_id . " - Gcc output: " . json_encode($avr_output));
                                $this->compiler_logger->addInfo($this->logger_id . " - Clang initial output: " . json_encode($output));
                                $this->compiler_logger->addInfo($this->logger_id . " - Clang reformated output: " . json_encode($next_clang_output));
                                $next_clang_output = $this->pathRemover ($next_clang_output, $compiler_config);
                                $resp["message"] = $next_clang_output;
                                if ($resp["message"] == "")
                                    $resp["message"] = $this->pathRemover ($output, $compiler_config);
                                return $resp;
                            }
                        }

                        $resp["message"] = $this->pathRemover ($output, $compiler_config);
                        if ($resp["message"] == "")
                            $resp["message"] = $this->pathRemover($avr_output, $compiler_config);
                        return $resp;
                    }
                    unset($output);
                    if ($caching && $lock_check){
                        flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                }
                elseif ($caching && $lock_check){
                    if($compiler_config['logging'])
                        file_put_contents($compiler_config['logFileName'],"Using previously compiled version of $object_file.o\n", FILE_APPEND);
                    flock($lock, LOCK_UN);
                    fclose($lock);
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

    private function set_avr($version, $ARDUINO_CORES_DIR, $BINUTILS, &$CC, &$CPP, &$AS, &$AR, &$LD, &$OBJCOPY, &$SIZE)
    {
        // External binaries.
        $binaries = array("cc" => "-gcc", "cpp" => "-g++", "as" => "-gcc", "ar" => "-ar", "ld" => "-gcc", "objcopy" => "-objcopy", "size" => "-size");

        $binpaths = array();
        foreach ($binaries as $key => $value){
            if (!file_exists("$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/bin/avr$value"))
                $binpaths[$key] = "$BINUTILS/avr$value";
            else
                $binpaths[$key] = "$ARDUINO_CORES_DIR/v$version/hardware/tools/avr/bin/avr$value";
        }

        $CC = $binpaths["cc"];
        $CPP = $binpaths["cpp"];
        $AS = $binpaths["as"];
        $AR = $binpaths["ar"];
        $LD = $binpaths["ld"];
        $OBJCOPY = $binpaths["objcopy"];
        $SIZE = $binpaths["size"];

    }
    private function set_values($compiler_config,
                                &$BINUTILS, &$CLANG, &$CFLAGS, &$CPPFLAGS,
                                &$ASFLAGS, &$ARFLAGS, &$LDFLAGS, &$LDFLAGS_TAIL, &$CLANG_FLAGS, &$OBJCOPY_FLAGS, &$SIZE_FLAGS,
                                &$OUTPUT, &$ARDUINO_CORES_DIR, &$EXTERNAL_CORES_DIR, &$TEMP_DIR, &$ARCHIVE_DIR, &$AUTOCC_DIR, &$PYTHON, &$AUTOCOMPLETER)
    {
        // External binaries.
        //If the current version of the core files does not include its own binaries, then use the default
        //ones included in the binutils parameter
        $BINUTILS = $compiler_config["binutils"];
        //Clang is used to return the output in case of an error, it's version independent, so its
        //value is set by set_values function.

	$LDLIBRARYPATH="LD_LIBRARY_PATH=" . $compiler_config["arduino_cores_dir"] . "/clang/v3_5/lib:\$LD_LIBRARY_PATH";
        $CLANG = $LDLIBRARYPATH . " " . $compiler_config["clang"];
		//Path to Python binaries, needed for the execution of the autocompletion script.
	$PYTHONPATH="PYTHONPATH=" . $compiler_config["arduino_cores_dir"] . "/clang/v3_5/bindings/python:\$PYTHONPATH";
	$PYTHON = $LDLIBRARYPATH . " " . $PYTHONPATH . " " . $compiler_config["python"];

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
        // The directory name where archive files are stored in $TEMP_DIR
        $ARCHIVE_DIR = $compiler_config["archive_dir"];
		// The directory where autocompletion files are stored.
		$AUTOCC_DIR = $compiler_config["autocompletion_dir"];
		// The name of the python script that will be executed for autocompletion.
		$AUTOCOMPLETER = $compiler_config["autocompleter"];
        // Path to arduino-core-files repository.
        $ARDUINO_CORES_DIR = $compiler_config["arduino_cores_dir"];
        // Path to external core files (for example arduino ATtiny)
        $EXTERNAL_CORES_DIR = $compiler_config["external_core_files"];
    }

    private function set_variables($request, &$format, &$libraries, &$version, &$mcu, &$f_cpu, &$core, &$variant, &$vid, &$pid, &$compiler_config)
    {
        // Extract the request options for easier access.
        $format = $request["format"];
        $libraries = $request["libraries"];
        $version = $request["version"];
        $mcu = $request["build"]["mcu"];
        $f_cpu = $request["build"]["f_cpu"];
        $core = $request["build"]["core"];
        // Some cores do not specify any variants. In this case, set variant to be an empty string
        if (!array_key_exists("variant", $request["build"]))
            $variant = "";
        else
            $variant = $request["build"]["variant"];

		if ($format == "autocomplete") {
			$compiler_config["autocmpfile"] = $request["position"]["file"];
			$compiler_config["autocmprow"] = $request["position"]["row"];
			$compiler_config["autocmpcol"] = $request["position"]["column"];
			$compiler_config["autocmpmaxresults"] = $request["maxresults"];
			$compiler_config["autocmpprefix"] = $request["prefix"];
		}

        // Set the appropriate variables for vid and pid (Leonardo).

        $vid = (isset($request["build"]["vid"])) ? $request["build"]["vid"] : "null";
        $pid = (isset($request["build"]["pid"])) ? $request["build"]["pid"] : "null";
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
            //The code below was added to ensure that no error will be returned because of multithreaded execution.
            if(!file_exists($directory)){
                $make_dir_success = @mkdir($directory, 0777, true);
                if (!$make_dir_success && !is_dir($directory)) {
                    usleep(rand( 5000 , 10000 ));
                    $make_dir_success = @mkdir($directory, 0777, true);
                }
                if(!$make_dir_success)
                    return array("success"=>false, "message"=>"Failed to create logfiles directory.");
            }

            $compiler_part = str_replace(".", "_", substr($compiler_dir, strpos($compiler_dir, "compiler")));

            $compiler_config['logFileName'] = $directory ."/". $basename ."_".$compiler_part."_". $randPart .".txt";

            file_put_contents($compiler_config['logFileName'], '');
        }
        elseif(!array_key_exists('logging', $request)|| (!$request['logging']))
            $compiler_config['logging'] = false;

        return array("success"=>true);
    }


    /**
    \brief Reads all core files from the respective directory and passes their contents to extractFiles function
    which then writes them to the compiler temp directory

    \param string $core_files_directory The directory containing the core files.
    \param string $tmp_compiler The tmp directory where the actual compilation process takes place.
    \return array An array containing the function results.
     */
    private function makeCoresTmp($core_files_directory, $core_overrd_directory, $temp_directory, $tmp_compiler, &$files){

        $core = array();
        if(false === ($scanned_files = @scandir($core_files_directory)))
            return array( "success"=>false, "step"=>5, "message"=>"Failed to read core files." );

        // Get the contents of the core files
        foreach ($scanned_files as $core_file)
            if(!is_dir("$core_files_directory/$core_file")){
				if (!empty($core_overrd_directory) && $core_overrd_directory !="" && file_exists("$core_overrd_directory/$core_file"))
					$core[] = array("filename" => $core_file, "content" => file_get_contents("$core_overrd_directory/$core_file"), "filepath" => "$core_overrd_directory/$core_file");
				else
					$core[] = array("filename" => $core_file, "content" => file_get_contents("$core_files_directory/$core_file"), "filepath" => "$core_files_directory/$core_file");
			}

        // Check if the version of the core files includes an avr-libc directory and scan
        if(file_exists("$core_files_directory/avr-libc")){
            if(false === ($scanned_avr_files = @scandir("$core_files_directory/avr-libc")))
                return array( "success"=>false, "step"=>5, "message"=>"Failed to read core files." );
            foreach($scanned_avr_files as $avr_file)
                if(!is_dir("$core_files_directory/avr-libc/$avr_file")){
					if (!empty($core_overrd_directory) && $core_overrd_directory !="" && file_exists("$core_overrd_directory/avr-libc/$avr_file"))
						$core[] = array("filename" => "avr-libc/$avr_file", "content" => file_get_contents("$core_overrd_directory/avr-libc/$avr_file"), "filepath" => "$core_overrd_directory/avr-libc/$avr_file");
					else
						$core[] = array("filename" => "avr-libc/$avr_file", "content" => file_get_contents("$core_files_directory/avr-libc/$avr_file"), "filepath" => "$core_files_directory/avr-libc/$avr_file");
				}
        }


        $tmp = $this->extractFiles($core, $temp_directory, $tmp_compiler, $files["core"], "core");
        if($tmp["success"] === false)
            return array( "success"=>false, "step"=>5, "message"=>$tmp["message"] );

        return array('success' => true);
    }


    private function handleCompile($compile_directory, &$files_array, $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $AS, $ASFLAGS, $CLANG, $CLANG_FLAGS, $core_includes, $target_arch, $clang_target_arch, $include_directories, $format, $caching = false, $name_params = null){

        //Add any include directories needed
        if (pathinfo($compile_directory, PATHINFO_BASENAME) !== "files")
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

	private function doAutocomplete($ARDUINO_CORES_DIR, $compiler_config, $compile_directory, $CC, $CFLAGS, $CPP, $CPPFLAGS, $core_includes, $target_arch, $include_directories, $autocompletionDir, $PYTHON, $AUTOCOMPLETER){

		$file = $compile_directory . "/" . $compiler_config["autocmpfile"];

		$filename =  pathinfo($file, PATHINFO_DIRNAME) . "/" . pathinfo($file, PATHINFO_FILENAME);

		$ext = pathinfo($file, PATHINFO_EXTENSION);
		if (!in_array($ext, array("ino", "c", "cpp", "h", "hpp")))
			return array("success" => false, "message" => "Sorry, autocompletion is only supported for .ino, .c, .cpp or .h files.");
		if ($ext == "ino"){
			$ext = "cpp";
		}
		$compiler_config["autocmpfile"] = "$filename.$ext";

		$commandline = "";

		if ($ext == "c")
		{
			$commandline = "$CC $CFLAGS $core_includes $target_arch $include_directories -c -o $filename.o $filename.$ext 2>&1";
			$json_array = array("file" => $compiler_config["autocmpfile"], "row" => $compiler_config["autocmprow"], "column" => $compiler_config["autocmpcol"], "prefix" => $compiler_config["autocmpprefix"], "command" => $commandline);

		}
		elseif ($ext == "cpp" || $ext == "h")
		{
			$commandline = "$CPP $CPPFLAGS $core_includes $target_arch -MMD $include_directories -c -o $filename.o $filename.$ext 2>&1";
			$json_array = array("file" => $compiler_config["autocmpfile"], "row" => $compiler_config["autocmprow"], "column" => $compiler_config["autocmpcol"], "prefix" => $compiler_config["autocmpprefix"], "command" => $commandline);

		}
		if (empty($json_array) || (false === file_put_contents("$compile_directory/autocc.json", json_encode($json_array))))
			return array("success" => false, "message" => "Failed to process autocompletion data.");

		if (!is_dir("$ARDUINO_CORES_DIR/clang/"))
			return array("success" => false, "message" => "Failed to locate python bindings directory.");

		$time = microtime(true);
		// Set the PYTHONPATH environment variable here, instead of setting a global variable in
		// every machine the compiler runs on.
		$SET_PYTHONPATH = "export PYTHONPATH=\"$ARDUINO_CORES_DIR/clang/v3_5/bindings/python:\$PYTHONPATH\"";
		$result = exec("$SET_PYTHONPATH && $PYTHON $AUTOCOMPLETER " . $compiler_config["autocmpmaxresults"] . " $compile_directory/autocc.json", $output, $retval);

		$exec_time = microtime(true) - $time;

		if ($retval != 0)
			return array("success" => false, "message" => "There was an error during autocompletion process.", "retval" => $retval, "autocc_exec_time" => $exec_time);

		$command_output = implode("\n", $output);
		if (false === json_decode($command_output, true))
			return array("success" => false, "message" => "Failed to handle autocompletion output.", "autocc_exec_time" => $exec_time);

		return array("success" => true, "retval" => $retval, "message" => "Autocompletion was successful!", "autocomplete" => $command_output, "autocc_exec_time" => $exec_time);
	}

	private function handleAutocompletion($ARDUINO_CORES_DIR, $compile_directory, $include_directories, $compiler_config, $CC, $CFLAGS, $CPP, $CPPFLAGS, $core_includes, $target_arch, $tmpDir, $autoccDir, $PYTHON, $AUTOCOMPLETER){

		$make_dir_success = @mkdir("$tmpDir/$autoccDir", 0777, true);
		if (!$make_dir_success && !is_dir("$tmpDir/$autoccDir")) {
			usleep(rand( 5000 , 10000 ));
			$make_dir_success = @mkdir("$tmpDir/$autoccDir", 0777, true);
		}
		if (!$make_dir_success && !is_dir("$tmpDir/$autoccDir"))
			return array("success" => false, "message" => "Failed to create autocompletion file structure.");

		$include_directories .= " -I$compile_directory ";

		$compile_res = $this->doAutocomplete($ARDUINO_CORES_DIR, $compiler_config, $compile_directory, $CC, $CFLAGS, $CPP, $CPPFLAGS, $core_includes, $target_arch, $include_directories, "$tmpDir/$autoccDir", $PYTHON, $AUTOCOMPLETER);

		return $compile_res;
	}

    private function getClangErrorFileList ($clang_output) {
        /**
         * Clang's output processing
         */
        // Get all the 'filename.extension:line' elements. Include only those followed by an 'error' statement.
        $tag_free_content = strip_tags($clang_output);     // Remove color tags (as many as possible).

        $clang_matches = preg_split('/([\w*\s*(!@#$%^&*()-+;\'{}\[\])*]+\.\w+:\d+:[\d+:]?)/', $tag_free_content, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

        $elements = array();
        foreach ($clang_matches as $key => $val ) {
            if (preg_match('/([\w*\s*(!@#$%^&*()-+;\'{}\[\])*]+\.\w+:\d+:[\d+:]?)/', $val)
                && array_key_exists($key + 1, $clang_matches)
                && (strpos($clang_matches[$key +1 ],"error:") !== false
                    || strpos($clang_matches[$key +1 ],"note:") !== false
                    || strpos($clang_matches[$key +1 ],"in asm") !== false
                    || strpos($clang_matches[$key],"in asm") !== false)) {
                if (strpos($val, "In file included from ") !== false)
                    $val = str_replace("In file included from ", "", $val);
                    $val = str_replace("In file included from ", "", $val);
                $elements[] = $val;
            }
        }

        // Split the elements from above and get an associative array structure of [filename => lines]
        $clang_elements = array();
        foreach ($elements as $element) {

            // The first part is filename.extension, the second represents the line,
            // and the third one is the column number (not used for now).
            $split = explode(':', $element);

            if (!array_key_exists($split[0], $clang_elements)) {
                $clang_elements[$split[0]] = array();
                $clang_elements[$split[0]][] = $split[1];
                continue;
            }
            $clang_elements[$split[0]][] = $split[1];
        }
        return $clang_elements;
    }

    private function getGccErrorFileList ($avr_output) {
        /**
         * Avr gcc's output processing
         */
        // Get all 'filename.extension:line' elements.
        // Note that avr-gcc output only includes filenames and lines in error reporting, not collumns.
        preg_match_all('/([\w*\s*(!@#$%^&*()-+;\'{}\[\])*]+\.\w+:\d+:[\d+:]?)/', $avr_output, $gcc_matches, PREG_PATTERN_ORDER);

        $gcc_elements = array();
        foreach ($gcc_matches[0] as $element) {

            // The first part is filename.extension, the second represents the line.
            $split = explode(':', $element);
            if (!array_key_exists($split[0], $gcc_elements)) {
                $gcc_elements[$split[0]] = array();
                $gcc_elements[$split[0]][] = $split[1];
                continue;
            }
            $gcc_elements[$split[0]][] = $split[1];
        }
        return $gcc_elements;
    }

    private function cleanUpClangOutput ($clang_output, $compiler_config, $option) {

        $content_line_array = explode("\n", $clang_output);

        $header = "";
        $body = "";
        $final = "";
        $header_found = false;
        $libFound = false;
        $coreFound = false;
        $asmFound = false;

        foreach ($content_line_array as $key => $line) {

            if ((strpos($line, "In file included from") !== false
                    && preg_match('/([\w*\s*(!@#$%^&*()-+;\'{}\[\])*]+\.\w+:\d+:[\d+:]?)/', $line))
                || (preg_match('/([\w*\s*(!@#$%^&*()-+;\'{}\[\])*]+\.\w+:\d+:[\d+:]?)/', $line)
                    && strpos($line, "error:") !== false)) {

                if ($header_found === false) {
                    if (($option == "non_asm" && preg_match('/(\/compiler\.\w+\/libraries\/)/', $header)
                            || strpos($header, $compiler_config["arduino_cores_dir"]) !== false
                            || (array_key_exists("external_core_files", $compiler_config)
                                && strpos($header, $compiler_config["external_core_files"]) !== false))
                        || ($option == "asm"
                            && (strpos($header, "in asm") !== false
                                || strpos($body, "in asm") !== false))) {

                        if (preg_match('/(\/compiler\.\w+\/libraries\/)/', $header) && $libFound === false && $option != "asm") {
                            $this->compiler_logger->addInfo($this->logger_id . " - Clang reports library issue.");
                            $libFound = true;
                        }
                        if ((strpos($header, $compiler_config["arduino_cores_dir"]) !== false
                                || (array_key_exists("external_core_files", $compiler_config)
                                    && strpos($header, $compiler_config["external_core_files"]) !== false))
                            && $coreFound === false && $option != "asm") {
                            $this->compiler_logger->addInfo($this->logger_id . " - Clang reports core issue.");
                            $coreFound = true;
                        }
                        if ((strpos($header, "in asm") !== false || strpos($body, "in asm") !== false) && $asmFound === false && $option == "asm") {
                            $this->compiler_logger->addInfo($this->logger_id . " - Clang reports assembly issue.");
                            $asmFound = true;
                        }
                        $header = "";
                        $body = "";
                    }

                    if ($header != "") {
                        if (strpos($header, "</font></b>") == 0)
                            $header = substr_replace($header, '', 0, 11);
                        if (array_key_exists($key + 1, $content_line_array)
                            && strpos($content_line_array[$key + 1], "</font></b>") == 0)
                            $body = $body . "</font></b>";
                        $final .= $header ."\n";
                        $final .= $body . "\n";
                        $header = "";
                        $body = "";
                    }
                }

                $header .= $line . "\n";
                $header_found = true;
                continue;
            }

            if (!array_key_exists($key + 1, $content_line_array)) {
                if ((!preg_match('/(\/compiler\.\w+\/libraries\/)/', $header)
                        && strpos($header, $compiler_config["arduino_cores_dir"]) === false
                        && (array_key_exists("external_core_files", $compiler_config)
                            && strpos($header, $compiler_config["external_core_files"]) === false)
                        && $option == "non_asm")
                    || ($option == "asm"
                        && strpos($header, "in asm") === false
                        && strpos($body, "in asm") === false)) {
                    if ($header != "") {
                        if (strpos($header, "</font></b>") == 0)
                            $header = substr_replace($header, '', 0, 11);
                        $final .= $header ."\n";
                        $final .= $body . "\n";
                    }
                }else {
                    if (preg_match('/(\/compiler\.\w+\/libraries\/)/', $header) && $libFound === false && $option != "asm") {
                        $this->compiler_logger->addInfo($this->logger_id . " - Clang reports library issue.");
                    }
                    if ((strpos($header, $compiler_config["arduino_cores_dir"]) !== false
                            || (array_key_exists("external_core_files", $compiler_config)
                                && strpos($header, $compiler_config["external_core_files"]) !== false))
                        && $coreFound === false && $option != "asm") {
                        $this->compiler_logger->addInfo($this->logger_id . " - Clang reports core issue.");
                    }
                    if ((strpos($header, "in asm") !== false || strpos($body, "in asm") !== false) && $asmFound === false && $option == "asm") {
                        $this->compiler_logger->addInfo($this->logger_id . " - Clang reports assembly issue.");
                    }
                }
            }

            $header_found = false;
            $body .= $line . "\n";

        }

        return $final;
    }

    private function pathRemover ($output, $compiler_config) {

        // Remove any instance of "compiler.RANDOM/files/" folder name from the text
        $modified = str_replace($compiler_config["compiler_dir"] . "/files/", '', $output);

        // Remove any remaining instance of "compiler.RANDOM/" folder name from the text.
        $modified = str_replace($compiler_config["compiler_dir"] . "/", '', $modified);

        // Remove any instance of codebender arduino core files folder name from the text
        $modified = str_replace($compiler_config["arduino_cores_dir"] . "/v105/", '', $modified);

        // Remove any instance of codebender external core file folder name from the text
        if (isset($compiler_config["external_core_files"]) && $compiler_config["external_core_files"] != "") {
            $modified = str_replace($compiler_config["external_core_files"], '', $modified);
            $modified = str_replace("/override_cores/", '', $modified);
        }

        return $modified;
    }

}
