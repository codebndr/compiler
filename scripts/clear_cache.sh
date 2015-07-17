#!/bin/bash

echo "Clearing the cache"
php app/console cache:clear --env=dev
php app/console cache:clear --env=prod
php app/console cache:clear --env=test
