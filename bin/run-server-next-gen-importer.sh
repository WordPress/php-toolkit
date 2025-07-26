#!/bin/bash

# @TODO: Figure out how to set up the dev environment to be as similar to
#        production as possible.
npx @wp-playground/cli@latest \
    server \
    --mount=`pwd`/plugins/next-gen-importer:/wordpress/wp-content/plugins/next-gen-importer \
    --blueprint=./blueprint-next-gen-importer.json \
    --mount=`pwd`/vendor:/wordpress/wp-content/vendor \
    --mount=`pwd`/components:/wordpress/wp-content/components
    