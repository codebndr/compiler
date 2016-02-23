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
    function main($request, $config)
    {
        $log = array();

        error_reporting(E_ALL & ~E_STRICT);

        // The tmp folder where logfiles and object files are placed
        $TEMP_DIR = $config["temp_dir"];

        // The directory name where archive files are stored in $TEMP_DIR
        $ARCHIVE_DIR = $config["archive_dir"];

        $start_time = microtime(true);

        // Step 0: Reject the request if the input data is not valid.
        $tmpVar = $this->requestValid($request);
        if ($tmpVar["success"] === false)
            return $tmpVar;

        $this->setVariables($request, $format, $libraries, $should_archive, $config);

        $incoming_files = array();

        // Step 1(part 1): Extract the project files included in the request.
        $tmpVar = $this->extractFiles($request["files"], $TEMP_DIR, $project_dir, $incoming_files, "files");
        if ($tmpVar["success"] === false)
            return $tmpVar;

        // Add the compiler temp directory to the config struct.
        $config["project_dir"] = $project_dir;

        // This is used, for example, to provide object files, and to provide output files.
        $config["project_name"] = str_replace($project_dir . "/files/",
                                                       "",
                                                       $incoming_files["ino"][0]) . ".ino";

        // Step 1(part 2): Extract the library files included in the request.
        $files["libs"] = array();
        foreach ($libraries as $library => $library_files) {

            $tmpVar = $this->extractFiles($library_files, $TEMP_DIR, $project_dir, $files["libs"][$library], "libraries/$library", true);
            if ($tmpVar["success"] === false)
                return $tmpVar;
        }

        if ($should_archive) {
            $arch_ret = $this->createArchive($project_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
            if ($arch_ret["success"] === false)
                return $arch_ret;
        }
        else
            $ARCHIVE_PATH = "";

        //Set logging to true if requested, and create the directory where logfiles are stored.
        $tmpVar = $this->setLoggingParams($request, $config, $TEMP_DIR, $project_dir);
        if ($tmpVar["success"] === false)
            return array_merge($tmpVar, ($should_archive) ? array("archive" => $ARCHIVE_PATH) : array());

        // Step 2: Preprocess Arduino source files.
        // Ordinarily this would convert .ino files into .cpp files, but arduino-builder
        // and ctypes takes care of that for us already.

        // Step 3: Copy cached config files into directory
        $this->copyCachedCoreDirectory($config);

        // Log the names of the project files and the libraries used in it.
        $this->makeLogEntry($request, $config, $should_archive, $ARCHIVE_PATH);

        // Step 4: Syntax-check and compile source files.
        $ret = $this->handleCompile("$project_dir/files", $incoming_files, $config, $format);

        /*
        $log_content = (($config['logging'] === true) ? @file_get_contents($config['logFileName']) : "");
        if ($config['logging'] === true) {
            if ($log_content !== false) {
                $ret["log"] = $log_content;
                file_put_contents($config["project_dir"]."/log", $log_content);
            } else
                $ret["log"] = "Failed to access logfile.";
        }
        */
        if (($config['logging'] === true) && $ret["log"]) {
            foreach ($ret["log"] as $line) {
                array_push($log, $line);
            }
        }

        if ($should_archive) {
            $arch_ret = $this->createArchive($project_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
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
                ($config['logging'] === true) ? array("log" => $log) : array());

        //TODO: return objects if more than one file??
        if ($format == "object") {
            $path = $config["project_dir"] . "/sketch/" . $config["project_name"] . ".cpp.o";
            $content = base64_encode(file_get_contents($path));
            if (count($incoming_files["ino"]) != 1 || !$content) {
                return array_merge(array(
                        "success" => false,
                        "step" => -1, //TODO: Fix this step?
                        "message" => ""),
                    ($should_archive) ? array("archive" => $ret["archive"]) : array(),
                    ($config['logging'] === true) ? array("log" => $log) : array());
            } else
                return array_merge(array(
                        "success" => true,
                        "time" => microtime(true) - $start_time,
                        "output" => $content),
                    ($should_archive) ? array("archive" => $ret["archive"]) : array(),
                    ($config['logging'] === true) ? array("log" => $log) : array());
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
        $tmpVar = $this->convertOutput($format, $start_time, $config);

        if ($config['logging'] === true) {
            /*
            $log_content = @file_get_contents($config['logFileName']);
            if (!$log_content)
                $tmpVar["log"] = "Failed to access logfile.";
            else {
                file_put_contents($config["project_dir"]."/log", $log_content);
                $tmpVar["log"] = $log_content;
            }
            */
            $tmpVar["log"] = $log;
        }

        if ($should_archive) {
            $arch_ret = $this->createArchive($project_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
            if ($arch_ret["success"] === false)
                $tmpVar["archive"] = $arch_ret["message"];
            else
                $tmpVar["archive"] = $ARCHIVE_PATH;
        }
        return $tmpVar;
    }

    private function copyCachedCoreDirectory($config)
    {
        $cache_path = $this->object_directory
                    . "/" . $config["version"]
                    . "/" . $config["fqbn"]
                    . "/" . $config["vid"]
                    . "/" . $config["pid"]
                    ;
        if (!file_exists($cache_path))
            return array(
                "success" => false,
                "message" => "No existing cache found");
    }

    private function createCachedCoreDirectory($config)
    {
        if (!@mkdir($directory, 0777, true))
            return array(
                "success" => false,
                "message" => "Unable to create cache path.");
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

    private function createArchive($project_dir, $TEMP_DIR, $ARCHIVE_DIR, &$ARCHIVE_PATH)
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
        exec("tar -zcf $ARCHIVE_PATH -C $TEMP_DIR/ ".pathinfo($project_dir, PATHINFO_BASENAME), $output, $ret_var);

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

    private function makeLogEntry($request, $config, $should_archive, $archive_path)
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

        $this->logger_id = microtime(true)."_".substr($config['project_dir'], -6)."_user:$user_id"."_project:$sketch_id";

        $this->compiler_logger->addInfo($this->logger_id." - ".implode(" ", $req_elements));
        if ($should_archive)
            $this->compiler_logger->addInfo($this->logger_id." - "."Archive file: $archive_path");
    }

    private function convertOutput($format, $start_time, $config)
    {
        $content = "";
        if ($format == "elf") {
            $content_path = $config["project_dir"] . "/" . $config["project_name"] . ".elf";
            if (file_exists($content_path))
                $content = base64_encode(file_get_contents($content_path));
            else
                $content = "";
        } elseif ($format == "hex") {
            $content_path = $config["project_dir"] . "/" . $config["project_name"] . ".hex";
            if (file_exists($content_path))
                $content = file_get_contents($content_path);
            else
                $content = "";
        } elseif ($format == "binary") {
            $content_path = $config["project_dir"] . "/" . $config["project_name"] . ".bin";
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

        $size = $config["project_dir"] . "/" . $config["project_name"] . ".size";
        $size = intval(file_get_contents($size));

        return array(
            "success" => true,
            "time"    => microtime(true) - $start_time,
            "size"    => $size,
            "output"  => $content);
    }

    private function setVariables($request,
            &$format, &$libraries, &$should_archive, &$config)
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

        $config["fqbn"] = $request["fqbn"];
        $config["vid"] = $vid;
        $config["pid"] = $pid;
        $config["version"] = $version;
    }

    private function setLoggingParams($request, &$config, $temp_dir, $project_dir)
    {
        //Check if $request['logging'] exists and is true, then make the logfile, otherwise set
        //$config['logdir'] to false and return to caller
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

            $config['logging'] = true;
            $directory = $temp_dir."/".$config['logdir'];
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

            $compiler_part = str_replace(".", "_", substr($project_dir, strpos($project_dir, "compiler")));

            $config['logFileName'] = $directory."/".$basename."_".$compiler_part."_".$randPart.".txt";

            file_put_contents($config['logFileName'], '');
        }
        elseif (!array_key_exists('logging', $request) || (!$request['logging']))
            $config['logging'] = false;

        return array("success" => true);
    }

    private function handleCompile($compile_directory, $files_array, $config, $format,
                                   $caching = false, $name_params = null)
    {
        $base_path = $config["arduino_cores_dir"] . "/v" . $config["version"];
        $core_path = $config["external_core_files"];
        $output_path = $config["project_dir"];
        $fqbn = $config["fqbn"];
        $filename = $files_array["ino"][0] . ".ino";
        $size_script = $config["project_dir"] . "/get_size.sh";

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
        if (($config["vid"] != "null") && ($config["pid"] != "null")) {
            $vid = intval($config["vid"], 0);
            $pid = intval($config["pid"], 0);
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

        if ($config["logging"]) {
            file_put_contents($config['logFileName'], $cmd, FILE_APPEND);
            file_put_contents($config['logFileName'], implode(" ", $output), FILE_APPEND);
        }

        if ($ret_link) {
            return array(
                "success" => false,
                "retcode" => $ret_link,
                "output" => $output,
                "cmd" => $cmd,
                "output_dir" => $output_path,
                "message" => $this->pathRemover($output, $config),
                "log"     => array($cmd, implode("\n", $output)),
                "filename" => $filename
            );
        }

        return array(
            "success" => true,
            "log"     => array($cmd, $output)
        );
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

    private function pathRemover($output, $config)
    {

        // Remove any instance of "compiler.RANDOM/files/" folder name from the text, add (sketch file) info text
        $modified = str_replace($config["project_dir"]."/files/", '(sketch file) ', $output);

        // Remove any remaining instance of "compiler.RANDOM/" folder name from the text.
        $modified = str_replace($config["project_dir"]."/", '', $modified);

        // Replace userId_cb_personal_lib prefix from personal libraries errors with a (personal library file) info text.
        $modified = preg_replace('/libraries\/\d+_cb_personal_lib_/', '(personal library file) ', $modified);

        // Replace libraries/ prefix from personal libraries errors with a (personal library file) info text.
        $modified = str_replace('libraries/', '(library file) ', $modified);

        // Remove any instance of codebender arduino core files folder name from the text, add (arduino core file) info text
        $modified = str_replace($config["arduino_cores_dir"]."/v105/", '(arduino core file) ', $modified);

        // Remove any instance of codebender external core file folder name from the text, , add (arduino core file) info text
        if (isset($config["external_core_files"]) && $config["external_core_files"] != "") {
            $modified = str_replace($config["external_core_files"], '(arduino core file) ', $modified);
            $modified = str_replace("/override_cores/", '(arduino core file) ', $modified);
        }

        return $modified;
    }
}
