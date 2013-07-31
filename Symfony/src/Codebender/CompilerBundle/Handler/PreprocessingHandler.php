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
	\brief Generates valid C++ code from Arduino source code.

	\param string $skel The contents of the Arduino skeleton file.
	\param string $code The input source code.
	\param string $filename (optional) The name of the input file.
	\return Valid C++ code, the result of processing the input.

	Arduino source code files are simplified C++ files. Thus, some preprocessing has
	to be done to convert them to valid C++ code for the compiler to read. Some of
	these "simplifications" include:
	- lack of a <b>main()</b> function
	- lack of function prototypes

	A skeleton file is provided in the Arduino core files that contains a
	<b>main()</b> function. Its contents have to be at the top of the output file.
	The prototypes of the functions defined in the input file should be added
	beneath that. This is required to avoid compiler errors regarding undefined
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
	function ino_to_cpp($skel, $code, $filename = NULL)
	{
		// Supported primitives for parameters and return values. They are put
		// in a string, separated by "|" to be used in regular expressions.
		// Type "void" is put in its own variable to be more readable later on
		// in $REGEX.
		$VOID = "void";
		$TYPES = array($VOID, "int", "char", "word", "short", "long", "float",
			"byte", "boolean", "uint8_t", "uint16_t", "uint32_t", "int8_t",
			"int16_t", "int32_t");
		$TYPES = implode("|", $TYPES);
		// Type qualifiers for declarators.
		$QUALS = array("const", "volatile");
		$QUALS = implode("|", $QUALS);
		// Type specifiers for declarators.
		$SPECS = array("signed", "unsigned");
		$SPECS = implode("|", $SPECS);
		// Matches C/C++ function definitions, has high tolerance to whitespace
		// characters. Grouping constructs are used but no value is stored in
		// the registers.
		//
		// The limitations of this regular expression are described in the
		// comments above the function definition.
		//
		// Examples:
		// int foo()
		// int foo(void)
		// int foo(int bar)
		// int *foo(const int bar)
		// int *foo(volatile int *bar, int baz)
		$REGEX = "/^\s*((?:$SPECS)\s*)*(?:$TYPES)\s*\**\s*\w+\s*\((?:\s*(?:$VOID|((?:$QUALS)\s*)*((?:$SPECS)\s*)*(?:$TYPES)\s*\**\s*\w+\s*,?)\s*)*\)/";

		$new_code = "";

		// Firstly, include the contents of the skeleton file.
		$new_code .= $skel;

		// Secondly, generate and add the function prototypes.
		foreach (explode("\n", $code) as $line)
			if (preg_match($REGEX, $line, $matches))
				$new_code .= $matches[0].";\n";

		// Thirdly, add a preprocessor directive for line numbering.
		if ($filename)
			$new_code .= "#line 1 \"$filename\"\n";
		else
			$new_code .= "#line 1\n";

		// Lastly, include the input source code.
		$new_code .= $code;

		return $new_code;
	}

	/**
	\brief Decodes and performs validation checks on input data.

	\param string $request The JSON-encoded compile request.
	\return The value encoded in JSON in appropriate PHP type or <b>NULL</b>.
	 */
	function validate_input($request)
	{
		$request = json_decode($request);

		// Request must be successfully decoded.
		if ($request === NULL)
			return NULL;
		// Request must contain certain entities.
		if (!(array_key_exists("format", $request)
			&& array_key_exists("version", $request)
			&& array_key_exists("build", $request)
			&& array_key_exists("files", $request)
			&& is_object($request->build)
			&& array_key_exists("mcu", $request->build)
			&& array_key_exists("f_cpu", $request->build)
			&& array_key_exists("core", $request->build)
			&& array_key_exists("variant", $request->build)
			&& is_array($request->files))
		)
			return NULL;

		// Leonardo-specific flags.
		if ($request->build->variant == "leonardo")
			if (!(array_key_exists("vid", $request->build)
				&& array_key_exists("pid", $request->build))
			)
				return NULL;

		// Values used as command-line arguments may not contain any special
		// characters. This is a serious security risk.
		foreach (array("version", "mcu", "f_cpu", "core", "variant", "vid", "pid") as $i)
			if (isset($request->build->$i) && escapeshellcmd($request->build->$i) != $request->build->$i)
				return NULL;

		// Request is valid.
		return $request;
	}
}