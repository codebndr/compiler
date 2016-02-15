<?php
/**
 * \file
 * \brief Functions used by the compiler backend.
 *
 * \author Dimitrios Christidis
 * \author Vasilis Georgitzikis
 *
 * \copyright (c) 2012-2013, The Codebender Development Team
 * \copyright Licensed under the Simplified BSD License
 */

namespace Codebender\CompilerBundle\Handler;

// This file uses mktemp() to create a temporary directory where all the files
// needed to process the compile request are stored.
require_once "System.php";
use System;
use Codebender\CompilerBundle\Handler\MCUHandler;
use Symfony\Bridge\Monolog\Logger;

class CompilerV2Handler
{
    private $preproc;
    private $postproc;
    private $utility;
    private $compiler_logger;
    private $object_directory;
    private $logger_id;

    function __construct(
            PreprocessingHandler $preprocHandl,
            PostprocessingHandler $postprocHandl,
            UtilityHandler $utilHandl,
            Logger $logger,
            $objdir
    ) {
        $this->preproc = $preprocHandl;
        $this->postproc = $postprocHandl;
        $this->utility = $utilHandl;
        $this->compiler_logger = $logger;
        $this->object_directory = $objdir;
    }

    /**
     * \brief Processes a compile request.
     *
     * \param string $request The body of the POST request.
     * \return A message to be JSON-encoded and sent back to the requestor.
     */
    function main($request, $compiler_config)
    {
        $log = array();

        error_reporting(E_ALL & ~E_STRICT);

        $this->setValues($compiler_config,
            $OUTPUT, $ARDUINO_CORES_DIR, $EXTERNAL_CORES_DIR,
            $TEMP_DIR, $ARCHIVE_DIR);

        $start_time = microtime(true);

        // Step 0: Reject the request if the input data is not valid.
        $tmpVar = $this->requestValid($request);
        if ($tmpVar["success"] === false)
            return $tmpVar;

        $this->setVariables($request, $format, $libraries, $version, $fqbn, $vid, $pid, $should_archive, $compiler_config);

        $incoming_files = array();

        // Step 1(part 1): Extract the project files included in the request.
        $tmpVar = $this->extractFiles($request["files"], $TEMP_DIR, $compiler_dir, $incoming_files, "files");
        if ($tmpVar["success"] === false)
            return $tmpVar;

        // Add the compiler temp directory to the compiler_config struct.
        $compiler_config["compiler_dir"] = $compiler_dir;

        // This is used, for example, to provide object files, and to provide output files.
        $compiler_config["project_name"] = str_replace($compiler_dir . "/files/",
                                                       "",
                                                       $incoming_files["ino"][0]) . ".ino";

        // Step 1(part 2): Extract the library files included in the request.
        $files["libs"] = array();
        foreach ($libraries as $library => $library_files) {

            $tmpVar = $this->extractFiles($library_files, $TEMP_DIR, $compiler_dir, $files["libs"][$library], "libraries/$library", true);
            if ($tmpVar["success"] === false)
                return $tmpVar;
        }

        if ($should_archive) {
            $arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
            if ($arch_ret["success"] === false)
                return $arch_ret;
        }
        else
            $ARCHIVE_PATH = "";

        //Set logging to true if requested, and create the directory where logfiles are stored.
        $tmpVar = $this->setLoggingParams($request, $compiler_config, $TEMP_DIR, $compiler_dir);
        if ($tmpVar["success"] === false)
            return array_merge($tmpVar, ($should_archive) ? array("archive" => $ARCHIVE_PATH) : array());

        // Step 2: Preprocess Arduino source files.
        // Ordinarily this would convert .ino files into .cpp files, but arduino-builder
        // and ctypes takes care of that for us already.

        // Step 3: Preprocess Header includes and determine which core files directory(CORE_DIR) will be used.

        // Log the names of the project files and the libraries used in it.
        $this->makeLogEntry($request, $compiler_config, $should_archive, $ARCHIVE_PATH);

        // Step 4: Syntax-check and compile source files.
        $ret = $this->handleCompile("$compiler_dir/files", $incoming_files, $compiler_config, $format);

        /*
        $log_content = (($compiler_config['logging'] === true) ? @file_get_contents($compiler_config['logFileName']) : "");
        if ($compiler_config['logging'] === true) {
            if ($log_content !== false) {
                $ret["log"] = $log_content;
                file_put_contents($compiler_config["compiler_dir"]."/log", $log_content);
            } else
                $ret["log"] = "Failed to access logfile.";
        }
        */
        if (($compiler_config['logging'] === true) && $ret["log"]) {
            foreach ($ret["log"] as $line) {
                array_push($log, $line);
            }
        }

        if ($should_archive) {
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
                ($should_archive) ? array("archive" => $ret["archive"]) : array(),
                ($compiler_config['logging'] === true) ? array("log" => $log) : array());

        //TODO: return objects if more than one file??
        if ($format == "object") {
            $path = $compiler_config["compiler_dir"] . "/sketch/" . $compiler_config["project_name"] . ".cpp.o";
            $content = base64_encode(file_get_contents($path));
            if (count($incoming_files["ino"]) != 1 || !$content) {
                return array_merge(array(
                        "success" => false,
                        "step" => -1, //TODO: Fix this step?
                        "message" => ""),
                    ($should_archive) ? array("archive" => $ret["archive"]) : array(),
                    ($compiler_config['logging'] === true) ? array("log" => $log) : array());
            } else
                return array_merge(array(
                        "success" => true,
                        "time" => microtime(true) - $start_time,
                        "output" => $content),
                    ($should_archive) ? array("archive" => $ret["archive"]) : array(),
                    ($compiler_config['logging'] === true) ? array("log" => $log) : array());
        }

        // Step 5: Create objects for core files (if core file does not already exist)
        //Link all core object files to a core.a library.
        //
        // This has become a no-op in v2, but perhaps we could speed things up in the future.

        // Step 6: Create objects for libraries.
        // The elements of the "build" array are needed to build the unique name of every library object file.
        //
        // This has also become a no-op in v2.

        // Step 7: Link all object files and create executable.
        //
        // The arduino builder already pre-links files for us.

        // Step 8: Convert the output to the requested format and measure its
        // size.
        $tmpVar = $this->convertOutput($format, $start_time, $compiler_config);

        if ($compiler_config['logging'] === true) {
            /*
            $log_content = @file_get_contents($compiler_config['logFileName']);
            if (!$log_content)
                $tmpVar["log"] = "Failed to access logfile.";
            else {
                file_put_contents($compiler_config["compiler_dir"]."/log", $log_content);
                $tmpVar["log"] = $log_content;
            }
            */
            $tmpVar["log"] = $log;
        }

        if ($should_archive) {
            $arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
            if ($arch_ret["success"] === false)
                $tmpVar["archive"] = $arch_ret["message"];
            else
                $tmpVar["archive"] = $ARCHIVE_PATH;
        }
        return $tmpVar;
    }

