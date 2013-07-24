This repository is part of the [codebender.cc](http://www.codebender.cc) maker and artist web platform.

## And what's that?

codebender comes to fill the need for reliable and easy to use tools for makers. A need that from our own experience could not be totally fulfilled by any of the existing solutions. Things like installing libraries, updating the software or installing the IDE can be quite a painful process.

In addition to the above, the limited features provided (e.g. insufficient highlighting, indentation and autocompletion) got us starting building codebender, a completely web-based IDE, that requires virtually no installation and offers a great code editor. It also stores your sketches on the cloud.

That way, you can still access your sketches safely even if your laptop is stolen or your hard drive fails! codebender also takes care of compilation, giving you extremely descriptive warnings on terrible code. On top of that, when you are done, you can upload your code to your Arduino straight from the browser.

## How does the compiler come into the picture?

The compiler repository includes all the necessary files needed to run the compiler as a service. It receives the code as input and outputs the errors in the code, or the compiled output if the compilation was successful. We provide a really easy to use interface to allow us to send the code to the compiler easily.

Here's a list of open source projects we use
* Clang
* gcc-avr
* avr binutils (avrsize)

We are using Ubuntu Server 12.04, and we know it works perfectly with that, so we suggest using that as well.

## Ubuntu 12.04 Installation Instructions

### Pre-installation

After installing ubuntu 12.04, first of all you should update your package definitions and upgrade your software.

> sudo apt-get update 

> sudo apt-get upgrade

### Installation

Download the debian package we have prepared for you

> https://www.dropbox.com/s/iozqyk6w3net0ao/codebender-arduino-compiler_1.0_all.deb

Install the package:

> sudo dpkg -i codebender-arduino-compiler_1.0_all.deb

The system will complain about missing dependencies. That is completely normal. To take care of dependencies, and start the installation procedure, execute:

> sudo apt-get install -f

During the installation, you will be asked to edit some configs. The only ones that you should need to change are the arduino_core_dir and auth_key. In the first one, enter the folder where you will save your Arduino core files (see Downloading Arduino Cores). In the second one, write a unique string that you will use for authorization.


That's it! Try out the compiler. Go to

> http://your_servers_ip_address/compiler/status

You should get the following:

> {""success":true,status":"OK"}

### Enable development mode (Optional)
If you want to be able to use the development mode and access debug info from Symfony, then open

> /opt/codebender/codebender-arduino-compiler/Symfony/web/app_dev.php

and comment out these two lines at the beginning of the file

> header('HTTP/1.0 403 Forbidden');

> exit('You are not allowed to access this file. Check '.basename(__FILE__).' for more information.');

### Downloading Arduino Cores

Download the arduino-files sketch from GitHub and put them on the path you provided above (Guide on that coming soon™)

## Mac OS X Installation Instructions

## Compiler API documentation

Coming Soon™
