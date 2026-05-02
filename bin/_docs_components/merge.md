---
slug: merge
title: Merge
install: wp-php-toolkit/merge

see_also: git | Git | Merge file contents discovered through repository history.
see_also: markdown | Markdown | Resolve file-based editorial workflows before converting to blocks.
see_also: dataliberation | DataLiberation | Make content synchronization conflicts visible.
---

Three-way merge and diff. Pluggable differ + merger + optional validator.

## Why this exists

<p>Content synchronization needs more than "last write wins." A Markdown file changes in Git while the same post changes in WordPress. A generated config changes through both a CLI tool and a UI. In those cases you need a common ancestor, two edited versions, and a way to explain conflicts to a human.</p>

<p>The Merge component provides the diff and three-way merge primitives used by those workflows. The default examples are line-oriented because that is the most familiar shape, but the strategy is intentionally pluggable: choose the differ, choose the merger, and optionally validate the merged result before accepting it.</p>

<p>Use the merge result to auto-accept independent edits and to show structured conflicts when a person must decide.</p>

## Diff two strings line by line

<p>Feed two strings to <code>LineDiffer</code> and inspect the operations. Every <code>get_changes()</code> entry is a <code>[op, text]</code> pair.</p>

<!-- snippet:
filename: line-diff.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Merge\Diff\Diff;
use WordPress\Merge\Diff\LineDiffer;

$diff = ( new LineDiffer() )->diff(
	"alpha\nbeta\ngamma\n",
	"alpha\nBETA\ngamma\ndelta\n"
);

$labels = array( Diff::DIFF_EQUAL => '=', Diff::DIFF_DELETE => '-', Diff::DIFF_INSERT => '+' );
foreach ( $diff->get_changes() as $change ) {
	echo $labels[ $change[0] ] . ' ' . rtrim( $change[1] ) . "\n";
}
```

<!-- expected-output -->
```
= alpha
- beta
+ BETA
= gamma
+ delta
= 
```

## Render a unified patch

<p><code>format_as_git_patch()</code> produces output that mirrors <code>git diff</code>, including hunk headers — handy for emails, CI annotations, or a "what changed?" panel.</p>

<!-- snippet:
filename: git-patch.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Merge\Diff\LineDiffer;

$old = "title: Hello\nauthor: Alice\nstatus: draft\n";
$new = "title: Hello, world\nauthor: Alice\nstatus: published\ntags: greeting\n";

$diff = ( new LineDiffer() )->diff( $old, $new );
echo $diff->format_as_git_patch( array(
	'a_source' => 'a/post.yml',
	'b_source' => 'b/post.yml',
) );
```

<!-- expected-output -->
```
diff --git a/post.yml b/post.yml
--- a/post.yml
+++ b/post.yml
@@ -1,4 +1,5 @@- title: Hello
+ title: Hello, world
  author: Alice
- status: draft
+ status: published
+ tags: greeting
  
```

## Three-way merge with no conflicts

<p>The classic case: each branch changes a different region. Pass the common ancestor plus both edits to <code>MergeStrategy::merge()</code> and read the merged result.</p>

<!-- snippet:
filename: three-way.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Merge\Diff\LineDiffer;
use WordPress\Merge\Merge\LineMerger;
use WordPress\Merge\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );

$result = $strategy->merge(
	"intro\nbody\noutro\n",
	"intro updated\nbody\noutro\n",
	"intro\nbody\noutro\nappendix\n"
);

echo $result->has_conflicts() ? "conflicts!\n" : "clean merge:\n";
echo $result->get_merged_content();
```

<!-- expected-output -->
```
clean merge:
intro updated
body
outro
appendix
```

## Inspect and surface conflicts

<p>When both sides edit the same region, the merger produces a <code>MergeConflict</code>. The merged content carries Git-style markers, but the structured <code>get_conflicts()</code> output is what you want for a UI that lets the user pick a side.</p>

<!-- snippet:
filename: conflicts.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Merge\Diff\LineDiffer;
use WordPress\Merge\Merge\LineMerger;
use WordPress\Merge\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );
$result = $strategy->merge(
	"line 1\nline 2\n",
	"line 1\nline 2 from Alice\n",
	"line 1\nline 2 from Bob\n"
);

if ( $result->has_conflicts() ) {
	foreach ( $result->get_conflicts() as $c ) {
		echo "ours:   " . trim( $c->ours ) . "\n";
		echo "theirs: " . trim( $c->theirs ) . "\n";
	}
}
echo "\n--- merged content with markers ---\n";
echo $result->get_merged_content();
```

<!-- expected-output -->
```
ours:   line 2 from Alice
theirs: line 2 from Bob

--- merged content with markers ---
line 1

<<<<<<< HEAD
line 2 from Alice

=======
line 2 from Bob

>>>>>>> incoming 
```

## Sync a Markdown folder against an edited DB copy

<p>A real-world scenario: posts live both in a Git-tracked Markdown folder and in WordPress, and someone edits each. Three-way-merge each post against its common ancestor.</p>

<!-- snippet:
filename: sync-folder-vs-db.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Merge\Diff\LineDiffer;
use WordPress\Merge\Merge\LineMerger;
use WordPress\Merge\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );

$posts = array(
	'hello.md' => array(
		'base' => "# Hello\nDraft body.\n",
		'disk' => "# Hello\nDraft body, expanded on disk.\n",
		'db'   => "# Hello\nDraft body.\nNew section from the editor.\n",
	),
	'about.md' => array(
		'base' => "# About\nWho we are.\n",
		'disk' => "# About\nWho *they* are.\n",
		'db'   => "# About\nWho we really are.\n",
	),
);

foreach ( $posts as $name => $sides ) {
	$result = $strategy->merge( $sides['base'], $sides['disk'], $sides['db'] );
	echo "=== {$name} ===\n";
	echo $result->has_conflicts() ? "(conflict — needs review)\n" : "(auto-merged)\n";
	echo $result->get_merged_content() . "\n";
}
```

<!-- expected-output -->
```
=== hello.md ===
(conflict — needs review)
# Hello

<<<<<<< HEAD
Draft body, expanded on disk.

=======
New section from the editor.

>>>>>>> incoming 


=== about.md ===
(conflict — needs review)
# About

<<<<<<< HEAD
Who *they* are.

=======
Who we really are.

>>>>>>> incoming 
```
