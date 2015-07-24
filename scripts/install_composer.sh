#!/bin/bash

echo "Installing Dependencies"
curl -s http://getcomposer.org/installer | php
php composer.phar install
