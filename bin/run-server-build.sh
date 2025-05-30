#!/bin/bash

# @TODO: Figure out how to set up the dev environment to be as similar to
#        production as possible.
npx @wp-playground/cli@latest \
    server \
    --mount=`pwd`/dist:/wordpress/dist \
    --mount=`pwd`/.my-notes-git:/wordpress/wp-content/uploads \
    --blueprint=./blueprint-build.json
