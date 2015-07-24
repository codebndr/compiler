#!/bin/bash

#Left here to be commited on git, in case dirname doesn't work as expected
#echo "The script you are running has basename `basename $0`, dirname `dirname $0`"
#echo "The present working directory is `pwd`"


#Ask user to make sure we want to run this
echo "NEVER run this script in production. It will purge your database to a clean state"
read -r -p "Are you sure you want to run this? [y/N] " response
case $response in
    [yY][eE][sS]|[yY])
        # User accepted
        ;;
    *)
        # Abort
        exit
        ;;
esac

#Changing directory to Symfony, regardless where we are
cd `dirname $0`/../Symfony

set -x
pwd

../scripts/install_composer.sh

../scripts/clear_cache.sh

../scripts/warmup_cache.sh

../scripts/run_tests.sh
