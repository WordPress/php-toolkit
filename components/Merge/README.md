# Merge

<!-- docs-site-banner -->
> 📚 **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/merge.html](https://wordpress.github.io/php-toolkit/reference/merge.html)
> Open the page to edit each snippet in your browser and run it in WordPress Playground.
<!-- /docs-site-banner -->

A three-way merge and diff library for PHP. Given a common base version and two diverging branches, it computes diffs and merges the changes together, detecting conflicts along the way. The architecture is pluggable: swap out the differ (line-based or character-based), the merger (line-level or chunk-level), and add optional validation of the merged result.

## Installation

```
composer require wp-php-toolkit/merge
```

## Quick Start

```php
use WordPress\Merge\Diff\LineDiffer;
use WordPress\Merge\Merge\LineMerger;
use WordPress\Merge\MergeStrategy;

$strategy = new MergeStrategy(
    new LineDiffer(),
    new LineMerger()
);

$base     = "Line 1\nLine 2\nLine 3\n";
$branch_a = "Line 1\nLine 2 modified\nLine 3\n";
$branch_b = "Line 1\nLine 2\nLine 3\nLine 4\n";

$result = $strategy->merge( $base, $branch_a, $branch_b );
echo $result->get_merged_content();
// Line 1
// Line 2 modified
// Line 3
// Line 4
```

## Usage

### Computing Diffs

The `Diff` class represents a sequence of operations: equal, insert, and delete. You can create diffs manually or through a `Differ` implementation.

```php
use WordPress\Merge\Diff\Diff;
use WordPress\Merge\Diff\LineDiffer;

$differ = new LineDiffer();
$diff   = $differ->diff(
    "The quick brown fox\njumps over the lazy dog.\n",
    "The quick brown fox\njumps over the lazy cat.\nA new line.\n"
);

// Inspect the changes
foreach ( $diff->get_changes() as $change ) {
    $op   = $change[0]; // Diff::DIFF_EQUAL, DIFF_DELETE, or DIFF_INSERT
    $text = $change[1];
}

// Reconstruct the original and modified documents
echo $diff->get_old_document();
// The quick brown fox
// jumps over the lazy dog.

echo $diff->get_new_document();
// The quick brown fox
// jumps over the lazy cat.
// A new line.
```

### Delta Format

The delta format is a compact representation of a diff. Equal spans are encoded as byte counts, deletions as negative byte counts, and insertions as literal text.

```php
use WordPress\Merge\Diff\Diff;

$diff = new Diff( array(
    array( Diff::DIFF_EQUAL, "Line 1: The quick brown fox\n" ),
    array( Diff::DIFF_DELETE, "Line 2: jumps over the lazy dog.\n" ),
    array( Diff::DIFF_INSERT, 'A new line' ),
) );

echo $diff->format_as_delta();
// =28\r-33\r+A new line
//
// =28 means "keep 28 bytes unchanged"
// -33 means "delete 33 bytes"
// +A new line means "insert this text"
```

### Git Patch Format

Generate standard unified diffs that look like `git diff` output.

```php
use WordPress\Merge\Diff\Diff;

$diff = new Diff( array(
    array( Diff::DIFF_EQUAL, "Line 1: The quick brown fox\n" ),
    array( Diff::DIFF_DELETE, "Line 2: jumps over the lazy dog.\n" ),
    array( Diff::DIFF_INSERT, "Line 2: jumps over the lazy cat.\n" ),
    array( Diff::DIFF_EQUAL, "Line 3: consectetur adipiscing elit.\n" ),
) );

echo $diff->format_as_git_patch();
// diff --git a/string b/string
// --- a/string
// +++ b/string
// @@ -1,3 +1,3 @@  Line 1: The quick brown fox
// - Line 2: jumps over the lazy dog.
// + Line 2: jumps over the lazy cat.
//   Line 3: consectetur adipiscing elit.
```

### Three-Way Merge

`MergeStrategy` orchestrates the full merge workflow. It diffs each branch against the common base and then merges the two diffs together.

```php
use WordPress\Merge\Diff\MyersDiffer;
use WordPress\Merge\Merge\ChunkMerger;
use WordPress\Merge\MergeStrategy;

$strategy = new MergeStrategy(
    new MyersDiffer(),
    new ChunkMerger()
);

$base     = '{"level":1}';
$branch_a = '{"newattr": "before", "level":1}';
$branch_b = '{"level":2}';

$result = $strategy->merge( $base, $branch_a, $branch_b );
echo $result->get_merged_content();
// {"newattr": "before", "level":2}
```

### Handling Merge Conflicts

When both branches modify the same region, the merger produces a `MergeConflict`. You can inspect conflicts programmatically or render them as git-style conflict markers.

```php
use WordPress\Merge\Diff\LineDiffer;
use WordPress\Merge\Merge\LineMerger;
use WordPress\Merge\MergeStrategy;

$strategy = new MergeStrategy(
    new LineDiffer(),
    new LineMerger()
);

$result = $strategy->merge(
    "Line 1\nLine 2\n",
    "Line 1\nLine 2 from branch A\n",
    "Line 1\nLine 2 from branch B\n"
);

if ( $result->has_conflicts() ) {
    foreach ( $result->get_conflicts() as $conflict ) {
        echo 'Ours:   ' . $conflict->ours . "\n";
        echo 'Theirs: ' . $conflict->theirs . "\n";
    }
}

// The merged content includes git-style conflict markers
echo $result->get_merged_content();
```

### Merge Validation

Add a `MergeValidator` to reject merges that produce structurally invalid output, even when there are no textual conflicts. The built-in `BlockMarkupMergeValidator` validates WordPress block markup.

```php
use WordPress\Merge\Diff\MyersDiffer;
use WordPress\Merge\Merge\ChunkMerger;
use WordPress\Merge\MergeStrategy;
use WordPress\Merge\Validate\BlockMarkupMergeValidator;

$strategy = new MergeStrategy(
    new MyersDiffer(),
    new ChunkMerger(),
    new BlockMarkupMergeValidator()
);

$result = $strategy->merge( $base, $branch_a, $branch_b );

if ( $result->has_conflicts() ) {
    // The merge produced valid text but invalid block markup,
    // so it was converted into a conflict.
    $message = $result->get_conflicts()[0]->get_message();
}
```

## API Reference

### MergeStrategy

| Method | Description |
|--------|-------------|
| `__construct( Differ, Merger, ?MergeValidator )` | Create a strategy with pluggable components |
| `merge( $base, $branch_a, $branch_b )` | Perform a three-way merge, returns `MergeResult` |

### Diff

| Method | Description |
|--------|-------------|
| `__construct( array $changes )` | Create from an array of `[op, text]` pairs |
| `get_changes()` | Get the raw array of diff operations |
| `get_old_document()` | Reconstruct the original document from the diff |
| `get_new_document()` | Reconstruct the modified document from the diff |
| `format_as_delta()` | Compact delta format (`=28`, `-33`, `+text`) |
| `format_as_git_patch( $options )` | Unified diff format like `git diff` |

### Diff Constants

| Constant | Value | Meaning |
|----------|-------|---------|
| `Diff::DIFF_EQUAL` | `0` | Text is the same in both versions |
| `Diff::DIFF_DELETE` | `-1` | Text was removed |
| `Diff::DIFF_INSERT` | `1` | Text was added |

### MergeResult

| Method | Description |
|--------|-------------|
| `get_merged_content()` | Get the merged text, with conflict markers if applicable |
| `has_conflicts()` | Whether the merge has unresolved conflicts |
| `get_conflicts()` | Get an array of `MergeConflict` objects |

### MergeConflict

| Property/Method | Description |
|-----------------|-------------|
| `$ours` | Text from branch A |
| `$theirs` | Text from branch B |
| `get_message()` | Human-readable conflict description |

### Differ Implementations

| Class | Description |
|-------|-------------|
| `LineDiffer` | Line-by-line diff using longest common subsequence |
| `MyersDiffer` | Character-level diff using the Myers algorithm (via diff-match-patch) |

### Merger Implementations

| Class | Description |
|-------|-------------|
| `LineMerger` | Merges line-by-line diffs |
| `ChunkMerger` | Merges character-level chunk diffs |

## Requirements

- PHP 7.4+
- `ext-mbstring`
