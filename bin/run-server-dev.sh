#!/bin/bash

# @TODO: Figure out how to set up the dev environment to be as similar to
#        production as possible.
npx @wp-playground/cli@latest \
    server \
    --mount=`pwd`/vendor:/wordpress/wp-content/vendor \
    --mount=`pwd`/components:/wordpress/wp-content/components \
    --mount=`pwd`/plugins/data-liberation:/wordpress/wp-content/plugins/data-liberation \
    --mount=`pwd`/plugins/static-files-editor:/wordpress/wp-content/plugins/static-files-editor \
    --mount=`pwd`/plugins/url-updater:/wordpress/wp-content/plugins/url-updater \
    --mount=`pwd`/plugins/git-repo:/wordpress/wp-content/plugins/git-repo \
    --mount=`pwd`/.my-notes-git:/wordpress/wp-content/uploads \
    --blueprint=./blueprint-dev.json