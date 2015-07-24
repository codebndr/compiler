#!/bin/sh
sudo apt-get install -y unzip
cd ~
wget https://github.com/codebendercc/arduino-core-files/archive/master.zip
unzip master.zip
sudo cp -r arduino-core-files-master /opt/codebender/codebender-arduino-core-files
rm master.zip
wget https://github.com/codebendercc/external_cores/archive/master.zip
unzip master.zip
sudo cp -r external_cores-master /opt/codebender/external-core-files
cd -