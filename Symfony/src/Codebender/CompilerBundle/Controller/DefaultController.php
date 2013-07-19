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
    public function indexAction($type)
    {
	    if($type == "status")
	    {
		    return new Response("OK");
	    }
	    else if($type == "v1")
	    {
		    $request = $this->getRequest()->getContent();
		    $compiler = new CompilerHandler();
		    $reply = $compiler->main($request, $this->generateParameters());
		    return new Response(json_encode($reply));
	    }
	    else
	    {
		    return new Response("Unknown Type");
	    }
    }

	private function generateParameters()
	{
		$parameters = array("cc", "cpp", "ld", "clang", "objcopy", "size", "cflags", "cppflags", "ldflags", "ldflags_tail", "clang_flags", "objcopy_flags", "size_flags", "output", "root", "arduino_skel", "arduino_version");

		$compiler_config = array();

		foreach($parameters as $parameter)
		{
			$compiler_config[$parameter] = $this->container->getParameter($parameter);
		}

		return $compiler_config;
	}
}
