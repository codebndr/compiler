This repository is part of the [codebender.cc](http://www.codebender.cc) maker and artist web platform. 

[![Build Status](https://travis-ci.org/codebendercc/compiler.svg?branch=master)](https://travis-ci.org/codebendercc/compiler) 
[![Coverage Status](https://coveralls.io/repos/codebendercc/compiler/badge.svg?branch=master)](https://coveralls.io/r/codebendercc/compiler?branch=master)

## And what's that?

codebender fills the need for reliable, easy to use tools for makers, a need that couldn't be completely fulfilled by any existing solution according to our experience. 

Things like installing libraries or the IDE and updating software sometimes were (and still are) quite a painful process. But in addition to the above, the limited features provided (e.g. insufficient highlighting, indentation and autocompletion) got us to start codebender, a completely web-based IDE that requires virtually no installation and offers a great code editor. Plus it stores your sketches on the cloud. Yeah!

With your code on the cloud, you can access your sketches safely even if your laptop is stolen or your hard drive fails! codebender also compiles your code giving you extremely descriptive error descriptions on terrible code. There's even more, you can upload your code to your Arduino straight from the browser.

## How does the compiler come into the picture?

The compiler repository includes all the necessary files needed to run the compiler as a service. It receives the code as input and outputs the compiled output if the compilation was successful or the errors present in the code. We provide an easy interface to send the code to the compiler.

Here's a list of open source projects we use
* Clang
* gcc-avr
* avr binutils (avrsize)

For development we've run it on a variety of Linux and Mac OS X machines.

For production we are using Ubuntu Server 12.04. We know the compiler works perfectly on it, so we suggest you using it as well.

## How to install

Check out the code in any directory you wish

`git clone https://github.com/codebendercc/compiler.git`

Then cd in the created directory (if you run the command as is above, it would be named `compiler`) and run

`scripts/install.sh`

(don't cd into scripts directory and run install.sh from there, it won't work)

If you now visit `http://localhost/status` you'll see a JSON response telling you everything's ok: 
`{"success":true,"status":"OK"}`

## What's next?

Visit the [wiki](https://github.com/codebendercc/compiler/wiki) for more information.

## How can someone contribute?

Contribution is always welcome whether it is by creating an issue for a bug or suggestion you can't fix yourself or a pull request for something you can. 

If you write new code or edit old code please don't forget to add/update relative unit tests that come with it. It is always a good idea to run tests localy to make sure nothing breaks before you create a pull request. 

We expect new code to be [PSR-2](http://www.php-fig.org/psr/psr-2/) but we know we carry legacy code with different coding styles. You're welcome to fix that.
