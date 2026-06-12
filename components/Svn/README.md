---
slug: svn
title: Svn
install: wp-php-toolkit/svn

see_also:
  - git | Git | Work with Git repositories through the same pure-PHP philosophy.
  - filesystem | Filesystem | Place working copies on disk, in memory, or in other backends.
  - http-client | HttpClient | The transport behind http:// and https:// repository access.
  - xml | XML | The streaming parser behind the DAV protocol support.
---

A Subversion client in pure PHP. Check out, update, and commit to SVN repositories – including WordPress.org's – over both <code>svn://</code> and <code>http(s)://</code>, without the <code>svn</code> binary or any PHP extension.

## Why this exists

<p>WordPress itself lives in Subversion: core development happens in <code>develop.svn.wordpress.org</code> and every plugin and theme ships through <code>plugins.svn.wordpress.org</code> and <code>themes.svn.wordpress.org</code>. Automating anything around that ecosystem – deploying a plugin release, mirroring core, building developer tooling – traditionally requires shelling out to the <code>svn</code> binary, which hosted environments rarely provide.</p>

<p>This component implements the client side of both Subversion protocols in PHP: the custom <code>svn://</code> wire protocol (ra_svn) and the HTTP-based one (mod_dav_svn, HTTP protocol v2). Checkouts and updates stream the server's "editor drive" – the same tree-delta mechanism the official client uses – so a full <code>wordpress-develop</code> checkout arrives in a single HTTP response and runs in tens of megabytes of memory.</p>

<p><code>svn:externals</code> definitions are honored: referenced repositories are checked out as nested working copies, updated alongside their parent, and skipped by status and commit – matching the official client's behavior.</p>

<p>One caveat: working copies use this component's own <code>.svn</code> metadata format (JSON plus a pristine store), not the SQLite database of the official client. The repositories themselves are fully interoperable – commits made here are indistinguishable from commits made with <code>svn</code> – but a working copy created by one client cannot be operated on by the other.</p>

## Check out a plugin from WordPress.org

<p>Point <code>checkout()</code> at any repository URL. The revision, the depth, and <code>svn:externals</code> handling are controlled per call.</p>

<!-- snippet:
filename: checkout-plugin.php
runnable: false
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Svn\SvnClient;

$client = new SvnClient();
$result = $client->checkout(
	'https://plugins.svn.wordpress.org/hello-dolly/trunk',
	'/tmp/hello-dolly'
);

echo "checked out r{$result['revision']}\n";
echo file_get_contents( '/tmp/hello-dolly/hello.php' );
```

## Make a commit

<p>Edit files, schedule additions and deletions, commit. Locally modified files that changed upstream too are marked conflicted on update and refuse to commit until <code>resolved()</code>.</p>

<!-- snippet:
filename: commit-changes.php
runnable: false
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Svn\SvnClient;

$client = new SvnClient( array(
	'username' => 'my-wporg-login',
	'password' => getenv( 'SVN_PASSWORD' ),
) );

$wc = '/tmp/my-plugin';
$client->checkout( 'https://plugins.svn.wordpress.org/my-plugin/trunk', $wc );

file_put_contents( "$wc/readme.txt", "= My Plugin 1.1 =\n" );
file_put_contents( "$wc/new-feature.php", "<?php // ...\n" );
$client->add( $wc, 'new-feature.php' );
$client->delete( $wc, 'deprecated.php' );

print_r( $client->status( $wc ) );

$info = $client->commit( $wc, 'Release 1.1.' );
echo "committed r{$info['revision']}\n";
```

## Talk to a repository without a working copy

<p><code>open_session()</code> exposes the repository primitives both protocols share: list directories, fetch files and properties, inspect revisions, or commit a changeset built in memory.</p>

<!-- snippet:
filename: repository-session.php
runnable: false
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Svn\SvnClient;

$client  = new SvnClient();
$session = $client->open_session( 'https://develop.svn.wordpress.org/trunk' );

echo "HEAD is r{$session->get_latest_revision()}\n";
foreach ( $session->list_directory( 'src/wp-includes/blocks' ) as $entry ) {
	echo "{$entry['kind']}\t{$entry['name']}\n";
}

$file = $session->get_file( 'wp-config-sample.php' );
echo $file['contents'];

$session->close();
```

## Supported and not supported

<p>Supported: checkout (any depth), update (with per-file conflict
detection), status, add, delete, revert, resolved, commit (files,
directories, and property changes), <code>svn:externals</code> (including
<code>^/</code>, <code>../</code>, <code>//</code>, and <code>/</code> relative
URLs, pinned revisions, and the pre-1.5 syntax), <code>svn:eol-style</code>
translation, anonymous and password authentication (HTTP Basic over
http(s), CRAM-MD5 over svn://).</p>

<p>Not supported (yet): <code>file://</code> and <code>svn+ssh://</code>
URLs, locks, <code>svn:keywords</code> expansion, <code>svn:special</code>
symlinks, merge tracking, mixed-depth sparse checkouts, and working
copies shared with the official client. HTTP servers must run
Subversion 1.7 or newer (HTTP protocol v2).</p>
