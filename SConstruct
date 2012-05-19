#!/usr/bin/python

# scons script for the Arduino sketch
# http://code.google.com/p/arscons/
#
# Copyright (C) 2010 by Homin Lee <ff4500@gmail.com>
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 3 of the License, or
# (at your option) any later version.

# You'll need the serial module: http://pypi.python.org/pypi/pyserial

# Basic Usage:
# 1. make a folder which have same name of the sketch (ex. Blink/ for Blik.pde)
# 2. put the sketch and SConstruct(this file) under the folder.
# 3. to make the HEX. do following in the folder.
#     $ scons
# 4. to upload the binary, do following in the folder.
#     $ scons upload

# Thanks to:
# * Ovidiu Predescu <ovidiu@gmail.com> and Lee Pike <leepike@gmail.com>
#     for Mac port and bugfix.
#
# This script tries to determine the port to which you have an Arduino
# attached. If multiple USB serial devices are attached to your
# computer, you'll need to explicitly specify the port to use, like
# this:
#
# $ scons ARDUINO_PORT=/dev/ttyUSB0
#
# To add your own directory containing user libraries, pass EXTRA_LIB
# to scons, like this:
#
# $ scons EXTRA_LIB=<my-extra-library-dir>
#
from glob import glob
import sys
import re
import os
pathJoin = os.path.join

env = Environment()
platform = env['PLATFORM']

def getUsbTty(rx):
    usb_ttys = glob(rx)
    if len(usb_ttys) == 1: return usb_ttys[0]
    else: return None

AVR_BIN_PREFIX = None
AVRDUDE_CONF = None

if platform == 'darwin':
    # For MacOS X, pick up the AVR tools from within Arduino.app
    ARDUINO_HOME_DEFAULT = '/Applications/Arduino.app/Contents/Resources/Java'
    ARDUINO_PORT_DEFAULT = getUsbTty('/dev/tty.usbserial*')
    SKETCHBOOK_HOME_DEFAULT = ''
elif platform == 'win32':
    # For Windows, use environment variables.
    ARDUINO_HOME_DEFAULT = os.environ.get('ARDUINO_HOME')
    ARDUINO_PORT_DEFAULT = os.environ.get('ARDUINO_PORT')
    SKETCHBOOK_HOME_DEFAULT = ''
else:
    # For Ubuntu Linux (9.10 or higher)
    ARDUINO_HOME_DEFAULT = '/usr/share/arduino/' #'/home/YOU/apps/arduino-00XX/'
    ARDUINO_PORT_DEFAULT = getUsbTty('/dev/ttyUSB*')
    AVR_BIN_PREFIX = 'avr-'
    SKETCHBOOK_HOME_DEFAULT = os.path.realpath('~/share/arduino/sketchbook/')

ARDUINO_BOARD_DEFAULT = os.environ.get('ARDUINO_BOARD', 'atmega328')

ARDUINO_HOME    = ARGUMENTS.get('ARDUINO_HOME', ARDUINO_HOME_DEFAULT)
ARDUINO_PORT    = ARGUMENTS.get('ARDUINO_PORT', ARDUINO_PORT_DEFAULT)
ARDUINO_BOARD   = ARGUMENTS.get('ARDUINO_BOARD', ARDUINO_BOARD_DEFAULT)
ARDUINO_VER     = ARGUMENTS.get('ARDUINO_VER', 0) # Default to 0 if nothing is specified
FILENAME				= ARGUMENTS.get('FILENAME', None);
RST_TRIGGER     = ARGUMENTS.get('RST_TRIGGER', None) # use built-in pulseDTR() by default
EXTRA_LIB       = ARGUMENTS.get('EXTRA_LIB', None) # handy for adding another arduino-lib dir
SKETCHBOOK_HOME = ARGUMENTS.get('SKETCHBOOK_HOME', SKETCHBOOK_HOME_DEFAULT) # If set will add the libraries dir from the sketchbook

if not ARDUINO_HOME:
    print 'ARDUINO_HOME must be defined.'
    raise KeyError('ARDUINO_HOME')

ARDUINO_CORE = pathJoin(ARDUINO_HOME, 'hardware/arduino/cores/arduino')
ARDUINO_SKEL = pathJoin(ARDUINO_CORE, 'main.cpp')
ARDUINO_CONF = pathJoin(ARDUINO_HOME, 'hardware/arduino/boards.txt')

arduino_file_path = pathJoin(ARDUINO_CORE, 'Arduino.h')
if ARDUINO_VER == 0:
        print "No Arduino version specified. Discovered version",
        if os.path.exists(arduino_file_path): 
                print "100 or above"
                ARDUINO_VER = 100
        else:   
                print "0023 or below"
                ARDUINO_VER = 23
else:
        print "Arduino version " + ARDUINO_VER + " specified"

if ARDUINO_VER < 100: FILE_EXTENSION = ".pde"
if ARDUINO_VER >= 100: FILE_EXTENSION = ".ino"

