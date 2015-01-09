<?php
/**
 * Created by PhpStorm.
 * User: iluvatar
 * Date: 9/1/15
 * Time: 4:44 AM
 *
 * \file
 * \brief Functions used to delete caches (object files) from past compilations.
 *
 * \author Vasilis Georgitzikis
 *
 * \copyright (c) 2012-2015, The Codebender Development Team
 * \copyright Licensed under the Simplified BSD License
 */

namespace Codebender\CompilerBundle\Handler;

use Symfony\Bridge\Monolog\Logger;

class DeletionHandler
{
	private $compiler_logger;
	private $object_directory;

	function __construct(Logger $logger, $objdir)
	{
		$this->compiler_logger = $logger;
		$this->object_directory = $objdir;
	}

	function deleteAllObjects(&$success, &$fileCount, &$deletionStats, &$undeletedFiles)
	{
		$success = false;
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

		if ($handle = opendir($this->object_directory))
		{
			$success = true;

			while (false !== ($entry = readdir($handle)))
			{
				if ($entry != "." && $entry != ".." && $entry != ".DS_Store")
				{
					$fileCount++;
					$extension = pathinfo($entry, PATHINFO_EXTENSION);

					if (!in_array($extension, array("a", "o", "d", "LOCK")))
						continue;

					if (@unlink($this->object_directory."/$entry") === false)
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
	}

	function deleteSpecificObjects(&$success, &$response, &$option, &$to_delete)
	{
		if ($option == "core")
			$to_delete = str_replace(":", "_", $to_delete);

		$success = true;
		$response = array();
		$response["deleted_files"] = "";
		$response["undeleted_files"] = "";

		if ($handle = opendir($this->object_directory))
		{
			while (false !== ($entry = readdir($handle)))
			{
				if ($entry == "." || $entry == ".." || $entry == ".DS_Store")
					continue;

				if ($option == "library" && strpos($entry, "______".$to_delete."_______") === false)
					continue;

				if ($option == "core" && strpos($entry, "_".$to_delete."_") === false)
					continue;


				if (@unlink($this->object_directory."/$entry") === false)
					$response["undeleted_files"] .= $entry."\n";
				else
					$response["deleted_files"] .= $entry."\n";

			}
			closedir($handle);
		}
		else
			$success = false;
	}
} 