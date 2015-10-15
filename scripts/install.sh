#!/bin/bash
set -x
set -e

PACKAGENAME=compiler

if [[ "$OSTYPE" == "linux-gnu" ]]; then
	echo "Configuring environment for Linux"
	sudo apt-get update
	if [[ ! $TRAVIS ]]; then
		# Ubuntu Server (on AWS?) lacks UTF-8 for some reason. Give it that
		sudo locale-gen en_US.UTF-8
		# Make sure we have up-to-date stuff
		sudo apt-get install -y php5-intl
	fi
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
	#### Set Max nesting lvl to something Symfony is happy with
	export ADDITIONAL_PATH=`php -i | grep -F --color=never 'Scan this dir for additional .ini files'`
	echo 'xdebug.max_nesting_level=256' | sudo tee ${ADDITIONAL_PATH:42}/symfony2.ini
fi

if [[ $TRAVIS ]]; then
	HTTPDUSER="root"
else
	HTTPDUSER=`ps aux | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\  -f1`
fi

if [[ ${#HTTPDUSER} -eq 0 ]]; then
	echo "Failed to set HTTPDUSER"
	echo `ps aux`
	exit 1
fi

sudo mkdir -p /opt/codebender
sudo cp -r . /opt/codebender/$PACKAGENAME
sudo chown -R `whoami`:$HTTPDUSER /opt/codebender/$PACKAGENAME
cd /opt/codebender/$PACKAGENAME

#Set permissions for app/cache and app/logs

rm -rf Symfony/app/cache/*
rm -rf Symfony/app/logs/*

if [[ "$OSTYPE" == "linux-gnu" ]]; then

	if [[ ! $TRAVIS ]]; then

		mkdir -p `pwd`/Symfony/app/cache/
		mkdir -p `pwd`/Symfony/app/logs/

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
cp app/config/parameters.yml.dist app/config/parameters.yml

../scripts/install_dependencies.sh

../scripts/install_composer.sh

../scripts/warmup_cache.sh

# TODO: Fix this crap later on (Apache config), it's all hardcoded now
if [[ "$OSTYPE" == "linux-gnu" ]]; then
	sudo cp /opt/codebender/$PACKAGENAME/apache-config /etc/apache2/sites-available/codebender-compiler
	cd /etc/apache2/sites-enabled
	sudo ln -s ../sites-available/codebender-compiler 00-codebender-compiler
	sudo service apache2 restart
fi
