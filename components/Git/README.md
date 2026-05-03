# Git

<!-- docs-site-banner -->
> 📚 **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/git.html](https://wordpress.github.io/php-toolkit/reference/git.html)
> Open the page to edit each snippet in your browser and run it in WordPress Playground.
<!-- /docs-site-banner -->

## Why this exists

Git is typically used through the `git` binary — a compiled C program that reads and writes the repository on disk. That's perfect for most development workflows, but it breaks down in a few important scenarios:

- **Serverless and sandboxed environments.** WordPress Playground runs PHP entirely in the browser via WebAssembly. There is no OS, no filesystem, no ability to exec a subprocess. Yet Playground needs to clone, commit, and push WordPress installations as Git repositories.
- **Programmatic repository manipulation.** Sometimes you want to create commits, rewrite history, or sync files between repositories entirely from PHP — without spawning a shell process or depending on the `git` binary being installed.
- **Embedding Git into a PHP application.** Build tools, deployment systems, and migration scripts that want to produce or consume Git repositories without a compile-time dependency on libgit2 or similar native libraries.

This component implements the Git object model, pack protocol, and HTTP smart transport in pure PHP. It can talk to any standard Git remote — GitHub, GitLab, Gitea, self-hosted — using only PHP's HTTP client.

## How it works

Git's data model is simpler than it looks. Everything is content-addressed: the SHA-1 hash of an object's content is its name. There are four object types:

- **blob** — file content, nothing else.
- **tree** — a directory listing: each entry maps a filename to either a blob hash (file) or another tree hash (subdirectory).
- **commit** — a snapshot: it points to a tree (the root of the working directory), zero or more parent commit hashes, and metadata like the author and message.
- **tag** — a named pointer to another object (usually a commit).

When you commit a file, Git stores the file content as a blob, builds a tree structure from the directory layout, and creates a commit object that records which tree represents the project state at that moment. Branches are just named pointers to commit hashes stored in `refs/heads/`.

`GitRepository` handles all of this. Give it a `Filesystem` object to use as backing storage, and it reads and writes Git objects directly into the `.git` directory structure. `GitRemote` handles the HTTP smart protocol — fetching a list of remote refs, downloading pack files, uploading missing objects.

`GitFilesystem` wraps a `GitRepository` and exposes the contents of a specific commit through the standard `Filesystem` interface, so the rest of your code doesn't need to know it's reading from a Git object store.

## Usage

### Create a new repository and make a commit

```php
use WordPress\Git\GitRepository;
use WordPress\Filesystem\InMemoryFilesystem;

$fs   = new InMemoryFilesystem();
$repo = new GitRepository( $fs );
$repo->init();

// Stage a file by writing it to the working directory...
$fs->put_contents( '/hello.txt', 'Hello, world.' );

// ...then commit.
$repo->stage_files( array( 'hello.txt' ) );
$repo->commit( 'Initial commit', 'Author Name', 'author@example.com' );
```

### Read a file from a specific commit

```php
use WordPress\Git\GitFilesystem;

// Mount the HEAD commit as a filesystem.
$git_fs = new GitFilesystem( $repo, 'HEAD' );

$contents = $git_fs->get_contents( '/hello.txt' );
// "Hello, world."
```

### Clone from a remote

```php
use WordPress\Git\GitRepository;
use WordPress\Git\GitRemote;
use WordPress\Filesystem\LocalFilesystem;

$fs   = new LocalFilesystem( '/tmp/my-clone' );
$repo = new GitRepository( $fs );
$repo->init();

$repo->add_remote( 'origin', 'https://github.com/WordPress/wordpress-develop' );
$remote = $repo->get_remote_client( 'origin' );

// Fetch the default branch.
$remote->fetch( 'refs/heads/trunk' );
```

### Push to a remote

```php
$remote = $repo->get_remote_client( 'origin' );
$remote->push( 'refs/heads/my-branch' );
```

### Read the commit log

```php
$head   = $repo->get_head();
$commit = $repo->read_commit( $head );

while ( $commit !== null ) {
    echo $commit->message . "\n";
    echo '  by ' . $commit->author_name . ' <' . $commit->author_email . ">\n";

    $parent_hash = $commit->parent_hash;
    $commit      = $parent_hash ? $repo->read_commit( $parent_hash ) : null;
}
```

### Diff two commits

```php
$changes = $repo->diff( $commit_hash_a, $commit_hash_b );

foreach ( $changes as $path => $change ) {
    echo $change['status'] . ' ' . $path . "\n";
    // 'A' = added, 'M' = modified, 'D' = deleted
}
```

### Use GitFilesystem anywhere a Filesystem is expected

Because `GitFilesystem` implements the `Filesystem` interface, you can pass it to any code that operates on a filesystem — including `ZipEncoder` to package a commit as a ZIP file:

```php
use WordPress\Git\GitFilesystem;
use WordPress\Zip\ZipEncoder;

$git_fs  = new GitFilesystem( $repo, $commit_hash );
$encoder = new ZipEncoder( $output_stream );
$encoder->append_from_filesystem( $git_fs, '/' );
$encoder->finish();
```

## Architecture notes

Git object storage uses a two-level directory scheme: objects live in `.git/objects/ab/cdef...` where `ab` is the first two hex characters of the SHA-1 hash and `cdef...` is the rest. Pack files (compressed bundles of many objects) live in `.git/objects/pack/`. `GitRepository` handles both loose objects and pack file reading transparently.

The HTTP smart protocol works in two round trips for a fetch: first a discovery request that returns the list of refs the remote knows about, then a pack-file negotiation that uploads a pack containing only the objects you don't already have. `GitRemote` implements this protocol using PHP's HTTP client, with no native dependencies.
