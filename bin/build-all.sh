#!/bin/bash

composer build-blueprints-phar
composer build-php-toolkit-phar
bash bin/build-libraries-phar.sh
bash bin/build-plugins.sh
bash bin/build-examples.sh
