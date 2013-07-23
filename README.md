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

## Getting Started

If you want to host your own version of the compiler, first of all, you will need to install:
* clang
* avr-gcc
* a webserver with php support (like apache)
* avr binutils
* git (optional, otherwize you can download the zip file from GitHub)

We are using Ubuntu Server 12.04, and we know it works perfectly with that, so we suggest using it.

## Ubuntu 12.04 Installation Instructions
After installing ubuntu 12.04, first of all you should update your package definitions and upgrade your software.

> sudo apt-get update 

> sudo apt-get upgrade

After that, you should install all the necessary software

> sudo apt-get install apache2 libapache2-mod-php5 php-pear clang gcc-avr binutils-avr git

Then clone the GitHub project on the machine

> git clone https://github.com/codebendercc/compiler.git

Link myhost_ip/compiler to the compiler

> sudo ln -s ~/compiler/Symfony/web /var/wwww/compiler

Enable Apache's rewrite module and restart apache

> sudo a2enmod rewrite

Edit apache's configuration:

> sudo vim /etc/apache2/sites-available/default

Add this somewhere in the middle:

>       <Directory /var/www/compiler>
              Options -Indexes FollowSymLinks MultiViews
              AllowOverride All
              Order allow,deny
              Allow from all
      </Directory>

And restart apache

> sudo service apache2 restart

Set the correct permissions

> sudo chown -R ubuntu:www-data ~/compiler

> mkdir ~/compiler/Symfony/app/cache ~/compiler/Symfony/app/logs

> sudo chmod -R 777 ~/compiler/Symfony/app/cache ~/compiler/Symfony/app/logs

Create and edit a config file in ~/compiler/Symfony/config/parameters.yml

```
parameters:
  # Path to cores and libraries.
  root: "/the/root/of/my/arduino/files"
  auth_key: "myNewSecretPassword"
```

Download the arduino-files sketch from GitHub and put them on that path (Guide on that coming soon™)

Edit your ~/compiler/Symfony/composer file cause Symfony is a pain in the ass and remove this line

> "Incenteev\\ParameterHandler\\ScriptHandler::buildParameters",

Install composer and perform Symfony's installation requirements
> cd ~/compiler/Symfony

> curl -s http://getcomposer.org/installer | php

> php composer.phar install

> sudo chmod -R 777 ~/compiler/Symfony/app/cache ~/compiler/Symfony/app/logs


Try out the compiler. Go to

> http://your_servers_ip_address/compiler/yourAuthKey/v1

You should get the following error message. Don't worry, that's perfectly normal

> {"success":false,"step":0,"message":"Invalid input."}

### Enable development mode (Optional)
If you want to be able to use the development mode and access debug info from Symfony, then open

> ~/compiler/Symfony/web/app_dev.php

and comment out these two lines at the beginning of the file

> header('HTTP/1.0 403 Forbidden');

> exit('You are not allowed to access this file. Check '.basename(__FILE__).' for more information.');

