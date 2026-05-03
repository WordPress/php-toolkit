<?php
/**
 * Generates docs/reference/<slug>.html for every component.
 *
 * The catalog comes from each components/<Name>/README.md — frontmatter +
 * lede + sections + snippets + expected-output fences.
 *
 * Parsing: webuni/front-matter peels off the YAML frontmatter and
 * league/commonmark parses the body into an AST. We walk the AST to
 * pick out section boundaries (H2 headings), pitfall callouts (raw
 * HTML blocks beginning with `<p>Footgun:` / `<p>Gotcha:`) and snippet
 * triples (HTML comment + `php` fence + optional `<!-- expected-output -->`
 * + plain fence). Both libraries are already vendored under
 * components/Markdown/vendor-patched/ for the Markdown component.
 */

declare(strict_types=1);

namespace WordPress\Toolkit\DocsBuild;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

if ( ! is_file( __DIR__ . '/../vendor/autoload.php' ) ) {
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 2 );
}
require __DIR__ . '/../vendor/autoload.php';

const ASSET_VERSION = '20260504-php-rewrite';
const ROOT          = __DIR__ . '/..';
const COMPONENTS    = ROOT . '/components';
const DOCS          = ROOT . '/docs/reference';

/** Slug → directory map (also defines docs-site ordering). */
const COMPONENT_ORDER = array(
	array( 'html',             'HTML' ),
	array( 'zip',              'Zip' ),
	array( 'bytestream',       'ByteStream' ),
	array( 'filesystem',       'Filesystem' ),
	array( 'blockparser',      'BlockParser' ),
	array( 'markdown',         'Markdown' ),
	array( 'xml',              'XML' ),
	array( 'encoding',         'Encoding' ),
	array( 'dataliberation',   'DataLiberation' ),
	array( 'git',              'Git' ),
	array( 'merge',            'Merge' ),
	array( 'httpclient',       'HttpClient' ),
	array( 'httpserver',       'HttpServer' ),
	array( 'corsproxy',        'CORSProxy' ),
	array( 'cli',              'CLI' ),
	array( 'polyfill',         'Polyfill' ),
	array( 'blueprints',       'Blueprints' ),
	array( 'coding-standards', 'ToolkitCodingStandards' ),
);

const PAGE_HEAD = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} — PHP Toolkit reference</title>
<meta name="description" content="{description}">
<link rel="stylesheet" href="../assets/style.css?v={asset_version}">
<script type="module" src="https://playground.wordpress.net/php-code-snippet.js"></script>
<script id="toolkit-setup" type="application/json"></script>
<script src="../assets/page.js?v={asset_version}" defer></script>
</head>
<body>
<header class="site">
	<a class="brand" href="../">PHP Toolkit</a>
	<nav>
		<a href="../learn/">Learn</a>
		<a href="./">Reference</a>
		<a href="https://github.com/WordPress/php-toolkit">GitHub</a>
	</nav>
</header>

<div class="layout">
';

const PAGE_FOOT = '	</article>
</div>

<footer class="site">
	<a href="https://github.com/WordPress/php-toolkit">WordPress/php-toolkit</a>
</footer>
</body>
</html>
';

/** @var Environment|null Lazy-initialized. */
$GLOBALS['__cm_env'] = null;

function commonmark_env(): Environment {
	if ( null === $GLOBALS['__cm_env'] ) {
		$env = new Environment( array() );
		$env->addExtension( new CommonMarkCoreExtension() );
		$GLOBALS['__cm_env'] = $env;
	}
	return $GLOBALS['__cm_env'];
}

function render_nodes( array $nodes ): string {
	$renderer = new HtmlRenderer( commonmark_env() );
	return rtrim( (string) $renderer->renderNodes( $nodes ) );
}

/**
 * Render the lede as inline HTML, without an outer `<p>`. If the lede
 * is a single Paragraph (the catalog convention), render its inline
 * children directly. Otherwise fall back to rendering all nodes.
 */
function render_lede( array $nodes ): string {
	$renderer = new HtmlRenderer( commonmark_env() );
	if ( 1 === count( $nodes ) && $nodes[0] instanceof Paragraph ) {
		$inline = iterator_to_array( $nodes[0]->children() );
		return rtrim( (string) $renderer->renderNodes( $inline ) );
	}
	return rtrim( (string) $renderer->renderNodes( $nodes ) );
}

