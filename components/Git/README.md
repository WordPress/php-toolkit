---
slug: git
title: Git
install: wp-php-toolkit/git

see_also:
  - filesystem | Filesystem | Work with repository trees through a storage abstraction.
  - merge | Merge | Resolve divergent histories with explicit three-way merge logic.
  - bytestream | ByteStream | Read and write object data without accidental buffering.
---

A PHP implementation of core Git repository operations plus HTTP protocol helpers. Commits, branches, diffs, and selected push/pull workflows run without shelling out to <code>git</code>.

## Why this exists

<p>Git is a useful storage model even when a server cannot run the <code>git</code> binary: snapshots, branches, object-addressed files, diffs, merges, and sync over HTTP. That matters for WordPress tools that want revision history for generated files, content snapshots, site state, or collaborative edits in constrained runtimes.</p>

<p>The Git component implements the core repository operations in PHP and stores objects through the toolkit <code>Filesystem</code> interface. That means the same repository can live on disk, in memory, or in another backend, and higher-level code can commit files without knowing where objects are stored. It is a toolkit implementation for supported workflows, not a complete replacement for every <code>git</code> command and protocol edge case.</p>

<p>Git object storage and pack processing use zlib compression through the ByteStream compression filters.</p>

<p>The docs start with simple commits because that mental model scales: a repository is just objects plus refs. From there, branches, history walking, root commits, and merges become details you can reason about instead of magic shell behavior.</p>

<p>Choose it for tests, browser-like sandboxes, hosted WordPress environments, and applications that need Git behavior through PHP APIs instead of shell commands.</p>

## Commit files into an in-memory repo

<p>The simplest possible repository: an <code>InMemoryFilesystem</code> as object storage and one <code>commit()</code> call. Reach for this in tests, in WP-CLI snapshots, or any place you want versioning without touching disk.</p>

<!-- snippet:
filename: commit-in-memory.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );

$oid = $repo->commit( array(
	'updates' => array(
		'README.md'           => "# My Project\n",
		'src/hello-world.php' => '<?php echo "Hello!";',
	),
) );

echo "commit: {$oid}\n";
echo "HEAD:   " . $repo->get_branch_tip( 'HEAD' ) . "\n";
echo "README: " . $repo->read_object_by_path( '/README.md' )->consume_all();
```

<!-- expected-output -->
```
commit: <oid>
HEAD: <oid>
README: # My Project
```

## Walk the commit history

<p>Follow the parent chain from <code>HEAD</code> backwards. Building block for a WP-CLI "post revisions" log or a "what changed since release X" report.</p>

<!-- snippet:
filename: walk-history.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;

$repo = new GitRepository( InMemoryFilesystem::create() );
foreach ( array( 'add intro', 'fix typo', 'expand examples' ) as $i => $msg ) {
	$repo->commit( array(
		'updates' => array( 'post.md' => "# Draft {$i}" ),
		'commit'  => array( 'message' => $msg ),
	) );
}

$oid = $repo->get_branch_tip( 'HEAD' );
while ( ! Commit::is_null_hash( $oid ) ) {
	$c = $repo->read_object( $oid )->as_commit();
	echo substr( $c->hash, 0, 7 ) . '  ' . trim( $c->message ) . "\n";
	$oid = $c->get_first_parent_hash();
	if ( ! $oid || ! $repo->has_object( $oid ) ) break;
}
```

<!-- expected-output -->
```
<hash>  expand examples
<hash>  fix typo
<hash>  add intro
```

## Treat a repository like a filesystem

<p><code>GitFilesystem</code> wraps a repository in this toolkit's <code>Filesystem</code> interface. With the default options, each <code>put_contents()</code> records a new commit.</p>

<!-- snippet:
filename: git-filesystem.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$fs   = GitFilesystem::create( $repo );

$fs->put_contents( '/posts/hello.md', "# Hello\nFirst draft." );
$fs->put_contents( '/posts/about.md', "# About\nWho we are." );
$fs->put_contents( '/posts/hello.md', "# Hello\nSecond draft." );

