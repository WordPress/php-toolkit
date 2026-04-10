# Git

A pure PHP implementation of a Git client and server. It can create repositories, read and write objects, commit files, manage branches, diff, merge, and communicate with remote servers over HTTP -- all without shelling out to the `git` binary or requiring any native extensions.

## Installation

```bash
composer require wp-php-toolkit/git
```

## Quick Start

```php
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;

// Create a repository backed by an in-memory filesystem.
// You can also use a local filesystem for on-disk storage.
$repo = new GitRepository( InMemoryFilesystem::create() );

// Commit files directly -- the repository builds the
// blob, tree, and commit objects for you.
$commit_oid = $repo->commit( array(
    'updates' => array(
        'README.md'          => '# My Project',
        'src/hello-world.php' => '<?php echo "Hello!";',
    ),
) );

// Read a file back from the latest commit.
$contents = $repo->read_object_by_path( '/README.md' )->consume_all();
// "# My Project"
```

## Usage

### Creating and reading objects

Every piece of data in Git is an object identified by its SHA-1 hash. You can create blobs, trees, and commits directly:

```php
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );

// Store a blob and get its SHA-1 hash.
$blob_oid = $repo->add_object( 'blob', 'Hello, world!' );
// "5dd01c177f5d7d1be5346a5bc18a569a7410c2ef"

// Read it back.
$reader = $repo->read_object( $blob_oid );
$reader->pull( 8096 );
$data = $reader->peek( 8096 );
// "Hello, world!"
```

### Committing files

The `commit()` method handles building the tree hierarchy, creating blob objects, and wiring up parent commits automatically:

```php
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );

// First commit.
$first_oid = $repo->commit( array(
    'updates' => array(
        'dir1/file1.txt' => 'Initial content of file1',
        'dir2/file2.txt' => 'Initial content of file2',
    ),
) );

// Second commit -- only the changed files are updated.
$second_oid = $repo->commit( array(
    'updates' => array(
        'dir1/file1.txt' => 'Updated file1',
    ),
) );

// Delete a file in a commit.
$third_oid = $repo->commit( array(
    'deletes' => array( 'dir2/file2.txt' ),
) );
```

### Branch management

```php
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$initial_oid = $repo->commit( array(
    'updates' => array( 'file.txt' => 'initial' ),
) );

// Create a new branch pointing at the current commit.
$repo->create_branch( 'refs/heads/feature', $initial_oid );

// Switch to it.
$repo->checkout( 'refs/heads/feature' );

// Commit on the new branch.
$repo->commit( array(
    'updates' => array( 'file.txt' => 'changed on feature' ),
) );

// Switch back to the default branch.
$repo->checkout( 'refs/heads/trunk' );

// Read the current branch tip hash.
$head_hash = $repo->get_branch_tip( 'HEAD' );
```

### Merging

```php
$repo->checkout( 'refs/heads/trunk' );
$result = $repo->merge( 'refs/heads/feature' );

// $result['new_head'] -- the hash of the merge commit
// $result['conflicts'] -- array of conflicting paths (empty if none)
```

### Using GitFilesystem

`GitFilesystem` wraps a `GitRepository` with the standard `Filesystem` interface, so you can read and write files as if working with a regular filesystem. Each write creates a new commit.

```php
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;

$repo = new GitRepository( InMemoryFilesystem::create() );
$repo->commit( array(
    'updates' => array(
        'README.md'                    => 'Hello, world!',
        'subdirectory/hello-world.txt' => 'Hello, world!',
    ),
) );

$fs = GitFilesystem::create( $repo );

$fs->ls( '/' );
// ['README.md', 'subdirectory']

$fs->is_file( '/README.md' );           // true
$fs->is_dir( '/subdirectory' );          // true
$fs->get_contents( '/README.md' );       // "Hello, world!"

// Writing creates a new commit automatically.
$fs->put_contents( '/new-file.txt', 'content' );

// Rename a directory.
$fs->rename( '/subdirectory', '/renamed' );
```

### Working with remotes

```php
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$repo->add_remote( 'origin', 'https://github.com/user/repo' );

$remote = $repo->get_remote_client( 'origin' );

// List remote refs.
$refs = $remote->ls_refs( 'refs/heads/' );

// Pull a branch.
$remote->pull( 'refs/heads/trunk' );

// Push local changes.
$remote->push( 'trunk' );
```

## API Reference

### GitRepository

| Method | Description |
|---|---|
| `__construct( Filesystem $fs )` | Create a repository backed by a filesystem |
| `add_object( $type, $content )` | Store a blob, tree, or commit; returns its SHA-1 hash |
| `read_object( $oid )` | Read an object by hash; returns a stream with `consume_all()` and `as_commit()` / `as_tree()` |
| `has_object( $oid )` | Check whether an object exists locally |
| `find_hash_by_path( $path, $commit )` | Resolve a file path to its object hash |
| `read_object_by_path( $path, $commit )` | Read a file's content by path |
| `commit( $options )` | Create a commit with `'updates'`, `'deletes'`, and `'move_trees'` |
| `create_branch( $name, $oid )` | Create a new branch |
| `checkout( $branch_or_hash )` | Switch HEAD to a branch or commit |
| `get_branch_tip( $name )` | Get the commit hash a branch points to |
| `set_branch_tip( $name, $oid )` | Point a branch at a specific commit |
| `merge( $branch_name, $options )` | Three-way merge; returns `['new_head' => ..., 'conflicts' => [...]]` |
| `diff_commits( $hash1, $hash2 )` | Diff two commits |
| `add_remote( $name, $url )` | Register a remote |
| `get_remote_client( $name )` | Get a `GitRemote` for push/pull operations |

### GitFilesystem

| Method | Description |
|---|---|
| `GitFilesystem::create( $repo )` | Wrap a repository with the Filesystem interface |
| `ls( $path )` | List directory entries |
| `is_file( $path )` / `is_dir( $path )` | Check entry type |
| `get_contents( $path )` | Read file contents |
| `put_contents( $path, $data )` | Write a file (creates a commit) |
| `rename( $from, $to )` | Rename a file or directory |
| `rm( $path )` / `rmdir( $path )` | Delete a file or directory |

### Model classes

| Class | Key properties |
|---|---|
| `Commit` | `$hash`, `$tree`, `$parents`, `$author`, `$message` |
| `Tree` | `$entries` (map of name to `TreeEntry`) |
| `TreeEntry` | `$mode`, `$name`, `$hash`; constants `FILE_MODE_REGULAR_NON_EXECUTABLE`, `FILE_MODE_DIRECTORY` |

## Requirements

- PHP 7.2+
- No external dependencies (no `git` binary required)
