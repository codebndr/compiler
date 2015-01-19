#!/bin/bash

set -x

echo "Running Tests"
if [[ $TRAVIS ]]; then
    bin/phpunit -c app/ --coverage-clover build/logs/clover.xml --stderr
    #bin/phpcpd --log-pmd build/pmd-cpd.xml --exclude app --exclude vendor --names-exclude *Test.php -n .
    #bin/phpmd src/Codebender/ xml cleancode,codesize,design,naming,unusedcode --exclude *Test.php --reportfile build/pmd.xml
else
    bin/phpunit -c app/ --stderr --coverage-html=coverage/

    echo "Running Copy-Paste-Detector"
    bin/phpcpd --exclude app --exclude vendor --names-exclude *Test.php -n .
    bin/phpmd src/Codebender/ xml cleancode,codesize,design,naming,unusedcode --exclude *Test.php
fi
