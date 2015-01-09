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

namespace Codebender\CompilerBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Codebender\CompilerBundle\Handler\CompilerHandler;

class DefaultController extends Controller
{
	public function statusAction()
	{
		return new Response(json_encode(array("success" => true, "status" => "OK")));
	}

	public function testAction($auth_key)
	{
		$params = $this->generateParameters();

		if ($auth_key !== $params["auth_key"])
		{
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Invalid authorization key.")));
		}

		set_time_limit(0); // make the script execution time unlimited (otherwise the request may time out)

		// change the current Symfony root dir
		chdir($this->get('kernel')->getRootDir()."/../");

		//TODO: replace this with a less horrible way to handle phpunit
		exec("phpunit -c app --stderr 2>&1", $output, $return_val);

		return new Response(json_encode(array("success" => (bool) !$return_val, "message" => implode("\n", $output))));
	}

	public function indexAction($auth_key, $version)
	{
		$params = $this->generateParameters();

		if ($auth_key !== $params["auth_key"])
		{
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Invalid authorization key.")));
		}

		if ($version == "v1")
		{
			$request = $this->getRequest()->getContent();

			//Get the compiler service
			$compiler = $this->get('compiler_handler');

			$reply = $compiler->main($request, $params);

			return new Response(json_encode($reply));
		}
		else
		{
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Invalid API version.")));
		}
	}

	public function deleteAllObjectsAction($auth_key, $version)
	{
		if ($this->container->getParameter('auth_key') != $auth_key)
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Invalid authorization key.")));

		if ($version != "v1")
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Invalid API version.")));

		$tempDir = $this->container->getParameter('temp_dir');
		$objectFilesDir = $this->container->getParameter('objdir');
		$fileCount = 0;
		$undeletedFiles = "";
		$deletionStats = array("success_dot_a" => 0,
			"failure_dot_a" => 0,
			"success_dot_o" => 0,
			"failure_dot_o" => 0,
			"success_dot_d" => 0,
			"failure_dot_d" => 0,
			"success_dot_LOCK" => 0,
			"failure_dot_LOCK" => 0);

		if ($handle = opendir("$tempDir/$objectFilesDir"))
		{

			while (false !== ($entry = readdir($handle)))
			{
				if ($entry != "." && $entry != ".." && $entry != ".DS_Store")
				{
					$fileCount++;
					$extension = pathinfo($entry, PATHINFO_EXTENSION);

					if (!in_array($extension, array("a", "o", "d", "LOCK")))
						continue;

					if (@unlink("$tempDir/$objectFilesDir/$entry") === false)
					{
						$deletionStats["failure_dot_$extension"]++;
						$undeletedFiles .= $entry."\n";
					}
					else
						$deletionStats["success_dot_$extension"]++;
				}
			}
			closedir($handle);
		}
		else
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Failed to access object files directory.")));

		return new Response(json_encode(array_merge(array("success" => true,
				"message" => "Object files deletion complete. Found $fileCount files."),
			$deletionStats,
			array("Files not deleted" => $undeletedFiles))));
	}

	public function deleteSpecificObjectsAction($auth_key, $version, $option, $to_delete)
	{
		if ($this->container->getParameter('auth_key') != $auth_key)
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Invalid authorization key.")));

		if ($version != "v1")
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Invalid API version.")));

		$tempDir = $this->container->getParameter('temp_dir');
		$objectFilesDir = $this->container->getParameter('objdir');

		if ($option == "core")
			$to_delete = str_replace(":", "_", $to_delete);

		$response = array();
		$response["deleted_files"] = "";
		$response["undeleted_files"] = "";

		if ($handle = opendir("$tempDir/$objectFilesDir"))
		{
			while (false !== ($entry = readdir($handle)))
			{
				if ($entry == "." || $entry == ".." || $entry == ".DS_Store")
					continue;

				if ($option == "library" && strpos($entry, "______".$to_delete."_______") === false)
					continue;

				if ($option == "core" && strpos($entry, "_".$to_delete."_") === false)
					continue;


				if (@unlink("$tempDir/$objectFilesDir/$entry") === false)
					$response["undeleted_files"] .= $entry."\n";
				else
					$response["deleted_files"] .= $entry."\n";

			}
			closedir($handle);
		}
		else
		{
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Failed to access object files directory.")));
		}

		if ($response["undeleted_files"] != "")
		{
			$message = ($option == "library") ? "Failed to delete one or more of the specified library object files." : "Failed to delete one or more of the specified core object files.";
			return new Response(json_encode(array_merge(array("success" => false, "step" => 0, "message" => $message), $response)));
		}

		$message = ($option == "library") ? "Library deleted successfully." : "Core object files deleted successfully.";
		return new Response(json_encode(array_merge(array("success" => true, "message" => $message), $response)));
	}

	/**
	 * \brief Creates a list of the configuration parameters to be used in the compilation process.
	 *
	 * \return An array of the parameters.
	 *
	 * This function accesses the Symfony global configuration parameters, and creates an array that our handlers (which
	 * don't have access to them) can use them.

	 */
	private function generateParameters()
	{
		$parameters = array("binutils", "python", "clang", "logdir", "temp_dir", "archive_dir", "autocompletion_dir", "autocompleter", "cflags", "cppflags", "asflags", "arflags", "ldflags", "ldflags_tail", "clang_flags", "objcopy_flags", "size_flags", "output", "arduino_cores_dir", "external_core_files", "auth_key");

		$compiler_config = array();

		foreach ($parameters as $parameter)
		{
			$compiler_config[$parameter] = $this->container->getParameter($parameter);
		}

		return $compiler_config;
	}

}
