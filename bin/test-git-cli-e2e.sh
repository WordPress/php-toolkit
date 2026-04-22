#!/usr/bin/env bash
set -eu

docker compose run --rm sandbox vendor/bin/phpunit components/Git/Tests/GitCliEndToEndTest.php
