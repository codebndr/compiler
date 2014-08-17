#!/bin/bash
set -x
set -e

PACKAGENAME=compiler

if [[ "$OSTYPE" == "linux-gnu" ]]; then
	echo "Configuring environment for Linux"
	if [[ ! $TRAVIS ]]; then
		# Ubuntu Server (on AWS?) lacks UTF-8 for some reason. Give it that
		sudo locale-gen en_US.UTF-8
		# Make sure we have up-to-date stuff
		sudo apt-get install -y php5-intl
	fi
	sudo apt-get update
	# Install dependencies
	sudo apt-get install -y apache2 libapache2-mod-php5 php-pear php5-curl php5-sqlite acl curl git
	# Enable Apache configs
	sudo a2enmod rewrite
    sudo a2enmod alias
    # Restart Apache
    sudo service apache2 restart
elif [[ "$OSTYPE" == "darwin"* ]]; then
	# is there something comparable to this on os x? perhaps Homebrew
	echo "Configuring environment for OS X"
fi

if [[ ! $TRAVIS ]]; then

	##### Enable/Install phpunit
	sudo apt-get install -y phpunit

	#### Set Max nesting lvl to something Symfony is happy with
	export ADDITIONAL_PATH=`php -i | grep -F --color=never 'Scan this dir for additional .ini files'`
	echo 'xdebug.max_nesting_level=256' | sudo tee ${ADDITIONAL_PATH:42}/symfony2.ini
fi

HTTPDUSER=`ps aux | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\  -f1`

sudo mkdir -p /opt/codebender
sudo cp -r . /opt/codebender/$PACKAGENAME
sudo chown -R `whoami`:$HTTPDUSER /opt/codebender/$PACKAGENAME
cd /opt/codebender/$PACKAGENAME

sudo apt-get install -y unzip
wget https://github.com/codebendercc/arduino-core-files/archive/master.zip
unzip master.zip
sudo cp -r arduino-core-files-master /opt/codebender/codebender-arduino-core-files
rm master.zip
rm -rf arduino-core-files-master
wget https://github.com/codebendercc/external_cores/archive/master.zip
unzip master.zip
sudo cp -r external_cores-master /opt/codebender/external-core-files
rm master.zip
rm -rf external_cores-master


#Set permissions for app/cache and app/logs

rm -rf Symfony/app/cache/*
rm -rf Symfony/app/logs/*

if [[ "$OSTYPE" == "linux-gnu" ]]; then

	if [[ ! $TRAVIS ]]; then

		sudo dd if=/dev/zero of=cache-fs bs=1024 count=0 seek=200000
		sudo dd if=/dev/zero of=logs-fs bs=1024 count=0 seek=200000

		yes | sudo mkfs.ext4 cache-fs
		yes | sudo mkfs.ext4 logs-fs

		mkdir -p `pwd`/Symfony/app/cache/
		mkdir -p `pwd`/Symfony/app/logs/

		echo "`pwd`/cache-fs `pwd`/Symfony/app/cache/ ext4 loop,acl 0 0" | sudo tee -a /etc/fstab > /dev/null 2>&1
		echo "`pwd`/logs-fs `pwd`/Symfony/app/logs/ ext4 loop,acl 0 0" | sudo tee -a /etc/fstab > /dev/null 2>&1
		cat /etc/fstab

		sudo mount `pwd`/Symfony/app/cache/
		sudo mount `pwd`/Symfony/app/logs/

		sudo rm -rf `pwd`/Symfony/app/cache/*
		sudo rm -rf `pwd`/Symfony/app/logs/*

		sudo setfacl -R -m u:www-data:rwX -m u:`whoami`:rwX `pwd`/Symfony/app/cache `pwd`/Symfony/app/logs
		sudo setfacl -dR -m u:www-data:rwx -m u:`whoami`:rwx `pwd`/Symfony/app/cache `pwd`/Symfony/app/logs
	fi

elif [[ "$OSTYPE" == "darwin"* ]]; then

	HTTPDUSER=`ps aux | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\  -f1`
	sudo chmod +a "$HTTPDUSER allow delete,write,append,file_inherit,directory_inherit" Symfony/app/cache Symfony/app/logs
	sudo chmod +a "`whoami` allow delete,write,append,file_inherit,directory_inherit" Symfony/app/cache Symfony/app/logs
fi

cd Symfony

# TODO: generate parameters.yml file somehow
## For reference, here's a command to replace a substring in a file
## cat kourades.sh  | sed 's/kourades/skata/g' | tee skata.sh  > /dev/null 2>&1

cp app/config/parameters.yml.dist app/config/parameters.yml

curl -s http://getcomposer.org/installer | php
php composer.phar install
php composer.phar update

# TODO: Add this later on (Apache config)
#sudo cp /opt/codebender/$PACKAGENAME/apache-config /etc/apache2/sites-available/codebender
#cd /etc/apache2/sites-enabled
#sudo ln -s ../sites-available/codebender 00-codebender
