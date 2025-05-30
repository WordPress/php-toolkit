#!/bin/bash

# Builds the standalone dist/core-data-liberation.phar.gz file meant for
# use in the importWxr Blueprint step.
#
# This is a temporary measure until we have a canonical way of distributing,
# versioning, and using the Data Liberation modules and their dependencies.
# Possible solutions might include composer packages, WordPress plugins, or
# tree-shaken zip files with each module and its composer deps.

set -e
echo "Building data liberation plugin"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR=$SCRIPT_DIR/..
BUILD_DIR=$PROJECT_DIR/bin/build-phar
DIST_DIR=$PROJECT_DIR/dist

cd $PROJECT_DIR

mkdir -p $BUILD_DIR
rm $DIST_DIR/wordpress-libraries.* > /dev/null 2>&1 || true
export BOX_BASE_PATH=$(type -a box | grep -v 'alias' | awk '{print $3}')
php $BUILD_DIR/box.php compile -d $PROJECT_DIR -c $PROJECT_DIR/phar-box.json
php -d 'phar.readonly=0' $BUILD_DIR/truncate-composer-checks.php $DIST_DIR/wordpress-libraries.phar
cd $DIST_DIR
php $BUILD_DIR/smoke-test.php
PHP=7.2 bunx @php-wasm/cli@latest $BUILD_DIR/smoke-test.php
ls -sgh $DIST_DIR