# Some OSs need bundle with IDE tool-chain
if platform == 'darwin' or platform == 'win32': 
    AVR_BIN_PREFIX = pathJoin(ARDUINO_HOME, 'hardware/tools/avr/bin', 'avr-')
    AVRDUDE_CONF = pathJoin(ARDUINO_HOME, 'hardware/tools/avr/etc/avrdude.conf')

ARDUINO_LIBS = [pathJoin(ARDUINO_HOME, 'libraries')]
if EXTRA_LIB:
    ARDUINO_LIBS += [EXTRA_LIB]
if SKETCHBOOK_HOME:
    ARDUINO_LIBS += [pathJoin(SKETCHBOOK_HOME, 'libraries')]

# check given board name, ARDUINO_BOARD is valid one
ptnBoard = re.compile(r'^(.*)\.name=(.*)')
boards = {}
for line in open(ARDUINO_CONF):
    result = ptnBoard.findall(line)
    if result:
        boards[result[0][0]] = result[0][1]
if not ARDUINO_BOARD in boards.keys():
    print ("ERROR! the given board name, %s is not in the supported board list:"%ARDUINO_BOARD)
    print ("all available board names are:")
    for name in boards.keys():
        print ("\t%s for %s"%(name.ljust(14), boards[name]))
    print ("however, you may edit %s to add a new board."%ARDUINO_CONF)
    sys.exit(-1)

def getBoardConf(strPtn):
    ptn = re.compile(strPtn)
    for line in open(ARDUINO_CONF):
        result = ptn.findall(line)
        if result:
            return result[0]
    assert(False)

MCU = getBoardConf(r'^%s\.build\.mcu=(.*)'%ARDUINO_BOARD)
MCU = ARGUMENTS.get('MCU', MCU)
F_CPU = getBoardConf(r'^%s\.build\.f_cpu=(.*)'%ARDUINO_BOARD)
F_CPU = ARGUMENTS.get('F_CPU', F_CPU)

# There should be a file with the same name as the folder and with the extension .pde
if FILENAME:
		TARGET = FILENAME
else:
		TARGET = os.path.basename(os.path.realpath(os.curdir))
assert(os.path.exists(TARGET+FILE_EXTENSION))

cFlags = ['-ffunction-sections', '-fdata-sections', '-fno-exceptions',
    '-funsigned-char', '-funsigned-bitfields', '-fpack-struct', '-fshort-enums',
    '-Os', '-mmcu=%s'%MCU]
envArduino = Environment(CC = AVR_BIN_PREFIX+'gcc', CXX = AVR_BIN_PREFIX+'g++',
    CPPPATH = ['build/core'], CPPDEFINES = {'F_CPU':F_CPU, 'ARDUINO':ARDUINO_VER},
    CFLAGS = cFlags+['-std=gnu99'], CCFLAGS = cFlags, TOOLS = ['gcc','g++'])

if ARDUINO_VER >= 100:
        if ARDUINO_BOARD == 'nano328': envArduino.Append(CPPPATH = pathJoin(ARDUINO_HOME, 'hardware/arduino/variants/eightanaloginputs/'))
        elif ARDUINO_BOARD == 'leonardo': envArduino.Append(CPPPATH = pathJoin(ARDUINO_HOME, 'hardware/arduino/variants/leonardo/'))
        elif ARDUINO_BOARD == 'mega2560': envArduino.Append(CPPPATH = pathJoin(ARDUINO_HOME, 'hardware/arduino/variants/leonardo/'))
        elif ARDUINO_BOARD == 'micro': envArduino.Append(CPPPATH = pathJoin(ARDUINO_HOME, 'hardware/arduino/variants/micro/'))
        else: envArduino.Append(CPPPATH = pathJoin(ARDUINO_HOME, 'hardware/arduino/variants/standard/'))

def fnProcessing(target, source, env):
    wp = open ('%s'%target[0], 'wb')
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

    for file in glob(os.path.realpath(os.curdir) + "/*" + FILE_EXTENSION):
        for line in open(file):
            result = re_signature.findall(line)
            if result:
                prototypes[result[0][0]] = result[0][1]

    for name in prototypes.keys():
        print ("%s;"%(name))
        wp.write("%s;\n"%name)

    for file in glob(os.path.realpath(os.curdir) + "/*" + FILE_EXTENSION):
        print file, TARGET
        if not os.path.samefile(file, TARGET+FILE_EXTENSION):
                wp.write('#line 1 "%s"\r\n' % file)
                wp.write(open(file).read())

    # Add this preprocessor directive to localize the errors.
    sourcePath = str(source[0]).replace('\\', '\\\\');
    wp.write('#line 1 "%s"\r\n' % sourcePath)
    wp.write(open('%s'%source[0]).read())

envArduino.Append(BUILDERS = {'Processing':Builder(action = fnProcessing,
        suffix = '.cpp', src_suffix = FILE_EXTENSION)})
envArduino.Append(BUILDERS={'Elf':Builder(action=AVR_BIN_PREFIX+'gcc '+
        '-mmcu=%s -Os -Wl,--gc-sections -o $TARGET $SOURCES -lm'%MCU)})
envArduino.Append(BUILDERS={'Hex':Builder(action=AVR_BIN_PREFIX+'objcopy '+
        '-O ihex -R .eeprom $SOURCES $TARGET')})

