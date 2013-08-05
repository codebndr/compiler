#!/bin/bash
sudo rm /etc/apache2/sites-available/codebender /etc/apache2/sites-enabled/00-codebender

sudo rm -rf /opt/codebender/@PACKAGENAME@/Symfony/app/cache/*
sudo rm -rf /opt/codebender/@PACKAGENAME@/Symfony/app/logs/*

sudo umount /opt/codebender/@PACKAGENAME@/Symfony/app/cache/
sudo umount /opt/codebender/@PACKAGENAME@/Symfony/app/logs/

sudo rm /opt/codebender/@PACKAGENAME@/cache-fs
sudo rm /opt/codebender/@PACKAGENAME@/logs-fs

sudo rm -rf /opt/codebender/@PACKAGENAME@

sed '/\/opt\/codebender\/@PACKAGENAME@\/Symfony\/app\//d' /etc/fstab | sudo tee /etc/fstab > /dev/null 2>&1
