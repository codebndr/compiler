#!/bin/bash
sudo rm /etc/apache2/sites-available/@PACKAGENAME@ /etc/apache2/sites-enabled/00-@PACKAGENAME@
sudo rm -rf /opt/codebender/@PACKAGENAME@
sudo rmdir /opt/codebender
