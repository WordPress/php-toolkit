#!/bin/bash

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR=$SCRIPT_DIR/..

rm -rf $PROJECT_DIR/dist/examples
mkdir -p $PROJECT_DIR/dist/examples

cp $PROJECT_DIR/dist/plugins/data-liberation.zip $PROJECT_DIR/examples/create-wp-site/data-liberation.zip
cp -r $PROJECT_DIR/examples/create-wp-site/ $PROJECT_DIR/dist/package
cd $PROJECT_DIR/dist
tar -czvf examples/create-wp-site.tar.gz package/{*.js,*.json,*.php,*.zip,cli,playground-protocol,README.md}
rm -rf package
