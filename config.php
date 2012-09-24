<?php
putenv("ARDUINO_FILES_DIR=arduino-files");
putenv("ARDUINO_LIBS_DIR=".getenv("ARDUINO_FILES_DIR")."/libraries/");
putenv("ARDUINO_EXTRA_LIBS_DIR=".getenv("ARDUINO_FILES_DIR")."/extra-libraries/");
?>
