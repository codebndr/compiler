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
use Codebender\CompilerBundle\Handler\CompilerV2Handler;
use Codebender\CompilerBundle\Handler\DeletionHandler;

class DefaultController extends Controller
{
    public function statusAction()
    {
        return new Response(json_encode(array(
                        "success" => true,
                        "status"  => "OK"
        )));
    }

    public function testAction($authorizationKey)
    {
        $params = $this->generateParameters();

        if ($authorizationKey !== $params["authorizationKey"]) {
            return new Response(json_encode(array(
                            "success" => false,
                            "step"    => 0,
                            "message" => "Invalid authorization key."
            )));
        }

        set_time_limit(0); // make the script execution time unlimited (otherwise the request may time out)

        // change the current Symfony root dir
        chdir($this->get('kernel')->getRootDir()."/../");

        //TODO: replace this with a less horrible way to handle phpunit
        exec("phpunit -c app --stderr 2>&1", $output, $return_val);

        return new Response(json_encode(array(
                        "success" => (bool) !$return_val,
                        "message" => implode("\n", $output)
        )));
    }

    public function indexAction($authorizationKey, $version)
    {
        $params = $this->generateParameters();

        if ($authorizationKey !== $params["authorizationKey"]) {
            return new Response(json_encode(array(
                            "success" => false,
                            "step"    => 0,
                            "message" => "Invalid authorization key."
            )));
        }

        if ($version == "v1") {
            $request = $this->getRequest()->getContent();

            //Get the compiler service
            /** @var CompilerHandler $compiler */
            $compiler = $this->get('compiler_handler');

            $reply = $compiler->main($request, $params);

            return new Response(json_encode($reply));
        } elseif ($version == "v2") {
            $request = $this->getRequest()->getContent();

            //Get the compiler service
            /** @var CompilerHandler $compiler */
            $compiler = $this->get('compiler_v2_handler');

            $reply = $compiler->main($request, $params);

            return new Response(json_encode($reply));
        } else {
            return new Response(json_encode(array(
                            "success" => false,
                            "step"    => 0,
                            "message" => "Invalid API version."
            )));
        }
    }

    public function deleteAllObjectsAction($authorizationKey, $version)
    {
        if ($this->container->getParameter('authorizationKey') != $authorizationKey) {
            return new Response(json_encode(
                array(
                    'success' => false,
                    'step'    => 0,
                    'message' => 'Invalid authorization key.'
                )
            ));
        }

        if (($version != 'v1') && ($version != 'v2')) {
            return new Response(json_encode(
                array(
                    'success' => false,
                    'step'    => 0,
                    'message' => 'Invalid API version.'
                )
            ));
        }

        //Get the compiler service
        /** @var DeletionHandler $deleter */
        $deleter = $this->get('deletion_handler');

        $response = $deleter->deleteAllObjects();

        if ($response['success'] !== true) {
            return new Response(json_encode(
                array(
                    'success' => false,
                    'step'    => 0,
                    'message' => 'Failed to access object files directory.'
                )
            ));
        }

        return new Response(json_encode(
            array_merge(
                array(
                    'success' => true,
                    'message' => 'Object files deletion complete. Found ' . $response['fileCount'] . ' files.'
                ),
                $response['deletionStats'],
                array("Files not deleted" => $response['notDeletedFiles'])
            )));
    }

    public function deleteSpecificObjectsAction($authorizationKey, $version, $option, $cachedObjectToDelete)
    {
        if ($this->container->getParameter('authorizationKey') != $authorizationKey) {
            return new Response(json_encode(
                array('success' => false, 'step' => 0, 'message' => 'Invalid authorization key.')
            ));
        }

        if (($version != 'v1') && ($version != 'v2')) {
            return new Response(json_encode(
                array('success' => false, 'step' => 0, 'message' => 'Invalid API version.')
            ));
        }

        //Get the compiler service
        /** @var DeletionHandler $deleter */
        $deleter = $this->get('deletion_handler');

        $response = $deleter->deleteSpecificObjects($option, $cachedObjectToDelete);

        if ($response['success'] !== true) {
            return new Response(json_encode(
                array('success' => false, 'step' => 0, 'message' => 'Failed to access object files directory.')
            ));
        }

        if (!empty($response["notDeletedFiles"])) {
            $message = 'Failed to delete one or more of the specified core object files.';
            if ($option == 'library') {
                $message = 'Failed to delete one or more of the specified library object files.';
            }

            return new Response(json_encode(
                array_merge(array('success' => false, 'step' => 0, 'message' => $message), $response)
            ));
        }

        $message = 'Core object files deleted successfully.';
        if ($option == 'library') {
            $message = 'Library deleted successfully.';
        }

        return new Response(json_encode(array_merge(array('success' => true, 'message' => $message), $response)));
    }

    /**
     * \brief Creates a list of the configuration parameters to be used in the compilation process.
     *
     * \return An array of the parameters.
     *
     * This function accesses the Symfony global configuration parameters,
     * and creates an array that our handlers (which don't have access to them)
     * can use them.
     */
    private function generateParameters()
    {
        $parameters = array(
                "binutils", "python", "clang", "logdir", "temp_dir",
                "archive_dir", "autocompletion_dir", "autocompleter",
                "cflags", "cppflags", "asflags", "arflags", "ldflags",
                "ldflags_tail", "clang_flags", "objcopy_flags", "size_flags",
                "output", "arduino_cores_dir", "external_core_files",
                "authorizationKey");

        $compiler_config = array();

        foreach ($parameters as $parameter) {
            $compiler_config[$parameter] = $this->container->getParameter($parameter);
        }

        return $compiler_config;
    }

}