echo "tree:\n";
foreach ( $fs->ls( '/posts' ) as $name ) {
	echo "  /posts/{$name}\n";
}
echo "\nhello.md now:\n" . $fs->get_contents( '/posts/hello.md' ) . "\n";
```

<!-- expected-output -->
```
tree:
  /posts/about.md
  /posts/hello.md

hello.md now:
# Hello
Second draft.
```

## Branch, edit, and switch back

<p>Create a feature branch off the current commit, change files, flip <code>HEAD</code> back. Useful for experimental edits in collaborative tools.</p>

<!-- snippet:
filename: branches.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$base = $repo->commit( array(
	'updates' => array( 'config.json' => '{"flag":false}' ),
	'commit'  => array( 'message' => 'baseline' ),
) );

$repo->create_branch( 'refs/heads/experiment', $base );
$repo->checkout( 'refs/heads/experiment' );
$repo->commit( array(
	'updates' => array( 'config.json' => '{"flag":true}' ),
	'commit'  => array( 'message' => 'flip the flag' ),
) );

echo "on experiment: " . $repo->read_object_by_path( '/config.json' )->consume_all() . "\n";

$repo->checkout( 'refs/heads/trunk' );
echo "on trunk:      " . $repo->read_object_by_path( '/config.json' )->consume_all() . "\n";
```

<!-- expected-output -->
```
on experiment: {"flag":true}
on trunk:      {"flag":false}
```

## Three-way merge two branches

<p>The classic Git workflow: branch off, edit on each side, merge. <code>$repo-&gt;merge()</code> finds the common ancestor, three-way-merges every file, and creates a merge commit.</p>

<!-- snippet:
filename: merge-branches.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$base = $repo->commit( array( 'updates' => array(
	'todo.txt' => "buy milk\nwalk dog\nread book\n",
) ) );

$repo->commit( array( 'updates' => array(
	'todo.txt' => "buy oat milk\nwalk dog\nread book\n",
) ) );

$repo->create_branch( 'refs/heads/feature', $base );
$repo->checkout( 'refs/heads/feature' );
$repo->commit( array( 'updates' => array(
	'todo.txt' => "buy milk\nwalk dog\nread book\nwrite blog post\n",
) ) );

$repo->checkout( 'refs/heads/trunk' );
$result = $repo->merge( 'refs/heads/feature' );

echo "merge head: {$result['new_head']}\n";
echo "conflicts:  " . ( $result['conflicts'] ? implode( ',', $result['conflicts'] ) : 'none' ) . "\n";
echo "result:\n" . $repo->read_object_by_path( '/todo.txt' )->consume_all();
```

<!-- expected-output -->
```
merge head: <oid>
conflicts:  none
result:
buy oat milk
walk dog
read book
write blog post
```

## Snapshot WordPress options into a repo

<p>Serialize a chunk of WP state (options, post meta, a theme config) on every save and commit it. You get free history, diffs between snapshots, and a "rollback to last week" button.</p>

<!-- snippet:
filename: options-snapshot.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );

$snapshots = array(
	array( 'blogname' => 'My Site',  'posts_per_page' => 10, 'timezone_string' => 'UTC' ),
	array( 'blogname' => 'My Site',  'posts_per_page' => 20, 'timezone_string' => 'UTC' ),
	array( 'blogname' => 'New Name', 'posts_per_page' => 20, 'timezone_string' => 'Europe/Warsaw' ),
);

foreach ( $snapshots as $i => $options ) {
	$repo->commit( array(
		'updates' => array( 'options.json' => json_encode( $options, JSON_PRETTY_PRINT ) ),
		'commit'  => array( 'message' => "snapshot #{$i}" ),
	) );
}

$head    = $repo->get_branch_tip( 'HEAD' );
$parent  = $repo->read_object( $head )->as_commit()->get_first_parent_hash();
$diff    = $repo->diff_commits( $head, $parent );

echo "Files changed in last snapshot:\n";
foreach ( $diff as $name => $entry ) {
	echo "  {$name}\n";
}
```

<!-- expected-output -->
```
Files changed in last snapshot:
  options.json
```