/**
 * Parse the markdown body into the catalog's structured shape:
 *   [ lede_html, [ ['heading'=>..., 'body'=>html, 'snippet'=>{...}|null,
 *                    'pitfalls'=>[html, ...]], ... ], document_pitfalls ]
 *
 * Snippet detection: an HtmlBlock whose literal begins with
 * `<!-- snippet:` followed by a `FencedCode` with info `php` is a
 * snippet. An optional `<!-- expected-output -->` HtmlBlock plus a
 * plain `FencedCode` after that captures the expected stdout.
 *
 * Pitfall detection: an HtmlBlock whose literal begins with
 * `<p>Footgun:` or `<p>Gotcha:` (case-insensitive) is lifted out of
 * its section into a separate pitfalls list.
 */
function parse_body( string $md ): array {
	$parser = new MarkdownParser( commonmark_env() );
	/** @var Document $doc */
	$doc = $parser->parse( $md );

	$children = iterator_to_array( $doc->children() );

	// Find section boundaries (top-level H2 headings).
	$boundaries = array();
	foreach ( $children as $idx => $node ) {
		if ( $node instanceof Heading && 2 === $node->getLevel() ) {
			$boundaries[] = $idx;
		}
	}

	// The lede is everything before the first H2. The catalog convention
	// is a single Paragraph; render its inline children directly so the
	// page template's <p class="lede"> wrapper is the only paragraph.
	$lede_nodes = array_slice( $children, 0, $boundaries ? $boundaries[0] : count( $children ) );
	$lede_html  = render_lede( $lede_nodes );

	$sections = array();
	foreach ( $boundaries as $i => $start ) {
		$end           = $boundaries[ $i + 1 ] ?? count( $children );
		/** @var Heading $heading_node */
		$heading_node  = $children[ $start ];
		$heading_text  = inline_text( $heading_node );
		$content_nodes = array_slice( $children, $start + 1, $end - $start - 1 );
		list( $body_nodes, $snippet, $pitfalls ) = classify_section_children( $content_nodes );
		$sections[] = array(
			'heading'  => $heading_text,
			'body'     => render_nodes( $body_nodes ),
			'snippet'  => $snippet,
			'pitfalls' => $pitfalls,
		);
	}

	return array( $lede_html, $sections );
}

/**
 * Classify the children of a section into [body_nodes, snippet, pitfalls].
 * Each input is one of: snippet block, expected-output block, pitfall
 * paragraph, plain content.
 */
function classify_section_children( array $nodes ): array {
	$body     = array();
	$pitfalls = array();
	$snippet  = null;
	$i        = 0;
	$n        = count( $nodes );
	while ( $i < $n ) {
		$node = $nodes[ $i ];
		$pitfall_inner = classify_pitfall_block( $node );
		if ( null !== $pitfall_inner ) {
			$pitfalls[] = $pitfall_inner;
			$i++;
			continue;
		}
		if ( is_snippet_marker( $node ) && $i + 1 < $n && is_php_fence( $nodes[ $i + 1 ] ) ) {
			$meta = parse_snippet_meta( $node->getLiteral() );
			$code = rtrim( $nodes[ $i + 1 ]->getLiteral(), "\n" );
			$expected = null;
			$consumed = 2;
			if ( $i + 3 < $n && is_expected_output_marker( $nodes[ $i + 2 ] ) && $nodes[ $i + 3 ] instanceof FencedCode ) {
				$expected = rtrim( $nodes[ $i + 3 ]->getLiteral(), "\n" );
				$consumed = 4;
			}
			$snippet = array(
				'filename'        => $meta['filename'] ?? '',
				'code'            => $code,
				'runnable'        => ! in_array( strtolower( $meta['runnable'] ?? 'true' ), array( 'false', 'no', '0' ), true ),
				'expected_output' => $expected,
			);
			if ( '' === $snippet['filename'] ) {
				throw new \RuntimeException( 'snippet missing filename in metadata' );
			}
			$i += $consumed;
			continue;
		}
		$body[] = $node;
		$i++;
	}
	return array( $body, $snippet, $pitfalls );
}

function inline_text( Node $node ): string {
	$parts = array();
	foreach ( $node->iterator() as $sub ) {
		if ( $sub instanceof Text ) {
			$parts[] = $sub->getLiteral();
		}
	}
	return trim( implode( '', $parts ) );
}

