<?php
/**
 * Created by JetBrains PhpStorm.
 * User: iluvatar
 * Date: 31/7/13
 * Time: 10:47 AM
 * To change this template use File | Settings | File Templates.
 */

namespace Codebender\CompilerBundle\Handler;


class UtilityHandler
{
	public $directory;

	function __construct()
	{
		$this->directory = "/tmp/codebender_object_files";
		if(!file_exists($this->directory))
			mkdir($this->directory);
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
		$sources = $this->get_files_by_extension($directory, array("c", "cpp", "S"));

		if (file_exists("$directory/utility"))
		{
			$utility_sources = $this->get_files_by_extension("$directory/utility", array("c", "cpp", "S"));
			foreach ($utility_sources as &$i)
				$i = "utility/$i";
			unset($i);
			$sources = array_merge($sources, $utility_sources);
		}

		if (file_exists("$directory/avr-libc") && $libc_headers)
		{
			$avr_libc_sources = $this->get_files_by_extension("$directory/avr-libc", array("c", "cpp", "S"));
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
			$object_file = $this->directory."/".pathinfo(str_replace("/", "__", $directory."_"), PATHINFO_FILENAME)."_______"."${mcu}_${f_cpu}_${core}_${variant}".(($variant == "leonardo") ? "_${vid}_${pid}" : "")."_______".pathinfo(str_replace("/", "__", "$filename"), PATHINFO_FILENAME);
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

				//Include header files from c library ( needed for malloc and realloc )
				if($libc_headers && !array_key_exists("files", $request_template))

					if (file_exists("$directory/avr-libc"))
					{
						$request_template["files"] = array();
						$header_files = array();

						$avr_libc_headers = $this->get_files_by_extension("$directory/avr-libc", array("h", "inc"));
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
				$compiler = new CompilerHandler();
				$reply = $compiler->main(json_encode($request), $compiler_config);

				if ($reply["success"] == false)
					return array(
						"success" => false,
						"step" => 5,
						"message" => $reply["message"],
						"debug" => $request);

				//TODO: Make a check here and fail gracefully
				file_put_contents("$object_file.o", base64_decode($reply["output"]));
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
		return array("success" => true, "files" => $files);
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
	function debug_exec($command, /** @noinspection PhpUnusedParameterInspection */
	                    &$output, /** @noinspection PhpUnusedParameterInspection */
	                    &$retval)
	{
		echo "$ $command\n";
		passthru("$command 2>&1");
	}
}