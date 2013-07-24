#!/bin/bash
sudo rm /etc/apache2/sites-available/@PACKAGENAME@ /etc/apache2/sites-enabled/00-@PACKAGENAME@

sudo rm -rf /opt/codebender/@PACKAGENAME@/Symfony/app/cache/*
sudo rm -rf /opt/codebender/@PACKAGENAME@/Symfony/app/logs/*

sudo umount /opt/codebender/@PACKAGENAME@/Symfony/app/cache/
sudo umount /opt/codebender/@PACKAGENAME@/Symfony/app/logs/

sudo rm /opt/codebender/@PACKAGENAME@/cache-fs
sudo rm /opt/codebender/@PACKAGENAME@/logs-fs

sudo rm -rf /opt/codebender/@PACKAGENAME@
sudo rmdir /opt/codebender

sed '/\/opt\/codebender\/codebender-arduino-compiler\/Symfony\/app\//d' /etc/fstab | sudo tee /etc/fstab