gatherSources = lambda x: glob(pathJoin(x, '*.c'))+\
        glob(pathJoin(x, '*.cpp'))+\
        glob(pathJoin(x, '*.S'))

# add arduino core sources
VariantDir('build/core', ARDUINO_CORE)
core_sources = gatherSources(ARDUINO_CORE)
core_sources = filter(lambda x: not (os.path.basename(x) == 'main.cpp'), core_sources)
core_sources = map(lambda x: x.replace(ARDUINO_CORE, 'build/core/'), core_sources)

# add libraries
libCandidates = []
ptnLib = re.compile(r'^[ ]*#[ ]*include [<"](.*)\.h[>"]')
for line in open (TARGET+FILE_EXTENSION):
    result = ptnLib.findall(line)
    if result:
        # Look for the library directory that contains the header.
        filename=result[0]+'.h'
        for libdir in ARDUINO_LIBS:
            for root, dirs, files in os.walk(libdir):
                for f in files:
                    if f == filename:
                        libCandidates += [os.path.basename(root)]

# Hack. In version 20 of the Arduino IDE, the Ethernet library depends
# implicitly on the SPI library.
if ARDUINO_VER >= 20 and 'Ethernet' in libCandidates:
    libCandidates += ['SPI']

all_libs_sources = []
index = 0
for orig_lib_dir in ARDUINO_LIBS:
    lib_sources = []
    lib_dir = 'build/lib_%02d'%index
    VariantDir(lib_dir, orig_lib_dir)
    for libPath in filter(os.path.isdir, glob(pathJoin(orig_lib_dir, '*'))):
        libName = os.path.basename(libPath)
        if not libName in libCandidates:
            continue
        envArduino.Append(CPPPATH = libPath.replace(orig_lib_dir, lib_dir))
        lib_sources = gatherSources(libPath)
        utilDir = pathJoin(libPath, 'utility')
        if os.path.exists(utilDir) and os.path.isdir(utilDir):
            lib_sources += gatherSources(utilDir)
            envArduino.Append(CPPPATH = utilDir.replace(orig_lib_dir, lib_dir))
        lib_sources = map(lambda x: x.replace(orig_lib_dir, lib_dir), lib_sources)
        all_libs_sources += lib_sources
    index += 1

# Add raw sources which live in sketch dir.
build_top = os.path.realpath('.')
VariantDir('build/local/', build_top)
local_sources = gatherSources(build_top)
local_sources = map(lambda x: x.replace(build_top, 'build/local/'), local_sources)
if local_sources:
    envArduino.Append(CPPPATH = 'build/local')

# Convert sketch(.pde) to cpp
envArduino.Processing('build/'+TARGET+'.cpp', 'build/'+TARGET+FILE_EXTENSION)
VariantDir('build', '.')

sources = ['build/'+TARGET+'.cpp']
sources += core_sources
sources += local_sources
sources += all_libs_sources

# Finally Build!!
objs = envArduino.Object(sources) #, LIBS=libs, LIBPATH='.')
envArduino.Elf(TARGET+'.elf', objs)
envArduino.Hex(TARGET+'.hex', TARGET+'.elf')

# Print Size
# TODO: check binary size
MAX_SIZE = getBoardConf(r'^%s\.upload.maximum_size=(.*)'%ARDUINO_BOARD)
print ("maximum size for hex file: %s bytes"%MAX_SIZE)
envArduino.Command(None, TARGET+'.hex', AVR_BIN_PREFIX+'size --target=ihex $SOURCE')

# Reset
def pulseDTR(target, source, env):
    import serial
    import time
    ser = serial.Serial(ARDUINO_PORT)
    ser.setDTR(1)
    time.sleep(0.5)
    ser.setDTR(0)
    ser.close()

if RST_TRIGGER:
    reset_cmd = '%s %s'%(RST_TRIGGER, ARDUINO_PORT)
else:
    reset_cmd = pulseDTR

# Upload
UPLOAD_PROTOCOL = getBoardConf(r'^%s\.upload\.protocol=(.*)'%ARDUINO_BOARD)
UPLOAD_SPEED = getBoardConf(r'^%s\.upload\.speed=(.*)'%ARDUINO_BOARD)

if UPLOAD_PROTOCOL == 'stk500':
    UPLOAD_PROTOCOL = 'stk500v1'


avrdudeOpts = ['-V', '-F', '-c %s'%UPLOAD_PROTOCOL, '-b %s'%UPLOAD_SPEED,
    '-p %s'%MCU, '-P %s'%ARDUINO_PORT, '-U flash:w:$SOURCES']
if AVRDUDE_CONF:
    avrdudeOpts += ['-C %s'%AVRDUDE_CONF]

fuse_cmd = '%s %s'%(pathJoin(os.path.dirname(AVR_BIN_PREFIX), 'avrdude'),
                     ' '.join(avrdudeOpts))

upload = envArduino.Alias('upload', TARGET+'.hex', [reset_cmd, fuse_cmd]);
AlwaysBuild(upload)

# Clean build directory
envArduino.Clean('all', 'build/')

# vim: et sw=4 fenc=utf-8:
