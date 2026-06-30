---
slug: filesystem
title: Filesystem
install: wp-php-toolkit/filesystem

see_also:
  - bytestream | ByteStream | Open files as readers and writers instead of loading full strings.
  - zip | Zip | Mount archives and copy data between archive-backed and normal filesystems.
  - git | Git | Expose repository trees through a filesystem-shaped API.
---

One <code>Filesystem</code> interface across local disk, in-memory trees, SQLite databases, and ZIP archives. Forward-slash paths everywhere — even on Windows — so the same code runs in tests, in production, and inside read-only ZIP-backed trees.

## Why this exists

<p>Code that touches the filesystem is hard to test, hard to port to Windows, and impossible to point at non-disk storage without rewriting it. Swap <code>LocalFilesystem</code> for <code>InMemoryFilesystem</code> in tests and your suite stops touching <code>/tmp</code>; swap it for <code>SQLiteFilesystem</code> when the <code>sqlite3</code> extension is available and your "files" become rows in a portable database; swap it for <code>ZipFilesystem</code> and you can read inside an archive with the same calls.</p>

<p>Every backend uses forward slashes regardless of host OS. No <code>DIRECTORY_SEPARATOR</code> juggling, no Windows-only test failures, no surprises when a path moves between backends.</p>

## In-memory tree

<p>The fastest backend. No disk I/O, no cleanup, no test-isolation problems.</p>

<!-- snippet:
filename: teaser-memory.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\InMemoryFilesystem;

$fs = InMemoryFilesystem::create();
$fs->put_contents( '/hello.txt', 'Hello, world!' );
echo $fs->get_contents( '/hello.txt' );
```

<!-- expected-output -->
```
Hello, world!
```

## Test code without touching disk

<p>Code that takes a <code>Filesystem</code> parameter, instead of calling <code>file_get_contents()</code> directly, can be tested against an <code>InMemoryFilesystem</code>. The test sets up files in memory, exercises the function, and asserts on what got written — no temp directories, no cleanup.</p>

<!-- snippet:
filename: test-without-disk.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\InMemoryFilesystem;

function bump_version( Filesystem $fs, $path ) {
	$json = json_decode( $fs->get_contents( $path ), true );
	list( $maj, $min, $patch ) = explode( '.', $json['version'] );
	$json['version'] = $maj . '.' . $min . '.' . ( (int) $patch + 1 );
	$fs->put_contents( $path, json_encode( $json ) );
}

$fs = InMemoryFilesystem::create();
$fs->put_contents( '/package.json', '{"version":"1.2.3"}' );
bump_version( $fs, '/package.json' );

echo $fs->get_contents( '/package.json' ) . "\n";
```

<!-- expected-output -->
```
{"version":"1.2.4"}
```

## Local disk with a chrooted root

<p><code>LocalFilesystem::create($root)</code> is implicitly chrooted: every path resolves relative to <code>$root</code> and a <code>../</code> cannot escape. Reach for it when a request path or CLI argument names a file inside one project directory.</p>

<!-- snippet:
filename: local-chroot.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\LocalFilesystem;

$root = sys_get_temp_dir() . '/toolkit-' . uniqid();
$fs   = LocalFilesystem::create( $root );

$fs->mkdir( '/uploads', array( 'recursive' => true ) );
$fs->put_contents( '/uploads/note.txt', 'Hi from local disk.' );

echo $fs->get_contents( '/uploads/../uploads/note.txt' ) . "\n";

$fs->rmdir( '/', array( 'recursive' => true ) );
echo "exists after cleanup? " . ( is_dir( $root ) ? 'yes' : 'no' ) . "\n";
```

<!-- expected-output -->
```
Hi from local disk.
exists after cleanup? no
```

## SQLite as a portable file store

<p>The whole tree lives in one SQLite database file. Use it for self-contained scratch storage that survives process boundaries without leaving loose files behind. This backend requires PHP's <code>sqlite3</code> extension.</p>

<!-- snippet:
filename: sqlite.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\SQLiteFilesystem;

$fs = SQLiteFilesystem::create( ':memory:' );
$fs->mkdir( '/posts', array( 'recursive' => true ) );
for ( $i = 1; $i <= 3; $i++ ) {
	$fs->put_contents( "/posts/post-{$i}.md", "# Post {$i}\n\nBody {$i}." );
}

foreach ( $fs->ls( '/posts' ) as $name ) {
	$first = strtok( $fs->get_contents( '/posts/' . $name ), "\n" );
	echo "{$name}: {$first}\n";
}
```

