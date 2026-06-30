#!/bin/sh
# Creates one fixture repository: create-repo.sh <name> <anon|auth>
# Used both by the entrypoint and by the test suite (via docker exec)
# to give every test an isolated repository.
set -e

name="$1"
mode="${2:-anon}"

if [ "$mode" = "auth" ]; then
	parent=/var/svn-auth
else
	parent=/var/svn
fi

# Clone the template fixture when it exists – much faster than
# rebuilding the fixture content with svn commands.
if [ -d "$parent/.template" ]; then
	cp -a "$parent/.template" "$parent/$name"
	exit 0
fi

svnadmin create "$parent/$name"
work="$(mktemp -d)"
svn checkout -q "file://$parent/$name" "$work"
cd "$work"
mkdir -p trunk/sub/deep branches
printf 'hello world\n' > trunk/hello.txt
printf 'line1\nline2\nline3\n' > trunk/multi.txt
printf 'nested file\n' > trunk/sub/deep/nested.txt
svn add -q trunk branches
svn commit -q -m 'initial content' --username fixture
svn propset -q svn:eol-style CRLF trunk/multi.txt
svn commit -q -m 'set eol-style' --username fixture
cd /
rm -rf "$work"
chown -R www-data:www-data "$parent/$name"
