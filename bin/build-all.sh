#!/bin/bash

composer build-php-toolkit-phar
composer build-blueprints-phar
bash bin/build-libraries-phar.sh
bash bin/build-plugins.sh
bash bin/build-examples.sh
