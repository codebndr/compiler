<?php

namespace Codebender\CompilerBundle\Handler;

class CompilerV2Handler extends CompilerHandler
{
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

        // Where compiled files go
        $config["output_dir"] = $project_dir . "/" . "output";

        // Where the compiler and base libraries live
        $config["base_dir"] = $config["arduino_cores_dir"] . "/v" . $config["version"];

        // This is used, for example, to provide object files, and to provide output files.
        $config["project_name"] = str_replace($config["project_dir"] . "/files/",
                                                       "",
                                                       $incoming_files["ino"][0]) . ".ino";

        // Set up a default library dir, but set it to empty so it won't be used by default.
        $config["lib_dir"] = "";

        // Step 1(part 2): Extract the library files included in the request.
        $files["libs"] = array();
        foreach ($libraries as $library => $library_files) {

            $lib_dir = $config["lib_dir"];
            $tmpVar = $this->extractFiles($library_files, $TEMP_DIR, $lib_dir,
                                          $files["libs"][$library], $library, true);
            if ($tmpVar["success"] === false)
                return $tmpVar;

            $config["lib_dir"] = $lib_dir;
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

        // Log the names of the project files and the libraries used in it.
        $this->makeLogEntry($request, $config, $should_archive, $ARCHIVE_PATH);

        // Step 4: Syntax-check and compile source files.
        $ret = $this->handleCompile("$project_dir/files", $incoming_files, $config, $format);
        if (array_key_exists("builder_time", $ret))
            $config["builder_time"] = $ret["builder_time"];

        // Step 4.5: Save the cache for future builds
        $this->saveCache($config);

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
            $path = $config["project_dir"] . "/output/sketch/" . $config["project_name"] . ".cpp.o";
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

        // Step 8: Convert the output to the requested format and measure its
        // size.
        $tmpVar = $this->convertOutput($format, $start_time, $config);

        if ($config['logging'] === true) {
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

    private function copyRecursive($src, $dst) {

        if (is_file($src)) {
            if (file_exists($dst) && is_dir($dst))
                return array(
                    "success" => false,
                    "message" => "Destination exists already, and is a directory.");

            if (!copy($src, $dst))
                return array(
                    "success" => false,
                    "message" => "Unable to copy $src to $dst.");
            return array("success" => true);
        }

        if (!is_dir($dst))
            if (!mkdir($dst, 0775, true))
                return array(
                    "success" => false,
                    "message" => "Unable to create directory $dst.");

        // The target directory exists.  Copy all files over.
        $dirent = dir($src);
        if (!$dirent)
            return array(
                "success" => false,
                "message" => "Unable to open directory " . $src . " for copying files.");

        while (false !== ($filename = $dirent->read())) {
            if (($filename == '.') || ($filename == '..'))
                continue;

            $ret = $this->copyRecursive($src . "/" . $filename, $dst . "/" . $filename);
            if ($ret["success"] != true) {
                $dirent->close();
                return $ret;
            }
        }
        $dirent->close();

        return array("success" => true);
    }

    private function copyCaches($sourceDirectory, $destinationDirectory, $caches)
    {
        if (!file_exists($sourceDirectory))
            return ['success' => null, 'message' => 'No existing cache found.'];

        // Ensure the target core directory exists
        if (!file_exists($destinationDirectory))
            if (!mkdir($destinationDirectory, 0777, true))
                return array(
                    "success" => false,
                    "message" => "Unable to create output dir.");

        // Go through each of the cache types and copy them, if they exist
        foreach ($caches as $dir) {
            if (!file_exists($sourceDirectory . "/" . $dir))
                continue;

            $ret = $this->copyRecursive($sourceDirectory . "/" . $dir, $destinationDirectory . "/" . $dir);
            if ($ret["success"] != true)
                return $ret;
        }

        return array("success" => true);
    }

    private function updateAccessTimesRecursive($dir, $pattern)
    {
        // The target directory exists.  Copy all files over.
        if (!file_exists($dir))
            return array(
                "success" => true,
                "message" => "Cache directory " . $dir . " does not exist.");

        if (!is_dir($dir))
            return array(
                "success" => false,
                "message" => "Cache directory " . $dir . " is not a directory.");

        $dirent = dir($dir);
        if (!$dirent)
            return array(
                "success" => false,
                "message" => "Unable to open directory " . $dir . " for updating access times.");

        while (false !== ($filename = $dirent->read())) {
            if (($filename == '.') || ($filename == '..'))
                continue;

            if ((substr($filename, strlen($filename) - strlen($pattern)) === $pattern)
                && file_exists($dir . "/" . $filename)) {
                $ret = touch($dir . "/" . $filename);
                if (!$ret) {
                    $dirent->close();
                    return array(
                        "success" => false,
                        "message" => "Unable to update " . $dir . "/" . $filename . " access time.");
                }
            }

            // Recurse into subdirectories, if we've encountered a subdir.
            if (is_dir($dir . "/" . $filename)) {
                $ret = $this->updateAccessTimesRecursive($dir . "/" . $filename, $pattern);
                if ($ret["success"] != true) {
                    $dirent->close();
                    return $ret;
                }
            }
        }
        $dirent->close();

        return array("success" => true);
    }

    private function updateDependencyPathsRecursive($dir, $old_dir, $new_dir)
    {
        $pattern = ".d";

        // The target directory exists.  Copy all files over.
        if (!file_exists($dir))
            return array(
                "success" => true,
                "message" => "Cache directory " . $dir . " does not exist.");

        if (!is_dir($dir))
            return array(
                "success" => false,
                "message" => "Cache directory " . $dir . " is not a directory.");

        $dirent = dir($dir);
        if (!$dirent)
            return array(
                "success" => false,
                "message" => "Unable to open directory " . $dir . " for updating access times.");

        while (false !== ($filename = $dirent->read())) {
            if (($filename == '.') || ($filename == '..'))
                continue;

            if ((substr($filename, strlen($filename) - strlen($pattern)) === $pattern)
                && file_exists($dir . "/" . $filename)) {
                $ret = touch($dir . "/" . $filename);
                $content = file_get_contents($dir . "/" . $filename);
                $ret = file_put_contents($dir . "/" . $filename, str_replace($old_dir, $new_dir, $content));
                if (!$ret) {
                    $dirent->close();
                    return array(
                        "success" => false,
                        "message" => "Unable to update " . $dir . "/" . $filename . " paths.");
                }
            }

            // Recurse into subdirectories, if we've encountered a subdir.
            if (is_dir($dir . "/" . $filename)) {
                $ret = $this->updateDependencyPathsRecursive($dir . "/" . $filename, $old_dir, $new_dir);
                if ($ret["success"] != true) {
                    $dirent->close();
                    return $ret;
                }
            }
        }
        $dirent->close();

        return array("success" => true);
    }

    private function updateAccessTimes($base_dir, $sub_dirs, $pattern)
    {
        foreach ($sub_dirs as $sub_dir) {
            if (file_exists($base_dir . "/" . $sub_dir)) {
                $ret = touch($base_dir . "/" . $sub_dir);
                if (!$ret)
                    return array(
                        "success" => false,
                        "message" => "Unable to update directory " . $base_dir . "/" . $sub_dir . " access time.");
            }

            $ret = $this->updateAccessTimesRecursive($base_dir . "/" . $sub_dir, $pattern);
            if ($ret["success"] != true)
                return $ret;
        }
        return array("success" => true);
    }

    private function updateDependencyPaths($output_dir, $sub_dirs, $old_dir, $new_dir)
    {
        foreach ($sub_dirs as $sub_dir) {
            $ret = $this->updateDependencyPathsRecursive($output_dir . "/" . $sub_dir, $old_dir, $new_dir);
            if ($ret["success"] != true)
                return $ret;
        }

        return array("success" => true);
    }

    private function cacheDirs()
    {
        return array("core", "libraries");
    }

    private function restoreCache($config)
    {
        $cache_dir = $this->object_directory
                   . "/" . $config["version"]
                   . "/" . $config["fqbn"]
                   . "/" . $config["vid"]
                   . "/" . $config["pid"]
                   ;
        $output_dir = $config["output_dir"];

        // Copy the files from the existing cache directory to the new project.
        $ret = $this->copyCaches($cache_dir, $output_dir, $this->cacheDirs());

        // A success of "null" indicates it was not successful, but didn't fail, probably
        // due to the lack of an existing cache directory.  That's fine, we just won't use
        // a cache.
        if ($ret["success"] == null)
            return array("success" => true);

        if ($ret["success"] != true)
            return $ret;

        // arduino-builder looks through dependency files.  Update the paths
        // in the cached files we're copying back.
        $this->updateDependencyPaths($output_dir, $this->cacheDirs(), "::BUILD_DIR::", $output_dir);

        $suffixes = array(".d", ".o", ".a");
        foreach ($suffixes as $suffix) {
            $ret = $this->updateAccessTimes($output_dir, $this->cacheDirs(), $suffix);
            if ($ret["success"] != true)
                return $ret;
        }

        return array("success" => true);
    }

    private function saveCache($config)
    {
        $cache_dir = $this->object_directory
                   . "/" . $config["version"]
                   . "/" . $config["fqbn"]
                   . "/" . $config["vid"]
                   . "/" . $config["pid"]
                   ;
        $output_dir = $config["output_dir"];

        $this->copyCaches($output_dir, $cache_dir, $this->cacheDirs());
        $this->updateDependencyPaths($cache_dir, $this->cacheDirs(), $output_dir, "::BUILD_DIR::");

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
        $builder_time = 0;
        if (array_key_exists('builder_time', $config)) {
            $builder_time = $config['builder_time'];
        }

        if (!in_array($format, ['elf', 'binary', 'hex'])) {
            return [
                'success' => false,
                'time' => microtime(true) - $start_time,
                'builder_time' => $builder_time,
                'step' => 8,
                'message' => 'Unrecognized format requested.'
            ];
        }

        // Set the output file base path. All the product files (bin/hex/elf) have the same base name.
        $base_path = $config['output_dir'] . '/' . $config['project_name'];

        $content = '';
        if ($format == 'elf') {
            $content_path = $base_path . '.elf';
            if (file_exists($content_path)) {
                $content = base64_encode(file_get_contents($content_path));
            }
        }
        if ($format == 'hex') {
            $content_path = $base_path . '.hex';
            if (file_exists($content_path))
                $content = file_get_contents($content_path);
            else {
                // TODO
                // Locate the correct objcopy (depends on AVR/SAM) and create the hex output from the .elf file.
            }
        }
        if ($format == 'binary') {
            $content_path = $base_path . '.bin';
            if (file_exists($content_path)) {
                $content = base64_encode(file_get_contents($content_path));
            } else {
                // TODO
                // Locate the correct objcopy (depends on AVR/SAM) and create the hex output from the .elf file.
            }
        }

        // If content is still empty, something went wrong
        if ($content == '') {
            return [
                'success' => false,
                'time' => microtime(true) - $start_time,
                'builder_time' => $builder_time,
                'step' => 8,
                'message' => 'There was a problem while generating the your binary file from ' . $content_path . '.'
            ];
        }

        // Get the size of the requested output file and return to the caller
        $size = $config['output_dir'] . '/' . $config['project_name'] . '.size';
        $size = intval(file_get_contents($size));

        return [
            'success' => true,
            'time' => microtime(true) - $start_time,
            'builder_time' => $builder_time,
            'size' => $size,
            'output'  => $content
        ];
    }

    private function setVariables($request, &$format, &$libraries, &$should_archive, &$config)
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

    private function handleCompile($compile_directory, $files_array, $config, $format,
                                   $caching = false, $name_params = null)
    {
        $base_dir = $config["base_dir"];
        $core_dir = $config["external_core_files"];
        $output_dir = $config["output_dir"];
        $fqbn = $config["fqbn"];
        $filename = $files_array["ino"][0] . ".ino";
        $size_script = $config["project_dir"] . "/get_size.sh";
        $libraries = array();

        // Set up a default library directory
        array_push($libraries, $base_dir . "/" . "libraries");
        if ($config["lib_dir"])
            array_push($libraries, $config["lib_dir"]);

        // Set the VID and PID, if they exist
        $vid_pid = "";
        if (($config["vid"] != "null") && ($config["pid"] != "null")) {
            $vid = intval($config["vid"], 0);
            $pid = intval($config["pid"], 0);
            $vid_pid = sprintf(" -vid-pid=0X%1$04X_%2$04X", $vid, $pid);
        }

        if (!file_exists($output_dir))
            if (!mkdir($output_dir, 0777, true))
                return array(
                        "success" => false,
                        "step"    => 4,
                        "message" => "Unable to make output path.",
                        "debug"   => $output_dir
                );

        if (!file_exists($base_dir))
            return array(
                    "success" => false,
                    "step"    => 4,
                    "message" => "Base path does not exist.",
                    "debug"   => $base_dir
            );

        if (!file_exists($filename))
            return array(
                    "success" => false,
                    "step"    => 4,
                    "message" => "Source file does not exist.",
                    "debug"   => $filename
            );

        $hardware_dirs = array(
            $base_dir . "/" . "hardware",
            $base_dir . "/" . "packages"
        );
        $tools_dirs = array(
            $base_dir . "/" . "tools-builder",
            $base_dir . "/" . "hardware/tools/avr",
            $base_dir . "/" . "packages"
        );

        // Create build.options.json, which is used for caching object files.
        // Also use it for passing parameters to the arduino-builder program.
        $build_options =
                "{\n"
              . "  \"builtInLibrariesFolders\": \"\",\n"
              . "  \"customBuildProperties\": \"recipe.hooks.objcopy.postobjcopy.0.pattern=" . $size_script . " \\\"{compiler.path}{compiler.size.cmd}\\\" \\\"{build.path}/{build.project_name}.elf\\\" \\\"{build.path}/{build.project_name}.size\\\"\",\n"
              . "  \"fqbn\": \"" . $fqbn . "\",\n"
              . "  \"hardwareFolders\": \"" . implode(",", $hardware_dirs) . "\",\n"
              . "  \"otherLibrariesFolders\": \"" . implode(",", $libraries) . "\",\n"
              . "  \"runtime.ide.version\": \"" . ($config["version"] * 100) . "\",\n"
              . "  \"sketchLocation\": \"" . $filename . "\",\n"
              . "  \"toolsFolders\": \"" . implode(",", $tools_dirs) . "\"\n"
              . "}"
              ;

        // Copy cached config files into directory (if they exist)
        file_put_contents($output_dir . "/" . "build.options.json", $build_options);
        $ret = $this->restoreCache($config);
        if ($ret["success"] != true)
            return $ret;

        // The arduino-builder tool automatically processes multiple .ino files into one,
        // so we only need to specify the first file to build.
        file_put_contents($size_script,
                  "#!/bin/sh\n"
                . "\"$1\" -A \"$2\" | grep Total | awk '{print $2}' > \"$3\"\n"
                );
        system("chmod a+x $size_script");

        $hardware_args = "";
        foreach ($hardware_dirs as $hardware)
            $hardware_args .= " -hardware=\"" . $hardware . "\"";

        $tools_args = "";
        foreach ($tools_dirs as $tools)
            $tools_args .= " -tools=\"" . $tools . "\"";

        $verbose_compile = "";
        if (array_key_exists("verbose_compile", $config) && $config["verbose_compile"])
            $verbose_compile = " -verbose";

        // Ensure the lib_str lists the libraries in the same order as the build.options.json, in
        // order to allow arduino-builder to reuse files.
        $lib_str = "";
        foreach ($libraries as $lib)
            $lib_str .= " -libraries=\"" . $lib . "\"";

        $cmd = $base_dir . "/arduino-builder"
                . " -logger=human"
                . " -compile"
                . $verbose_compile
                . " -ide-version=\"" . ($config["version"] * 100) . "\""
                . " -warnings=all"
                . $hardware_args
                . $lib_str
                . " -build-path=" . $output_dir
                . $tools_args
                . " -prefs='recipe.hooks.objcopy.postobjcopy.0.pattern=$size_script \"{compiler.path}{compiler.size.cmd}\" \"{build.path}/{build.project_name}.elf\" \"{build.path}/{build.project_name}.size\"'"
                . " -fqbn=" . $fqbn
                . $vid_pid
                . " " . escapeshellarg($filename)
                . " 2>&1"
                ;
        $arduino_builder_time_start = microtime(true);
        exec($cmd, $output, $ret_link);
        $arduino_builder_time_end = microtime(true);

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
                "output_dir" => $output_dir,
                "message" => $this->pathRemover($output, $config),
                "log"     => array($cmd, implode("\n", $output)),
                "filename" => $filename
            );
        }

        return array(
            "success" => true,
            "builder_time" => $arduino_builder_time_end - $arduino_builder_time_start,
            "log"     => array($cmd, $output)
        );
    }

    protected function pathRemover($output, $config)
    {
        // Remove the path of the project directory, add (sketch file) info text
        $modified = str_replace($config["project_dir"]."/files/", '(sketch file) ', $output);

        // Remove any remaining instance of the project directory name from the text.
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
