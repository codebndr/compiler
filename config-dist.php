<?php

/**
\file
\brief Configuration for the Codebender compiler backend.

Copy this file to 'config.php' and edit as appropriately.

\author Dimitrios Christidis

\copyright (c) 2013, The Codebender Development Team
\copyright Licensed under the Simplified BSD License
*/

$compiler_config = array();

// Path to cores and libraries.
$compiler_config["root"] = "";

// The version of the Arduino files.
$compiler_config["arduino_version"] = "104";

// Paths to various executables used by the compiler. These depend on the
// distribution used and the method of installation. Linking is performed by
// avr-gcc (same as compiler_config["cc"]).
$compiler_config["cc"] = "/usr/bin/avr-gcc";
$compiler_config["cpp"] = "/usr/bin/avr-g++";
$compiler_config["ld"] = "/usr/bin/avr-gcc";
$compiler_config["clang"] = "/usr/bin/clang";
$compiler_config["objcopy"] = "/usr/bin/avr-objcopy";
$compiler_config["size"] = "/usr/bin/avr-size";

// -------- You shouldn't need to edit anything beyond this point. -------- \\

// Command-line arguments used when calling the external executables. More
// arguments are used in compiler.php. During linking, the actual order of
// flags is important. Thus, two variables have to be used: "ldflags" and
// "ldflags_tail".
$compiler_config["cflags"] = "-Os -ffunction-sections -fdata-sections";
$compiler_config["cppflags"] = "-Os -ffunction-sections -fdata-sections -fno-exceptions";
$compiler_config["ldflags"] = "-Os -Wl,--gc-sections";
$compiler_config["ldflags_tail"] = "-lm -lc";
$compiler_config["clang_flags"] = "-w -fsyntax-only -fcolor-diagnostics -I/usr/lib/avr/include";
$compiler_config["objcopy_flags"] = "-R .eeprom";
$compiler_config["size_flags"] = "";

// The name of the Arduino skeleton file, which is prepended to *.ino files.
$compiler_config["arduino_skel"] = "main.cpp";

// The default name of the output file, which is created in /tmp/compiler.xxxx
// by default.
$compiler_config["output"] = "output";

?>
