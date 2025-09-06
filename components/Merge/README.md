# Merge

Diff and merge utilities with configurable strategies. Includes Myers diff, line diffing, chunk merger, and a wrapper over a vendor‑patched diff‑match‑patch for fine‑grained merges.

## Problems Solved
- Compute diffs between versions as structured chunks
- Merge text with conflict detection and resolution strategies
- Validate merges in block markup specific scenarios

## Example: Merge two text versions
```php
use WordPress\Merge\Merge\ChunkMerger;

$merger = new ChunkMerger();
$result = $merger->merge([
  'parent'  => "Line 1\nLine 2\n",
  'branchA' => "Line 1\nLine 2 changed\n",
  'branchB' => "Line 1\nLine 2 also changed\n",
]);

if ($result->has_conflicts()) {
  // handle conflicts or choose strategy (e.g., prefer theirs)
}

$merged = $result->get_merged_content();
```

## Notes
- Designed to power higher‑level operations (e.g., `GitRepository::merge`)
- See `Validate/BlockMarkupMergeValidator` for block‑aware safeguards

