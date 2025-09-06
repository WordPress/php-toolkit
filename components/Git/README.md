# Git

Pure‑PHP Git object store and protocol helpers tailored for shallow operations in constrained environments. Provides a minimal repository, object encode/decode, tree/commit navigation, and selective merges.

## Problems Solved
- Read/write Git objects without shelling out to `git`
- Walk trees, resolve paths at a commit, compute object sets added between commits
- Perform simple merges with pluggable strategies for text content

## Quick Start
```php
use WordPress\Git\GitRepository;
use WordPress\Filesystem\LocalFilesystem;

$repo = new GitRepository(new LocalFilesystem(__DIR__ . '/.git'));

// Add a blob object
$blobHash = $repo->add_object('blob', "Hello\n");

// Resolve path at HEAD
$oid = $repo->find_hash_by_path('README.md');
$object = $repo->read_object($oid);
$content = $object->consume_all();
```

## Example: Merge branches (text‑only content)
```php
$result = $repo->merge('feature', [
  'conflict_resolution_strategy' => 'theirs', // overwrite on conflict
]);
// $result['new_head'], $result['conflicts']
```

## Notes
- Repository uses a filesystem backend (see `components/Filesystem`)
- Only a subset of Git features are implemented; designed for deterministic operations

