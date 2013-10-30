<?php
/**
\file
\brief Functions used by the compiler backend.

\author Dimitrios Christidis
\author Vasilis Georgitzikis

\copyright (c) 2012-2013, The Codebender Development Team
\copyright Licensed under the Simplified BSD License
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
			$compiler = new CompilerHandler();
				
			$this->setLoggingParams($request, $params);
				
			$reply = $compiler->main($request, $params);
			
			return new Response(json_encode($reply));
		}
		else
		{
			return new Response(json_encode(array("success" => false, "step" => 0, "message" => "Invalid API version.")));
		}
	}

	/**
	\brief Creates a list of the configuration parameters to be used in the compilation process.

	\return An array of the parameters.

	This function accesses the Symfony global configuration parameters, and creates an array that our handlers (which
	don't have access to them) can use them.

	 */
	private function generateParameters()
	{
		$parameters = array("cc", "cpp", "as", "ar", "ld", "clang", "objcopy", "size", "cflags", "cppflags", "asflags", "ldflags", "ldflags_tail", "clang_flags", "objcopy_flags", "size_flags", "output", "arduino_cores_dir", "arduino_skel", "auth_key");

		$compiler_config = array();

		foreach ($parameters as $parameter)
		{
			$compiler_config[$parameter] = $this->container->getParameter($parameter);
		}

		return $compiler_config;
	}
	
	private function setLoggingParams($request, &$compiler_config)
	{
		$temp = json_decode($request,true);
		if(array_key_exists('logging', $temp) and $temp['logging'] == true)
		{
			/*
			Generate a random part for the log name based on current date and time,
			in order to avoid naming different Blink projects for which we need logfiles
			*/
			//$randpart = date('YmdHis');
			$randPart = date('YzHis');
			/*
			Then find the name of the arduino file which usually is the project name itself 
			and mix them all together
			*/
			
			foreach($temp['files'] as $file){
				if(pathinfo($file['filename'], PATHINFO_EXTENSION) == "ino"){$basename = pathinfo($file['filename'], PATHINFO_FILENAME);}
			}
			if(!isset($basename)){$basename="logfile";}
			
			$compiler_config['logging'] = true;
			$directory = "/tmp/codebender_log";
			if(!file_exists($directory)){mkdir($directory);}
			
			$compiler_config['logFileName'] = $directory ."/". $basename ."_". $randPart .".txt";
			
			file_put_contents($compiler_config['logFileName'], '');
		}
		elseif(!array_key_exists('logging', $temp) or $temp['logging'] == false)
		{
			$compiler_config['logging'] = false;
		}
	}
}
