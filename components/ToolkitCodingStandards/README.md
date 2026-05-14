---
slug: coding-standards
title: ToolkitCodingStandards

see_also:
  - polyfill | Polyfill | Share WordPress-style compatibility expectations across standalone packages.
---

PHP_CodeSniffer sniffs used by this project: enforce Yoda comparisons and ban the short ternary where it hides falsy-value bugs.

## Why this exists

<p>This component is not currently published as a separate Composer package and is not a general-purpose style guide. It holds project-specific PHP_CodeSniffer rules for review comments the toolkit wants automated: comparisons should follow the WordPress Yoda style, and short ternaries should not hide whether a fallback is meant for <code>null</code> only or for all falsy values.</p>

<p>Use it in this monorepo, or vendor/copy it into a project that intentionally wants the same review tradeoffs. If your project does not follow WordPress-style comparisons, the Yoda sniff is probably the wrong rule for you.</p>

## Reference the standard from your phpcs.xml

<p>The component is a PHPCS ruleset, so the useful examples are configuration and before/after code rather than runtime snippets. Activate both sniffs at once by referencing <code>WordPressToolkitCodingStandards</code>:</p>

<pre><code>&lt;?xml version="1.0"?&gt;
&lt;ruleset name="My Project"&gt;
  &lt;file&gt;src/&lt;/file&gt;

  &lt;!-- Activate both toolkit sniffs --&gt;
  &lt;rule ref="WordPressToolkitCodingStandards"/&gt;

  &lt;!-- Or pick them individually --&gt;
  &lt;!-- &lt;rule ref="WordPressToolkitCodingStandards.PHP.EnforceYodaComparison"/&gt; --&gt;
  &lt;!-- &lt;rule ref="WordPressToolkitCodingStandards.PHP.DisallowShortTernary"/&gt; --&gt;
&lt;/ruleset&gt;</code></pre>

<p>Then run phpcs and phpcbf the usual way:</p>

<pre><code>vendor/bin/phpcs --standard=phpcs.xml .
vendor/bin/phpcbf --standard=phpcs.xml .</code></pre>

## EnforceYodaComparison: catches accidental assignment

<p>Yoda comparisons (<code>true === $x</code>) make typo-induced assignments easier to catch and match the WordPress style used throughout the toolkit:</p>

<pre><code>// Bug: single = inside a condition. Always truthy, mutates $status.
if ( $status = 'published' ) {
    publish_post( $post );
}

// Yoda style: writing this typo would be a parse error.
if ( 'published' === $status ) {
    publish_post( $post );
}</code></pre>

<p>The sniff covers <code>===</code>, <code>!==</code>, <code>==</code>, and <code>!=</code>, and stays quiet when both sides are dynamic.</p>

## Why ban the short ternary

<p>Developers confuse the short ternary (<code>$a ?: $b</code>) with the null-coalescing operator (<code>$a ?? $b</code>). They differ on falsy-but-not-null values: <code>0 ?: 'fallback'</code> returns <code>'fallback'</code>, but <code>0 ?? 'fallback'</code> returns <code>0</code>. The sniff bans <code>?:</code> entirely so reviewers don't have to relitigate this on every PR.</p>

## Review-friendly replacements

<p>When the fallback should apply only to <code>null</code>, use <code>??</code>. When the fallback should apply to every falsy value, write the full ternary so the intent is visible in review.</p>

<pre><code>// Only missing values fall back. 0 and "" are preserved.
$limit = $request_limit ?? 20;

// Any falsy value falls back. The duplicated condition is intentional.
$title = $raw_title ? $raw_title : 'Untitled';</code></pre>
