#!/bin/sh
# Creates the initial test repositories and starts Apache in the
# foreground. The test suite creates more repositories on the fly with
# create-repo.sh via docker exec.
set -e

htpasswd -bc /etc/apache2/dav_svn.passwd alice secret123

mkdir -p /var/svn /var/svn-auth

/create-repo.sh .template anon
/create-repo.sh .template auth
/create-repo.sh repo1 anon
/create-repo.sh repo2 auth

. /etc/apache2/envvars
exec apache2 -DFOREGROUND
