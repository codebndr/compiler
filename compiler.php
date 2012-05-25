<?php

# Assertions:
#     - Source file exists and is a valid *.pde file
#     - Source file uses only core libraries
#     - Source file does NOT have an *.pde extension
#     - Core libraries are already compiled in build/core

# Where is this included?

function dothis($cmd, &$ret) { echo "\$ $cmd\n"; passthru($cmd, $ret); }
function dothat($cmd, &$out, &$ret)
{
	exec($cmd, $out, $ret); 
	if($ret)
		die("\$ $cmd\n ret: $ret out: $out");
}

function doit($cmd, &$out, &$ret)
{
	exec($cmd, $out, $ret); 
}


function config_output($output, $filename, &$lines, &$output_string)
{
	$output_string = "";
	$lines = array();
	foreach($output as $i)
	{
		$fat1 = "build/".$filename.":";
		$fat2 = "build/core/";
		$i = str_replace($fat1, " ", $i);
		$i = str_replace($fat2, " ", $i);
		
		$i = str_replace("tempfiles/".$filename.":", " ", $i)."\n";
		// $i = $i."\n<br />";
		$output_string .= $i;
		$colon = strpos($i, ":");
		$number = intval(substr($i, 0, $colon));
		$j = 0;
		for($j = 0; $j < $colon ; $j++)
		{
			if(!(strpos("1234567890", $i{$j}) === FALSE))
				break;
		}
		if(!($colon === FALSE) && $j < $colon)
		{
			$lines[] = $number;
		}
		
	}
}
function do_compile($filename, &$output, &$success, &$error)
{
	$path = "tempfiles/";
	$LIBS_PATH = "../aceduino/symfony/files/libraries/";
	// Temporary: some error checking?
	// This is ugly...
	$error = 0;
	
	$filename = $path.$filename;

	// General flags. Theese are common for all projects. Should be moved to a higher-level configuration.
	// Got these from original SConstruct. Get a monkey to check them?
	$CPPFLAGS = "-ffunction-sections -fdata-sections -fno-exceptions -funsigned-char -funsigned-bitfields -fpack-struct -fshort-enums -Os";
	$LDFLAGS = "-Os -Wl,--gc-sections -lm";
	$LIBB = "";
	$LIBB .= " -I".$LIBS_PATH."EEPROM -I".$LIBS_PATH."Ethernet -I".$LIBS_PATH."Firmata -I".$LIBS_PATH."LiquidCrystal";
	$LIBB .= " -I".$LIBS_PATH."SD -I".$LIBS_PATH."SPI -I".$LIBS_PATH."Servo -I".$LIBS_PATH."SoftwareSerial -I".$LIBS_PATH."Stepper -I".$LIBS_PATH."Wire";
	
	$LIBBSOURCES = "".$LIBS_PATH."LiquidCrystal/LiquidCrystal.o";

	// This is temporary too :(
	$CPPFLAGS .= " -Ibuild/variants/standard";

	// Append project-specific stuff.
	$CPPFLAGS .= " -mmcu=atmega328p -DARDUINO=100 -DF_CPU=16000000L";
	$LDFLAGS .= " -mmcu=atmega328p";

	// Where to places these? How to compile them?
	$SOURCES = "build/core/wiring_shift.o build/core/wiring_pulse.o build/core/wiring_digital.o build/core/wiring_analog.o build/core/WInterrupts.o build/core/wiring.o build/core/Tone.o build/core/WMath.o build/core/HardwareSerial.o build/core/Print.o build/core/WString.o";

	// Handle object files from libraries. Different CFLAGS? HELP!
	// Different error code, depending where it failed?

	dothat("./preprocess.py $filename 2>&1", $out, $ret); $error |= $ret; // *.pde -> *.cpp
	$out = "";
	doit("avr-g++ $LIBB $CPPFLAGS -c -o $filename.o $filename.cpp -Ibuild/core 2>&1", $out, $ret); // *.cpp -> *.o
	$output = $out;
	$success = !$ret;
	if($success)
	{
		dothat("avr-gcc $LDFLAGS -o $filename.elf $filename.o $SOURCES $LIBBSOURCES 2>&1", $out, $ret); $error |= $ret; // *.o -> *.elf
		dothat("objcopy -O ihex -R .eeprom $filename.elf $filename.hex 2>&1", $out, $ret); $error |= $ret; // *.elf -> *.hex
		dothat("avr-size --target=ihex $filename.hex 2>&1", $out, $ret); $error |= $ret; // We should be checking this.
	}
	if ($filename != $path."foo") // VERY TERMPORARY
	{
		if(file_exists($filename)) unlink($filename);	
	}
	else
	{
		if(file_exists($filename.".hex")) unlink($filename.".hex");	
	}
	if(file_exists($filename.".o")) unlink($filename.".o");	
	if(file_exists($filename.".cpp")) unlink($filename.".cpp");	
	if(file_exists($filename.".elf")) unlink($filename.".elf");	
	// Remeber to suggest a cronjob, in case something goes wrong...
	// find $path -name $filename.{o,cpp,elf,hex} -mtime +1 -delete

}
?>