function is_snippet_marker( Node $node ): bool {
	return $node instanceof HtmlBlock
		&& 0 === stripos( ltrim( $node->getLiteral() ), '<!-- snippet:' );
}

function is_expected_output_marker( Node $node ): bool {
	return $node instanceof HtmlBlock
		&& 0 === stripos( trim( $node->getLiteral() ), '<!-- expected-output' );
}

function is_php_fence( Node $node ): bool {
	return $node instanceof FencedCode && 'php' === strtolower( trim( $node->getInfo() ) );
}

/**
 * Detect a pitfall callout and return its inner HTML, or null if the
 * node isn't a pitfall.
 *
 * A pitfall is a single-paragraph HtmlBlock whose first inner text node
 * begins with "Footgun" or "Gotcha". The returned inner HTML keeps any
 * `<strong>`, `<code>`, etc. wrapping the lead sentence; it strips only
 * the literal "Footgun:" / "Gotcha:" prefix (and surrounding whitespace).
 *
 * Implementation walks the HtmlBlock with WP_HTML_Tag_Processor — no
 * regex over HTML.
 */
function classify_pitfall_block( Node $node ): ?string {
	if ( ! $node instanceof HtmlBlock ) {
		return null;
	}
	$html = trim( $node->getLiteral() );
	$p    = new \WP_HTML_Tag_Processor( $html );

	if ( ! $p->next_tag( 'P' ) || $p->is_tag_closer() ) {
		return null;
	}

	// Find the first inner text node. If anything else (a tag) comes
	// first, this isn't the pitfall pattern.
	$prefix_text = null;
	while ( $p->next_token() ) {
		$type = $p->get_token_type();
		if ( '#text' === $type ) {
			$prefix_text = $p->get_modifiable_text();
			break;
		}
		if ( '#tag' === $type ) {
			return null;
		}
	}
	if ( null === $prefix_text ) {
		return null;
	}

	$leading = ltrim( $prefix_text );
	$lower   = strtolower( $leading );
	if ( str_starts_with( $lower, 'footgun' ) ) {
		$word = 'footgun';
	} elseif ( str_starts_with( $lower, 'gotcha' ) ) {
		$word = 'gotcha';
	} else {
		return null;
	}

	// Strip the prefix word + optional ":" + whitespace, in place.
	$tail = ltrim( substr( $leading, strlen( $word ) ) );
	if ( str_starts_with( $tail, ':' ) ) {
		$tail = ltrim( substr( $tail, 1 ) );
	}
	$p->set_modifiable_text( $tail );

	$modified = trim( $p->get_updated_html() );

	// Strip the outer <p ...> ... </p>. The opener may carry attributes
	// (`<p class="...">`), so locate the end of the opening tag (`>`)
	// and the start of the closing tag (the LAST `<` in the fragment —
	// inner-content `<` characters belong to nested tags, which all
	// close before `</p>`).
	$opener_end   = strpos( $modified, '>' );
	$closer_start = strrpos( $modified, '<' );
	if ( false === $opener_end || false === $closer_start || $closer_start <= $opener_end ) {
		return $modified;
	}
	return trim( substr( $modified, $opener_end + 1, $closer_start - $opener_end - 1 ) );
}

/**
 * Parse the body of a `<!-- snippet: key: value\n... -->` HTML comment
 * into a key/value array. The comment delimiters are literal strings
 * (CommonMark already gave us the whole HtmlBlock); strip them by
 * length, then strip the leading `snippet:` label, then split each
 * remaining line on its first `:`.
 */
