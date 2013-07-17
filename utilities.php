<?php
/**
\file
\brief Functions used by the compiler backend.

\author Dimitrios Christidis
\author Vasilis Georgitzikis

\copyright (c) 2012-2013, The Codebender Development Team
\copyright Licensed under the Simplified BSD License
 */


/**
\brief Converts text with ANSI color codes to HTML.

\param string $text The string to convert.
\return A string with HTML tags.

Takes a string with ANSI color codes and converts them to HTML tags. Can be
useful for displaying the output of terminal commands on a web page. Handles
codes that modify the color (foreground and background) as well as the format
(bold, italics, underline and strikethrough). Other codes are ignored.

An ANSI escape sequence begins with the characters <b>^[</b> (hex 0x1B) and
<b>[</b>, and ends with <b>m</b>. The color code is placed in between. Multiple
color codes can be included, separated by semicolon.
 */
function ansi_to_html($text)
{
	$FORMAT = array(
		0 => NULL, // reset modes to default
		1 => "b", // bold
		3 => "i", // italics
		4 => "u", // underline
		9 => "del", // strikethrough
		30 => "black", // foreground colors
		31 => "red",
		32 => "green",
		33 => "yellow",
		34 => "blue",
		35 => "purple",
		36 => "cyan",
		37 => "white",
		40 => "black", // background colors
		41 => "red",
		42 => "green",
		43 => "yellow",
		44 => "blue",
		45 => "purple",
		46 => "cyan",
		47 => "white");
	// Matches ANSI escape sequences, starting with ^[[ and ending with m.
	// Valid characters inbetween are numbers and single semicolons. These
	// characters are stored in register 1.
	//
	// Examples: ^[[1;31m ^[[0m
	$REGEX = "/\x1B\[((?:\d+;?)*)m/";

	$text = htmlspecialchars($text);
	$stack = array();

	// ANSI escape sequences are located in the input text. Each color code
	// is replaced with the appropriate HTML tag. At the same time, the
	// corresponding closing tag is pushed on to the stack. When the reset
	// code '0' is found, it is replaced with all the closing tags in the
	// stack (LIFO order).
	while (preg_match($REGEX, $text, $matches))
	{
		$replacement = "";
		foreach (explode(";", $matches[1]) as $mode)
		{
			switch ($mode)
			{
				case 0:
					while ($stack)
						$replacement .= array_pop($stack);
					break;
				case 1:
				case 3:
				case 4:
				case 9:
					$replacement .= "<$FORMAT[$mode]>";
					array_push($stack, "</$FORMAT[$mode]>");
					break;
				case 30:
				case 31:
				case 32:
				case 33:
				case 34:
				case 35:
				case 36:
				case 37:
					$replacement .= "<font style=\"color: $FORMAT[$mode]\">";
					array_push($stack, "</font>");
					break;
				case 40:
				case 41:
				case 42:
				case 43:
				case 44:
				case 45:
				case 46:
				case 47:
					$replacement .= "<font style=\"background-color: $FORMAT[$mode]\">";
					array_push($stack, "</font>");
					break;
				default:
					error_log(__FUNCTION__."(): Unhandled ANSI code '$mode' in ".__FILE__);
					break;
			}
		}
		$text = preg_replace($REGEX, $replacement, $text, 1);
	}

	// Close any tags left in the stack, in case the input text didn't.
	while ($stack)
		$text .= array_pop($stack);

	return $text;
}

/**
\brief Extracts included headers from source code.

\param string $code The program's source code.
\return An array of headers.

Takes a string containing the source code of a C/C++ program, parses the
preprocessor directives and makes a list of header files to include. The
postfix <b>.h</b> is removed from the header names.
 */
function read_headers($code)
{
	// Matches preprocessor include directives, has high tolerance to
	// spaces. The actual header (without the postfix .h) is stored in
	// register 1.
	//
	// Examples:
	// #include<stdio.h>
	// # include "proto.h"
	$REGEX = "/^\s*#\s*include\s*[<\"]\s*(\w*)\.h\s*[>\"]/";

	$headers = array();
	foreach (explode("\n", $code) as $line)
		if (preg_match($REGEX, $line, $matches))
			$headers[] = $matches[1];

	return $headers;
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
function debug_exec($command)
{
	echo "$ $command\n";
	passthru("$command 2>&1");
}
