This repository is part of the [codebender.cc](http://www.codebender.cc) maker and artist web platform.

## And what's that?

codebender comes to fill the need for reliable and easy to use tools for makers. A need that from our own experience could not be totally fulfilled by any of the existing solutions. Things like installing libraries, updating the software or installing the IDE can be quite a painful process.

In addition to the above, the limited features provided (e.g. insufficient highlighting, indentation and autocompletion) got us starting building codebender, a completely web-based IDE, that requires no installation and offers a great code editor. It also stores your sketches on the cloud.

That way, you can still access your sketches safely even if your laptop is stolen or your hard drive fails! codebender also takes care of compilation, giving you extremely descriptive warnings on terrible code. On top of that, when you are done, you can upload your code to your Arduino straight from the browser without installing anything.

Currently codebender.cc is running its beta and we are trying to fix issues that may (will) come up so that we can launch and offer our services to everyone!
If you like what we do you can also support our campaign on [indiegogo](http://www.indiegogo.com/codebender) to also get early access to codebender! 

## How does the compiler come into the picture?

codebender.cc runs on PHP Fog, a PaaS that helps us run our PHP projects in a fast and scallable manner. However, we need to run our compiler on some VPS. That's why we use AWS to run our compilers on a scallable manner as well

The compiler repository includes all the necessary files needed to run the compiler as a service. It receives the code as input and outputs the errors in the code, or the compiled output if the compilation was successful. We provide a really easy to use interface to allow us to send the code to the compiler easily.

Here's a list of open source projects we use
* Clang
* gcc-avr
* avr binutils (avrsize)