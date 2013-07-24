#!/bin/bash

sudo cp /opt/codebender/@PACKAGENAME@/apache-config /etc/apache2/sites-available/@PACKAGENAME@
cd /etc/apache2/sites-enabled
sudo ln -s ../sites-available/@PACKAGENAME@ 00-@PACKAGENAME@

sudo a2enmod rewrite
sudo a2enmod alias
sudo service apache2 restart

cd /opt/codebender/@PACKAGENAME@/Symfony
sudo curl -s http://getcomposer.org/installer | sudo php
sudo php composer.phar install

#find a way to edit the fstab here
#more info: https://help.ubuntu.com/community/FilePermissionsACLs
#sudo mount -o remount /
#
#sudo rm -rf /opt/codebender/@PACKAGENAME@/Symfony/app/cache/*
#sudo rm -rf /opt/codebender/@PACKAGENAME@/Symfony/app/logs/*
#
#sudo setfacl -R -m u:www-data:rwX -m u:ubuntu:rwX /opt/codebender/@PACKAGENAME@/Symfony/app/cache /opt/codebender/@PACKAGENAME@/Symfony/app/logs
#sudo setfacl -dR -m u:www-data:rwx -m u:ubuntu:rwx /opt/codebender/@PACKAGENAME@/Symfony/app/cache /opt/codebender/@PACKAGENAME@/Symfony/app/logs

sudo chown -R ubuntu:www-data /opt/codebender/@PACKAGENAME@/Symfony/app/cache/ /opt/codebender/@PACKAGENAME@/Symfony/app/logs
sudo chmod -R 775 /opt/codebender/@PACKAGENAME@/Symfony/app/cache/ /opt/codebender/@PACKAGENAME@/Symfony/app/logs