function parse_snippet_meta( string $html_comment ): array {
	$body = trim( $html_comment );
	if ( str_starts_with( $body, '<!--' ) && str_ends_with( $body, '-->' ) ) {
		$body = trim( substr( $body, 4, -3 ) );
	}
	if ( str_starts_with( strtolower( $body ), 'snippet:' ) ) {
		$body = ltrim( substr( $body, strlen( 'snippet:' ) ) );
	}
	$meta = array();
	foreach ( explode( "\n", $body ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || false === strpos( $line, ':' ) ) {
			continue;
		}
		list( $k, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
		$meta[ $k ]    = $v;
	}
	return $meta;
}

function load_components(): array {
	$front_matter = new \Webuni\FrontMatter\FrontMatter();
	$components   = array();
	foreach ( COMPONENT_ORDER as $row ) {
		list( $slug, $dir ) = $row;
		$path = COMPONENTS . "/$dir/README.md";
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( "missing README: $path" );
		}
		$doc    = $front_matter->parse( file_get_contents( $path ) );
		$fields = $doc->getData();

		if ( ( $fields['slug'] ?? null ) !== $slug ) {
			throw new \RuntimeException( "$path: frontmatter slug !== '$slug'" );
		}
		$title = $fields['title'] ?? '';
		if ( '' === $title ) {
			throw new \RuntimeException( "$path: missing title" );
		}

		$credit = null;
		if ( ! empty( $fields['credit_title'] ) || ! empty( $fields['credit_body'] ) ) {
			$credit = array(
				$fields['credit_title'] ?? '',
				$fields['credit_body'] ?? '',
			);
		}
		$see_also = parse_see_also( $fields['see_also'] ?? null );

		list( $lede, $sections ) = parse_body( $doc->getContent() );

		$components[] = array(
			'slug'     => $slug,
			'title'    => $title,
			'install'  => $fields['install'] ?? null,
			'lede'     => $lede,
			'credit'   => $credit,
			'see_also' => $see_also,
			'sections' => $sections,
		);
	}
	return $components;
}


/**
 * Convert see-also entries (each "<target> | Title | reason") into
 * (href, title, reason) triples. A target containing `/` or `.` is a
 * verbatim URL; otherwise it's a slug rendered as `<slug>.html`.
 */
function parse_see_also( $value ): array {
	if ( null === $value || '' === $value || array() === $value ) {
		return array();
	}
	$items = is_array( $value ) ? $value : array( $value );
	$out   = array();
	foreach ( $items as $item ) {
		$item = trim( $item );
		if ( '' === $item ) {
			continue;
		}
		// Limit to 3 splits so a `|` inside the reason is preserved
		// verbatim instead of breaking the parse.
		$parts = array_map( 'trim', explode( '|', $item, 3 ) );
		if ( count( $parts ) !== 3 ) {
			throw new \RuntimeException( "see_also must have three pipe-separated fields, got: $item" );
		}
		list( $target, $title, $reason ) = $parts;
		$href = ( false !== strpos( $target, '/' ) || false !== strpos( $target, '.' ) )
			? $target
			: "{$target}.html";
		$out[] = array( $href, $title, $reason );
	}
	return $out;
}

// ---------------------------------------------------------------------
// Renderer.
//
// Every HTML fragment we emit goes through WP_HTML_Processor (or the
// underlying WP_HTML_Tag_Processor):
//
//   * Authored fragments — sidebar, headings, install line, see-also,
//     snippet block, credit callout — are built by parsing a skeleton
//     with WP_HTML_Tag_Processor and patching attributes / text-node
//     content via set_attribute() / set_modifiable_text(). The
//     processor handles attribute escaping and text-node entity
//     encoding, so no concatenation of escaped values is needed.
//
//   * Inputs we receive as HTML (lede, section bodies, credit body,
//     pitfall inner) get parsed by WP_HTML_Processor::create_fragment()
//     and re-emitted via ::serialize(). That validates and normalizes
//     them through the tokenizer rather than embedding them
//     verbatim — unclosed tags get closed, attributes get the
//     parser's quoting, etc. Nothing is "trusted" as a raw byte
//     stream into the output.
// ---------------------------------------------------------------------

/**
 * Run an HTML fragment through WP_HTML_Processor — parse it as a
 * fragment, then serialize it back. The parse+serialize cycle
 * normalizes whatever the caller hands us through the tokenizer
 * (closing unclosed tags, normalizing attribute quoting, etc.).
 */
function normalize_fragment( string $html ): string {
	$p = \WP_HTML_Processor::create_fragment( $html );
	return null !== $p ? $p->serialize() : '';
}

/**
 * Slugify a heading into an anchor id. ASCII case-fold, then walk
 * characters: alphanumeric / `_` / `-` survive verbatim, runs of
 * whitespace collapse to a single dash, everything else is dropped.
 * No regex.
 */
function slugify( string $text ): string {
	$text          = mb_strtolower( $text );
	$out           = '';
	$space_pending = false;
	foreach ( mb_str_split( $text ) as $ch ) {
		if ( ctype_alnum( $ch ) || '_' === $ch || '-' === $ch ) {
			if ( $space_pending && '' !== $out ) {
				$out .= '-';
			}
			$out          .= $ch;
			$space_pending = false;
		} elseif ( ctype_space( $ch ) ) {
			$space_pending = true;
		}
		// Anything else (punctuation, symbols) is dropped, matching the
		// older `[^\w\s-]` strip.
	}
	return $out;
}

