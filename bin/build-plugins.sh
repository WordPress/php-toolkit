#!/bin/bash

set -e
shopt -s extglob

echo "Building plugins"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR=$SCRIPT_DIR/..
DIST_DIR=$PROJECT_DIR/dist/plugins

cd $PROJECT_DIR

rm -rf $DIST_DIR
mkdir -p $DIST_DIR

cp -r $PROJECT_DIR/plugins/data-liberation $DIST_DIR
cp $PROJECT_DIR/dist/wordpress-libraries.phar $DIST_DIR/data-liberation/wordpress-libraries.phar
cd $DIST_DIR
zip -r data-liberation.zip data-liberation/
cd $PROJECT_DIR
rm -rf $DIST_DIR/data-liberation

cd $PROJECT_DIR/plugins/static-files-editor/
npm run build
cd $PROJECT_DIR
mkdir -p $DIST_DIR/static-files-editor
cp -r $PROJECT_DIR/plugins/static-files-editor/!(node_modules|src|webpack.config.js|package.json|package-lock.json) $DIST_DIR/static-files-editor
cd $DIST_DIR
zip -r static-files-editor.zip static-files-editor/
cd $PROJECT_DIR
rm -rf $DIST_DIR/static-files-editor

mkdir -p $DIST_DIR/url-updater
cp -r $PROJECT_DIR/plugins/url-updater/!(node_modules|src|webpack.config.js|package.json|package-lock.json) $DIST_DIR/url-updater
cd $DIST_DIR
zip -r url-updater.zip url-updater/
cd $PROJECT_DIR
rm -rf $DIST_DIR/url-updater
