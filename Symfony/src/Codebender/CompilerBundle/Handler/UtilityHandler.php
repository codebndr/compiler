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
	/**
	 * \brief Extracts the files included in a compile request.
	 *
	 * \param string $directory The directory to extract the files to.
	 * \param array $request_files The files structure, as taken from the JSON request.
	 * \return A list of files or a reply message in case of error.
	 *
	 * Takes the files structure from a compile request and creates each file in a
	 * specified directory. If requested, it may create additional directories and
	 * have the files placed inside them accordingly.
	 *
	 * Also creates a new structure where each key is the file extension and the
	 * associated value is an array containing the absolute paths of the file, minus
	 * the extension.
	 *
	 * In case of error, the return value is an array that has a key <b>success</b>
	 * and contains the response to be sent back to the user.
	 */
	function extract_files($directory, $request_files, $lib_extraction)
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

		if (!file_exists($directory))
			mkdir($directory, 0777, true);

		foreach ($request_files as $file)
		{
			$filename = $file["filename"];
			$content = $file["content"];
			$ignore = false;

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

				if (($lib_extraction === true) && ($new_directory !== "utility"))
					$ignore = true;
				if (!file_exists("$directory/$new_directory"))
					mkdir("$directory/$new_directory", 0777, true);
				// There is no reason to check whether mkdir()
				// succeeded, given that the call to
				// file_put_contents() that follows would fail
				// as well.

			}

			if (file_put_contents("$directory/$filename", $content) === false)
				return $failure_response;

			if ($ignore)
				continue;

			if (preg_match($REGEX, $filename, $matches))
				$files[$matches[2]][] = "$directory/$matches[1]";
			else
				error_log(__FUNCTION__."(): Unhandled file extension '$filename'");
		}

		// All files were extracted successfully.
		return array("success" => true, "files" => $files);
	}

	/**
	 * \brief Searches for files with specific extensions in a directory.
	 *
	 * \param string $directory The directory to search for files.
	 * \param mixed $extensions An array of strings, the extensions to look for.
	 * \return A list of files that have the appropriate extension.
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
	 * \brief Executes a command and displays the command itself and its output.
	 *
	 * \param string $command The command to be executed.
	 *
	 * Simplifies the creation and debugging of pages that rely on multiple external
	 * programs by "emulating" the execution of the requested command in a terminal
	 * emulator. Can be useful during early stages of development. Replace with
	 * <b>exec()</b> afterwards.
	 *
	 * To perform the command execution, <b>passthru()</b> is used. The string
	 * <b>2\>&1</b> is appended to the command to ensure messages sent to standard
	 * error are not lost.
	 *
	 * \warning It is not possible to redirect the standard error output to a file.
	 */
	function debug_exec($command, /** @noinspection PhpUnusedParameterInspection */
	                    &$output, /** @noinspection PhpUnusedParameterInspection */
	                    &$retval)
	{
		echo "$ $command\n";
		passthru("$command 2>&1");
	}
}
