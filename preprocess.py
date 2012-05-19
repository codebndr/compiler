#!/usr/bin/env python

# Generate valid *.cpp from *.pde files.
# This script is temporary. The code must be ported to PHP into
# /var/www/aceduino/symfony/Symfony/web/compiler.php
#
# Usage: preprocess.py FILENAME
# The input file *MUST* exist. Creates a FILENAME.cpp file.

from glob import glob
import os
import re
import sys

# Constants ported from original SConstruct
ARDUINO_SKEL = "build/core/main.cpp"

# Functions ported from original SConstruct
def fnProcessing(target, source, env):
    wp = open (target, 'wb')
    wp.write(open(ARDUINO_SKEL).read())

    types='''void 
    int char word long 
    float double byte long
    boolean 
    uint8_t uint16_t uint32_t 
    int8_t int16_t int32_t
    '''
    types=' | '.join(types.split())
    re_signature=re.compile(r"""^\s* (
        (?: (%s) \s+ )?
        \w+ \s*
        \( \s* ((%s) \s+ \*? \w+ (?:\s*,\s*)? )* \)
        ) \s* {? \s* $""" % (types,types), re.MULTILINE|re.VERBOSE)

    prototypes = {}

    for line in open(source):
	result = re_signature.findall(line)
	if result:
	    prototypes[result[0][0]] = result[0][1]

    for name in prototypes.keys():
        print ("%s;"%(name))
        wp.write("%s;\n"%name)

    sourcePath = source.replace('\\', '\\\\');
    wp.write('#line 1 "%s"\r\n' % sourcePath)
    wp.write(open(source).read())


if __name__ == "__main__":
    fnProcessing(sys.argv[1] + ".cpp", sys.argv[1], None)
