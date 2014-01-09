<?php
/**
 * Created by JetBrains PhpStorm.
 * User: iluvatar
 * Date: 31/7/13
 * Time: 10:15 AM
 * To change this template use File | Settings | File Templates.
 */

namespace Codebender\CompilerBundle\Handler;


class PreprocessingHandler
{
	/**
	\brief The following functions generate valid C++ code from Arduino source code.

	Arduino source code files are simplified C++ files. Thus, some preprocessing has
	to be done to convert them to valid C++ code for the compiler to read. Some of
	these "simplifications" include:
	- lack of a <b>main()</b> function
	- lack of function prototypes

	A skeleton file is provided in the Arduino core files that contains a
	<b>main()</b> function. Its contents have to be linked to the output file later.
	The prototypes of the functions defined in the input file should be added
	above the code. This is required to avoid compiler errors regarding undefined
	functions.

	The programmer is not aware of this modifications to his code. In case of a
	compiler error, the line numbering would be wrong. To avoid this issue, a
	<b>\#line</b> preprocessor directive is used. Thus it is ensured that the line
	numbering in the output file will be the same as the input file.

	A regular expression is used to match function definitions in the input file.
	Consequently this process will never be as sophisticated as a lexical analyzer.
	Thus, some valid constructs cannot be matched. These include:
	- definitions that are split across multiple lines
	- definitions for variadic functions
	- typedefs for the return value or the parameters
	- pointers to functions
	- arrays, structs, and unions
	 */

    /**
     \param string $code The original code of the sketch
     \return string A copy of the code with no comments, single- or double- quoted strings
      or pre-processor directives
     */
    function remove_comments_directives_quotes($code)
    {
        // Use a copy of the code and strip comments, pre-processor directives, single- and double-quoted strings

        $regex = "/(\'.\')|(\"(?:[^\"\\\\]|\\\\.)*\")|(\/\/.*?$)|(\/\*[^*]*(?:\*(?!\/)[^*]*)*\*\/)|(^\s*\#.*?$)/m";

        // Replace every match of the regular expression with a whitespace

        $return_code = preg_replace($regex, " ", $code);

        return $return_code;
    }

    /**
     \param string $code The code returned from remove_comments_directives_quotes function
     \return string The input code having all top level braces collapsed
     */
    function empty_braces($code)
    {
        // For every line of the code remove all the contents of top level braces

        $nesting = 0;
        $start = 0;
        $return_code = "";
        // Use the code as an array of characters
        for ($i=0; $i<strlen($code); $i++){

            if($code[$i] == "{"){
                if($nesting == 0){
                    $return_code .= substr($code, $start, $i+1 - $start);
                }
                $nesting++;
                continue;
            }
            if($code[$i] == "}"){
                $nesting--;
                if($nesting == 0){
                    $start = $i;
                }
                continue;

            }
        }
        $return_code .= substr($code, $start, strlen($code)-$start);

        return $return_code;
    }

    /**
     \param string $code The code returned from empty_braces function
     \return array An array including any prototypes found in the original code
     */
    function find_existing_prototypes(&$code)
    {
        // In this case, the original code is used. Existing prototypes are matched, stored, and then removed from the code,
        // so that in the next step the compiler knows which prototypes should really be generated
        $existing_prototypes = array();
        $regex = "/[\w\[\]\*]+\s+[&\[\]\*\w\s]+\([&,\[\]\*\w\s]*\)(?=\s*;)/m";

        if(preg_match_all($regex, $code, $matches)){
            $existing_prototypes = $matches[0];
        }

        $code = preg_replace($regex, " ", $code);

        return $existing_prototypes;
    }

    /**
     \param string $code The sketch code provided to the compiler
     \param array $existing_prototypes Array of prototypes returned by find_existing_prototypes function
     \return string The string including the function prototypes for the code
     */
    function generate_prototypes($code, $existing_prototypes)
    {
        // This function uses a regular expression to match all function declarations, generate the
        // respective prototype and store all the prototypes in a string
        $regex = "/[\w\[\]\*]+\s+[&\[\]\*\w\s]+\([&,\[\]\*\w\s]*\)(?=\s*\{)/m";

        $function_prototypes = "";
        if(preg_match_all($regex, $code, $matches)){

            foreach($matches[0] as $match){

                if(!empty($existing_prototypes)){
                    /*
                     * If a prototype match has no parameters, two prototypes are generated, one with no parameters and
                     * one with parameter void. Then the code searches if one of them already exists in the original code
                     */
                    if(preg_match("/\(\s*\)/", $match)){
                        $match_void = preg_replace("/(\(\s*\))/", "(void)", $match);
                        if(in_array($match_void, $existing_prototypes)){continue;}
                    }
                    // If none of the above was true, check if the prototype exists
                    if(in_array($match, $existing_prototypes)){continue;}
                }
                // If everything is ok, add the prototype to the return value
                $function_prototypes .= $match.";\n";
            }
        }
        return $function_prototypes;
    }