/**
 * Render one snippet's <php-snippet> block by patching attributes and
 * text nodes on a skeleton via WP_HTML_Tag_Processor — no string
 * concatenation of HTML, no manual entity escaping. set_attribute()
 * handles attribute escaping; set_modifiable_text() entity-encodes
 * text-node content (e.g. inside <code>) and writes script raw text
 * verbatim (after the one bypass for embedded `</script` literals
 * Tag_Processor explicitly delegates to the caller).
 */
function snippet_block( array $snippet ): string {
	$expected   = $snippet['runnable'] ? $snippet['expected_output'] : null;
	$has_output = null !== $expected;

	$skeleton  = '<php-snippet blueprint="toolkit-setup" name=""><pre class="snippet-fallback">';
	$skeleton .= '<code class="language-php"></code></pre>';
	$skeleton .= '<script type="application/x-php"></script>';
	if ( $has_output ) {
		$skeleton .= '<script type="text/expected-output"></script>';
	}
	$skeleton .= '</php-snippet>';

	$p = new \WP_HTML_Tag_Processor( $skeleton );
	while ( $p->next_tag() ) {
		switch ( $p->get_tag() ) {
			case 'PHP-SNIPPET':
				$p->set_attribute( 'name', $snippet['filename'] );
				if ( ! $snippet['runnable'] ) {
					$p->set_attribute( 'runnable', 'false' );
				}
				break;
			case 'CODE':
				$p->set_modifiable_text( rtrim( $snippet['code'] ) );
				break;
			case 'SCRIPT':
				$type = $p->get_attribute( 'type' );
				if ( 'application/x-php' === $type ) {
					$payload = rtrim( $snippet['code'] );
				} elseif ( 'text/expected-output' === $type && $has_output ) {
					$payload = rtrim( $expected );
				} else {
					break;
				}
				// WP_HTML_Tag_Processor::set_modifiable_text() refuses any
				// content that contains `</script` and explicitly leaves
				// this bypass to the caller; the documented escape is to
				// rewrite the leading `</` so the tokenizer doesn't treat
				// it as a tag opener. (See the comment in
				// class-wp-html-tag-processor.php's SCRIPT branch.)
				$payload = str_replace( '</script', '<\/script', $payload );
				$p->set_modifiable_text( $payload );
				break;
		}
	}
	return $p->get_updated_html() . "\n";
}

/**
 * Build the sidebar by parsing a skeleton with one `<li><a>x</a></li>`
 * per component, then walking and patching: set href on each `<a>`,
 * write the component title into the `<a>`'s text node, mark the
 * current component's `<li>` with `class="current"`. Tag_Processor
 * handles attribute and text escaping. No concat with escaped values.
 */
function sidebar( array $components, string $current_slug ): string {
	$skeleton  = '<aside class="sidebar" aria-label="Reference navigation">';
	$skeleton .= '<button class="sidebar-toggle" type="button" aria-expanded="false">On this page ▾</button>';
	$skeleton .= '<nav class="toc" aria-label="Table of contents"></nav>';
	$skeleton .= '<details class="components-nav" open>';
	$skeleton .= '<summary>All components</summary>';
	$skeleton .= '<ol>';
	foreach ( $components as $unused ) {
		$skeleton .= '<li><a href="">x</a></li>';
	}
	$skeleton .= '</ol></details></aside>';

	$p                  = new \WP_HTML_Tag_Processor( $skeleton );
	$idx                = 0;
	$awaiting_link_text = false;
	while ( $p->next_token() ) {
		$type = $p->get_token_type();
		if ( '#tag' === $type && ! $p->is_tag_closer() ) {
			$tag = $p->get_tag();
			if ( 'LI' === $tag ) {
				if ( $components[ $idx ]['slug'] === $current_slug ) {
					$p->add_class( 'current' );
				}
			} elseif ( 'A' === $tag ) {
				$p->set_attribute( 'href', $components[ $idx ]['slug'] . '.html' );
				$awaiting_link_text = true;
			}
		} elseif ( '#text' === $type && $awaiting_link_text ) {
			$p->set_modifiable_text( $components[ $idx ]['title'] );
			$awaiting_link_text = false;
			++$idx;
		}
	}
	return $p->get_updated_html();
}

