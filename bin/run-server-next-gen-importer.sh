#!/bin/bash

# @TODO: Figure out how to set up the dev environment to be as similar to
#        production as possible.
npx @wp-playground/cli@latest \
    server \
    --php=8.2 \
    --mount=`pwd`/plugins/next-gen-importer:/wordpress/wp-content/plugins/next-gen-importer \
    --blueprint=./blueprint-next-gen-importer.json \
    --mount=`pwd`:/wordpress/wp-content/data-liberation \
    --mount=`pwd`/debug.log:/wordpress/wp-content/debug.log
    