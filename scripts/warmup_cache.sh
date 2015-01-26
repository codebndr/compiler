#!/bin/bash

echo "Warming up the cache"
php app/console cache:warmup --env=dev
php app/console cache:warmup --env=prod
php app/console cache:warmup --env=test