<!-- expected-output -->
```
post-1.md: # Post 1
post-2.md: # Post 2
post-3.md: # Post 3
```

## Copy a tree across backends

<p>The killer composability move: <code>copy_between_filesystems()</code> streams files chunk-by-chunk from any source to any target. Pull a ZIP into SQLite, snapshot SQLite to disk, mirror disk into RAM — all the same call, with the relevant backend extensions available.</p>

<!-- snippet:
filename: cross-backend-copy.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Filesystem\SQLiteFilesystem;
use function WordPress\Filesystem\copy_between_filesystems;

$root  = sys_get_temp_dir() . '/copytree-' . uniqid();
$local = LocalFilesystem::create( $root );
$local->mkdir( '/site/posts', array( 'recursive' => true ) );
$local->put_contents( '/site/posts/2024-01.md', '# Hello 2024' );
$local->put_contents( '/site/index.html', '<h1>Home</h1>' );

$sqlite = SQLiteFilesystem::create( ':memory:' );
copy_between_filesystems( array(
	'source_filesystem' => $local,
	'source_path'       => '/site',
	'target_filesystem' => $sqlite,
	'target_path'       => '/snapshot',
) );

$mem = InMemoryFilesystem::create();
copy_between_filesystems( array(
	'source_filesystem' => $sqlite,
	'source_path'       => '/snapshot',
	'target_filesystem' => $mem,
	'target_path'       => '/copy',
) );

echo "in memory after two copies:\n";
echo "  posts: " . implode( ', ', $mem->ls( '/copy/posts' ) ) . "\n";
echo "  index: " . $mem->get_contents( '/copy/index.html' ) . "\n";

$local->rmdir( '/', array( 'recursive' => true ) );
```

<!-- expected-output -->
```
in memory after two copies:
  posts: 2024-01.md
  index: <h1>Home</h1>
```

## Atomic write via tempfile rename

<p>Write to a sibling tempfile, then rename — that's how you avoid leaving a half-written file on crash. <code>rename()</code> is atomic within a single filesystem.</p>

<!-- snippet:
filename: atomic-write.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;

function atomic_put_contents( Filesystem $fs, $path, $bytes ) {
	$tmp = $path . '.tmp.' . bin2hex( random_bytes( 4 ) );
	$fs->put_contents( $tmp, $bytes );
	$fs->rename( $tmp, $path );
}

$root = sys_get_temp_dir() . '/atomic-' . uniqid();
$fs   = LocalFilesystem::create( $root );

$fs->put_contents( '/config.json', '{"v":1}' );
atomic_put_contents( $fs, '/config.json', '{"v":2}' );

echo "config: " . $fs->get_contents( '/config.json' ) . "\n";
echo "no .tmp leftovers: " . count( $fs->ls( '/' ) ) . " entries in root\n";

$fs->rmdir( '/', array( 'recursive' => true ) );
```

<!-- expected-output -->
```
config: {"v":2}
no .tmp leftovers: 1 entries in root
```

## Path helpers that behave the same on Windows

<p>Unix path semantics apply on every host OS. This matters for abstract paths such as a SQLite key or a ZIP entry name because those paths do not live on a real drive.</p>

<!-- snippet:
filename: path-helpers.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_dirname;
use function WordPress\Filesystem\wp_unix_path_resolve_dots;

echo wp_join_unix_paths( '/var/www', '/site/', '/index.php' ) . "\n";
echo wp_unix_dirname( '/a/b/c/d.txt', 2 ) . "\n";
echo wp_unix_path_resolve_dots( '/a/b/../c/./d/../e' ) . "\n";
```

<!-- expected-output -->
```
/var/www/site/index.php
/a/b
a/c/e
```