/** Build the page <head> + opening header by patching PAGE_HEAD's
 * `<title>` text and `<meta name="description">` content via
 * Tag_Processor. The asset-version placeholder is a plain URL slot
 * (no HTML escaping), so a simple str_replace is fine for it.
 */
function build_page_head( string $title_text, string $description ): string {
	$head = str_replace( '{asset_version}', ASSET_VERSION, PAGE_HEAD );
	$p    = new \WP_HTML_Tag_Processor( $head );
	while ( $p->next_token() ) {
		if ( '#tag' !== $p->get_token_type() || $p->is_tag_closer() ) {
			continue;
		}
		$tag = $p->get_tag();
		if ( 'TITLE' === $tag ) {
			$p->set_modifiable_text( $title_text . ' — PHP Toolkit reference' );
		} elseif ( 'META' === $tag && 'description' === $p->get_attribute( 'name' ) ) {
			$p->set_attribute( 'content', $description );
		}
	}
	return $p->get_updated_html();
}

/** Build a heading via Tag_Processor: skeleton + navigate to the tag,
 * set its id, then walk to the inner text node and replace it. */
function build_heading( string $tag, string $text, ?string $id = null ): string {
	$lc       = strtolower( $tag );
	$uc       = strtoupper( $tag );
	$skeleton = "<{$lc} id=\"\">x</{$lc}>";
	$p        = new \WP_HTML_Tag_Processor( $skeleton );
	while ( $p->next_token() ) {
		$type = $p->get_token_type();
		if ( '#tag' === $type && ! $p->is_tag_closer() && $uc === $p->get_tag() ) {
			$p->set_attribute( 'id', $id ?? slugify( $text ) );
		} elseif ( '#text' === $type ) {
			$p->set_modifiable_text( $text );
		}
	}
	return $p->get_updated_html();
}

/** Build `<pre><code class="install">composer require <pkg></code></pre>`
 * by patching the inner <code>'s text node. */
function build_install_block( string $package ): string {
	$p = new \WP_HTML_Tag_Processor( '<pre><code class="install">x</code></pre>' );
	while ( $p->next_token() ) {
		if ( '#text' === $p->get_token_type() ) {
			$p->set_modifiable_text( "composer require {$package}" );
			break;
		}
	}
	return $p->get_updated_html();
}

/**
 * Build the credit callout. The lead title becomes a bold sentence;
 * the body is HTML received from the README's frontmatter. The full
 * fragment (lead + body) is parsed by WP_HTML_Processor and walked:
 * we navigate to the <strong>'s inner text node and write the title
 * via set_modifiable_text, then ::serialize() emits the result. The
 * body HTML is normalized by the parser; nothing is concat-as-bytes
 * into the output.
 */
function build_credit_block( string $title_text, string $body_html ): string {
	// Tag the lead <strong> with a sentinel attribute so we know which
	// one to patch even when the body HTML contains its own <strong>
	// tags. The attribute gets removed before serialization.
	$fragment = '<aside class="callout credit"><strong data-lead>x</strong> ' . $body_html . '</aside>';
	$p        = \WP_HTML_Processor::create_fragment( $fragment );
	if ( null === $p ) {
		return '';
	}
	$awaiting_lead_text = false;
	while ( $p->next_token() ) {
		$type = $p->get_token_type();
		if ( '#tag' === $type && ! $p->is_tag_closer() && 'STRONG' === $p->get_tag() && null !== $p->get_attribute( 'data-lead' ) ) {
			$p->remove_attribute( 'data-lead' );
			$awaiting_lead_text = true;
		} elseif ( '#text' === $type && $awaiting_lead_text ) {
			$p->set_modifiable_text( $title_text . '.' );
			break;
		}
	}
	// WP_HTML_Processor::serialize() refuses once the cursor has
	// advanced; get_updated_html() (inherited from Tag_Processor)
	// returns the byte-edited source with our text-node patch applied.
	return $p->get_updated_html();
}

/**
 * Build the see-also list. Each entry becomes
 *   <li><a href="..."><strong>Title</strong></a> <span>Reason</span></li>
 * Skeleton + navigate-and-set, no concat-with-escape.
 */