    /**
     \param string $code The sketch code
     \return int The position where function prototypes should be placed
     */
    function insertion_position($code)
    {
        // Use the following regular expression to match whitespaces, single- and multiline comments and preprocessor directives
        $regex = "/(\s+|(\/\*[^*]*(?:\*(?!\/)[^*]*)*\*\/)|(\/\/.*?$)|(\#(?:\\\\\\n|.)*))/m";

        // Then find all the matches in the original code and count the offset of each one.
        preg_match_all($regex, $code, $matches, PREG_OFFSET_CAPTURE);

        $prev_position = 0;
        $position = 0;
        // The second offset of each matches[0] object contains the starting position (string index) of the match in the code
        //
        foreach($matches[0] as $match){
            // In case of a mismatch between prev_position and the beginning index of the current match, a non matching
            // expression exists between the last two matches. This is the position where the prototypes should be placed.
            // In other words, this is the first line of the code that is not a whitespace, comment, or preprocessor directive
            if($match[1] != $prev_position){
                $position = $prev_position - 1; break;}
            $prev_position += strlen($match[0]);
        }
        // If position is set to -1, there have been found no matches to the regular expression, so it must be set back to zero
        if($position == -1){$position = 0;}
        return $position;
    }

    /**
     \param string $code The initial sketch code
     \param $function_prototypes The function prototypes returned by generate_prototypes function
     \param int $position The position to place the prototypes returned by insertion_position function
     \return string Valid c++ code
     */
    function build_code($code, $function_prototypes, $position)
    {

        // To build the final code, the compiler starts adding every character of the original string, until the the position
        // found by insertion_position is reached. Then, the function prototypes are added, as well as a preprocessor directive
        // to fix the line numbering.
        $line=1;
        $return_code = "";
        if(!($position == 0)){
            for($i=0; $i<=$position; $i++){

                $return_code .= $code[$i];
                if($code[$i] == "\n"){
                    $line++;
                }

            }
        }

        // Include the Arduino header file
        $return_code .= "#include <Arduino.h>\n";
        // Then insert the prototypes, and finally the rest of the code
        $return_code .= $function_prototypes."#line $line\n";
        if($position == 0)
            $next_pos = 0;
        else
            $next_pos = $position +1;
        for($i=$next_pos; $i<strlen($code); $i++){
            $return_code .= $code[$i];
        }

        return $return_code;
    }

    function ino_to_cpp($code, $filename = NULL)
    {
        // Remove comments, preprocessor directives, single- and double- quotes
        $no_comms_code = $this->remove_comments_directives_quotes($code);
        // Remove any code between all top level braces
        $empty_braces_code = $this->empty_braces($no_comms_code);
        // Find already existing prototypes
        $existing_prototypes = $this->find_existing_prototypes($empty_braces_code);
        // Generate prototypes that do not already exist
        $function_prototypes = $this->generate_prototypes($empty_braces_code, $existing_prototypes);
        // Find the right place to insert the function prototypes (after any preprocessor directives, comments, before any function declaration)
        $insertion_position = $this->insertion_position($code);

        $new_code = "";
		// Add a preprocessor directive for line numbering.
		if ($filename)
            $new_code .= "#line 1 \"$filename\"\n";
        else
            $new_code .= "#line 1\n";
        // Build the new code for the cpp file that will eventually be compiled
        $new_code .= $this->build_code($code, $function_prototypes, $insertion_position);

		return $new_code;

    }



	/**
	\brief Decodes and performs validation checks on input data.

	\param string $request The JSON-encoded compile request.
	\return The value encoded in JSON in appropriate PHP type or <b>NULL</b>.
	 */
	function validate_input($request)
	{
		$request = json_decode($request, true);

		// Request must be successfully decoded.
		if ($request === NULL)
			return NULL;
		// Request must contain certain entities.
		if (!(array_key_exists("format", $request)
			&& array_key_exists("version", $request)
			&& array_key_exists("build", $request)
			&& array_key_exists("files", $request)
			&& is_array($request["build"])
			&& array_key_exists("libraries", $request)
			&& array_key_exists("mcu", $request["build"])
			&& array_key_exists("f_cpu", $request["build"])
			&& array_key_exists("core", $request["build"])
			&& is_array($request["files"]))
		)
			return NULL;

		// Leonardo-specific flags.
		if (array_key_exists("variant", $request["build"]) && $request["build"]["variant"] == "leonardo")
			if (!(array_key_exists("vid", $request["build"])
				&& array_key_exists("pid", $request["build"]))
			)
				return NULL;

		// Values used as command-line arguments may not contain any special
		// characters. This is a serious security risk.
        $values = array("version", "mcu", "f_cpu", "core", "vid", "pid");
        if (array_key_exists("variant", $request["build"]))
            $values[] = "variant";
		foreach ($values as $i)
			if (isset($request["build"][$i]) && escapeshellcmd($request["build"][$i]) != $request["build"][$i])
				return NULL;

		// Request is valid.
		return $request;
	}
}