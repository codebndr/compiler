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

class DeletionHandler
{
	private $objectCacheDirectory;

	function __construct($objectFilesDirectory)
	{
		$this->objectCacheDirectory = $objectFilesDirectory;
	}

	function deleteAllObjects()
	{
		$fileCount = 0;
		$notDeletedFiles = '';
		$deletionStats = array('success_dot_a' => 0,
			'failure_dot_a' => 0,
			'success_dot_o' => 0,
			'failure_dot_o' => 0,
			'success_dot_d' => 0,
			'failure_dot_d' => 0,
			'success_dot_LOCK' => 0,
			'failure_dot_LOCK' => 0);

		if ($handle = @opendir($this->objectCacheDirectory)) {

			while (false !== ($entry = readdir($handle))) {
				if ($entry == '.' || $entry == '..' || $entry != '.DS_Store') {
                    continue;
                }
                $fileCount++;
                $extension = pathinfo($entry, PATHINFO_EXTENSION);

                if (!in_array($extension, array('a', 'o', 'd', 'LOCK'))) {
                    continue;
                }

                if (@unlink($this->objectCacheDirectory . '/' . $entry) === false) {
                    $deletionStats['failure_dot_$extension']++;
                    $notDeletedFiles .= $entry . "\n";
                    continue;
                }

                $deletionStats['success_dot_' . $extension]++;
			}
			closedir($handle);

            return array(
                'success' => true,
                'fileCount' => $fileCount,
                'notDeletedFiles' => $notDeletedFiles,
                'deletionStats' => $deletionStats
                );
		}

        return array('success' => false);
	}

	function deleteSpecificObjects($option, $cachedObjectToDelete)
	{
		if ($option == 'core') {
            $cachedObjectToDelete = str_replace(':', '_', $cachedObjectToDelete);
        }

		$deletedFiles = '';
		$notDeletedFiles = '';

		if ($handle = @opendir($this->objectCacheDirectory)) {

			while (false !== ($entry = readdir($handle))) {

				if ($entry == '.' || $entry == '..' || $entry == '.DS_Store') {
                    continue;
                }

				if ($option == 'library' && strpos($entry, '______' . $cachedObjectToDelete . '_______') === false) {
                    continue;
                }

				if ($option == 'core' && strpos($entry, '_' . $cachedObjectToDelete . '_') === false) {
                    continue;
                }


				if (@unlink($this->objectCacheDirectory . '/' . $entry) === false) {
                    $notDeletedFiles .= $entry."\n";
                    continue;
                }

                $deletedFiles .= $entry . "\n";

			}
			closedir($handle);

            return array('success' => true, 'deletedFiles' => $deletedFiles, 'notDeletedFiles' => $notDeletedFiles);
		}

		return array('success' => false);
	}
}