function build_see_also( array $see_also ): string {
	$skeleton = '<ul class="related-components">';
	foreach ( $see_also as $unused ) {
		$skeleton .= '<li><a href=""><strong>x</strong></a><span>x</span></li>';
	}
	$skeleton .= '</ul>';

	$p     = new \WP_HTML_Tag_Processor( $skeleton );
	$idx   = 0;
	$slot  = null; // 'STRONG' or 'SPAN'
	while ( $p->next_token() ) {
		$type = $p->get_token_type();
		if ( '#tag' === $type && ! $p->is_tag_closer() ) {
			$tag = $p->get_tag();
			if ( 'A' === $tag ) {
				$p->set_attribute( 'href', $see_also[ $idx ][0] );
			} elseif ( 'STRONG' === $tag || 'SPAN' === $tag ) {
				$slot = $tag;
			}
		} elseif ( '#text' === $type && null !== $slot ) {
			if ( 'STRONG' === $slot ) {
				$p->set_modifiable_text( $see_also[ $idx ][1] );
			} else { // 'SPAN'
				$p->set_modifiable_text( $see_also[ $idx ][2] );
				++$idx;
			}
			$slot = null;
		}
	}
	return $p->get_updated_html();
}

function render_component( array $components, array $c ): string {
	$pitfalls = array();
	$sections = $c['sections'];

	$purpose_html = '';
	$usage        = $sections;
	if ( $sections && strtolower( $sections[0]['heading'] ) === 'why this exists' ) {
		$purpose_html = $sections[0]['body'];
		$pitfalls     = array_merge( $pitfalls, $sections[0]['pitfalls'] );
		$usage        = array_slice( $sections, 1 );
	}

	$lede_text = trim( strip_tags( $c['lede'] ) );

	// Each builder returns a fully-formed HTML fragment built via
	// Tag_Processor; the page is glued together from those fragments.
	$pieces   = array();
	$pieces[] = build_page_head( $c['title'], $lede_text );
	$pieces[] = sidebar( $components, $c['slug'] );
	$pieces[] = "\t<article class=\"content\">\n";

	$pieces[] = build_heading( 'h1', $c['title'] );
	// The lede is HTML rendered by CommonMark from the README; wrap it
	// inside <p class="lede"> and re-parse the whole fragment through
	// WP_HTML_Processor, which closes any unclosed tags and normalizes
	// the markup before it lands in the output.
	$pieces[] = normalize_fragment( '<p class="lede">' . $c['lede'] . '</p>' );

	if ( $c['install'] ) {
		$pieces[] = build_install_block( (string) $c['install'] );
	}
	if ( $c['credit'] ) {
		list( $title_credit, $body_credit ) = $c['credit'];
		$pieces[] = build_credit_block( $title_credit, $body_credit );
	}

	if ( $purpose_html ) {
		$pieces[] = normalize_fragment( $purpose_html );
	}

	foreach ( $usage as $section ) {
		$pitfalls = array_merge( $pitfalls, $section['pitfalls'] );
		$pieces[] = build_heading( 'h2', $section['heading'] );
		if ( $section['body'] ) {
			$pieces[] = normalize_fragment( $section['body'] );
		}
		if ( $section['snippet'] ) {
			$pieces[] = snippet_block( $section['snippet'] );
		}
	}

	if ( $pitfalls ) {
		$pieces[] = build_heading( 'h2', 'Pitfalls', 'pitfalls' );
		foreach ( $pitfalls as $p ) {
			$pieces[] = normalize_fragment( '<aside class="callout pitfall">' . $p . '</aside>' );
		}
	}

	if ( $c['see_also'] ) {
		$pieces[] = build_heading( 'h2', 'See also', 'see-also' );
		$pieces[] = build_see_also( $c['see_also'] );
	}

	$pieces[] = PAGE_FOOT;
	return implode( "\n\n", $pieces );
}

function build_reference_main(): void {
	if ( ! is_dir( DOCS ) ) {
		mkdir( DOCS, 0755, true );
	}
	$components = load_components();
	foreach ( $components as $c ) {
		$path = DOCS . '/' . $c['slug'] . '.html';
		file_put_contents( $path, render_component( $components, $c ) );
		echo 'wrote reference/' . $c['slug'] . ".html\n";
	}
}

// Only run as a script when invoked directly. run-snippets.php requires
// this file purely to reuse load_components() and the constants.
if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	build_reference_main();
}