    private function requestValid(&$request)
    {
        $request = $this->preproc->validateInput($request);
        if (!$request)
            return array(
                "success" => false,
                "step" => 0,
                "message" => "Invalid input.");
        else
            return array("success" => true);
    }

    private function createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, &$ARCHIVE_PATH)
    {
        if (!file_exists($ARCHIVE_PATH)) {
            // Create a directory in tmp folder and store archive files there
            if (!file_exists("$TEMP_DIR/$ARCHIVE_DIR")) {
                //The code below was added to ensure that no error will be returned because of multithreaded execution.
                $make_dir_success = @mkdir("$TEMP_DIR/$ARCHIVE_DIR", 0777, true);
                if (!$make_dir_success && !is_dir("$TEMP_DIR/$ARCHIVE_DIR")) {
                    usleep(rand(5000, 10000));
                    $make_dir_success = @mkdir("$TEMP_DIR/$ARCHIVE_DIR", 0777, true);
                }
                if (!$make_dir_success)
                    return array(
                            "success" => false,
                            "message" => "Failed to create archive directory."
                    );
            }

            do {
                $tar_random_name = uniqid(rand(), true).'.tar.gz';
            } while (file_exists("$TEMP_DIR/$ARCHIVE_DIR/$tar_random_name"));
            $ARCHIVE_PATH = "$TEMP_DIR/$ARCHIVE_DIR/$tar_random_name";
        }

        // The archive files include all the files of the project and the libraries needed to compile it
        exec("tar -zcf $ARCHIVE_PATH -C $TEMP_DIR/ ".pathinfo($compiler_dir, PATHINFO_BASENAME), $output, $ret_var);

        if ($ret_var != 0)
            return array(
                    "success" => false,
                    "message" => "Failed to archive project files."
            );
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
                "message" => "Failed to create temporary directory."
            );

        $response = $this->utility->extractFiles("$dir/$suffix", $request, $lib_extraction);
        if ($response["success"] === false)
            return $response;

        $files = $response["files"];

        return array("success" => true);
    }

    private function makeLogEntry($request, $compiler_config, $should_archive, $archive_path)
    {
        $user_id = $sketch_id = "null";
        $req_elements = array("Files: ");

        if (isset($request['userId']) && $request['userId'] != 'null') {
            $user_id = $request['userId'];
        }
        if (isset($request['projectId']) && $request['projectId'] != 'null') {
            $sketch_id = $request['projectId'];
        }

        foreach ($request["files"] as $file) {
            $req_elements[] = $file["filename"];
        }

        if ($request["libraries"]) {
            $req_elements[] = "Libraries: ";
            foreach ($request["libraries"] as $libname => $libfiles) {
                foreach ($libfiles as $libfile)
                    $req_elements[] = $libname."/".$libfile["filename"];
            }
        }

        $this->logger_id = microtime(true)."_".substr($compiler_config['compiler_dir'], -6)."_user:$user_id"."_project:$sketch_id";

        $this->compiler_logger->addInfo($this->logger_id." - ".implode(" ", $req_elements));
        if ($should_archive)
            $this->compiler_logger->addInfo($this->logger_id." - "."Archive file: $archive_path");
    }

    private function convertOutput($format, $start_time, $compiler_config)
    {
        $content = "";
        if ($format == "elf") {
            $content_path = $compiler_config["compiler_dir"] . "/" . $compiler_config["project_name"] . ".elf";
            if (file_exists($content_path))
                $content = base64_encode(file_get_contents($content_path));
            else
                $content = "";
        } elseif ($format == "hex") {
            $content_path = $compiler_config["compiler_dir"] . "/" . $compiler_config["project_name"] . ".hex";
            if (file_exists($content_path))
                $content = file_get_contents($content_path);
            else
                $content = "";
        } elseif ($format == "binary") {
            $content_path = $compiler_config["compiler_dir"] . "/" . $compiler_config["project_name"] . ".bin";
            if (file_exists($content_path))
               $content = base64_encode(file_get_contents($content_path));
            else
                $content = "";
        } else {
            return array(
                "success" => false,
                "time"    => microtime(true) - $start_time,
                "step"    => 8,
                "message" => "Unrecognized format requested"
            );
        }

        // If everything went well, return the reply to the caller.
        if ($content === "")
            return array(
                "success" => false,
                "time"    => microtime(true) - $start_time,
                "step"    => 8,
                "message" => "There was a problem while generating the your binary file");

        $size = $compiler_config["compiler_dir"] . "/" . $compiler_config["project_name"] . ".size";
        $size = intval(file_get_contents($size));

        return array(
            "success" => true,
            "time"    => microtime(true) - $start_time,
            "size"    => $size,
            "output"  => $content);
    }

    private function setValues($compiler_config,
                                &$OUTPUT, &$ARDUINO_CORES_DIR, &$EXTERNAL_CORES_DIR, &$TEMP_DIR, &$ARCHIVE_DIR)
    {
        // The default name of the output file.
        $OUTPUT = $compiler_config["output"];
        // The tmp folder where logfiles and object files are placed
        $TEMP_DIR = $compiler_config["temp_dir"];
        // The directory name where archive files are stored in $TEMP_DIR
        $ARCHIVE_DIR = $compiler_config["archive_dir"];
        // Path to arduino-core-files repository.
        $ARDUINO_CORES_DIR = $compiler_config["arduino_cores_dir"];
        // Path to external core files (for example arduino ATtiny)
        $EXTERNAL_CORES_DIR = $compiler_config["external_core_files"];
    }

    private function setVariables($request,
            &$format, &$libraries, &$version, &$fqbn, &$vid, &$pid, &$should_archive, &$compiler_config)
    {
        // Extract the request options for easier access.
        $format = $request["format"];
        $libraries = $request["libraries"];
        $version = $request["version"];

        if (!array_key_exists("archive", $request))
            $should_archive = false;
        elseif ($request["archive"] !== false)
            $should_archive = false;
        else
            $should_archive = true;


        // Set the appropriate variables for USB vid and pid (Leonardo).
        $vid = (isset($request["vid"])) ? $request["vid"] : "null";
        $pid = (isset($request["pid"])) ? $request["pid"] : "null";

        $compiler_config["fqbn"] = $request["fqbn"];
        $compiler_config["vid"] = $vid;
        $compiler_config["pid"] = $pid;
        $compiler_config["version"] = $version;
    }

    private function setLoggingParams($request, &$compiler_config, $temp_dir, $compiler_dir)
    {
        //Check if $request['logging'] exists and is true, then make the logfile, otherwise set
        //$compiler_config['logdir'] to false and return to caller
        if (array_key_exists('logging', $request) && $request['logging']) {
            /*
            Generate a random part for the log name based on current date and time,
            in order to avoid naming different Blink projects for which we need logfiles
            */
            $randPart = date('YmdHis');
            /*
            Then find the name of the arduino file which usually is the project name itself
            and mix them all together
            */

            foreach ($request['files'] as $file) {
                if (strcmp(pathinfo($file['filename'], PATHINFO_EXTENSION), "ino") == 0) {
                    $basename = pathinfo($file['filename'], PATHINFO_FILENAME);
                }
            }
            if (!isset($basename)) {
                $basename = "logfile";
            }

            $compiler_config['logging'] = true;
            $directory = $temp_dir."/".$compiler_config['logdir'];
            //The code below was added to ensure that no error will be returned because of multithreaded execution.
            if (!file_exists($directory)) {
                $make_dir_success = @mkdir($directory, 0777, true);
                if (!$make_dir_success && !is_dir($directory)) {
                    usleep(rand(5000, 10000));
                    $make_dir_success = @mkdir($directory, 0777, true);
                }
                if (!$make_dir_success)
                    return array("success" => false, "message" => "Failed to create logfiles directory.");
            }

            $compiler_part = str_replace(".", "_", substr($compiler_dir, strpos($compiler_dir, "compiler")));

            $compiler_config['logFileName'] = $directory."/".$basename."_".$compiler_part."_".$randPart.".txt";

            file_put_contents($compiler_config['logFileName'], '');
        }
        elseif (!array_key_exists('logging', $request) || (!$request['logging']))
            $compiler_config['logging'] = false;

        return array("success" => true);
    }

    private function handleCompile($compile_directory, $files_array, $compiler_config, $format,
                                   $caching = false, $name_params = null)
    {
        $base_path = $compiler_config["arduino_cores_dir"] . "/v" . $compiler_config["version"];
        $core_path = $compiler_config["external_core_files"];
        $output_path = $compiler_config["compiler_dir"];
        $fqbn = $compiler_config["fqbn"];
        $filename = $files_array["ino"][0] . ".ino";
        $size_script = $compiler_config["compiler_dir"] . "/get_size.sh";

        if (!file_exists($output_path))
            return array(
                    "success" => false,
                    "step"    => 4,
                    "message" => "Output path does not exist.",
                    "debug"   => $output_path
            );

        if (!file_exists($base_path))
            return array(
                    "success" => false,
                    "step"    => 4,
                    "message" => "Base path does not exist.",
                    "debug"   => $base_path
            );

        if (!file_exists($filename))
            return array(
                    "success" => false,
                    "step"    => 4,
                    "message" => "Source file does not exist.",
                    "debug"   => $filename
            );

        /* The arduino-builder tool automatically processes multiple .ino files into one,
         * so we only need to specify the first file to build.
         */
        file_put_contents($size_script,
                  "#!/bin/sh\n"
                . "\"$1\" -A \"$2\" | grep Total | awk '{print $2}' > \"$3\"\n"
                );
        system("chmod a+x $size_script");

        $vid_pid = "";
        if (($compiler_config["vid"] != "null") && ($compiler_config["pid"] != "null")) {
            $vid = intval($compiler_config["vid"], 0);
            $pid = intval($compiler_config["pid"], 0);
            $vid_pid = sprintf(" -vid-pid=0X%1$04X_%2$04X", $vid, $pid);
        }

        $cmd = $base_path . "/arduino-builder"
                . " -logger=machine"
                . " -compile"
                . " -warnings=all"
                . " -hardware=" . $base_path . "/hardware"
                . " -hardware=" . $base_path . "/packages"
                . " -build-path=" . $output_path
                . " -tools=" . $base_path . "/tools-builder"
                . " -tools=" . $base_path . "/hardware/tools/avr"
                . " -tools=" . $base_path . "/packages"
                . " -prefs='recipe.hooks.objcopy.postobjcopy.0.pattern=$size_script \"{compiler.path}{compiler.size.cmd}\" \"{build.path}/{build.project_name}.hex\" \"{build.path}/{build.project_name}.size\"'"
                . " -fqbn=" . $fqbn
                . $vid_pid
                . " " . escapeshellarg($filename)
                . " 2>&1"
                ;
        exec($cmd, $output, $ret_link);

        if ($compiler_config["logging"]) {
            file_put_contents($compiler_config['logFileName'], $cmd, FILE_APPEND);
            file_put_contents($compiler_config['logFileName'], implode(" ", $output), FILE_APPEND);
        }

        if ($ret_link) {
            return array(
                "success" => false,
                "retcode" => $ret_link,
                "output" => $output,
                "cmd" => $cmd,
                "output_dir" => $output_path,
                "message" => $this->pathRemover($output, $compiler_config),
                "log"     => array($cmd, implode("\n", $output)),
                "filename" => $filename
            );
        }

        return array(
            "success" => true,
            "log"     => array($cmd, $output)
        );
    }

    private function getClangErrorFileList($clang_output)
    {
        /**
         * Clang's output processing
         */
        // Get all the 'filename.extension:line' elements. Include only those followed by an 'error' statement.
        $tag_free_content = strip_tags($clang_output); // Remove color tags (as many as possible).

        $clang_matches = preg_split('/([\w*\s*(!@#$%^&*()-+;\'{}\[\])*]+\.\w+:\d+:[\d+:]?)/', $tag_free_content, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

        $elements = array();
        foreach ($clang_matches as $key => $val)
        {
            if (preg_match('/([\w*\s*(!@#$%^&*()-+;\'{}\[\])*]+\.\w+:\d+:[\d+:]?)/', $val)
                && array_key_exists($key + 1, $clang_matches)
                && (strpos($clang_matches[$key + 1], "error:") !== false
                    || strpos($clang_matches[$key + 1], "note:") !== false
                    || strpos($clang_matches[$key + 1], "in asm") !== false
                    || strpos($clang_matches[$key], "in asm") !== false)
            )
            {
                if (strpos($val, "In file included from ") !== false)
                    $val = str_replace("In file included from ", "", $val);
                $val = str_replace("In file included from ", "", $val);
                $elements[] = $val;
            }
        }

        // Split the elements from above and get an associative array structure of [filename => lines]
        $clang_elements = array();
        foreach ($elements as $element)
        {

            // The first part is filename.extension, the second represents the line,
            // and the third one is the column number (not used for now).
            $split = explode(':', $element);

            if (!array_key_exists($split[0], $clang_elements))
            {
                $clang_elements[$split[0]] = array();
                $clang_elements[$split[0]][] = $split[1];
                continue;
            }
            $clang_elements[$split[0]][] = $split[1];
        }
        return $clang_elements;
    }

    private function getGccErrorFileList($avr_output)
    {
        /**
         * Avr gcc's output processing
         */
        // Get all 'filename.extension:line' elements.
        // Note that avr-gcc output only includes filenames and lines in error reporting, not collumns.
        preg_match_all('/([\w*\s*(!@#$%^&*()-+;\'{}\[\])*]+\.\w+:\d+:[\d+:]?)/', $avr_output, $gcc_matches, PREG_PATTERN_ORDER);

        $gcc_elements = array();
        foreach ($gcc_matches[0] as $element)
        {

            // The first part is filename.extension, the second represents the line.
            $split = explode(':', $element);
            if (!array_key_exists($split[0], $gcc_elements))
            {
                $gcc_elements[$split[0]] = array();
                $gcc_elements[$split[0]][] = $split[1];
                continue;
            }
            $gcc_elements[$split[0]][] = $split[1];
        }
        return $gcc_elements;
    }

    private function cleanUpClangOutput($clang_output, $compiler_config, $option)
    {

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
                    && strpos($line, "error:") !== false)
            ) {

                if ($header_found === false) {
                    if (($option == "non_asm" && preg_match('/(\/compiler\.\w+\/libraries\/)/', $header)
                            || strpos($header, $compiler_config["arduino_cores_dir"]) !== false
                            || (array_key_exists("external_core_files", $compiler_config)
                                && strpos($header, $compiler_config["external_core_files"]) !== false))
                        || ($option == "asm"
                            && (strpos($header, "in asm") !== false
                                || strpos($body, "in asm") !== false))
                    ) {

                        if (preg_match('/(\/compiler\.\w+\/libraries\/)/', $header) && $libFound === false && $option != "asm") {
                            $this->compiler_logger->addInfo($this->logger_id." - Clang reports library issue.");
                            $libFound = true;
                        }
                        if ((strpos($header, $compiler_config["arduino_cores_dir"]) !== false
                                || (array_key_exists("external_core_files", $compiler_config)
                                    && strpos($header, $compiler_config["external_core_files"]) !== false))
                            && $coreFound === false && $option != "asm"
                        ) {
                            $this->compiler_logger->addInfo($this->logger_id." - Clang reports core issue.");
                            $coreFound = true;
                        }
                        if ((strpos($header, "in asm") !== false || strpos($body, "in asm") !== false) && $asmFound === false && $option == "asm") {
                            $this->compiler_logger->addInfo($this->logger_id." - Clang reports assembly issue.");
                            $asmFound = true;
                        }
                        $header = "";
                        $body = "";
                    }

                    if ($header != "") {
                        if (strpos($header, "</font></b>") == 0)
                            $header = substr_replace($header, '', 0, 11);
                        if (array_key_exists($key + 1, $content_line_array)
                            && strpos($content_line_array[$key + 1], "</font></b>") == 0
                        )
                            $body = $body."</font></b>";
                        $final .= $header."\n";
                        $final .= $body."\n";
                        $header = "";
                        $body = "";
                    }
                }

                $header .= $line."\n";
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
                        && strpos($body, "in asm") === false)
                ) {
                    if ($header != "")
                    {
                        if (strpos($header, "</font></b>") == 0)
                            $header = substr_replace($header, '', 0, 11);
                        $final .= $header."\n";
                        $final .= $body."\n";
                    }
                } else {
                    if (preg_match('/(\/compiler\.\w+\/libraries\/)/', $header) && $libFound === false && $option != "asm") {
                        $this->compiler_logger->addInfo($this->logger_id." - Clang reports library issue.");
                    }
                    if ((strpos($header, $compiler_config["arduino_cores_dir"]) !== false
                            || (array_key_exists("external_core_files", $compiler_config)
                                && strpos($header, $compiler_config["external_core_files"]) !== false))
                        && $coreFound === false && $option != "asm"
                    ) {
                        $this->compiler_logger->addInfo($this->logger_id." - Clang reports core issue.");
                    }
                    if ((strpos($header, "in asm") !== false || strpos($body, "in asm") !== false) && $asmFound === false && $option == "asm") {
                        $this->compiler_logger->addInfo($this->logger_id." - Clang reports assembly issue.");
                    }
                }
            }

            $header_found = false;
            $body .= $line."\n";

        }

        return $final;
    }

    private function pathRemover($output, $compiler_config)
    {

        // Remove any instance of "compiler.RANDOM/files/" folder name from the text, add (sketch file) info text
        $modified = str_replace($compiler_config["compiler_dir"]."/files/", '(sketch file) ', $output);

        // Remove any remaining instance of "compiler.RANDOM/" folder name from the text.
        $modified = str_replace($compiler_config["compiler_dir"]."/", '', $modified);

        // Replace userId_cb_personal_lib prefix from personal libraries errors with a (personal library file) info text.
        $modified = preg_replace('/libraries\/\d+_cb_personal_lib_/', '(personal library file) ', $modified);

        // Replace libraries/ prefix from personal libraries errors with a (personal library file) info text.
        $modified = str_replace('libraries/', '(library file) ', $modified);

        // Remove any instance of codebender arduino core files folder name from the text, add (arduino core file) info text
        $modified = str_replace($compiler_config["arduino_cores_dir"]."/v105/", '(arduino core file) ', $modified);

        // Remove any instance of codebender external core file folder name from the text, , add (arduino core file) info text
        if (isset($compiler_config["external_core_files"]) && $compiler_config["external_core_files"] != "") {
            $modified = str_replace($compiler_config["external_core_files"], '(arduino core file) ', $modified);
            $modified = str_replace("/override_cores/", '(arduino core file) ', $modified);
        }

        return $modified;
    }

}
