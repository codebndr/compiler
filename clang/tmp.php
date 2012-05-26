<?php
$filename="OzZnnTkbaY";

$CPPFLAGS = "-ffunction-sections -fdata-sections -fno-exceptions -funsigned-char -funsigned-bitfields -fpack-struct -fshort-enums -Os";
// This is temporary too :(
$CPPFLAGS .= " -Ibuild/variants/standard";

// Append project-specific stuff.
$CPPFLAGS .= " -mmcu=atmega328p -DARDUINO=100 -DF_CPU=16000000L";

echo "avr-g++ $CPPFLAGS -c -o $filename.o $filename.cpp -Ibuild/core \n";
/*

clang -fsyntax-only -ffunction-sections -fdata-sections -fno-exceptions -funsigned-char -fshort-enums -Os -I/Applications/Arduino.app/Contents/Resources/Java/hardware/tools/avr/lib/gcc/avr/4.3.2/include -I/Applications/Arduino.app/Contents/Resources/Java/hardware/tools/avr/lib/gcc/avr/4.3.2/include-fixed -I/Applications/Arduino.app/Contents/Resources/Java/hardware/tools/avr/avr/include -Ibuild/variants/standard -Ibuild/core -D__AVR_ATmega328P__ -DARDUINO=100 -DF_CPU=16000000L -Wno-attributes OzZnnTkbaY.cpp

clang -fsyntax-only -ffunction-sections -fdata-sections -fno-exceptions -funsigned-char -fshort-enums -Os -I/Applications/Arduino.app/Contents/Resources/Java/hardware/tools/avr/lib/gcc/avr/4.3.2/include-fixed -I/Applications/Arduino.app/Contents/Resources/Java/hardware/tools/avr/avr/include -Ibuild/variants/standard -Ibuild/core -D__AVR_ATmega328P__ -DARDUINO=100 -DF_CPU=16000000L -Wno-attributes OzZnnTkbaY.cpp

clang -fsyntax-only -ffunction-sections -fdata-sections -fno-exceptions -funsigned-char -fshort-enums -Os -I/Applications/Arduino.app/Contents/Resources/Java/hardware/tools/avr/avr/include -Ibuild/variants/standard -Ibuild/core -D__AVR_ATmega328P__ -DARDUINO=100 -DF_CPU=16000000L -Wno-attributes OzZnnTkbaY.cpp

clang -fsyntax-only -Os -I/Applications/Arduino.app/Contents/Resources/Java/hardware/tools/avr/avr/include -Ibuild/variants/standard -Ibuild/core -D__AVR_ATmega328P__ -DARDUINO=100 -DF_CPU=16000000L -Wno-attributes OzZnnTkbaY.cpp

clang -fsyntax-only -Os -Iinclude -I../build/variants/standard -I../build/core -D__AVR_ATmega328P__ -DARDUINO=100 -DF_CPU=16000000L -Wno-unknown-attributes -Wno-attributes OzZnnTkbaY.cpp

CHECK FOR A BETTER WAY TO SUPRESS ATTRIBUTES WARNING, INSTEAD OF USING -Wno-attributes

*/

?>
