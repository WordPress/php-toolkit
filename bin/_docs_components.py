# Component catalog for the runnable docs site. Imported by bin/build-docs.py.
#
# Format: list of (slug, title, lede_html, install_package, sections), where sections is a list of
#   (heading, body_html, snippet_or_None)
# and snippet is (filename, php_code). Use (filename, php_code, False) for a
# non-runnable <php-snippet runnable="false"> example.
#
# Both body_html and php_code may use HTML entities (&lt; &gt; &amp; &quot; &#x27;)
# — the renderer in build-docs.py decodes them before output. That keeps the
# embedded snippets readable when this file is edited as Python.

LOAD = "require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';\n\n"


def php(snippet):
    return '<?php\n' + LOAD + snippet


COMPONENTS = []


# Upstream credits surfaced as a callout on each reference page.
# Keep these brief — the landing page's "Credits" section carries the longer note.
CREDITS = {
    'html': (
        'Ported from WordPress core',
        'The HTML component is a port of WordPress core\'s '
        '<code>WP_HTML_Tag_Processor</code> and <code>WP_HTML_Processor</code>. '
        'Source: <a href="https://github.com/WordPress/wordpress-develop/tree/trunk/src/wp-includes/html-api">WordPress/wordpress-develop</a>. '
        'Bug fixes flow in both directions.',
    ),
    'blockparser': (
        'WordPress core, packaged standalone',
        '<code>WP_Block_Parser</code> is WordPress core\'s block parser, '
        'packaged here so importers and linters can read '
        '<a href="https://developer.wordpress.org/block-editor/reference-guides/block-api/">block markup</a> '
        'without booting WordPress. Source: '
        '<a href="https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/class-wp-block-parser.php">WordPress/wordpress-develop</a>.',
    ),
    'markdown': (
        'Built on league/commonmark',
        'Markdown parsing is delegated to '
        '<a href="https://commonmark.thephpleague.com/"><code>league/commonmark</code></a>; '
        'YAML frontmatter is handled by '
        '<a href="https://github.com/webuni/front-matter"><code>webuni/front-matter</code></a>. '
        'The toolkit\'s own work is the bridge between CommonMark\'s AST and '
        '<a href="https://developer.wordpress.org/block-editor/reference-guides/block-api/">WordPress block markup</a>, in both directions.',
    ),
    'polyfill': (
        'WordPress-shaped behavior',
        'When WordPress is loaded, every function in this component defers to WordPress. '
        'The standalone implementations of <code>esc_html()</code>, <code>add_filter()</code>, '
        '<code>__()</code>, and friends match WordPress core\'s behavior so the same code runs '
        'inside and outside the platform.',
    ),
}


COMPONENT_GUIDES = {
    'html': {
        'mental_model':
            '<p>Start with the tag processor when you need to change markup that WordPress already stored: add <code>loading="lazy"</code> to post images, make feed links absolute, or remove inline event handlers from pasted HTML. It scans forward and preserves every byte it does not touch.</p>'
            '<p>Switch to the full processor when the browser tree matters. Use it to find images inside figures, walk heading depth, or return to a saved parent after inspecting child tags.</p>',
        'journey': (
            ('Rewrite one tag safely', 'Add image attributes without parsing a DOM or changing surrounding whitespace.'),
            ('Protect real content', 'Rewrite relative links, remove script behavior, and add CSP nonces without clobbering author-provided attributes.'),
            ('Use structure when you need it', 'Find figure images, print a heading outline, and use bookmarks to annotate a parent after scanning its children.'),
        ),
    },
    'zip': {
        'mental_model':
            '<p>Treat a ZIP as a small filesystem with a table of contents at the end. Read the central directory, open one entry stream, and copy that entry where it belongs.</p>'
            '<p>Use <code>ZipFilesystem</code> when your code wants <code>get_contents()</code> and <code>ls()</code>. Use <code>ZipEncoder</code> and <code>ZipDecoder</code> when the archive format matters, such as an EPUB that must store <code>mimetype</code> first and uncompressed.</p>',
        'journey': (
            ('Open an archive as files', 'Read <code>readme.txt</code> through <code>ZipFilesystem</code> before touching entry headers.'),
            ('Write a format with rules', 'Build an EPUB and make the first entry Stored, not Deflated.'),
            ('Move archives through streams', 'Repack entries, reject <code>../</code> paths, and copy a remote ZIP entry into another filesystem without a manual byte loop.'),
        ),
    },
    'bytestream': {
        'mental_model':
            '<p>A read stream separates three actions: pull bytes, inspect the buffer, then consume the bytes you accepted. That pattern lets a parser wait for a full line, a ZIP decoder wait for a complete header, or an HTTP client report progress without losing data.</p>'
            '<p>Write streams make the destination boring. The caller writes chunks; the sink decides whether those bytes go to memory, a file, a compressor, or another component.</p>',
        'journey': (
            ('Read in chunks', 'Pull from memory and files with the same loop, then stop only when the stream reaches end-of-data.'),
            ('Handle awkward boundaries', 'Read lines split across chunks and connect producers to consumers with <code>MemoryPipe</code>.'),
            ('Add behavior around bytes', 'Wrap streams with gzip, hashing, limits, and windows while keeping the caller on the same interface.'),
        ),
    },
    'filesystem': {
        'mental_model':
            '<p>Write your tool against a filesystem object, not against the host machine. Tests can pass an in-memory tree, a CLI command can pass a local directory, and an importer can pass a ZIP-backed filesystem.</p>'
            '<p>Every toolkit path uses forward slashes. A path such as <code>wp-content/uploads/2026/logo.png</code> means the same thing on macOS, Windows, Playground, and inside an archive.</p>',
        'journey': (
            ('Start in memory', 'Write and list files without touching disk, which makes examples and tests deterministic.'),
            ('Move to a real backend', 'Use local, SQLite, and atomic-write examples to keep the same calling style while changing storage.'),
            ('Copy between backends', 'Move a generated theme file from memory to disk, or from a ZIP archive into a local staging directory, through one helper.'),
        ),
    },
    'blockparser': {
        'mental_model':
            '<p>The parser turns serialized post content into the block array shape WordPress core returns. It does not render blocks, load <code>block.json</code>, or ask a registry whether a block exists.</p>'
            '<p>Handle <code>blockName === null</code> first. A real post can contain a paragraph block, a custom block, and loose HTML before or after both.</p>',
        'journey': (
            ('Inspect the returned shape', 'Parse one paragraph block and read <code>blockName</code>, <code>attrs</code>, <code>innerBlocks</code>, <code>innerHTML</code>, and <code>innerContent</code>.'),
            ('Walk the tree', 'Count nested blocks and find custom blocks without writing recursive boilerplate everywhere.'),
            ('Ask editorial questions', 'Detect skipped heading levels, stale embeds, and blocks that need a migration before import.'),
        ),
    },
    'markdown': {
        'mental_model':
            '<p>Use Markdown for files that humans edit and block markup for content that WordPress stores. This component translates the supported middle ground: headings, paragraphs, lists, code blocks, links, images, and frontmatter-backed metadata.</p>'
            '<p>Keep unsupported syntax visible. A migration tool should tell you that a file contains an unsupported table instead of silently dropping it before publishing.</p>',
        'journey': (
            ('Convert one document', 'Turn <code>posts/launch.md</code> into block markup and turn supported blocks back into readable Markdown.'),
            ('Carry metadata beside content', 'Read frontmatter for title, slug, date, tags, and import hints.'),
            ('Prepare a folder import', 'Map filenames to slugs, audit generated blocks, and hand the result to DataLiberation when you need WXR.'),
        ),
    },
    'xml': {
        'mental_model':
            '<p><code>XMLProcessor</code> walks XML as a cursor. It reads the next tag, exposes attributes and text, records edits, and emits updated XML only when you call <code>get_updated_xml()</code>.</p>'
            '<p>Query namespaces by URI, not by prefix. In WXR, look for <code>http://wordpress.org/export/1.2/</code> even when the source file writes the prefix as <code>wp:</code>.</p>',
        'journey': (
            ('Edit one attribute', 'Bump product prices and see how buffered updates keep untouched XML intact.'),
            ('Read namespaced exports', 'Find WXR status fields and attachment URLs by namespace URI and local name.'),
            ('Process export-sized files', 'Rewrite staging URLs and parse OPML without building a full in-memory tree.'),
        ),
    },
    'encoding': {
        'mental_model':
            '<p>Validate text with the Encoding helpers before a stricter parser sees unfamiliar bytes. A Latin-1 title from an old export, an overlong UTF-8 sequence in an upload, or a Unicode noncharacter can break XML, JSON, or a database write later in the pipeline — and the further downstream the failure happens, the harder it is to trace.</p>'
            '<p>The component gives the same answer whether PHP has <code>mbstring</code> available or falls back to the pure-PHP scanner.</p>',
        'journey': (
            ('Reject invalid bytes', 'Separate clean UTF-8 from Latin-1 bytes, overlong forms, surrogate halves, and incomplete sequences.'),
            ('Repair when content matters', 'Replace invalid bytes with <code>U+FFFD</code> when keeping the rest of a post title beats stopping the import.'),
            ('Check downstream limits', 'Detect noncharacters before writing XML or handing text to a system with stricter Unicode rules.'),
        ),
    },
    'dataliberation': {
        'mental_model':
            '<p>Model a migration as a stream of WordPress-shaped entities. Read a post, rewrite its content and metadata, write it out, then move to the next entity.</p>'
            '<p>The useful work happens between readers and writers: rewrite <code>https://staging.example.test</code> inside HTML, block attributes, CSS, GUIDs, and media URLs; download attachments; and keep enough state to resume after a failed request.</p>',
        'journey': (
            ('Write one entity', 'Create a WXR post record and read it back before building a site-sized pipeline.'),
            ('Transform as you stream', 'Rewrite URLs on each entity without loading the whole export.'),
            ('Compose a migration', 'Convert a Markdown folder, frontload media with HttpClient, and write WXR through XML and ByteStream layers.'),
        ),
    },
    'git': {
        'mental_model':
            '<p>Git stores snapshots as objects: blobs hold file bytes, trees hold directory listings, commits point at trees, and refs name commits.</p>'
            '<p>This component keeps those objects visible. A browser-based editor can commit generated files, move <code>refs/heads/main</code>, expose a commit tree as a filesystem, and merge another branch without running the <code>git</code> binary.</p>',
        'journey': (
            ('Create a snapshot', 'Commit files into an in-memory repository and print the resulting object ID.'),
            ('Read history by name', 'Resolve refs, walk parent commits, and mount a commit tree with <code>GitFilesystem</code>.'),
            ('Coordinate edits', 'Create branches, merge content, and keep conflicts explicit for the caller.'),
        ),
    },
    'merge': {
        'mental_model':
            '<p>A three-way merge needs the common base, your version, and their version. The base tells the merger whether two lines changed independently or collided.</p>'
            '<p>Start with line merges for Markdown, config files, and generated PHP. Move to a domain-specific differ only when lines hide the real unit of change.</p>',
        'journey': (
            ('See the edit', 'Generate a diff and patch so the merge inputs feel concrete.'),
            ('Auto-merge independent lines', 'Combine two edits that touch different parts of the same file.'),
            ('Surface conflicts', 'Return conflict records for a UI, CLI prompt, or sync log instead of guessing a winner.'),
        ),
    },
    'httpclient': {
        'mental_model':
            '<p>Make the first request boring: <code>GET https://api.wordpress.org/plugins/info/1.2/</code>, then read the response status and body. From there, add the details the workflow actually needs: a POST body, JSON headers, redirects, cache policy, or a chosen transport.</p>'
            '<p>When the response becomes a file, keep it as a stream. A plugin installer can show progress while downloading a ZIP, resume a partial archive with <code>Range</code>, and hand the remote body to ZipFilesystem without first building a giant string.</p>',
        'journey': (
            ('Start with GET and POST', 'Fetch a URL, submit form data, and build a JSON request before touching lower-level objects.'),
            ('Configure the request path', 'Choose a transport, follow redirects, cache responses, and report failures with useful context.'),
            ('Scale the transfer', 'Show progress, keep ten media downloads active, resume a partial ZIP, and stream-unzip a remote archive through Filesystem helpers.'),
        ),
    },
    'httpserver': {
        'mental_model':
            '<p>Use HttpServer when a PHP tool needs one local endpoint. A CLI command can open <code>http://127.0.0.1:8765/callback</code> for an OAuth flow, serve fixture JSON to HttpClient tests, or expose a tiny status page during an import.</p>'
            '<p>The server accepts a connection, parses one request, and gives your handler a response writer. Keep the process lifetime and shutdown rule in your command.</p>',
        'journey': (
            ('Serve one response', 'Bind to loopback and return text from a handler.'),
            ('Route a small local API', 'Branch on method and path for <code>/api/status</code> and <code>/api/echo</code>.'),
            ('Buffer when headers depend on the body', 'Use the buffered writer when the runtime needs the full response before sending headers.'),
        ),
    },
    'corsproxy': {
        'mental_model':
            '<p>A browser app cannot read <code>https://api.github.com/repos/WordPress/php-toolkit</code> unless GitHub sends CORS headers the app can use. A PHP proxy can fetch that URL server-side and return a controlled browser-readable response.</p>'
            '<p>Deploy the proxy as a gate, not as an open tunnel. Allow <code>api.github.com</code> and <code>raw.githubusercontent.com</code> for a docs tool; reject private IP ranges, unknown hosts, oversized responses, and credential-bearing request headers.</p>',
        'journey': (
            ('See the proxy URL shape', 'Request <code>/cors-proxy.php/https://api.github.com/repos/WordPress/php-toolkit</code> from a local PHP server.'),
            ('Lock down deployment', 'Add a rate limiter and a host allowlist before exposing the proxy.'),
            ('Use it from the browser', 'Wrap <code>fetch()</code> once, then deploy the PHP script behind nginx or another SAPI.'),
        ),
    },
    'cli': {
        'mental_model':
            '<p>Define the command-line contract once, then parse <code>argv</code> against it. The parser returns positional arguments and named options; your application validates them and runs the command.</p>'
            '<p>A command such as <code>toolkit import posts/launch.md --site=demo --dry-run -vv</code> should not need a console framework just to understand flags, values, and positionals.</p>',
        'journey': (
            ('Parse the smallest command', 'Read one boolean flag and one positional argument.'),
            ('Accept normal shell shapes', 'Handle <code>--port=8080</code>, <code>--port 8080</code>, <code>-p 8080</code>, and bundled booleans such as <code>-afv</code>.'),
            ('Build command behavior', 'Add required options, help output, and subcommand dispatch in application code.'),
        ),
    },
    'polyfill': {
        'mental_model':
            '<p>Load Polyfill when toolkit code runs outside WordPress but still calls WordPress-shaped helpers. Standalone tests can call <code>esc_html()</code>, add a filter, or use a translation stub without booting WordPress.</p>'
            '<p>The component defines only missing functions. If WordPress or the current PHP runtime already provides a function, the polyfill leaves it alone.</p>',
        'journey': (
            ('Backfill missing PHP helpers', 'Use PHP 7.2-compatible helpers without dropping support for older runtimes.'),
            ('Keep familiar WordPress calls', 'Escape output and keep translation-shaped call sites in standalone tools.'),
            ('Expose extension points', 'Register filters and actions for library code that needs hooks outside WordPress.'),
        ),
    },
    'blueprints': {
        'mental_model':
            '<p>A Blueprint is a versioned recipe for a WordPress site. It can install Gutenberg, set permalink structure, import content, copy files, and run WP-CLI steps in a predictable order.</p>'
            '<p>The runner supplies the environment: site root, site URL, execution mode, and filesystem access. The validator checks user-authored JSON before the runner mutates the target site.</p>',
        'journey': (
            ('Configure the target', 'Create a <code>RunnerConfiguration</code> with the site path, URL, and execution mode.'),
            ('Generate repeatable recipes', 'Build JSON from PHP when tests or docs need a fresh site with the same plugins and options.'),
            ('Validate before running', 'Catch misspelled step names and missing fields before installing packages or changing options.'),
        ),
    },
    'coding-standards': {
        'mental_model':
            '<p>Turn repeat review comments into PHPCS sniffs. If the project always rejects short ternaries, loose comparisons, or a confusing Yoda condition, the tool should report it before a reviewer does.</p>'
            '<p>Keep each sniff narrow. A useful sniff names the risky pattern and shows the replacement code shape contributors should write.</p>',
        'journey': (
            ('Enable the ruleset', 'Point PHPCS at the toolkit standard from a component or CI job.'),
            ('Read the rule as review guidance', 'Learn why the Yoda and short-ternary sniffs exist instead of treating them as arbitrary style.'),
            ('Write the explicit form', 'Replace compact syntax with code that stays clear on PHP 7.2 and across WordPress-style projects.'),
        ),
    },
}


STARTER_PATHS = (
    (
        'Content and migration',
        'Start here when you are importing, exporting, rewriting, or auditing WordPress content.',
        ('html', 'blockparser', 'markdown', 'xml', 'dataliberation'),
    ),
    (
        'Streams and storage',
        'Use this path for archives, large files, testable storage backends, and pure-PHP file movement.',
        ('bytestream', 'filesystem', 'zip', 'git', 'merge'),
    ),
    (
        'Networked tools',
        'Use this path for clients, local fixture servers, browser-facing proxies, and CLI workflows.',
        ('httpclient', 'httpserver', 'corsproxy', 'cli'),
    ),
    (
        'WordPress runtime support',
        'Use this path when your code needs WordPress-shaped helpers, repeatable sites, or project-specific review rules.',
        ('polyfill', 'blueprints', 'coding-standards'),
    ),
)


COMPONENT_RELATIONS = {
    'html': (
        ('blockparser', 'BlockParser', 'Parse block comments first, then rewrite the HTML inside each block.'),
        ('markdown', 'Markdown', 'Convert Markdown to blocks before polishing generated HTML.'),
        ('dataliberation', 'DataLiberation', 'Rewrite URLs and media references during import/export pipelines.'),
    ),
    'zip': (
        ('filesystem', 'Filesystem', 'Treat an archive like a swappable filesystem backend.'),
        ('bytestream', 'ByteStream', 'Feed readers and writers without whole-file buffers.'),
        ('httpclient', 'HttpClient', 'Stream downloaded archives into validation or extraction workflows.'),
    ),
    'bytestream': (
        ('filesystem', 'Filesystem', 'Back file reads and writes with the same stream primitives.'),
        ('zip', 'Zip', 'Read and write archive entries one stream at a time.'),
        ('httpclient', 'HttpClient', 'Process request and response bodies incrementally.'),
    ),
    'filesystem': (
        ('bytestream', 'ByteStream', 'Open files as readers and writers instead of loading full strings.'),
        ('zip', 'Zip', 'Mount archives and copy data between archive-backed and normal filesystems.'),
        ('git', 'Git', 'Expose repository trees through a filesystem-shaped API.'),
    ),
    'blockparser': (
        ('html', 'HTML', 'Inspect or rewrite the HTML carried by parsed blocks.'),
        ('markdown', 'Markdown', 'Move between author-friendly Markdown and serialized block markup.'),
        ('dataliberation', 'DataLiberation', 'Audit and transform blocks while migrating content.'),
    ),
    'markdown': (
        ('blockparser', 'BlockParser', 'Understand the block tree created from Markdown output.'),
        ('html', 'HTML', 'Rewrite rendered HTML fragments without using DOMDocument.'),
        ('dataliberation', 'DataLiberation', 'Turn Markdown folders into import/export streams.'),
    ),
    'xml': (
        ('dataliberation', 'DataLiberation', 'Read and write WXR-sized WordPress exports as entities.'),
        ('encoding', 'Encoding', 'Validate and scrub text before strict XML processing.'),
        ('bytestream', 'ByteStream', 'Keep large XML reads incremental.'),
    ),
    'encoding': (
        ('html', 'HTML', 'Normalize incoming text before HTML tokenization.'),
        ('xml', 'XML', 'Keep invalid bytes out of XML streams.'),
        ('dataliberation', 'DataLiberation', 'Clean content before importing it into WordPress.'),
    ),
    'dataliberation': (
        ('markdown', 'Markdown', 'Use Markdown as a source or destination format.'),
        ('blockparser', 'BlockParser', 'Analyze serialized blocks inside post content.'),
        ('httpclient', 'HttpClient', 'Download media and remote source data while importing.'),
    ),
    'git': (
        ('filesystem', 'Filesystem', 'Work with repository trees through a storage abstraction.'),
        ('merge', 'Merge', 'Resolve divergent histories with explicit three-way merge logic.'),
        ('bytestream', 'ByteStream', 'Read and write object data without accidental buffering.'),
    ),
    'merge': (
        ('git', 'Git', 'Merge file contents discovered through repository history.'),
        ('markdown', 'Markdown', 'Resolve file-based editorial workflows before converting to blocks.'),
        ('dataliberation', 'DataLiberation', 'Make content synchronization conflicts visible.'),
    ),
    'httpclient': (
        ('bytestream', 'ByteStream', 'Stream request and response bodies.'),
        ('filesystem', 'Filesystem', 'Persist large downloads without buffering them in memory.'),
        ('corsproxy', 'CORSProxy', 'Bridge browser-side tools to servers without CORS headers.'),
    ),
    'httpserver': (
        ('cli', 'CLI', 'Expose a local browser UI from a command-line tool.'),
        ('httpclient', 'HttpClient', 'Test client code against a small local fixture server.'),
    ),
    'corsproxy': (
        ('httpclient', 'HttpClient', 'Fetch upstream responses from PHP when browser CORS blocks direct access.'),
        ('httpserver', 'HttpServer', 'Understand the local-server shape before deploying a proxy endpoint.'),
    ),
    'cli': (
        ('filesystem', 'Filesystem', 'Keep command behavior testable with in-memory storage.'),
        ('blueprints', 'Blueprints', 'Build repeatable site setup commands around parsed options.'),
        ('httpserver', 'HttpServer', 'Add a local web UI to a CLI workflow.'),
    ),
    'polyfill': (
        ('html', 'HTML', 'Run WordPress-shaped escaping and translation helpers beside HTML processors.'),
        ('blockparser', 'BlockParser', 'Keep standalone block tooling familiar outside WordPress.'),
    ),
    'blueprints': (
        ('filesystem', 'Filesystem', 'Prepare files and fixtures before applying site setup steps.'),
        ('httpclient', 'HttpClient', 'Download packages or source data as part of provisioning workflows.'),
        ('cli', 'CLI', 'Wrap repeatable blueprint operations in a small command.'),
    ),
    'coding-standards': (
        ('polyfill', 'Polyfill', 'Share WordPress-style compatibility expectations across standalone packages.'),
    ),
}


# ===========================================================================
# HTML
# ===========================================================================
COMPONENTS.append(('html', 'HTML',
    'A pure-PHP HTML5 parser and tag rewriter mirroring WordPress core\'s HTML API. Treat HTML the way browsers do — without <code>libxml2</code>, <code>DOMDocument</code>, or regex hacks — and rewrite attributes in a single linear pass.',
    'wp-php-toolkit/html',
    [
        ('Why this exists',
            '<p>WordPress runs HTML fragments through filters every time a request renders: post content, block markup, comments, excerpts, widgets, feeds, imported documents. Those fragments can omit <code>&lt;html&gt;</code> and <code>&lt;body&gt;</code>, close tags implicitly, or mix browser-correct markup with author mistakes that <code>DOMDocument</code> and regular expressions do not model well.</p>'
            '<p>The HTML component gives WordPress-style code the same parsing model WordPress core uses: a browser-compatible tokenizer and tree-aware processor that run in pure PHP. Choose it for exact-byte rewrites, imperfect fragments, and post-content filters where a full DOM would do too much work.</p>'
            '<p>The component gives you two processors. <code>WP_HTML_Tag_Processor</code> is a forward-only cursor over tags and tokens — useful for attribute rewriting at scale. <code>WP_HTML_Processor</code> layers HTML5 tree construction on top so you can query by ancestry (breadcrumbs), serialize the parsed document, and trust that <code>&lt;p&gt;one&lt;p&gt;two</code> parses as two paragraphs the way a browser sees it.</p>'
            '<p><strong>Footgun:</strong> mutations are buffered. Nothing changes in the source string until you call <code>get_updated_html()</code>. If you read <code>get_attribute()</code> after a <code>set_attribute()</code> on the same tag, you see the new value — but downstream tooling reading the original string sees stale HTML until you serialize.</p>',
            None),
        ('Add loading="lazy" to every image',
            '<p>The "hello world" of tag rewriting. One linear pass, no DOM, no reserialization cost beyond the bytes you actually changed.</p>'
            '<p><strong>Try this:</strong> click <em>Run</em>, then change <code>\'lazy\'</code> to <code>\'eager\'</code> on the first image only by guarding it with <code>$tags-&gt;get_attribute( \'src\' ) === \'hero.jpg\'</code>. Run again and notice that <code>get_updated_html()</code> only rewrites the bytes for that one tag.</p>',
            ('lazy-load-images.php', php('''$html = '<article>
\t<img src="hero.jpg" alt="Hero">
\t<p>Intro copy.</p>
\t<img src="inline.jpg" alt="Inline">
</article>';

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'img' ) ) {
\t// Don't clobber an explicit eager hint the author already set.
\tif ( null === $tags->get_attribute( 'loading' ) ) {
\t\t$tags->set_attribute( 'loading', 'lazy' );
\t}
\t$tags->set_attribute( 'decoding', 'async' );
}

echo $tags->get_updated_html();'''))),
        ('Rewrite relative links to absolute URLs',
            '<p>Use this before sending post content to an RSS feed, an email template, or a CDN-backed copy of a site. The processor rewrites only the changed bytes, so untouched markup stays byte-identical.</p>',
            ('absolute-links.php', php('''$html = '<p>See <a href="/about">about</a>, <a href="https://example.com/x">x</a>, '
\t. 'and <a href="contact.html">contact</a>.</p>';

$base = 'https://my-site.test/';

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'a' ) ) {
\t$href = $tags->get_attribute( 'href' );
\tif ( null === $href || '' === $href ) {
\t\tcontinue;
\t}
\tif ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) || 0 === strpos( $href, '//' ) || 0 === strpos( $href, '#' ) ) {
\t\tcontinue;
\t}
\t$tags->set_attribute( 'href', rtrim( $base, '/' ) . '/' . ltrim( $href, '/' ) );
}

echo $tags->get_updated_html();'''))),
        ('Strip every script and inline event handler',
            '<p>A common sanitization step: neutralize untrusted HTML before display. Blank a script\'s body with <code>set_modifiable_text()</code> and strip every <code>on*</code> attribute via <code>get_attribute_names_with_prefix()</code>.</p>',
            ('sanitize-html.php', php('''$untrusted = '<p>Hi <b onclick="steal()">friend</b>!</p>'
\t. '<script>alert("xss")</script>'
\t. '<img src=x onerror="boom()">';

$tags = new WP_HTML_Tag_Processor( $untrusted );
while ( $tags->next_tag() ) {
\tif ( 'SCRIPT' === $tags->get_tag() && ! $tags->is_tag_closer() ) {
\t\t$tags->set_modifiable_text( '' );
\t}
\t$on_handlers = $tags->get_attribute_names_with_prefix( 'on' );
\tif ( $on_handlers ) {
\t\tforeach ( $on_handlers as $name ) {
\t\t\t$tags->remove_attribute( $name );
\t\t}
\t}
}

echo $tags->get_updated_html();'''))),
        ('Stamp a CSP nonce on inline scripts and styles',
            '<p>Content Security Policy in <code>nonce-</code> mode requires every inline <code>&lt;script&gt;</code> and <code>&lt;style&gt;</code> to carry a matching nonce attribute. Tag-by-tag is exactly the right granularity.</p>',
            ('csp-nonce.php', php('''$nonce = bin2hex( random_bytes( 8 ) );

$html = '<head><style>body{font:16px sans-serif}</style></head>'
\t. '<body><script>console.log("hi")</script><script src="vendor.js"></script></body>';

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag() ) {
\t$tag = $tags->get_tag();
\tif ( ( 'SCRIPT' === $tag || 'STYLE' === $tag ) && ! $tags->is_tag_closer() ) {
\t\t$tags->set_attribute( 'nonce', $nonce );
\t}
}

echo "nonce: {$nonce}\\n\\n";
echo $tags->get_updated_html();'''))),
        ('Build a srcset from a single src',
            '<p>Generate responsive image markup at render time without touching the editor data model. Read the existing <code>src</code>, derive a <code>srcset</code> with width descriptors, add a <code>sizes</code> hint.</p>',
            ('srcset-rewrite.php', php('''$html = '<figure><img src="https://cdn.test/uploads/photo.jpg" alt="Sunset"></figure>';
$widths = array( 480, 768, 1200 );

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'img' ) ) {
\t$src = $tags->get_attribute( 'src' );
\tif ( null === $src || $tags->get_attribute( 'srcset' ) !== null ) {
\t\tcontinue;
\t}
\t$variants = array();
\tforeach ( $widths as $w ) {
\t\t$variants[] = $src . '?w=' . $w . ' ' . $w . 'w';
\t}
\t$tags->set_attribute( 'srcset', implode( ', ', $variants ) );
\t$tags->set_attribute( 'sizes', '(max-width: 768px) 100vw, 768px' );
}

echo $tags->get_updated_html();'''))),
        ('Decode HTML entities the way the spec demands',
            '<p>The HTML5 entity table has roughly 2,200 named references and a long list of edge cases. <code>WP_HTML_Decoder</code> implements the algorithm — don\'t roll your own.</p>',
            ('decode-entities.php', php('''echo "attribute: " . WP_HTML_Decoder::decode_attribute( 'path?a=1&amp;b=2&amp;copy' ) . "\\n";
echo "text:      " . WP_HTML_Decoder::decode_text_node( 'AT&amp;T &mdash; 100&percnt; &#x1F600;' ) . "\\n";

// Safe URL prefix check that respects encoded colons (a classic XSS vector).
$is_javascript = WP_HTML_Decoder::attribute_starts_with(
\t'java&#x09;script:alert(1)',
\t'javascript:',
\t'ascii-case-insensitive'
);
var_dump( $is_javascript );'''))),
        ('Find images by ancestry with breadcrumbs',
            '<p>The full <code>WP_HTML_Processor</code> understands HTML5 tree construction, so you can ask "find every <code>&lt;img&gt;</code> directly inside a <code>&lt;figure&gt;</code>" without writing your own DOM walker.</p>',
            ('breadcrumbs.php', php('''$html = '<article>'
\t. '<figure><img src="hero.jpg" alt="Hero"><figcaption>Hero shot</figcaption></figure>'
\t. '<p>Body copy <img src="emoji.png" alt=""> mid-paragraph.</p>'
\t. '<figure><img src="diagram.png" alt="Diagram"></figure>'
\t. '</article>';

$p = WP_HTML_Processor::create_fragment( $html );
$figure_images = 0;
while ( $p->next_tag( array( 'breadcrumbs' => array( 'FIGURE', 'IMG' ) ) ) ) {
\t$p->add_class( 'figure-image' );
\t$figure_images++;
}

echo "found {$figure_images} figure images\\n";
echo $p->get_updated_html();'''))),
        ('Outline a document by walking tokens with depth',
            '<p>The full processor exposes <code>get_current_depth()</code> and <code>get_breadcrumbs()</code>. Combine with <code>next_token()</code> to print a structural outline.</p>',
            ('outline.php', php('''$html = '<section><h1>Title</h1>'
\t. '<section><h2>Chapter 1</h2><p>Body</p></section>'
\t. '<section><h2>Chapter 2</h2><p>More body</p></section>'
\t. '</section>';

$p = WP_HTML_Processor::create_fragment( $html );
while ( $p->next_token() ) {
\tif ( '#tag' !== $p->get_token_type() || $p->is_tag_closer() ) {
\t\tcontinue;
\t}
\t$tag = $p->get_tag();
\tif ( ! preg_match( '/^H[1-6]$/', $tag ) ) {
\t\tcontinue;
\t}
\t$indent = str_repeat( '  ', max( 0, $p->get_current_depth() - 2 ) );
\t$text = '';
\twhile ( $p->next_token() ) {
\t\tif ( '#text' === $p->get_token_type() ) {
\t\t\t$text .= $p->get_modifiable_text();
\t\t\tcontinue;
\t\t}
\t\tif ( '#tag' === $p->get_token_type() && $tag === $p->get_tag() && $p->is_tag_closer() ) {
\t\t\tbreak;
\t\t}
\t}
\techo "{$indent}{$tag}  {$text}\\n";
}'''))),
        ('Bookmarks: annotate a parent based on its children',
            '<p>Bookmarks are the one escape from forward-only scanning. Save a position, scan ahead, decide what to do, then <code>seek()</code> back and rewrite the earlier tag.</p>',
            ('bookmarks.php', php('''$html = '<ul>'
\t. '<li><input type="checkbox" checked> Buy milk</li>'
\t. '<li><input type="checkbox"> Walk the dog</li>'
\t. '<li><input type="checkbox" checked> Read book</li>'
\t. '</ul>';

$tags = new WP_HTML_Tag_Processor( $html );
$tags->next_tag( 'ul' );
$tags->set_bookmark( 'list' );

$total = 0;
$done = 0;
while ( $tags->next_tag( 'input' ) ) {
\t$total++;
\tif ( null !== $tags->get_attribute( 'checked' ) ) {
\t\t$done++;
\t}
}

$tags->seek( 'list' );
$tags->set_attribute( 'data-progress', $done . '/' . $total );
$tags->release_bookmark( 'list' );

echo $tags->get_updated_html();'''))),
    ]))

# ===========================================================================
# Zip
# ===========================================================================
COMPONENTS.append(('zip', 'Zip',
    'Read and write ZIP archives in pure PHP — no <code>libzip</code>, no <code>ZipArchive</code>. Streams entries one at a time, so you can build EPUBs, .docx files, and multi-gigabyte plugin bundles without buffering the archive in memory.',
    'wp-php-toolkit/zip',
    [
        ('Why this exists',
            '<p>Common PHP ZIP workflows rely on the <code>ZipArchive</code> extension or shelling out to <code>zip</code>. Those are awkward in hosts without libzip, WebAssembly builds, and code paths that need to stream archive data through toolkit byte streams.</p>'
            '<p>The Zip component reads and writes Stored and Deflate archives in pure PHP. The decoder is pull-based, so listing the central directory of a 2 GB ZIP costs roughly the size of the directory itself. The encoder accepts any <code>ByteWriteStream</code> as a sink and writes one entry at a time.</p>',
            None),
        ('Read a file out of a ZIP',
            '<p><code>ZipFilesystem</code> implements this toolkit\'s <code>Filesystem</code> interface, so once you wrap the byte reader you can call <code>get_contents()</code>, <code>ls()</code>, and <code>is_dir()</code> just like the other filesystem backends.</p>'
            '<p><strong>Try this:</strong> after <em>Run</em>, add a second <code>append_file()</code> call before <code>$enc-&gt;close()</code> for a <code>notes.md</code> entry, then call <code>print_r( $zip-&gt;ls( \'/\' ) )</code> at the end. The directory listing reflects the new entry without re-reading the file.</p>',
            ('teaser-read.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;

$path = tempnam( sys_get_temp_dir(), 'demo' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
$enc->append_file( new FileEntry( array(
\t'path'               => 'readme.txt',
\t'compression_method' => ZipDecoder::COMPRESSION_NONE,
\t'body_reader'        => new MemoryPipe( 'Hello from inside the zip.' ),
) ) );
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
echo $zip->get_contents( 'readme.txt' );'''))),
        ('Build an EPUB from scratch',
            '<p>An EPUB follows one strict ZIP rule: write the <code>mimetype</code> entry first and store it without compression. Deflate the rest of the archive normally.</p>'
            '<p>Gotcha: e-readers reject EPUBs whose <code>mimetype</code> entry has compression. Use <code>COMPRESSION_NONE</code> for that single entry.</p>',
            ('epub.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;

$path = tempnam( sys_get_temp_dir(), 'book' ) . '.epub';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );

// 1) The mimetype entry MUST be first and stored uncompressed.
$enc->append_file( new FileEntry( array(
\t'path'               => 'mimetype',
\t'compression_method' => ZipDecoder::COMPRESSION_NONE,
\t'body_reader'        => new MemoryPipe( 'application/epub+zip' ),
) ) );

$container = '<?xml version="1.0"?>'
\t. '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
\t. '<rootfiles><rootfile full-path="EPUB/package.opf" media-type="application/oebps-package+xml"/></rootfiles>'
\t. '</container>';

foreach ( array(
\t'META-INF/container.xml' => $container,
\t'EPUB/package.opf'       => '<package version="3.0" xmlns="http://www.idpf.org/2007/opf"><metadata/><manifest/><spine/></package>',
\t'EPUB/chapter1.xhtml'    => '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 1</h1><p>It was a dark and stormy night.</p></body></html>',
) as $name => $body ) {
\t$enc->append_file( new FileEntry( array(
\t\t'path'               => $name,
\t\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t\t'body_reader'        => new MemoryPipe( $body ),
\t) ) );
}
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
printf( "mimetype: %s\\n", $zip->get_contents( 'mimetype' ) );
printf( "size on disk: %d bytes\\n", filesize( $path ) );'''))),
        ('Stream a large entry without buffering it',
            '<p>Calling <code>get_contents()</code> on a 500 MB CSV inside a ZIP would eat 500 MB of RAM. Use <code>open_read_stream()</code> instead and inflate-as-you-go.</p>'
            '<p>Gotcha: only one entry stream open at a time. Drain or finish the previous stream before opening the next.</p>',
            ('stream-large.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;

$path = tempnam( sys_get_temp_dir(), 'big' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
$enc->append_file( new FileEntry( array(
\t'path'               => 'data.csv',
\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t'body_reader'        => new MemoryPipe( str_repeat( "id,value,timestamp\\n1,foo,2024\\n2,bar,2024\\n", 5000 ) ),
) ) );
$enc->close();
$out->close_writing();

$zip    = ZipFilesystem::create( FileReadStream::from_path( $path ) );
$stream = $zip->open_read_stream( 'data.csv' );

$rows  = 0;
$bytes = 0;
$tail  = '';
while ( ! $stream->reached_end_of_data() ) {
\t$n = $stream->pull( 8192 );
\tif ( 0 === $n ) break;
\t$chunk  = $tail . $stream->consume( $n );
\t$lines  = explode( "\\n", $chunk );
\t$tail   = array_pop( $lines );
\t$rows  += count( $lines );
\t$bytes += $n;
}
printf( "Inflated %d bytes in 8 KB chunks, parsed %d rows.\\n", $bytes, $rows );'''))),
        ('Repack: modify one file, copy the rest',
            '<p>Updating one file in a ZIP without rewriting the others is impossible at the format level — the central directory points at byte offsets. The pragmatic answer is repack: stream the source archive into a new one, swapping the file you care about.</p>',
            ('repack.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;

$src_path = tempnam( sys_get_temp_dir(), 'orig' ) . '.zip';
$src_out  = FileWriteStream::from_path( $src_path, 'truncate' );
$src_enc  = new ZipEncoder( $src_out );
foreach ( array(
\t'config.json'   => '{"debug":false,"version":"1.0"}',
\t'app/index.php' => '<?php echo "hello";',
\t'app/style.css' => 'body{color:#333}',
) as $name => $body ) {
\t$src_enc->append_file( new FileEntry( array(
\t\t'path'               => $name,
\t\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t\t'body_reader'        => new MemoryPipe( $body ),
\t) ) );
}
$src_enc->close();
$src_out->close_writing();

$source   = ZipFilesystem::create( FileReadStream::from_path( $src_path ) );
$dst_path = tempnam( sys_get_temp_dir(), 'repacked' ) . '.zip';
$dst_out  = FileWriteStream::from_path( $dst_path, 'truncate' );
$dst_enc  = new ZipEncoder( $dst_out );

$dirs = array( '/' );
while ( $dirs ) {
\t$dir = array_shift( $dirs );
\tforeach ( $source->ls( $dir ) as $name ) {
\t\t$path = rtrim( $dir, '/' ) . '/' . $name;
\t\tif ( $source->is_dir( $path ) ) {
\t\t\t$dirs[] = $path;
\t\t\tcontinue;
\t\t}
\t\t$rel  = ltrim( $path, '/' );
\t\t$body = ( 'config.json' === $rel )
\t\t\t? '{"debug":true,"version":"1.0.1"}'
\t\t\t: $source->get_contents( $rel );
\t\t$dst_enc->append_file( new FileEntry( array(
\t\t\t'path'               => $rel,
\t\t\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t\t\t'body_reader'        => new MemoryPipe( $body ),
\t\t) ) );
\t}
}
$dst_enc->close();
$dst_out->close_writing();

$repacked = ZipFilesystem::create( FileReadStream::from_path( $dst_path ) );
echo "new config.json: " . $repacked->get_contents( 'config.json' ) . "\\n";
echo "untouched: " . $repacked->get_contents( 'app/index.php' ) . "\\n";'''))),
        ('Defend against zip-slip',
            '<p>A malicious archive can name an entry <code>../../etc/passwd</code> and trick a naive extractor into clobbering files outside the destination. <code>ZipDecoder::sanitize_path()</code> strips leading <code>../</code> segments and collapses internal <code>/../</code> sequences before exposing the path.</p>',
            ('zip-slip.php', php('''use WordPress\\Zip\\ZipDecoder;

$evil_inputs = array(
\t'../../etc/passwd',
\t'./safe/path.txt',
\t'a/../../b/secret',
\t'a//b///c.txt',
\t'../../../../root/.ssh/authorized_keys',
);
foreach ( $evil_inputs as $name ) {
\tprintf( "%-45s => %s\\n", $name, ZipDecoder::sanitize_path( $name ) );
}'''))),
        ('Pipe ZIP entries into an InMemoryFilesystem',
            '<p>Real-world recipe: take an uploaded plugin ZIP, expand it into an <code>InMemoryFilesystem</code> so you can validate, edit, or scan it before it ever touches disk. Three components compose into something you couldn\'t build with <code>ZipArchive</code> alone.</p>',
            ('zip-to-memfs.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;
use function WordPress\\Filesystem\\copy_between_filesystems;

$path = tempnam( sys_get_temp_dir(), 'app' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
foreach ( array(
\t'app/index.php'        => '<?php echo "ok";',
\t'app/lib/util.php'     => '<?php // util',
\t'app/assets/style.css' => 'body{margin:0}',
\t'app/README.md'        => '# App',
) as $name => $body ) {
\t$enc->append_file( new FileEntry( array(
\t\t'path'               => $name,
\t\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t\t'body_reader'        => new MemoryPipe( $body ),
\t) ) );
}
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
$mem = InMemoryFilesystem::create();
copy_between_filesystems( array(
\t'source_filesystem' => $zip,
\t'source_path'       => '/',
\t'target_filesystem' => $mem,
\t'target_path'       => '/',
) );

$mem->put_contents( '/app/VERSION', '1.0.0' );
echo "files now in memory:\\n";
$dirs = array( '/' );
$files = array();
while ( $dirs ) {
\t$dir = array_shift( $dirs );
\tforeach ( $mem->ls( $dir ) as $name ) {
\t\t$p = rtrim( $dir, '/' ) . '/' . $name;
\t\tif ( $mem->is_dir( $p ) ) {
\t\t\t$dirs[] = $p;
\t\t\tcontinue;
\t\t}
\t\t$files[] = $p;
\t}
}
sort( $files );
foreach ( $files as $path ) {
\techo "  " . $path . "\\n";
}'''))),
    ]))

# ===========================================================================
# ByteStream
# ===========================================================================
COMPONENTS.append(('bytestream', 'ByteStream',
    'Composable streaming primitives for reading, writing, transforming, hashing, and compressing byte data. Pull/peek/consume semantics let parsers backtrack without copying, and deflate, inflate, and checksum filters snap together like Lego.',
    'wp-php-toolkit/bytestream',
    [
        ('Why this exists',
            '<p>PHP\'s native streams are powerful but inconsistent. <code>fread</code> on a socket may return short reads with no warning; <code>stream_filter_append</code> is awkward to compose; gzip helpers and file handles expose different APIs. The ByteStream component normalizes these behind one small interface — <code>pull / peek / consume</code> — so a parser, a hash function, and a deflate filter all see the same shape.</p>'
            '<p>The split between <em>pull</em> (buffer up to N bytes) and <em>consume</em> (advance past N bytes) is the secret. Parsers can <code>peek</code> ahead to detect a record boundary and decide whether to <code>consume</code>, without copying or allocating.</p>',
            None),
        ('Read a file in chunks',
            '<p>The canonical loop. <code>pull(N)</code> reads up to <code>N</code> bytes from the underlying source into an internal buffer and returns how many ended up there; <code>consume(N)</code> reads <code>N</code> bytes from that buffer and advances past them. The buffer never grows beyond the chunk size you ask for.</p>',
            ('teaser-read.php', php('''use WordPress\\ByteStream\\ReadStream\\FileReadStream;

$path = tempnam( sys_get_temp_dir(), 'demo' );
file_put_contents( $path, str_repeat( "log line\\n", 200 ) );

$reader = FileReadStream::from_path( $path );
$total = 0;
while ( ! $reader->reached_end_of_data() ) {
\t$n = $reader->pull( 256 );
\tif ( 0 === $n ) break;
\t$total += strlen( $reader->consume( $n ) );
}
$reader->close_reading();
echo "Read {$total} bytes in 256-byte chunks.\\n";'''))),
        ('MemoryPipe as write-then-read buffer',
            '<p><code>MemoryPipe</code> is bidirectional: you <code>append_bytes()</code> as a writer and <code>pull/consume</code> as a reader. Easiest way to wire one component\'s output into another\'s input.</p>'
            '<p>Gotcha: a producer must call <code>close_writing()</code> when done — otherwise the consumer eventually throws <code>NotEnoughDataException</code> instead of seeing EOF.</p>',
            ('memory-pipe.php', php('''use WordPress\\ByteStream\\MemoryPipe;

$pipe = new MemoryPipe();
$pipe->append_bytes( "first chunk\\n" );
$pipe->append_bytes( "second chunk\\n" );
$pipe->append_bytes( "third chunk\\n" );
$pipe->close_writing();

while ( ! $pipe->reached_end_of_data() ) {
\t$n = $pipe->pull( 1024 );
\tif ( 0 === $n ) break;
\techo "got: " . $pipe->consume( $n );
}'''))),
        ('Compress on the way in, decompress on the way out',
            '<p>Wrap a stream in <code>DeflateReadStream</code> to get compressed bytes out; wrap it in <code>InflateReadStream</code> to get decompressed bytes out. Both are full <code>ByteReadStream</code> implementations, so they nest into anything else that takes a stream.</p>',
            ('deflate-roundtrip.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\DeflateReadStream;
use WordPress\\ByteStream\\ReadStream\\InflateReadStream;

$original = str_repeat( "the quick brown fox. ", 50 );

$src        = new MemoryPipe( $original );
$src->close_writing();
$deflated   = new DeflateReadStream( $src, ZLIB_ENCODING_DEFLATE );
$compressed = $deflated->consume_all();

$src2     = new MemoryPipe( $compressed );
$src2->close_writing();
$inflated = new InflateReadStream( $src2, ZLIB_ENCODING_DEFLATE );
$round    = $inflated->consume_all();

printf( "original  : %d bytes\\n", strlen( $original ) );
printf( "deflated  : %d bytes (%.1f%%)\\n", strlen( $compressed ), 100 * strlen( $compressed ) / strlen( $original ) );
printf( "round-trip: %s\\n", $round === $original ? 'OK' : 'BROKEN' );'''))),
        ('Line-by-line reads from a chunked source',
            '<p>Reading text by line means handling chunk boundaries that fall mid-line. Keep the trailing partial line and prepend it to the next pull. The rest of the loop pretends the data was always whole.</p>',
            ('lines.php', php('''use WordPress\\ByteStream\\MemoryPipe;

$pipe = new MemoryPipe();
$pipe->append_bytes( "alpha\\nbravo\\ncharl" );
$pipe->append_bytes( "ie\\ndelta\\necho\\n" );
$pipe->close_writing();

$tail = '';
$count = 0;
while ( ! $pipe->reached_end_of_data() ) {
\t$n = $pipe->pull( 8 );
\tif ( 0 === $n ) break;
\t$buf   = $tail . $pipe->consume( $n );
\t$lines = explode( "\\n", $buf );
\t$tail  = array_pop( $lines );
\tforeach ( $lines as $line ) {
\t\tprintf( "[%d] %s\\n", ++$count, $line );
\t}
}
if ( '' !== $tail ) {
\tprintf( "[%d] %s\\n", ++$count, $tail );
}'''))),
        ('Limit a stream to a fixed window',
            '<p><code>LimitedByteReadStream</code> exposes only the next N bytes of an underlying stream as if those were the entire stream. This is how the ZIP decoder hands you the body of one entry without letting you read into the next.</p>',
            ('limited.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\LimitedByteReadStream;

$source = new MemoryPipe( "HEADER:42|BODY:hello there|FOOTER:done" );
$source->close_writing();

$source->pull( 10 );
$source->consume( 10 );

$body = new LimitedByteReadStream( $source, 16 );
echo "body sees: " . $body->consume_all() . "\\n";
echo "remaining in source: " . $source->consume_all() . "\\n";'''))),
    ]))

# ===========================================================================
# Filesystem
# ===========================================================================
COMPONENTS.append(('filesystem', 'Filesystem',
    'One <code>Filesystem</code> interface across local disk, in-memory trees, SQLite databases, and ZIP archives. Forward-slash paths everywhere — even on Windows — so the same code runs in tests, in production, and inside read-only ZIPs.',
    'wp-php-toolkit/filesystem',
    [
        ('Why this exists',
            '<p>Code that touches the filesystem is hard to test, hard to port to Windows, and impossible to point at non-disk storage without rewriting it. Swap <code>LocalFilesystem</code> for <code>InMemoryFilesystem</code> in tests and your suite stops touching <code>/tmp</code>; swap it for <code>SQLiteFilesystem</code> and your "files" become rows in a portable database; swap it for <code>ZipFilesystem</code> and you can read inside an archive with the same calls.</p>'
            '<p>Every backend uses forward slashes regardless of host OS. No <code>DIRECTORY_SEPARATOR</code> juggling, no Windows-only test failures, no surprises when a path moves between backends.</p>',
            None),
        ('In-memory tree',
            '<p>The fastest backend. No disk I/O, no cleanup, no test-isolation problems.</p>',
            ('teaser-memory.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;

$fs = InMemoryFilesystem::create();
$fs->put_contents( '/hello.txt', 'Hello, world!' );
echo $fs->get_contents( '/hello.txt' );'''))),
        ('Test code without touching disk',
            '<p>Code that takes a <code>Filesystem</code> parameter, instead of calling <code>file_get_contents()</code> directly, can be tested against an <code>InMemoryFilesystem</code>. The test sets up files in memory, exercises the function, and asserts on what got written — no temp directories, no cleanup.</p>',
            ('test-without-disk.php', php('''use WordPress\\Filesystem\\Filesystem;
use WordPress\\Filesystem\\InMemoryFilesystem;

function bump_version( Filesystem $fs, $path ) {
\t$json = json_decode( $fs->get_contents( $path ), true );
\tlist( $maj, $min, $patch ) = explode( '.', $json['version'] );
\t$json['version'] = $maj . '.' . $min . '.' . ( (int) $patch + 1 );
\t$fs->put_contents( $path, json_encode( $json ) );
}

$fs = InMemoryFilesystem::create();
$fs->put_contents( '/package.json', '{"version":"1.2.3"}' );
bump_version( $fs, '/package.json' );

echo $fs->get_contents( '/package.json' ) . "\\n";'''))),
        ('Local disk with a chrooted root',
            '<p><code>LocalFilesystem::create($root)</code> is implicitly chrooted: every path resolves relative to <code>$root</code> and a <code>../</code> cannot escape. Reach for it when a request path or CLI argument names a file inside one project directory.</p>',
            ('local-chroot.php', php('''use WordPress\\Filesystem\\LocalFilesystem;

$root = sys_get_temp_dir() . '/toolkit-' . uniqid();
$fs   = LocalFilesystem::create( $root );

$fs->mkdir( '/uploads', array( 'recursive' => true ) );
$fs->put_contents( '/uploads/note.txt', 'Hi from local disk.' );

echo $fs->get_contents( '/uploads/../uploads/note.txt' ) . "\\n";

$fs->rmdir( '/', array( 'recursive' => true ) );
echo "exists after cleanup? " . ( is_dir( $root ) ? 'yes' : 'no' ) . "\\n";'''))),
        ('SQLite as a portable file store',
            '<p>The whole tree lives in one SQLite database file. Use it for self-contained scratch storage that survives process boundaries without leaving loose files behind.</p>',
            ('sqlite.php', php('''use WordPress\\Filesystem\\SQLiteFilesystem;

$fs = SQLiteFilesystem::create( ':memory:' );
$fs->mkdir( '/posts', array( 'recursive' => true ) );
for ( $i = 1; $i <= 3; $i++ ) {
\t$fs->put_contents( "/posts/post-{$i}.md", "# Post {$i}\\n\\nBody {$i}." );
}

foreach ( $fs->ls( '/posts' ) as $name ) {
\t$first = strtok( $fs->get_contents( '/posts/' . $name ), "\\n" );
\techo "{$name}: {$first}\\n";
}'''))),
        ('Copy a tree across backends',
            '<p>The killer composability move: <code>copy_between_filesystems()</code> streams files chunk-by-chunk from any source to any target. Pull a ZIP into SQLite, snapshot SQLite to disk, mirror disk into RAM — all the same call.</p>',
            ('cross-backend-copy.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Filesystem\\LocalFilesystem;
use WordPress\\Filesystem\\SQLiteFilesystem;
use function WordPress\\Filesystem\\copy_between_filesystems;

$root  = sys_get_temp_dir() . '/copytree-' . uniqid();
$local = LocalFilesystem::create( $root );
$local->mkdir( '/site/posts', array( 'recursive' => true ) );
$local->put_contents( '/site/posts/2024-01.md', '# Hello 2024' );
$local->put_contents( '/site/index.html', '<h1>Home</h1>' );

$sqlite = SQLiteFilesystem::create( ':memory:' );
copy_between_filesystems( array(
\t'source_filesystem' => $local,
\t'source_path'       => '/site',
\t'target_filesystem' => $sqlite,
\t'target_path'       => '/snapshot',
) );

$mem = InMemoryFilesystem::create();
copy_between_filesystems( array(
\t'source_filesystem' => $sqlite,
\t'source_path'       => '/snapshot',
\t'target_filesystem' => $mem,
\t'target_path'       => '/copy',
) );

echo "in memory after two copies:\\n";
echo "  posts: " . implode( ', ', $mem->ls( '/copy/posts' ) ) . "\\n";
echo "  index: " . $mem->get_contents( '/copy/index.html' ) . "\\n";

$local->rmdir( '/', array( 'recursive' => true ) );'''))),
        ('Atomic write via tempfile rename',
            '<p>Write to a sibling tempfile, then rename — that\'s how you avoid leaving a half-written file on crash. <code>rename()</code> is atomic within a single filesystem.</p>',
            ('atomic-write.php', php('''use WordPress\\Filesystem\\Filesystem;
use WordPress\\Filesystem\\LocalFilesystem;

function atomic_put_contents( Filesystem $fs, $path, $bytes ) {
\t$tmp = $path . '.tmp.' . bin2hex( random_bytes( 4 ) );
\t$fs->put_contents( $tmp, $bytes );
\t$fs->rename( $tmp, $path );
}

$root = sys_get_temp_dir() . '/atomic-' . uniqid();
$fs   = LocalFilesystem::create( $root );

$fs->put_contents( '/config.json', '{"v":1}' );
atomic_put_contents( $fs, '/config.json', '{"v":2}' );

echo "config: " . $fs->get_contents( '/config.json' ) . "\\n";
echo "no .tmp leftovers: " . count( $fs->ls( '/' ) ) . " entries in root\\n";

$fs->rmdir( '/', array( 'recursive' => true ) );'''))),
        ('Path helpers that behave the same on Windows',
            '<p>Unix path semantics apply on every host OS. This matters for abstract paths such as a SQLite key or a ZIP entry name because those paths do not live on a real drive.</p>',
            ('path-helpers.php', php('''use function WordPress\\Filesystem\\wp_join_unix_paths;
use function WordPress\\Filesystem\\wp_unix_dirname;
use function WordPress\\Filesystem\\wp_unix_path_resolve_dots;

echo wp_join_unix_paths( '/var/www', '/site/', '/index.php' ) . "\\n";
echo wp_unix_dirname( '/a/b/c/d.txt', 2 ) . "\\n";
echo wp_unix_path_resolve_dots( '/a/b/../c/./d/../e' ) . "\\n";'''))),
    ]))

# ===========================================================================
# BlockParser
# ===========================================================================
COMPONENTS.append(('blockparser', 'BlockParser',
    'WordPress core\'s block parser, packaged as a standalone library. Turn block markup into a structured tree, lint posts for common authoring mistakes, and audit block usage — all without booting WordPress.',
    'wp-php-toolkit/blockparser',
    [
        ('Why this exists',
            '<p>Block markup is not plain HTML. A post can contain HTML comments that identify blocks, JSON attributes inside those comments, freeform HTML between blocks, and nested blocks whose rendered HTML is interleaved with parent markup.</p>'
            '<p>This component packages WordPress core\'s block parser so importers, linters, migration tools, and static analyzers can understand block content without loading WordPress. It deliberately mirrors core behavior — same array shape, same <code>null</code> blocks for freeform HTML, same core block names such as <code>core/paragraph</code> — so code written against this parser keeps working when run inside WordPress, and vice versa.</p>'
            '<p>Reach for it when you need answers about the block tree: which blocks a post uses, which attributes they carry, where nested blocks appear, or whether content violates a rule your project cares about.</p>',
            None),
        ('What you get back',
            '<p><code>WP_Block_Parser::parse()</code> returns an array of blocks. Each block is an associative array with five keys: <code>blockName</code>, <code>attrs</code>, <code>innerBlocks</code>, <code>innerHTML</code>, and <code>innerContent</code>.</p>'
            '<p><code>innerHTML</code> is the HTML inside the block <em>with inner blocks stripped out</em>. <code>innerContent</code> is the interleaved version: an array of HTML strings with <code>null</code> placeholders marking where each inner block belongs.</p>'
            '<p>Most code starts by checking <code>blockName</code>, then reading <code>attrs</code> or <code>innerHTML</code>. When a post has container blocks such as Group, Columns, or Navigation, look inside <code>innerBlocks</code> too.</p>'
            '<p><strong>Footgun:</strong> freeform HTML between blocks shows up as a block with <code>blockName === null</code>. Always skip that case before comparing names.</p>',
            None),
        ('Parse a document',
            '<p>The simplest possible use. Pass a string, get back a tree.</p>',
            ('parse.php', php('''$document = "<!-- wp:heading {\\"level\\":2} -->\\n<h2>Welcome</h2>\\n<!-- /wp:heading -->\\n\\n"
\t. "<!-- wp:paragraph -->\\n<p>Hello from the block editor.</p>\\n<!-- /wp:paragraph -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );
foreach ( $blocks as $block ) {
\tif ( null === $block['blockName'] ) {
\t\tcontinue;
\t}
\techo $block['blockName'] . ': ' . trim( strip_tags( $block['innerHTML'] ) ) . "\\n";
}'''))),
        ('Count every block type in a post',
            '<p>A common audit task: "How many Paragraph, Image, and Gallery blocks does this post use?" A small queue keeps the example readable while still visiting nested blocks.</p>',
            ('count-blocks.php', php('''$document = "<!-- wp:group --><div class=\\"wp-block-group\\">"
\t. "<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->"
\t. "<!-- wp:paragraph --><p>One.</p><!-- /wp:paragraph -->"
\t. "<!-- wp:paragraph --><p>Two.</p><!-- /wp:paragraph -->"
\t. "<!-- wp:image {\\"id\\":1} --><figure><img src=\\"a.jpg\\"/></figure><!-- /wp:image -->"
\t. "</div><!-- /wp:group -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

$counts = array();
$queue  = $blocks;

while ( ! empty( $queue ) ) {
\t$block = array_shift( $queue );

\tif ( null !== $block['blockName'] ) {
\t\t$name             = $block['blockName'];
\t\t$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
\t}

\tforeach ( $block['innerBlocks'] as $inner_block ) {
\t\t$queue[] = $inner_block;
\t}
}

arsort( $counts );
foreach ( $counts as $name => $n ) {
\techo str_pad( (string) $n, 4, ' ', STR_PAD_LEFT ) . '  ' . $name . "\\n";
}'''))),
        ('Check whether a post uses a block',
            '<p>Useful for templates, audits, and migrations: answer one yes/no question without caring where the block appears in the tree.</p>',
            ('has-block.php', php('''$document = "<!-- wp:group --><div class=\\"wp-block-group\\">"
\t. "<!-- wp:buttons --><div class=\\"wp-block-buttons\\">"
\t. "<!-- wp:button --><div class=\\"wp-block-button\\"><a>Buy now</a></div><!-- /wp:button -->"
\t. "</div><!-- /wp:buttons -->"
\t. "</div><!-- /wp:group -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

function post_has_block( $blocks, $name ) {
\t$queue = $blocks;

\twhile ( ! empty( $queue ) ) {
\t\t$block = array_shift( $queue );
\t\tif ( $name === $block['blockName'] ) {
\t\t\treturn true;
\t\t}

\t\tforeach ( $block['innerBlocks'] as $inner_block ) {
\t\t\t$queue[] = $inner_block;
\t\t}
\t}

\treturn false;
}

echo post_has_block( $blocks, 'core/button' ) ? "has button\\n" : "missing button\\n";
echo post_has_block( $blocks, 'core/gallery' ) ? "has gallery\\n" : "missing gallery\\n";'''))),
        ('Lint headings for hierarchy mistakes',
            '<p>"Don\'t skip from H2 to H4" is a real accessibility rule. The helper below keeps headings in document order, including headings nested inside Group, Column, and Cover blocks.</p>',
            ('lint-headings.php', php('''$document = "<!-- wp:heading -->\\n<h2>Intro</h2>\\n<!-- /wp:heading -->"
\t. "<!-- wp:heading {\\"level\\":4} -->\\n<h4>Subsection</h4>\\n<!-- /wp:heading -->"
\t. "<!-- wp:heading {\\"level\\":3} -->\\n<h3>Body</h3>\\n<!-- /wp:heading -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

function collect_headings( $blocks, &$headings ) {
\tforeach ( $blocks as $block ) {
\t\tif ( 'core/heading' === $block['blockName'] ) {
\t\t\t$headings[] = array(
\t\t\t\t'level' => isset( $block['attrs']['level'] ) ? (int) $block['attrs']['level'] : 2,
\t\t\t\t'text'  => trim( strip_tags( $block['innerHTML'] ) ),
\t\t\t);
\t\t}

\t\tcollect_headings( $block['innerBlocks'], $headings );
\t}
}

$headings = array();
collect_headings( $blocks, $headings );

$last = 1;
foreach ( $headings as $heading ) {
\t$level = $heading['level'];
\t$label = $heading['text'];

\tif ( $level > $last + 1 ) {
\t\techo "WARN {$label}: jumped from H{$last} to H{$level}\\n";
\t} else {
\t\techo "ok   {$label}: H{$level}\\n";
\t}
\t$last = $level;
}'''))),
        ('Find all instances of a custom block',
            '<p>When auditing an export for a block your plugin owns, collect every match and print the fields a human cares about.</p>',
            ('find-custom-block.php', php('''$document = "<!-- wp:paragraph --><p>Reviews</p><!-- /wp:paragraph -->"
\t. "<!-- wp:my-plugin/testimonial {\\"author\\":\\"Jane\\",\\"rating\\":5} -->"
\t. "<blockquote>Loved it.</blockquote>"
\t. "<!-- /wp:my-plugin/testimonial -->"
\t. "<!-- wp:my-plugin/testimonial {\\"author\\":\\"Joe\\",\\"rating\\":4} -->"
\t. "<blockquote>Pretty good.</blockquote>"
\t. "<!-- /wp:my-plugin/testimonial -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

function find_blocks_by_name( $blocks, $name, &$matches ) {
\tforeach ( $blocks as $block ) {
\t\tif ( $name === $block['blockName'] ) {
\t\t\t$matches[] = $block;
\t\t}

\t\tfind_blocks_by_name( $block['innerBlocks'], $name, $matches );
\t}
}

$testimonials = array();
find_blocks_by_name( $blocks, 'my-plugin/testimonial', $testimonials );

foreach ( $testimonials as $i => $b ) {
\techo ( $i + 1 ) . '. ' . $b['attrs']['author'] . ' (' . $b['attrs']['rating'] . '/5): '
\t\t. trim( strip_tags( $b['innerHTML'] ) ) . "\\n";
}'''))),
        ('Detect blocks with stale embed URLs',
            '<p>A real-world content audit: find every <code>core/embed</code> whose URL points at a domain you have retired.</p>',
            ('audit-embeds.php', php('''$document = '<!-- wp:embed {"url":"https://twitter.com/wordpress/status/1","providerNameSlug":"twitter"} /-->'
\t. '<!-- wp:embed {"url":"https://youtube.com/watch?v=abc","providerNameSlug":"youtube"} /-->'
\t. '<!-- wp:embed {"url":"https://vine.co/v/xyz","providerNameSlug":"vine"} /-->';

$retired = array( 'vine.co', 'plus.google.com' );

foreach ( ( new WP_Block_Parser() )->parse( $document ) as $b ) {
\tif ( 'core/embed' !== $b['blockName'] ) {
\t\tcontinue;
\t}
\t$url  = isset( $b['attrs']['url'] ) ? $b['attrs']['url'] : '';
\t$host = parse_url( $url, PHP_URL_HOST );
\t$bad  = $host && in_array( $host, $retired, true );
\techo ( $bad ? 'STALE  ' : 'ok     ' ) . $url . "\\n";
}'''))),
    ]))

# ===========================================================================
# Markdown
# ===========================================================================
COMPONENTS.append(('markdown', 'Markdown',
    'Bidirectional converter between Markdown and WordPress block markup. Useful for moving content between Markdown files and WordPress while preserving the structures both formats can express.',
    'wp-php-toolkit/markdown',
    [
        ('Why this exists',
            '<p>Many publishing workflows start in Markdown: documentation sites, static-site generators, Git-backed editorial workflows, Obsidian vaults, and developer notes. WordPress stores editor content as block markup. Moving between those worlds by string replacement loses metadata and quickly breaks on lists, tables, code blocks, and frontmatter.</p>'
            '<p>The Markdown component provides a structured bridge. <code>MarkdownConsumer</code> turns Markdown plus frontmatter into block markup and metadata; <code>MarkdownProducer</code> turns supported block markup back into Markdown. The conversion is meant for practical content workflows, not byte-identical round-tripping of every custom block attribute.</p>',
            None),
        ('Markdown to blocks',
            '<p>Feed Markdown into <code>MarkdownConsumer</code>, get block markup back. The result is a <code>BlocksWithMetadata</code> object that holds both the rendered blocks and any frontmatter parsed from the document.</p>',
            ('quickstart.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

$result = ( new MarkdownConsumer( "# Hello\\n\\nWelcome to **WordPress**." ) )->consume();
echo $result->get_block_markup();'''))),
        ('Round-trip: blocks back to Markdown',
            '<p>Pair <code>MarkdownProducer</code> with <code>MarkdownConsumer</code> to convert in either direction. Round-tripping is lossy for block attributes that have no Markdown representation (custom classes, alignment), so do not expect byte-perfect equality.</p>',
            ('roundtrip.php', php('''use WordPress\\Markdown\\MarkdownConsumer;
use WordPress\\Markdown\\MarkdownProducer;

$md       = "## Round trip\\n\\n- one\\n- two\\n- three\\n";
$blocks   = ( new MarkdownConsumer( $md ) )->consume();
$markdown = ( new MarkdownProducer( $blocks ) )->produce();

echo $markdown;'''))),
        ('Reading YAML frontmatter as post meta',
            '<p>Frontmatter keys come back as arrays so a single key can hold multiple values. Use <code>get_meta_value()</code> when you only want the first scalar.</p>',
            ('frontmatter.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

$md = <<<MD
---
post_title: "The Name of the Wind"
post_status: publish
tags: [fantasy, kingkiller]
---

Once upon a time...
MD;

$consumer = new MarkdownConsumer( $md );
$consumer->consume();

echo 'Title: '   . $consumer->get_meta_value( 'post_title' )  . "\\n";
echo 'Status: '  . $consumer->get_meta_value( 'post_status' ) . "\\n";
$metadata = $consumer->get_all_metadata();
echo 'Tags: ' . implode( ', ', $metadata['tags'][0] ) . "\\n";'''))),
        ('Migrating an Obsidian or Hugo folder of Markdown',
            '<p>Walk a directory of <code>.md</code> files (Obsidian vault, Hugo <code>content/</code>, Jekyll <code>_posts</code>) and emit one block-markup record per file.</p>',
            ('migrate-folder.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

@mkdir( '/tmp/vault', 0777, true );
file_put_contents( '/tmp/vault/welcome.md', "---\\ntitle: Welcome\\n---\\n\\nHello world." );
file_put_contents( '/tmp/vault/roadmap.md', "# Roadmap\\n\\n1. Ship\\n2. Iterate" );

foreach ( glob( '/tmp/vault/*.md' ) as $path ) {
\t$consumer = new MarkdownConsumer( file_get_contents( $path ) );
\t$consumer->consume();
\t$title = $consumer->get_meta_value( 'title' );
\tif ( ! $title ) $title = basename( $path, '.md' );
\techo "=== $title ($path) ===\\n";
\techo substr( $consumer->get_block_markup(), 0, 120 ) . "...\\n\\n";
}'''))),
        ('Counting blocks produced by a Markdown document',
            '<p>After conversion, the block markup is plain WordPress block markup, so <code>parse_blocks()</code> works on it directly. The standard way to introspect what the converter emitted before saving to the database.</p>',
            ('count-blocks.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

$md = <<<MD
# Title

A paragraph with **bold** and *italics*.

| Col A | Col B |
|-------|-------|
| 1     | 2     |

```php
echo 'hi';
```

> A quote.
MD;

$blocks = ( new MarkdownConsumer( $md ) )->consume()->get_block_markup();
$counts = array();
$queue  = parse_blocks( $blocks );

while ( $queue ) {
\t$block = array_shift( $queue );
\tif ( null !== $block['blockName'] ) {
\t\t$name             = $block['blockName'];
\t\t$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
\t}
\tforeach ( $block['innerBlocks'] as $inner_block ) {
\t\t$queue[] = $inner_block;
\t}
}
foreach ( $counts as $name => $count ) {
\techo "{$name}: {$count}\\n";
}'''))),
    ]))

# ===========================================================================
# XML
# ===========================================================================
COMPONENTS.append(('xml', 'XML',
    'A streaming, namespace-aware XML processor in pure PHP. Read and modify huge feeds, WXR exports, ePub manifests, and Office Open XML parts without ever loading the document into memory and without depending on <code>libxml2</code>.',
    'wp-php-toolkit/xml',
    [
        ('Why this exists',
            '<p><code>SimpleXMLElement</code> and <code>DOMDocument</code> both need <code>libxml2</code> and both build a complete in-memory tree. <code>XMLProcessor</code> walks the document forward as a cursor, keeps modifications in a side buffer, and emits the full updated XML with <code>get_updated_xml()</code> only when you ask for it.</p>'
            '<p>This design came from WordPress-scale documents such as WXR exports. A migration may only need to rewrite <code>wp:attachment_url</code> values or bump a feed attribute, so the processor optimizes for targeted cursor edits instead of a full validating XML stack.</p>'
            '<p><strong>Footgun #1:</strong> namespace-aware methods use the namespace name declared in <code>xmlns</code>, not the prefix written in the tag. In WXR, <code>get_attribute( \'wp\', \'status\' )</code> looks for a namespace literally named <code>wp</code>; for the usual WXR declaration you want <code>get_attribute( \'http://wordpress.org/export/1.2/\', \'status\' )</code>.</p>'
            '<p><strong>Footgun #2:</strong> in streaming mode <code>next_tag()</code> can return false because input ran out, not because the document ended. Check <code>is_paused_at_incomplete_input()</code> before assuming you\'re done.</p>',
            None),
        ('Bump every price in a catalog',
            '<p>Find each <code>&lt;book&gt;</code>, read its price, write a new one, emit the updated document.</p>',
            ('bump-prices.php', php('''use WordPress\\XML\\XMLProcessor;

$xml = '<catalog>'
\t. '<book sku="A1" price="29.99"><title>PHP Internals</title></book>'
\t. '<book sku="A2" price="14.50"><title>WordPress at Scale</title></book>'
\t. '</catalog>';

$p = XMLProcessor::create_from_string( $xml );
while ( $p->next_tag( 'book' ) ) {
\t$old = (float) $p->get_attribute( '', 'price' );
\t$new = number_format( $old * 1.10, 2, '.', '' );
\t$p->set_attribute( '', 'price', $new );
}

echo $p->get_updated_xml();'''))),
        ('Read namespaced attributes from a WXR export',
            '<p>WordPress\'s WXR commonly uses <code>wp:</code>, <code>dc:</code>, and <code>content:</code> prefixes bound to namespace names such as <code>http://wordpress.org/export/1.2/</code>. Pass that expanded namespace name, not the prefix; the processor handles whichever prefix the document actually uses.</p>',
            ('wxr-namespaces.php', php('''use WordPress\\XML\\XMLProcessor;

$wxr = '<?xml version="1.0"?>'
\t. '<rss xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
\t. '<channel><item>'
\t. '<title>Hello World</title>'
\t. '<dc:creator>admin</dc:creator>'
\t. '<wp:post_id>42</wp:post_id>'
\t. '<wp:status>publish</wp:status>'
\t. '</item></channel></rss>';

$WP = 'http://wordpress.org/export/1.2/';
$DC = 'http://purl.org/dc/elements/1.1/';

$p = XMLProcessor::create_from_string( $wxr );
while ( $p->next_tag( 'item' ) ) {
\twhile ( $p->next_token() ) {
\t\tif ( $p->is_tag_closer() && 'item' === $p->get_tag_local_name() ) break;
\t\tif ( ! $p->is_tag_opener() ) continue;
\t\t$ns = $p->get_tag_namespace();
\t\t$local = $p->get_tag_local_name();
\t\t$prefix = ( $WP === $ns ) ? 'wp/' : ( ( $DC === $ns ) ? 'dc/' : '' );
\t\techo "{$prefix}{$local}: ";
\t\twhile ( $p->next_token() && '#text' !== $p->get_token_name() ) {}
\t\techo trim( $p->get_modifiable_text() ) . "\\n";
\t}
}'''))),
        ('Rewrite URLs across an entire WXR export',
            '<p>Large WXR exports can hold many URLs in <code>&lt;link&gt;</code>, <code>&lt;guid&gt;</code>, and post content. Streaming the file lets you rewrite large exports without loading the whole XML document into memory.</p>',
            ('rewrite-wxr-urls.php', php('''use WordPress\\XML\\XMLProcessor;

$wxr = '<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
\t. '<wp:base_site_url>https://old.example.com</wp:base_site_url>'
\t. '<item><link>https://old.example.com/2024/post-1</link>'
\t. '<guid>https://old.example.com/?p=1</guid></item>'
\t. '</channel></rss>';

$from = 'https://old.example.com';
$to   = 'https://new.example.com';

$p = XMLProcessor::create_from_string( $wxr );
$rewritten = 0;

while ( $p->next_token() ) {
\tif ( '#text' !== $p->get_token_name() ) continue;
\t$text = $p->get_modifiable_text();
\tif ( false === strpos( $text, $from ) ) continue;
\t$p->set_modifiable_text( str_replace( $from, $to, $text ) );
\t$rewritten++;
}

echo "rewrote {$rewritten} text nodes\\n\\n";
echo $p->get_updated_xml();'''))),
        ('Parse OPML to extract feed URLs',
            '<p>OPML is the format Feedly and many readers use to import/export feed lists. Flat, attribute-heavy XML — exactly what a tag processor handles best.</p>',
            ('opml.php', php('''use WordPress\\XML\\XMLProcessor;

$opml = '<?xml version="1.0"?><opml version="2.0"><head><title>My Feeds</title></head>'
\t. '<body>'
\t. '<outline text="Tech"><outline text="Hacker News" type="rss" xmlUrl="https://news.ycombinator.com/rss"/>'
\t. '<outline text="LWN" type="rss" xmlUrl="https://lwn.net/headlines/rss"/></outline>'
\t. '<outline text="WordPress" type="rss" xmlUrl="https://wordpress.org/news/feed/"/>'
\t. '</body></opml>';

$p = XMLProcessor::create_from_string( $opml );
while ( $p->next_tag( 'outline' ) ) {
\t$url = $p->get_attribute( '', 'xmlUrl' );
\tif ( null === $url ) continue;
\techo $p->get_attribute( '', 'text' ) . "\\t" . $url . "\\n";
}'''))),
    ]))

# ===========================================================================
# Encoding
# ===========================================================================
COMPONENTS.append(('encoding', 'Encoding',
    'UTF-8 validation and scrubbing with a pure-PHP fallback when <code>mbstring</code> is unavailable. Detects malformed bytes and replaces them per the Unicode maximal-subpart algorithm.',
    'wp-php-toolkit/encoding',
    [
        ('Why this exists',
            '<p>Every parser in this toolkit eventually has to decide what to do with text bytes. XML rejects malformed UTF-8. JSON and databases can fail late. CSS, HTML, WXR, and Blueprint validation all need consistent answers about whether a string is well-formed Unicode.</p>'
            '<p>The Encoding component provides the small UTF-8 primitives the rest of the toolkit can share: validate bytes, scrub invalid sequences, scan code points, and detect Unicode noncharacters. When <code>mbstring</code> is available it can delegate to it; when it is not, the component uses its own byte scanner so behavior stays available in restricted PHP environments.</p>'
            '<p>Historically, this became the common foundation for Blueprint validation and CSS/XML processing, replacing ad hoc Unicode helpers with the WordPress core UTF-8 routines used here.</p>',
            None),
        ('Validating UTF-8 before storing it',
            '<p><code>wp_is_valid_utf8()</code> rejects overlong sequences, surrogate halves, and stray ISO-8859-1 bytes. Use it as a guard in front of any code path that assumes UTF-8 (database, JSON, XML).</p>',
            ('validate.php', php('''use function WordPress\\Encoding\\wp_is_valid_utf8;

$samples = array(
\t'ASCII'          => 'just a test',
\t'UTF-8 pencil'   => "\\xE2\\x9C\\x8F",
\t'latin-1 byte'   => "B\\xFCch",
\t'overlong slash' => "\\xC1\\xBF",
\t'surrogate half' => "\\xED\\xB0\\x80",
);

foreach ( $samples as $label => $bytes ) {
\techo sprintf( "%-14s %s\\n", $label . ':', wp_is_valid_utf8( $bytes ) ? 'valid' : 'invalid' );
}'''))),
        ('Scrubbing invalid bytes with U+FFFD',
            '<p>Replace each ill-formed sequence with the Unicode replacement character. Useful right before serializing to XML, JSON, or sending to an LLM that will choke on broken bytes.</p>',
            ('scrub.php', php('''use function WordPress\\Encoding\\wp_scrub_utf8;

$broken = "the byte \\xC0 should not be here.";
echo wp_scrub_utf8( $broken ) . "\\n";

echo wp_scrub_utf8( ".\\xE2\\x8C\\xE2\\x8C." ) . "\\n";'''))),
        ('Detecting noncharacters MySQL/utf8mb4 will reject',
            '<p>Code points like U+FFFE, U+FFFF, and the U+FDD0–U+FDEF block are valid Unicode but forbidden in XML and rejected by some databases. Check before inserting user-submitted content into a strict <code>utf8mb4</code> column.</p>',
            ('noncharacters.php', php('''use function WordPress\\Encoding\\wp_has_noncharacters;

$samples = array(
\t'normal text' => 'normal text',
\t'U+FFFE'      => "oops \\u{FFFE}",
\t'U+FDD0'      => "hi \\u{FDD0} bye",
);

foreach ( $samples as $label => $text ) {
\techo sprintf( "%-12s %s\\n", $label . ':', wp_has_noncharacters( $text ) ? 'reject' : 'ok' );
}'''))),
        ('Three-way pipeline: validate, scrub, then check noncharacters',
            '<p>Real-world inputs are messy: an old WXR export, a CSV with mixed encodings, a paste from Word. Combination of validate + scrub + noncharacter-check covers the three classes of breakage that bite later.</p>',
            ('pipeline.php', php('''use function WordPress\\Encoding\\wp_is_valid_utf8;
use function WordPress\\Encoding\\wp_scrub_utf8;
use function WordPress\\Encoding\\wp_has_noncharacters;

$inputs = array(
\t'good'      => 'Café',
\t'latin1'    => "caf\\xE9",
\t'overlong'  => "x\\xC1\\xBFy",
\t'noncharac' => "hi \\u{FFFE} there",
);

foreach ( $inputs as $label => $bytes ) {
\t$valid    = wp_is_valid_utf8( $bytes );
\t$cleaned  = wp_scrub_utf8( $bytes );
\t$weird    = wp_has_noncharacters( $cleaned );
\techo sprintf( "%-10s valid=%s noncharacter=%s -> %s\\n", $label, $valid ? 'Y' : 'N', $weird ? 'Y' : 'N', $cleaned );
}'''))),
        ('Salvaging a legacy ISO-8859-1 column inside a UTF-8 corpus',
            '<p>Old WordPress databases sometimes mix encodings: most rows are UTF-8 but a few were stored as latin-1. Detect the bad rows with <code>wp_is_valid_utf8()</code> and only re-encode those.</p>',
            ('mixed-encoding.php', php('''use function WordPress\\Encoding\\wp_is_valid_utf8;
use function WordPress\\Encoding\\wp_scrub_utf8;

$rows = array(
\t1 => 'Plain ASCII',
\t2 => 'Café',
\t3 => "caf\\xE9",
\t4 => "weird \\xC0 byte",
);

foreach ( $rows as $id => $value ) {
\tif ( wp_is_valid_utf8( $value ) ) {
\t\techo "#$id ok: $value\\n";
\t\tcontinue;
\t}
\t$converted = @iconv( 'ISO-8859-1', 'UTF-8', $value );
\tif ( false !== $converted && wp_is_valid_utf8( $converted ) ) {
\t\techo "#$id recovered as latin1: $converted\\n";
\t} else {
\t\techo "#$id unrecoverable, scrubbing: " . wp_scrub_utf8( $value ) . "\\n";
\t}
}'''))),
    ]))

# ===========================================================================
# DataLiberation
# ===========================================================================
COMPONENTS.append(('dataliberation', 'DataLiberation',
    'Streaming WordPress import/export. WXR, SQL, block markup — without loading whole datasets into memory.',
    'wp-php-toolkit/data-liberation',
    [
        ('Why this exists',
            '<p>WordPress content should be portable, but real migrations cross several formats. A site export might arrive as WXR, a Markdown folder, or entities from another CMS. URLs can hide in block attributes, HTML, CSS, feeds, GUIDs, and post meta. Importers must also resume after a failed media download or upload.</p>'
            '<p>The DataLiberation component streams WordPress-shaped data through readers, transformers, and writers. It models posts, terms, comments, attachments, and metadata as <code>ImportEntity</code> objects, then lets a pipeline rewrite each entity without loading the full export into memory.</p>'
            '<p>The API reflects specific migration bugs: relative URLs in known block attributes, URLs inside inline CSS, self-closing block comments that must keep their shape, and origin-only URLs whose trailing slash style should not change during a rewrite.</p>'
            '<p>Reach for it when the job combines formats: build WXR from another CMS, rewrite a staging export for production, frontload remote assets, or compose Markdown, XML, HTML, CSS, and URL rewriting into one pipeline.</p>',
            None),
        ('Write a WXR file in five lines',
            '<p>Stream a single post into a WXR document via <code>WXRWriter</code>. The writer holds no buffer beyond what is needed to close currently-open tags, so memory stays flat regardless of input size.</p>',
            ('wxr-quickstart.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\DataLiberation\\EntityWriter\\WXRWriter;
use WordPress\\DataLiberation\\ImportEntity;

$pipe   = new MemoryPipe();
$writer = new WXRWriter( $pipe );
$writer->append_entity( new ImportEntity( 'post', array(
\t'post_title' => 'Hello',
\t'content'    => 'World.',
\t'post_id'    => '1',
\t'status'     => 'publish',
) ) );
$writer->finalize();
$writer->close_writing();
$pipe->close_writing();
$wxr = $pipe->consume_all();

echo "bytes: " . strlen( $wxr ) . "\\n";
echo false !== strpos( $wxr, '<title>Hello</title>' ) ? "title exported\\n" : "title missing\\n";
echo false !== strpos( $wxr, '<wp:status>publish</wp:status>' ) ? "status exported\\n" : "status missing\\n";'''))),
        ('Build a WXR programmatically from any source',
            '<p>The writer doesn\'t care where entities come from. Loop over rows from a CMS, a CSV, or a Notion API dump and emit posts plus their meta and comments.</p>',
            ('build-wxr.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\DataLiberation\\EntityWriter\\WXRWriter;
use WordPress\\DataLiberation\\ImportEntity;

$rows = array(
\tarray( 'id' => 10, 'title' => 'About', 'body' => '<p>About us.</p>', 'tags' => array( 'company' ) ),
\tarray( 'id' => 11, 'title' => 'Blog',  'body' => '<p>Hello world.</p>', 'tags' => array( 'news', 'launch' ) ),
);

$pipe   = new MemoryPipe();
$writer = new WXRWriter( $pipe );

foreach ( $rows as $row ) {
\t$writer->append_entity( new ImportEntity( 'post', array(
\t\t'post_id'    => (string) $row['id'],
\t\t'post_title' => $row['title'],
\t\t'content'    => $row['body'],
\t\t'status'     => 'publish',
\t\t'post_type'  => 'post',
\t) ) );
\tforeach ( $row['tags'] as $i => $tag ) {
\t\t$writer->append_entity( new ImportEntity( 'term', array(
\t\t\t'term_id'  => (string) ( $row['id'] * 100 + $i ),
\t\t\t'taxonomy' => 'post_tag',
\t\t\t'slug'     => $tag,
\t\t\t'parent'   => '0',
\t\t) ) );
\t}
}

$writer->finalize();
$writer->close_writing();
$pipe->close_writing();

$wxr = $pipe->consume_all();
echo "items: " . substr_count( $wxr, '<item>' ) . "\\n";
echo "terms: " . substr_count( $wxr, '<wp:term>' ) . "\\n";
echo false !== strpos( $wxr, '<title>Blog</title>' ) ? "Blog post exported\\n" : "Blog post missing\\n";'''))),
        ('Read entities from a WXR file with constant memory',
            '<p><code>WXREntityReader</code> emits one entity at a time. A 10 GB WXR uses the same memory as a 10 KB one.</p>',
            ('wxr-read.php', php('''use WordPress\\DataLiberation\\EntityReader\\WXREntityReader;

$wxr = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
<title>Demo</title>
<item><title>First</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type><content:encoded>Body 1</content:encoded></item>
<item><title>Second</title><wp:post_id>2</wp:post_id><wp:post_type>post</wp:post_type><content:encoded>Body 2</content:encoded></item>
</channel>
</rss>
XML;

$reader = WXREntityReader::create();
$reader->append_bytes( $wxr );
$reader->input_finished();

while ( $reader->next_entity() ) {
\t$entity = $reader->get_entity();
\techo $entity->get_type() . ': ' . json_encode( $entity->get_data() ) . "\\n";
}'''))),
        ('Streaming transform: rewrite URLs while copying WXR',
            '<p>Wire reader to writer to rewrite a WXR file on the fly. This pattern is how you migrate a staging export to production: swap <code>staging.example.com</code> for <code>example.com</code> without ever loading the file into memory.</p>',
            ('rewrite-urls.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\DataLiberation\\EntityReader\\WXREntityReader;
use WordPress\\DataLiberation\\EntityWriter\\WXRWriter;
use WordPress\\DataLiberation\\ImportEntity;

$source_xml = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
<item><title>Hello</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
<content:encoded>Visit https://staging.example.com/about for more.</content:encoded></item>
</channel>
</rss>
XML;

$reader = WXREntityReader::create();
$reader->append_bytes( $source_xml );
$reader->input_finished();

$out_pipe = new MemoryPipe();
$writer   = new WXRWriter( $out_pipe );

while ( $reader->next_entity() ) {
\t$entity = $reader->get_entity();
\t$data   = $entity->get_data();
\tforeach ( array( 'post_content', 'content', 'description' ) as $field ) {
\t\tif ( isset( $data[ $field ] ) ) {
\t\t\t$data[ $field ] = str_replace( 'staging.example.com', 'example.com', $data[ $field ] );
\t\t}
\t}
\tif ( 'post' === $entity->get_type() ) {
\t\t$data['content'] = isset( $data['post_content'] ) ? $data['post_content'] : ( isset( $data['content'] ) ? $data['content'] : '' );
\t}
\t$writer->append_entity( new ImportEntity( $entity->get_type(), $data ) );
}

$writer->finalize();
$writer->close_writing();
$out_pipe->close_writing();

$wxr = $out_pipe->consume_all();
echo false !== strpos( $wxr, 'https://example.com/about' ) ? "new URL present\\n" : "new URL missing\\n";
echo false === strpos( $wxr, 'staging.example.com' ) ? "old URL removed\\n" : "old URL still present\\n";'''))),
        ('Render Markdown into a WXR import in one pipeline',
            '<p>Compose <code>MarkdownConsumer</code> with <code>WXRWriter</code> to publish a folder of Markdown directly as a WordPress import file.</p>',
            ('md-to-wxr.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\DataLiberation\\EntityWriter\\WXRWriter;
use WordPress\\DataLiberation\\ImportEntity;
use WordPress\\Markdown\\MarkdownConsumer;

@mkdir( '/tmp/md-src', 0777, true );
file_put_contents( '/tmp/md-src/hello.md',  "---\\ntitle: Hello\\n---\\n\\n# Hello\\n\\nFirst post." );
file_put_contents( '/tmp/md-src/second.md', "---\\ntitle: Second\\n---\\n\\nMore text **here**." );

$pipe   = new MemoryPipe();
$writer = new WXRWriter( $pipe );

$id = 1;
foreach ( glob( '/tmp/md-src/*.md' ) as $path ) {
\t$consumer = new MarkdownConsumer( file_get_contents( $path ) );
\t$consumer->consume();
\t$writer->append_entity( new ImportEntity( 'post', array(
\t\t'post_id'    => (string) $id++,
\t\t'post_title' => $consumer->get_meta_value( 'title' ) ?: basename( $path, '.md' ),
\t\t'content'    => $consumer->get_block_markup(),
\t\t'status'     => 'publish',
\t\t'post_type'  => 'post',
\t\t'post_name'  => basename( $path, '.md' ),
\t) ) );
}

$writer->finalize();
$writer->close_writing();
$pipe->close_writing();

$wxr = $pipe->consume_all();
echo "posts: " . substr_count( $wxr, '<item>' ) . "\\n";
echo false !== strpos( $wxr, '&lt;!-- wp:heading' ) ? "block markup exported\\n" : "block markup missing\\n";
echo false !== strpos( $wxr, '<title>Second</title>' ) ? "frontmatter title exported\\n" : "frontmatter title missing\\n";'''))),
    ]))

# ===========================================================================
# Git
# ===========================================================================
COMPONENTS.append(('git', 'Git',
    'A pure-PHP Git client and server. Commits, branches, diffs, HTTP push/pull — all without shelling out to <code>git</code>.',
    'wp-php-toolkit/git',
    [
        ('Why this exists',
            '<p>Git is a useful storage model even when a server cannot run the <code>git</code> binary: snapshots, branches, object-addressed files, diffs, merges, and sync over HTTP. That matters for WordPress tools that want revision history for generated files, content snapshots, site state, or collaborative edits in constrained runtimes.</p>'
            '<p>The Git component implements the core repository operations in PHP and stores objects through the toolkit <code>Filesystem</code> interface. That means the same repository can live on disk, in memory, or in another backend, and higher-level code can commit files without knowing where objects are stored.</p>'
            '<p>The docs start with simple commits because that mental model scales: a repository is just objects plus refs. From there, branches, history walking, root commits, and merges become details you can reason about instead of magic shell behavior.</p>'
            '<p>Choose it for tests, browser-like sandboxes, hosted WordPress environments, and applications that need Git behavior through PHP APIs instead of shell commands.</p>',
            None),
        ('Commit files into an in-memory repo',
            '<p>The simplest possible repository: an <code>InMemoryFilesystem</code> as object storage and one <code>commit()</code> call. Reach for this in tests, in WP-CLI snapshots, or any place you want versioning without touching disk.</p>',
            ('commit-in-memory.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );

$oid = $repo->commit( array(
\t'updates' => array(
\t\t'README.md'           => "# My Project\\n",
\t\t'src/hello-world.php' => '<?php echo "Hello!";',
\t),
) );

echo "commit: {$oid}\\n";
echo "HEAD:   " . $repo->get_branch_tip( 'HEAD' ) . "\\n";
echo "README: " . $repo->read_object_by_path( '/README.md' )->consume_all();'''))),
        ('Walk the commit history',
            '<p>Follow the parent chain from <code>HEAD</code> backwards. Building block for a WP-CLI "post revisions" log or a "what changed since release X" report.</p>',
            ('walk-history.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;
use WordPress\\Git\\Model\\Commit;

$repo = new GitRepository( InMemoryFilesystem::create() );
foreach ( array( 'add intro', 'fix typo', 'expand examples' ) as $i => $msg ) {
\t$repo->commit( array(
\t\t'updates' => array( 'post.md' => "# Draft {$i}" ),
\t\t'commit'  => array( 'message' => $msg ),
\t) );
}

$oid = $repo->get_branch_tip( 'HEAD' );
while ( ! Commit::is_null_hash( $oid ) ) {
\t$c = $repo->read_object( $oid )->as_commit();
\techo substr( $c->hash, 0, 7 ) . '  ' . trim( $c->message ) . "\\n";
\t$oid = $c->get_first_parent_hash();
\tif ( ! $oid || ! $repo->has_object( $oid ) ) break;
}'''))),
        ('Treat a repository like a filesystem',
            '<p><code>GitFilesystem</code> wraps a repository in this toolkit\'s <code>Filesystem</code> interface. With the default options, each <code>put_contents()</code> records a new commit.</p>',
            ('git-filesystem.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$fs   = GitFilesystem::create( $repo );

$fs->put_contents( '/posts/hello.md', "# Hello\\nFirst draft." );
$fs->put_contents( '/posts/about.md', "# About\\nWho we are." );
$fs->put_contents( '/posts/hello.md', "# Hello\\nSecond draft." );

echo "tree:\\n";
foreach ( $fs->ls( '/posts' ) as $name ) {
\techo "  /posts/{$name}\\n";
}
echo "\\nhello.md now:\\n" . $fs->get_contents( '/posts/hello.md' ) . "\\n";'''))),
        ('Branch, edit, and switch back',
            '<p>Create a feature branch off the current commit, change files, flip <code>HEAD</code> back. Useful for experimental edits in collaborative tools.</p>',
            ('branches.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$base = $repo->commit( array(
\t'updates' => array( 'config.json' => '{"flag":false}' ),
\t'commit'  => array( 'message' => 'baseline' ),
) );

$repo->create_branch( 'refs/heads/experiment', $base );
$repo->checkout( 'refs/heads/experiment' );
$repo->commit( array(
\t'updates' => array( 'config.json' => '{"flag":true}' ),
\t'commit'  => array( 'message' => 'flip the flag' ),
) );

echo "on experiment: " . $repo->read_object_by_path( '/config.json' )->consume_all() . "\\n";

$repo->checkout( 'refs/heads/trunk' );
echo "on trunk:      " . $repo->read_object_by_path( '/config.json' )->consume_all() . "\\n";'''))),
        ('Three-way merge two branches',
            '<p>The classic Git workflow: branch off, edit on each side, merge. <code>$repo-&gt;merge()</code> finds the common ancestor, three-way-merges every file, and creates a merge commit.</p>',
            ('merge-branches.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$base = $repo->commit( array( 'updates' => array(
\t'todo.txt' => "buy milk\\nwalk dog\\nread book\\n",
) ) );

$repo->commit( array( 'updates' => array(
\t'todo.txt' => "buy oat milk\\nwalk dog\\nread book\\n",
) ) );

$repo->create_branch( 'refs/heads/feature', $base );
$repo->checkout( 'refs/heads/feature' );
$repo->commit( array( 'updates' => array(
\t'todo.txt' => "buy milk\\nwalk dog\\nread book\\nwrite blog post\\n",
) ) );

$repo->checkout( 'refs/heads/trunk' );
$result = $repo->merge( 'refs/heads/feature' );

echo "merge head: {$result['new_head']}\\n";
echo "conflicts:  " . ( $result['conflicts'] ? implode( ',', $result['conflicts'] ) : 'none' ) . "\\n";
echo "result:\\n" . $repo->read_object_by_path( '/todo.txt' )->consume_all();'''))),
        ('Snapshot WordPress options into a repo',
            '<p>Serialize a chunk of WP state (options, post meta, a theme config) on every save and commit it. You get free history, diffs between snapshots, and a "rollback to last week" button.</p>',
            ('options-snapshot.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );

$snapshots = array(
\tarray( 'blogname' => 'My Site',  'posts_per_page' => 10, 'timezone_string' => 'UTC' ),
\tarray( 'blogname' => 'My Site',  'posts_per_page' => 20, 'timezone_string' => 'UTC' ),
\tarray( 'blogname' => 'New Name', 'posts_per_page' => 20, 'timezone_string' => 'Europe/Warsaw' ),
);

foreach ( $snapshots as $i => $options ) {
\t$repo->commit( array(
\t\t'updates' => array( 'options.json' => json_encode( $options, JSON_PRETTY_PRINT ) ),
\t\t'commit'  => array( 'message' => "snapshot #{$i}" ),
\t) );
}

$head    = $repo->get_branch_tip( 'HEAD' );
$parent  = $repo->read_object( $head )->as_commit()->get_first_parent_hash();
$diff    = $repo->diff_commits( $head, $parent );

echo "Files changed in last snapshot:\\n";
foreach ( $diff as $name => $entry ) {
\techo "  {$name}\\n";
}'''))),
    ]))

# ===========================================================================
# Merge
# ===========================================================================
COMPONENTS.append(('merge', 'Merge',
    'Three-way merge and diff. Pluggable differ + merger + optional validator.',
    'wp-php-toolkit/merge',
    [
        ('Why this exists',
            '<p>Content synchronization needs more than "last write wins." A Markdown file changes in Git while the same post changes in WordPress. A generated config changes through both a CLI tool and a UI. In those cases you need a common ancestor, two edited versions, and a way to explain conflicts to a human.</p>'
            '<p>The Merge component provides the diff and three-way merge primitives used by those workflows. The default examples are line-oriented because that is the most familiar shape, but the strategy is intentionally pluggable: choose the differ, choose the merger, and optionally validate the merged result before accepting it.</p>'
            '<p>Use the merge result to auto-accept independent edits and to show structured conflicts when a person must decide.</p>',
            None),
        ('Diff two strings line by line',
            '<p>Feed two strings to <code>LineDiffer</code> and inspect the operations. Every <code>get_changes()</code> entry is a <code>[op, text]</code> pair.</p>',
            ('line-diff.php', php('''use WordPress\\Merge\\Diff\\Diff;
use WordPress\\Merge\\Diff\\LineDiffer;

$diff = ( new LineDiffer() )->diff(
\t"alpha\\nbeta\\ngamma\\n",
\t"alpha\\nBETA\\ngamma\\ndelta\\n"
);

$labels = array( Diff::DIFF_EQUAL => '=', Diff::DIFF_DELETE => '-', Diff::DIFF_INSERT => '+' );
foreach ( $diff->get_changes() as $change ) {
\techo $labels[ $change[0] ] . ' ' . rtrim( $change[1] ) . "\\n";
}'''))),
        ('Render a unified patch',
            '<p><code>format_as_git_patch()</code> produces output that mirrors <code>git diff</code>, including hunk headers — handy for emails, CI annotations, or a "what changed?" panel.</p>',
            ('git-patch.php', php('''use WordPress\\Merge\\Diff\\LineDiffer;

$old = "title: Hello\\nauthor: Alice\\nstatus: draft\\n";
$new = "title: Hello, world\\nauthor: Alice\\nstatus: published\\ntags: greeting\\n";

$diff = ( new LineDiffer() )->diff( $old, $new );
echo $diff->format_as_git_patch( array(
\t'a_source' => 'a/post.yml',
\t'b_source' => 'b/post.yml',
) );'''))),
        ('Three-way merge with no conflicts',
            '<p>The classic case: each branch changes a different region. Pass the common ancestor plus both edits to <code>MergeStrategy::merge()</code> and read the merged result.</p>',
            ('three-way.php', php('''use WordPress\\Merge\\Diff\\LineDiffer;
use WordPress\\Merge\\Merge\\LineMerger;
use WordPress\\Merge\\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );

$result = $strategy->merge(
\t"intro\\nbody\\noutro\\n",
\t"intro updated\\nbody\\noutro\\n",
\t"intro\\nbody\\noutro\\nappendix\\n"
);

echo $result->has_conflicts() ? "conflicts!\\n" : "clean merge:\\n";
echo $result->get_merged_content();'''))),
        ('Inspect and surface conflicts',
            '<p>When both sides edit the same region, the merger produces a <code>MergeConflict</code>. The merged content carries Git-style markers, but the structured <code>get_conflicts()</code> output is what you want for a UI that lets the user pick a side.</p>',
            ('conflicts.php', php('''use WordPress\\Merge\\Diff\\LineDiffer;
use WordPress\\Merge\\Merge\\LineMerger;
use WordPress\\Merge\\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );
$result = $strategy->merge(
\t"line 1\\nline 2\\n",
\t"line 1\\nline 2 from Alice\\n",
\t"line 1\\nline 2 from Bob\\n"
);

if ( $result->has_conflicts() ) {
\tforeach ( $result->get_conflicts() as $c ) {
\t\techo "ours:   " . trim( $c->ours ) . "\\n";
\t\techo "theirs: " . trim( $c->theirs ) . "\\n";
\t}
}
echo "\\n--- merged content with markers ---\\n";
echo $result->get_merged_content();'''))),
        ('Sync a Markdown folder against an edited DB copy',
            '<p>A real-world scenario: posts live both in a Git-tracked Markdown folder and in WordPress, and someone edits each. Three-way-merge each post against its common ancestor.</p>',
            ('sync-folder-vs-db.php', php('''use WordPress\\Merge\\Diff\\LineDiffer;
use WordPress\\Merge\\Merge\\LineMerger;
use WordPress\\Merge\\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );

$posts = array(
\t'hello.md' => array(
\t\t'base' => "# Hello\\nDraft body.\\n",
\t\t'disk' => "# Hello\\nDraft body, expanded on disk.\\n",
\t\t'db'   => "# Hello\\nDraft body.\\nNew section from the editor.\\n",
\t),
\t'about.md' => array(
\t\t'base' => "# About\\nWho we are.\\n",
\t\t'disk' => "# About\\nWho *they* are.\\n",
\t\t'db'   => "# About\\nWho we really are.\\n",
\t),
);

foreach ( $posts as $name => $sides ) {
\t$result = $strategy->merge( $sides['base'], $sides['disk'], $sides['db'] );
\techo "=== {$name} ===\\n";
\techo $result->has_conflicts() ? "(conflict — needs review)\\n" : "(auto-merged)\\n";
\techo $result->get_merged_content() . "\\n";
}'''))),
    ]))

# ===========================================================================
# HttpClient
# ===========================================================================
COMPONENTS.append(('httpclient', 'HttpClient',
    'Async HTTP client without <code>curl</code> required. Uses sockets when curl is missing, supports concurrent requests and streaming responses.',
    'wp-php-toolkit/http-client',
    [
        ('Why this exists',
            '<p>A plugin installer starts with one request to download <code>plugin.zip</code>. A migration then adds progress reporting, a ten-request media window, resumable downloads, and a remote ZIP reader that feeds ZipFilesystem directly. Those workflows need the same request API from the first GET to the final streamed archive.</p>'
            '<p>The HttpClient component gives the toolkit a small request/response model, middleware for redirects and caching, concurrent fetches, and response bodies exposed as byte streams. It runs through curl when PHP provides curl and through pure PHP sockets when it does not. Callers keep the same code path.</p>'
            '<p>Use it to fetch plugin metadata, submit import callbacks, mirror a media library, read a WXR export, or pipe a remote archive into Zip and Filesystem code.</p>',
            None),
        ('GET a URL',
            '<p class="callout"><strong>Network access in the demo runtime.</strong> Live request examples show the real API, but outbound HTTP in browser sandboxes may require a CORS proxy.</p>'
            '<p>The smallest flow has three steps: create a request, wait until headers arrive, then consume the body stream. This is intentionally close to the Fetch API shape, but the body is a toolkit byte stream instead of a buffered string.</p>',
            ('get.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$client  = new Client();
$stream  = $client->fetch( new Request( 'https://example.com/' ) );

$response = $stream->await_response();
echo "status: " . $response->status_code . "\\n";
echo "first 80 bytes: " . substr( $stream->consume_all(), 0, 80 ) . "\\n";'''))),
        ('POST to a URL',
            '<p>Uploads use the same shape. The only difference is that the request declares a method, request headers, and an upload body stream. Here the body is form-encoded text wrapped in <code>MemoryPipe</code>; a file upload could provide a file-backed read stream instead.</p>',
            ('post.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;
use WordPress\\ByteStream\\MemoryPipe;

$payload = http_build_query(
\tarray(
\t\t'title' => 'Hello',
\t\t'tags'  => 'http,php',
\t),
\t'',
\t'&'
);

$client  = new Client();
$request = new Request( 'https://httpbin.org/post', array(
\t'method'      => 'POST',
\t'headers'     => array(
\t\t'content-type'   => 'application/x-www-form-urlencoded',
\t\t'content-length' => (string) strlen( $payload ),
\t),
\t'body_stream' => new MemoryPipe( $payload ),
) );

$response = $client->fetch( $request )->json();
echo "Server saw form title: " . $response['form']['title'] . "\\n";'''))),
        ('Build a JSON request object',
            '<p>A <code>Request</code> is just data until a client enqueues it. That makes it easy to test request construction without network access. The constructor normalizes headers, calculates <code>content-length</code> when the body stream has a known length, and moves URL credentials into an Authorization header.</p>',
            ('request-object.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\HttpClient\\Request;

$body = new MemoryPipe( json_encode( array(
\t'title' => 'Hello',
\t'tags'  => array( 'docs', 'php' ),
) ) );
$body->close_writing();

$request = new Request( 'https://user:secret@api.example.test/posts', array(
\t'method'      => 'POST',
\t'headers'     => array( 'content-type' => 'application/json' ),
\t'body_stream' => $body,
) );

echo $request->method . ' ' . $request->url . "\\n";
echo "content-type: " . $request->get_header( 'content-type' ) . "\\n";
echo "content-length: " . $request->get_header( 'content-length' ) . "\\n";
echo "authorization: " . substr( $request->get_header( 'authorization' ), 0, 10 ) . "...\\n";'''))),
        ('Parse response headers',
            '<p>Most applications receive <code>Response</code> objects from <code>await_response()</code>. Transports, middleware, and tests sometimes need the lower-level parser: <code>Response::from_http_headers()</code> turns raw HTTP header bytes into normalized status and case-insensitive headers.</p>',
            ('parse-response.php', php('''use WordPress\\HttpClient\\Request;
use WordPress\\HttpClient\\Response;

$request = new Request( 'https://api.example.test/posts/42' );
$raw = "HTTP/1.1 201 Created\\r\\n"
\t. "Content-Type: application/json\\r\\n"
\t. "Location: /posts/42\\r\\n"
\t. "Content-Length: 27\\r\\n\\r\\n";

$response = Response::from_http_headers( $raw, $request );

echo "status: " . $response->status_code . ' ' . $response->get_reason_phrase() . "\\n";
echo "ok:     " . ( $response->ok() ? 'yes' : 'no' ) . "\\n";
echo "type:   " . $response->get_header( 'CONTENT-TYPE' ) . "\\n";
echo "size:   " . $response->total_bytes . " bytes\\n";'''))),
        ('Pick the right reading style',
            '<p>There are three common ways to consume a response. Start simple, then move down the table only when the workflow demands it.</p>'
            '<table><thead><tr><th>Style</th><th>Use when</th><th>Tradeoff</th></tr></thead><tbody>'
            '<tr><td><code>consume_all()</code> or <code>json()</code></td><td>Small HTML, JSON, or API responses.</td><td>Buffers the full body.</td></tr>'
            '<tr><td><code>Client::await_next_event()</code></td><td>Progress bars, streaming to disk, queues, failure handling.</td><td>You own the event loop.</td></tr>'
            '<tr><td>Filesystem and parser composition</td><td>Remote ZIPs, WXR files, import pipelines.</td><td>Requires a stream-aware consumer.</td></tr>'
            '</tbody></table>',
            None),
        ('Choose a transport',
            '<p>The transport is the I/O backend. It should not change your request, response, redirect, cache, or stream code; it only changes how bytes move across the network.</p>'
            '<table><thead><tr><th>Transport</th><th>What it does</th><th>When to choose it</th></tr></thead><tbody>'
            '<tr><td><code>auto</code></td><td>Uses curl when loaded, otherwise sockets.</td><td>Application default. Best when you want portability and the fastest available backend.</td></tr>'
            '<tr><td><code>sockets</code></td><td>Uses PHP stream sockets, no curl extension.</td><td>Tests, Playground-style runtimes, hosts where curl is unavailable, or proving the dependency-free path works.</td></tr>'
            '<tr><td><code>curl</code></td><td>Uses the curl extension.</td><td>Hosts where curl is available and you want to compare behavior or performance explicitly.</td></tr>'
            '</tbody></table>'
            '<p><code>concurrency</code>, <code>timeout_ms</code>, <code>cache_dir</code>, redirects, and response streaming sit above the transport, so the examples later on work with either backend.</p>',
            ('transports.php', php('''use WordPress\\HttpClient\\Client;

$default = new Client(); // Same as array( 'transport' => 'auto' ).

$portable = new Client( array(
\t'transport' => 'sockets',
) );

if ( extension_loaded( 'curl' ) ) {
\t$curl = new Client( array(
\t\t'transport' => 'curl',
\t) );
}'''), False)),
        ('Follow redirects and inspect the final request',
            '<p>Redirects are middleware, not transport behavior. The client follows up to five redirects by default. The original <code>Request</code> keeps a chain to the final request, so importers can log where a source URL actually landed.</p>',
            ('redirects.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$client   = new Client();
$request  = new Request( 'https://httpbin.org/redirect-to?url=https://example.com/' );
$stream   = $client->fetch( $request );
$response = $stream->await_response();
$stream->consume_all();

$final = $request->latest_redirect();
echo "original: " . $request->url . "\\n";
echo "final:    " . $final->url . "\\n";
echo "status:   " . $response->status_code . "\\n";'''), False)),
        ('Cache repeatable GET responses',
            '<p>Pass <code>cache_dir</code> to add disk caching for cacheable GET and HEAD responses. Fresh cached responses replay the same header/body events as a network response, so crawlers and importers do not need a separate cache code path. Non-GET requests invalidate matching cache entries instead of being cached.</p>',
            ('cache.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$cache_dir = sys_get_temp_dir() . '/http-cache-' . uniqid();
mkdir( $cache_dir );

$client = new Client( array( 'cache_dir' => $cache_dir ) );
$url    = 'https://httpbin.org/cache/60';

for ( $i = 1; $i <= 2; $i++ ) {
\t$stream   = $client->fetch( new Request( $url ) );
\t$response = $stream->await_response();
\t$body     = $stream->consume_all();
\techo "request {$i}: HTTP " . $response->status_code . ', body=' . strlen( $body ) . " bytes\\n";
}

echo "cache files: " . count( glob( $cache_dir . '/*' ) ) . "\\n";'''), False)),
        ('Handle failures without losing the queue',
            '<p>Failures arrive as events. That lets a crawler, importer, package installer, or media frontloader log one bad URL and keep processing the rest of the queue. Treat failure handling as part of the event loop, not as one global try/catch around the whole batch.</p>',
            ('failures.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$client = new Client( array( 'timeout_ms' => 5000 ) );
$client->enqueue( array(
\tnew Request( 'https://example.com/', array( 'method' => 'HEAD' ) ),
\tnew Request( 'https://example.invalid/missing' ),
) );

while ( $client->await_next_event() ) {
\t$request = $client->get_request();
\t$event   = $client->get_event();

\tif ( Client::EVENT_GOT_HEADERS === $event ) {
\t\techo "ok: " . $request->url . " HTTP " . $request->response->status_code . "\\n";
\t} elseif ( Client::EVENT_FAILED === $event ) {
\t\techo "failed: " . $request->url . "\\n";
\t} elseif ( Client::EVENT_FINISHED === $event ) {
\t\techo "finished: " . $request->url . "\\n";
\t}
}'''), False)),
        ('Monitor download progress',
            '<p>When you care about progress, use the event loop directly. Count bytes from each <code>EVENT_BODY_CHUNK_AVAILABLE</code> event and compare them with <code>Content-Length</code> when the server provides one.</p>',
            ('progress.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$url  = 'https://raw.githubusercontent.com/WordPress/php-toolkit/trunk/components/Zip/Tests/fixtures/childrens-literature.zip';
$dest = sys_get_temp_dir() . '/progress-' . uniqid() . '.zip';

$client  = new Client();
$request = new Request( $url );
$client->enqueue( array( $request ) );

$downloaded = 0;
$last_step  = -1;
@unlink( $dest );

while ( $client->await_next_event() ) {
\t$event   = $client->get_event();
\t$request = $client->get_request();

\tif ( Client::EVENT_GOT_HEADERS === $event ) {
\t\techo "status: " . $request->response->status_code . "\\n";
\t\tcontinue;
\t}

\tif ( Client::EVENT_BODY_CHUNK_AVAILABLE === $event ) {
\t\t$chunk       = $client->get_response_body_chunk();
\t\t$downloaded += strlen( $chunk );
\t\tfile_put_contents( $dest, $chunk, FILE_APPEND );

\t\t$total = $request->response->total_bytes;
\t\tif ( $total ) {
\t\t\t$step = min( 100, (int) floor( $downloaded / $total * 100 ) );
\t\t\tif ( $step >= $last_step + 25 || 100 === $step ) {
\t\t\t\techo "progress: {$step}% ({$downloaded}/{$total} bytes)\\n";
\t\t\t\t$last_step = $step;
\t\t\t}
\t\t} else {
\t\t\techo "downloaded: {$downloaded} bytes\\n";
\t\t}
\t\tcontinue;
\t}

\tif ( Client::EVENT_FINISHED === $event ) {
\t\techo "saved: {$dest}\\n";
\t} elseif ( Client::EVENT_FAILED === $event ) {
\t\techo "failed: " . $request->error->message . "\\n";
\t}
}'''))),
        ('Keep a sliding window of 10 requests',
            '<p>For large queues, do not enqueue everything at once. Keep at most ten active requests, enqueue another as each one finishes, and let the client multiplex only that window.</p>',
            ('sliding-window.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$urls = array();
for ( $i = 1; $i <= 25; $i++ ) {
\t$urls[] = 'https://example.com/?request=' . $i;
}

$client  = new Client( array( 'concurrency' => 10 ) );
$pending = $urls;
$active  = array();
$done    = 0;

$enqueue_next = function () use ( &$pending, &$active, $client ) {
\tif ( ! $pending ) {
\t\treturn;
\t}
\t$url     = array_shift( $pending );
\t$request = new Request( $url, array( 'method' => 'HEAD' ) );
\t$active[ $request->id ] = $request;
\t$client->enqueue( array( $request ) );
};

for ( $i = 0; $i < 10; $i++ ) {
\t$enqueue_next();
}

while ( $active && $client->await_next_event() ) {
\t$request = $client->get_request();
\t$event   = $client->get_event();

\tif ( Client::EVENT_GOT_HEADERS === $event ) {
\t\techo "headers {$request->id}: " . $request->response->status_code . "\\n";
\t\tcontinue;
\t}

\tif ( Client::EVENT_FINISHED === $event || Client::EVENT_FAILED === $event ) {
\t\tunset( $active[ $request->id ] );
\t\t$done++;
\t\techo "finished {$done}/25, active=" . count( $active ) . "\\n";
\t\t$enqueue_next();
\t}
}'''))),
        ('Resume a partial download',
            '<p>Resuming is an HTTP contract between you and the server. Save what you already have, send a <code>Range</code> request for the remaining bytes, and append only if the server returns <code>206 Partial Content</code>.</p>',
            ('resume-download.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$url  = 'https://raw.githubusercontent.com/WordPress/php-toolkit/trunk/components/Zip/Tests/fixtures/childrens-literature.zip';
$dest = sys_get_temp_dir() . '/resume-' . uniqid() . '.zip';

$client = new Client();

// Simulate an interrupted first attempt by downloading only the first 32 KB.
$first = new Request( $url, array(
\t'headers' => array( 'range' => 'bytes=0-32767' ),
) );
$stream   = $client->fetch( $first );
$response = $stream->await_response();
file_put_contents( $dest, $stream->consume_all() );

if ( 206 !== $response->status_code ) {
\techo "Server did not honor Range; start over with a full download.\\n";
\texit;
}

$downloaded = filesize( $dest );
echo "partial file: {$downloaded} bytes\\n";

$resume = new Request( $url, array(
\t'headers' => array( 'range' => 'bytes=' . $downloaded . '-' ),
) );
$stream   = $client->fetch( $resume );
$response = $stream->await_response();

if ( 206 !== $response->status_code ) {
\techo "Server did not resume; discard partial file and retry from byte 0.\\n";
\texit;
}

while ( ! $stream->reached_end_of_data() ) {
\t$n = $stream->pull( 8192 );
\tif ( 0 === $n ) {
\t\tbreak;
\t}
\tfile_put_contents( $dest, $stream->consume( $n ), FILE_APPEND );
}

echo "complete file: " . filesize( $dest ) . " bytes\\n";
echo "saved: {$dest}\\n";'''))),
        ('Stream-unzip a remote archive',
            '<p>Mount the remote archive with <code>ZipFilesystem</code>, then copy it into any writable filesystem. <code>SeekableRequestReadStream</code> caches received bytes to a temporary file so <code>ZipFilesystem</code> can read the central directory and seek to entries without first writing the ZIP yourself.</p>',
            ('stream-unzip.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\ByteStream\\SeekableRequestReadStream;
use WordPress\\HttpClient\\Request;
use WordPress\\Filesystem\\LocalFilesystem;
use WordPress\\Zip\\ZipFilesystem;
use function WordPress\\Filesystem\\copy_between_filesystems;
use function WordPress\\Filesystem\\ls_recursive;

$url  = 'https://raw.githubusercontent.com/WordPress/php-toolkit/trunk/components/Zip/Tests/fixtures/childrens-literature.zip';
$root = sys_get_temp_dir() . '/remote-zip-' . uniqid();
mkdir( $root );

$client = new Client();
$reader = new SeekableRequestReadStream(
\tnew Request( $url ),
\tarray( 'client' => $client )
);

$response = $reader->await_response();
if ( ! $response->ok() ) {
\techo "HTTP " . $response->status_code . "\\n";
\texit;
}

$zip   = ZipFilesystem::create( $reader );
$local = LocalFilesystem::create( $root );

copy_between_filesystems( array(
\t'source_filesystem' => $zip,
\t'source_path'       => '/',
\t'target_filesystem' => $local,
\t'target_path'       => '/',
) );

$tree  = ls_recursive( $local, '/' );
$files = 0;
array_walk_recursive( $tree, function ( $value, $key ) use ( &$files ) {
\tif ( 'type' === $key && 'file' === $value ) {
\t\t$files++;
\t}
} );

echo "extracted {$files} files\\n";
echo "root: {$root}\\n";'''))),
        ('Parallel fan-out: fetch many URLs at once',
            '<p>Enqueue a batch of requests and react to events as they fire. The client multiplexes them — total wall time is roughly the slowest request, not the sum.</p>',
            ('fan-out.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$urls = array(
\t'https://wordpress.org/',
\t'https://make.wordpress.org/',
\t'https://developer.wordpress.org/',
);

$client = new Client();
$client->enqueue( array_map( function ( $url ) {
\treturn new Request( $url, array( 'method' => 'HEAD' ) );
}, $urls ) );

$results = array();
while ( $client->await_next_event() ) {
\t$request = $client->get_request();
\tif ( Client::EVENT_GOT_HEADERS === $client->get_event() ) {
\t\t$results[ $request->url ] = $request->response->status_code;
\t} elseif ( Client::EVENT_FAILED === $client->get_event() ) {
\t\t$results[ $request->url ] = 'ERR ' . $request->error->message;
\t}
}

foreach ( $results as $url => $status ) {
\tprintf( "%-40s %s\\n", $url, $status );
}'''))),
        ('Stream a download to disk without OOM',
            '<p>Process the body chunk-by-chunk via the event loop. Memory stays flat regardless of file size.</p>',
            ('stream-to-disk.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$dest   = sys_get_temp_dir() . '/wp-readme.html';
$client = new Client();
$client->enqueue( array( new Request( 'https://wordpress.org/' ) ) );

$bytes = 0;
@unlink( $dest );

while ( $client->await_next_event() ) {
\tswitch ( $client->get_event() ) {
\t\tcase Client::EVENT_BODY_CHUNK_AVAILABLE:
\t\t\t$chunk  = $client->get_response_body_chunk();
\t\t\t$bytes += strlen( $chunk );
\t\t\tfile_put_contents( $dest, $chunk, FILE_APPEND );
\t\t\tbreak;
\t\tcase Client::EVENT_FINISHED:
\t\t\techo "Wrote {$bytes} bytes to {$dest}\\n";
\t\t\tbreak;
\t}
}

echo "Peak memory: " . round( memory_get_peak_usage( true ) / 1024 / 1024, 2 ) . " MB\\n";'''))),
    ]))

# ===========================================================================
# HttpServer
# ===========================================================================
COMPONENTS.append(('httpserver', 'HttpServer',
    'A minimal blocking TCP HTTP server in pure PHP. For CLI tools and tests, not for production traffic.',
    'wp-php-toolkit/http-server',
    [
        ('Why this exists',
            '<p>Sometimes a PHP tool needs a tiny local HTTP surface: a test fixture server, a webhook receiver during development, a CLI tool with a browser UI, or a demo endpoint for another component. Pulling in a production web framework would obscure the example and add dependencies the toolkit avoids.</p>'
            '<p>The HttpServer component is intentionally small: a blocking TCP server, incoming request objects, and response writers. It is useful for local tools and tests. It is not a replacement for nginx, Apache, php-fpm, RoadRunner, Swoole, or a production application server.</p>',
            None),
        ('Hello world on port 8080',
            '<p class="callout"><strong>Run on your machine:</strong> the Playground sandbox does not allow processes to bind listening TCP ports. Save this snippet locally and run <code>php hello-server.php</code>.</p>',
            ('hello-server.php', '''<?php
require __DIR__ . '/vendor/autoload.php';

use WordPress\\HttpServer\\TcpServer;
use WordPress\\HttpServer\\IncomingRequest;
use WordPress\\HttpServer\\Response\\ResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );

$server->set_handler( function ( IncomingRequest $request, ResponseWriteStream $response ) {
\t$response->send_http_code( 200 );
\t$response->send_header( 'Content-Type', 'text/plain' );
\t$response->append_bytes( "Hello from " . $request->method . " " . $request->url . "\\n" );
} );

$server->serve( function ( $host, $port ) {
\techo "Listening on http://{$host}:{$port}\\n";
} );''', False)),
        ('A tiny JSON router',
            '<p class="callout"><strong>Run on your machine:</strong> needs a listening port. Once running, try <code>curl localhost:8080/api/status</code>.</p>'
            '<p>Build a CLI tool with a web UI by switching on the parsed path and method.</p>',
            ('mini-router.php', '''<?php
require __DIR__ . '/vendor/autoload.php';

use WordPress\\HttpServer\\TcpServer;
use WordPress\\HttpServer\\IncomingRequest;
use WordPress\\HttpServer\\Response\\ResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );

$server->set_handler( function ( IncomingRequest $request, ResponseWriteStream $response ) {
\t$path = $request->get_parsed_url()->pathname;

\tif ( '/api/status' === $path ) {
\t\t$response->send_http_code( 200 );
\t\t$response->send_header( 'Content-Type', 'application/json' );
\t\t$response->append_bytes( json_encode( array(
\t\t\t'ok'     => true,
\t\t\t'pid'    => getmypid(),
\t\t\t'memory' => memory_get_usage( true ),
\t\t) ) );
\t\treturn;
\t}

\tif ( '/api/echo' === $path && 'POST' === $request->method ) {
\t\t$body = '';
\t\twhile ( ! $request->body_stream->reached_end_of_data() ) {
\t\t\t$n = $request->body_stream->pull( 4096 );
\t\t\tif ( $n > 0 ) $body .= $request->body_stream->consume( $n );
\t\t}
\t\t$response->send_http_code( 200 );
\t\t$response->send_header( 'Content-Type', 'text/plain' );
\t\t$response->append_bytes( $body );
\t\treturn;
\t}

\t$response->send_http_code( 404 );
\t$response->append_bytes( "Not found\\n" );
} );

$server->serve();''', False)),
        ('Buffered response with auto Content-Length',
            '<p>Use <code>BufferingResponseWriter</code> when you want the framework to compute <code>Content-Length</code> for you, or when the runtime is CGI-shaped and expects the full body up front. This one runs anywhere — no socket required.</p>',
            ('buffered-writer.php', php('''use WordPress\\HttpServer\\Response\\BufferingResponseWriter;

$writer = new BufferingResponseWriter();
$writer->send_http_code( 200 );
$writer->send_header( 'Content-Type', 'text/html' );
$writer->append_bytes( '<!doctype html><title>Hi</title><h1>Hello</h1>' );
$writer->append_bytes( '<p>Buffered body, sent at the end.</p>' );

ob_start();
$writer->close_writing();
$response_body = ob_get_clean();

echo "headers before send:\\n";
foreach ( $writer->get_buffered_headers() as $name => $value ) {
\techo "{$name}: {$value}\\n";
}
echo "\\nbody:\\n" . $response_body;'''))),
    ]))

# ===========================================================================
# CORSProxy
# ===========================================================================
COMPONENTS.append(('corsproxy', 'CORSProxy',
    'A small PHP CORS proxy intended for browser-side code that needs to reach servers without CORS headers.',
    'wp-php-toolkit/corsproxy',
    [
        ('Why this exists',
            '<p>A Playground-style browser tool reads <code>https://api.github.com/repos/WordPress/php-toolkit</code>, a plugin ZIP from <code>downloads.wordpress.org</code>, or a raw fixture from GitHub. The browser blocks the response when the upstream server does not send the required CORS headers, even though PHP can fetch the same public URL server-side.</p>'
            '<p>The CORSProxy component is that server-side bridge. It accepts a target URL, fetches it from PHP, and returns a browser-readable response. Because an open proxy is a security and abuse risk, real deployments should add host allowlists, rate limits, header controls, and private-network protections appropriate to their environment.</p>',
            None),
        ('Run the proxy locally',
            '<p class="callout"><strong>Run on your machine:</strong> the proxy needs to listen on a port. Start PHP\'s built-in server and request any HTTPS URL through it.</p>'
            '<pre><code>PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT=1 \\\n  php -S 127.0.0.1:5263 vendor/wp-php-toolkit/corsproxy/cors-proxy.php\n\n# In another terminal:\ncurl -s "http://127.0.0.1:5263/cors-proxy.php/https://api.github.com/repos/WordPress/php-toolkit" | head\n</code></pre>',
            None),
        ('Production rate limiting',
            '<p>Drop a <code>cors-proxy-config.php</code> next to <code>cors-proxy.php</code>. If that file defines a <code>playground_cors_proxy_maybe_rate_limit()</code> function, the proxy calls it before forwarding any request — your one chance to reject early. Without the file, the proxy applies its default rate limiter, which is fine for development but should be replaced for any deployment that gets real traffic.</p>'
            '<p>This example uses a per-IP token bucket stored on disk. Replace with Redis or memcached for multi-host deployments.</p>',
            ('cors-proxy-config.php', '''<?php
// cors-proxy-config.php — placed next to cors-proxy.php.

function playground_cors_proxy_maybe_rate_limit() {
\t$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
\t$bucket  = sys_get_temp_dir() . '/cors-rl-' . md5( $ip );
\t$now     = time();
\t$window  = 60;
\t$max_req = 30;

\t$hits = array();
\tif ( file_exists( $bucket ) ) {
\t\t$hits = json_decode( file_get_contents( $bucket ), true );
\t\tif ( ! is_array( $hits ) ) $hits = array();
\t}
\t$hits = array_filter( $hits, function ( $t ) use ( $now, $window ) {
\t\treturn $t > $now - $window;
\t} );

\tif ( count( $hits ) >= $max_req ) {
\t\theader( 'Retry-After: ' . $window );
\t\thttp_response_code( 429 );
\t\techo 'Rate limit exceeded';
\t\texit;
\t}

\t$hits[] = $now;
\tfile_put_contents( $bucket, json_encode( array_values( $hits ) ) );
}

echo "Config loaded — rate limiter armed.\\n";''', False)),
        ('Allowlist upstream hosts',
            '<p>Out of the box the proxy will fetch any public URL. Most real deployments want a fixed list of upstreams — GitHub, Packagist, wp.org. Both the rate-limit logic and the allowlist live in the same hook, since <code>cors-proxy.php</code> only calls <code>playground_cors_proxy_maybe_rate_limit()</code> once. The example below shows just the allowlist concern; in practice you stack both in one function inside <code>cors-proxy-config.php</code>.</p>',
            ('cors-proxy-config-allowlist.php', '''<?php
// cors-proxy-config.php — combine with the rate-limit example above.

function playground_cors_proxy_maybe_rate_limit() {
\t$allow = array(
\t\t'api.github.com',
\t\t'raw.githubusercontent.com',
\t\t'codeload.github.com',
\t\t'repo.packagist.org',
\t\t'downloads.wordpress.org',
\t\t'api.wordpress.org',
\t);

\t$target = isset( $_SERVER['PATH_INFO'] ) ? $_SERVER['PATH_INFO'] : ( '/' . ( isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '' ) );
\t$target = ltrim( $target, '/' );
\t$host   = parse_url( $target, PHP_URL_HOST );

\tif ( ! $host || ! in_array( strtolower( $host ), $allow, true ) ) {
\t\thttp_response_code( 403 );
\t\theader( 'Content-Type: text/plain' );
\t\techo "Upstream not allowed: " . ( $host ? $host : '(none)' );
\t\texit;
\t}
}

echo "Allowlist config active.\\n";''', False)),
        ('Browser-side fetch through the proxy',
            '<p>Once deployed, the client side is just <code>fetch()</code> with the proxy URL. Drop this into any HTML page.</p>'
            '<pre><code>const PROXY = "https://cors.example.com/cors-proxy.php";\n\nasync function viaProxy(url, init = {}) {\n  const res = await fetch(`${PROXY}/${url}`, {\n    ...init,\n    headers: {\n      ...(init.headers || {}),\n      "X-Cors-Proxy-Allowed-Request-Headers": "Authorization",\n    },\n  });\n  if (!res.ok) throw new Error(`Proxy returned ${res.status}`);\n  return res;\n}\n\nconst repo = await viaProxy("https://api.github.com/repos/WordPress/php-toolkit").then(r =&gt; r.json());\nconsole.log(repo.full_name, repo.stargazers_count);\n</code></pre>',
            None),
        ('Deploy behind nginx',
            '<p>The proxy is a single PHP script — any SAPI works. nginx + php-fpm is a common production setup. <code>PATH_INFO</code> is what the proxy reads to learn the target URL.</p>'
            '<pre><code>server {\n  listen 443 ssl http2;\n  server_name cors.example.com;\n\n  root /var/www/cors-proxy;\n  index cors-proxy.php;\n\n  location ~ ^/cors-proxy\\.php(/.*)?$ {\n    fastcgi_pass unix:/run/php/php8.1-fpm.sock;\n    fastcgi_split_path_info ^(.+\\.php)(/.*)$;\n    fastcgi_param SCRIPT_FILENAME $document_root/cors-proxy.php;\n    fastcgi_param PATH_INFO $fastcgi_path_info;\n    include fastcgi_params;\n  }\n}\n</code></pre>',
            None),
    ]))

# ===========================================================================
# CLI
# ===========================================================================
COMPONENTS.append(('cli', 'CLI',
    'POSIX-style argument parser. Long options, short bundles, inline values, positional args — one static call.',
    'wp-php-toolkit/cli',
    [
        ('Why this exists',
            '<p>Real CLI tools in PHP usually mean either pulling in <code>symfony/console</code> (and the transitive dependencies that come with it) or hand-rolling argv parsing that breaks the first time someone writes <code>-vvv</code> or <code>--port=8080</code>. The toolkit\'s <code>CLI</code> class is one static method, no dependencies, and handles the POSIX shapes you actually see.</p>',
            None),
        ('Parse a single flag',
            '<p>The smallest useful invocation: one boolean flag, one positional. Each option is a four-tuple of <code>[ short, has_value, default, description ]</code>.</p>',
            ('parse-flag.php', php('''use WordPress\\CLI\\CLI;

$option_defs = array(
\t'verbose' => array( 'v', false, false, 'Enable verbose output' ),
);

list( $positionals, $options ) = CLI::parse_command_args_and_options(
\tarray( '-v', 'input.txt' ),
\t$option_defs
);

echo "verbose: " . ( $options['verbose'] ? 'yes' : 'no' ) . "\\n";
echo "input:   " . $positionals[0] . "\\n";'''))),
        ('Mix values, flags, and bundles',
            '<p>The parser accepts <code>--port 8080</code>, <code>--port=8080</code>, <code>-p 8080</code>, and <code>-p=8080</code>. It also expands bundled boolean shorts such as <code>-afv</code>.</p>',
            ('mix-shapes.php', php('''use WordPress\\CLI\\CLI;

$option_defs = array(
\t'all'     => array( 'a', false, false, 'Process everything' ),
\t'force'   => array( 'f', false, false, 'Overwrite existing files' ),
\t'verbose' => array( 'v', false, false, 'Verbose output' ),
\t'output'  => array( 'o', true,  null,  'Output path' ),
\t'port'    => array( 'p', true,  '3000', 'Server port' ),
);

$argv = array( '-afv', '--port=8080', '-o', '/tmp/result.txt', 'input.json' );
list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );

echo "input:   " . $positionals[0] . "\\n";
echo "flags:   " . implode( ', ', array_keys( array_filter( array(
\t'all'     => $options['all'],
\t'force'   => $options['force'],
\t'verbose' => $options['verbose'],
) ) ) ) . "\\n";
echo "output:  " . $options['output'] . "\\n";
echo "port:    " . $options['port'] . "\\n";'''))),
        ('Validate required options',
            '<p>The parser fills in defaults but never enforces "required". Check for <code>null</code> after parsing — full control over the error message.</p>',
            ('require-options.php', php('''use WordPress\\CLI\\CLI;

$option_defs = array(
\t'site-url'  => array( 'u', true, null, 'Public site URL (required)' ),
\t'site-path' => array( null, true, null, 'Target directory (required)' ),
);

$argv = array( '--site-url', 'https://mysite.test' );

try {
\tlist( , $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );
\tforeach ( array( 'site-url', 'site-path' ) as $name ) {
\t\tif ( null === $options[ $name ] ) {
\t\t\tthrow new RuntimeException( "Missing required option --{$name}" );
\t\t}
\t}
\techo "All good.\\n";
} catch ( Exception $e ) {
\techo "error: " . $e->getMessage() . "\\n";
}'''))),
        ('Generate --help from definitions',
            '<p>Because each option carries its own description, you can render help text by walking the same definitions you parse with. No second source of truth.</p>',
            ('help-text.php', php('''use WordPress\\CLI\\CLI;

$option_defs = array(
\t'output'  => array( 'o', true,  null,  'Write result to FILE' ),
\t'force'   => array( 'f', false, false, 'Overwrite existing files' ),
\t'verbose' => array( 'v', false, false, 'Verbose output' ),
\t'help'    => array( 'h', false, false, 'Show this help and exit' ),
);

function render_help( array $defs ) {
\techo "Usage: mytool [options] <input>\\n\\nOptions:\\n";
\tforeach ( $defs as $long => $def ) {
\t\tlist( $short, $has_value, $default, $desc ) = $def;
\t\t$flag = ( $short ? "-{$short}, " : '    ' ) . "--{$long}";
\t\tif ( $has_value ) $flag .= '=VALUE';
\t\techo sprintf( "  %-28s %s\\n", $flag, $desc );
\t}
}

list( , $options ) = CLI::parse_command_args_and_options( array( '-h' ), $option_defs );
if ( $options['help'] ) render_help( $option_defs );'''))),
        ('Git-style subcommands',
            '<p>To build a tool with subcommands like <code>mytool deploy</code>, peel the first positional off <code>argv</code>, dispatch, and parse the rest with a per-command option set.</p>',
            ('subcommands.php', php('''use WordPress\\CLI\\CLI;

$commands = array(
\t'deploy' => array(
\t\t'env'     => array( 'e', true, 'staging', 'Target environment' ),
\t\t'dry-run' => array( 'n', false, false, 'Preview without applying' ),
\t),
\t'rollback' => array(
\t\t'to' => array( 't', true, null, 'Revision to roll back to' ),
\t),
);

function run( array $argv, array $commands ) {
\tif ( empty( $argv ) ) {
\t\techo "Usage: mytool <command> [options]\\nCommands: " . implode( ', ', array_keys( $commands ) ) . "\\n";
\t\treturn;
\t}
\t$command = array_shift( $argv );
\tif ( ! isset( $commands[ $command ] ) ) {
\t\techo "Unknown command: {$command}\\n";
\t\treturn;
\t}
\tlist( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $commands[ $command ] );
\techo "command={$command}\\n";
\techo "options: " . json_encode( $options ) . "\\n";
\techo "positionals: " . json_encode( $positionals ) . "\\n";
}

run( array( 'deploy', '--env=production', '-n', 'web-01', 'web-02' ), $commands );
echo "---\\n";
run( array( 'rollback', '-t', 'abc123' ), $commands );'''))),
    ]))

# ===========================================================================
# Polyfill
# ===========================================================================
COMPONENTS.append(('polyfill', 'Polyfill',
    'PHP 8 string functions on PHP 7.2+, WordPress hook stubs, and translation/escaping passthroughs so toolkit code runs without WordPress.',
    'wp-php-toolkit/polyfill',
    [
        ('Why this exists',
            '<p>A lot of WordPress-adjacent code wants to call <code>esc_html()</code>, <code>__()</code>, or <code>apply_filters()</code> without booting WordPress. The polyfill component provides minimal but real implementations so that code runs unchanged outside WordPress, and stays out of the way when WordPress is loaded (every function uses <code>function_exists()</code> guards).</p>',
            None),
        ('PHP 8 string functions on PHP 7.2',
            '<p>The polyfills define <code>str_contains</code>, <code>str_starts_with</code>, <code>str_ends_with</code>, and <code>array_key_first</code> only when missing.</p>',
            ('php8-strings.php', php('''var_dump( str_starts_with( '/var/www/html', '/var' ) );
var_dump( str_ends_with( 'image.png', '.png' ) );
var_dump( str_contains( 'WordPress Toolkit', 'Toolkit' ) );

$first_key = array_key_first( array( 'alpha' => 1, 'beta' => 2 ) );
echo "first key: {$first_key}\\n";'''))),
        ('Escaping and translation stubs',
            '<p>Pass-through implementations let you write code that looks WordPressy and runs anywhere.</p>',
            ('wp-stubs.php', php('''echo __( 'Hello, world' ) . "\\n";
echo esc_html( '<script>alert("xss")</script>' ) . "\\n";
echo esc_attr( 'a "quoted" value' ) . "\\n";
echo esc_url( 'https://example.com/?a=1&b=2' ) . "\\n";'''))),
        ('A simple filter chain',
            '<p>The hook system is a real implementation of the WordPress filter API: registered callbacks get applied in priority order, and each one transforms the running value.</p>',
            ('filter-chain.php', php('''add_filter( 'sanitize_title', 'trim' );
add_filter( 'sanitize_title', 'strtolower' );
add_filter( 'sanitize_title', function ( $title ) {
\treturn preg_replace( '/\\s+/', '-', $title );
} );

echo apply_filters( 'sanitize_title', '  My Post Title  ' ) . "\\n";'''))),
        ('Priority ordering and multi-arg passing',
            '<p>Lower priority numbers run first. The fourth argument to <code>add_filter</code> controls how many context values get passed to the callback.</p>',
            ('priority-args.php', php('''add_filter( 'render_price', function ( $html, $price, $currency ) {
\treturn $html . " ({$currency} markup)";
}, 30, 3 );

add_filter( 'render_price', function ( $html, $price ) {
\treturn "<strong>{$html}</strong>";
}, 10, 2 );

add_filter( 'render_price', function ( $html, $price, $currency ) {
\tif ( 'EUR' === $currency ) return $html . ' EUR';
\treturn $html . " {$currency}";
}, 20, 3 );

echo apply_filters( 'render_price', '19.99', 19.99, 'EUR' ) . "\\n";'''))),
        ('Hook-based extension points in standalone libraries',
            '<p>Use <code>do_action</code> and <code>apply_filters</code> as cheap extension points in your own code, without depending on WordPress.</p>',
            ('library-hooks.php', php('''class ImportPipeline {
\tpublic function process( array $row ) {
\t\t$row = apply_filters( 'import_pipeline_normalize', $row );
\t\tdo_action( 'import_pipeline_row_processed', $row );
\t\treturn $row;
\t}
}

add_filter( 'import_pipeline_normalize', function ( $row ) {
\t$row['email'] = strtolower( trim( $row['email'] ) );
\treturn $row;
} );

$log = array();
add_action( 'import_pipeline_row_processed', function ( $row ) use ( &$log ) {
\t$log[] = $row['email'];
} );

$pipeline = new ImportPipeline();
$pipeline->process( array( 'email' => '  USER@EXAMPLE.COM  ' ) );
$pipeline->process( array( 'email' => 'OTHER@example.com' ) );

echo implode( "\\n", $log ) . "\\n";'''))),
    ]))

# ===========================================================================
# Blueprints
# ===========================================================================
COMPONENTS.append(('blueprints', 'Blueprints',
    'Declarative WordPress site provisioning. Write a JSON description of plugins, options, and content; let the runner execute it.',
    'wp-php-toolkit/blueprints',
    [
        ('Why this exists',
            '<p>A WordPress environment is more than a database dump. It can require a specific core version, plugins, themes, site options, uploaded files, content, and setup steps. Rebuilding that by hand makes demos, tests, bug reports, workshops, and CI fixtures drift over time.</p>'
            '<p>The Blueprints component treats site setup as data. A blueprint JSON document describes the desired steps, and the runner applies them to either a new WordPress install or an existing one. The validator exists because user-authored JSON needs clear, path-specific errors rather than generic schema failures.</p>'
            '<p><code>RunnerConfiguration</code> separates the web root from the WordPress core directory, since real hosts often put them in different places. Both paths are explicit on the runner, never inferred.</p>'
            '<p>Blueprints can <em>create</em> a new WordPress install (download core, set up the database, apply steps) or <em>apply to an existing</em> site. Creating a fresh install needs filesystem access this in-browser runtime doesn\'t have, so the runnable snippets focus on <code>APPLY_TO_EXISTING_SITE</code>.</p>',
            None),
        ('Configure a runner for an existing site',
            '<p><code>RunnerConfiguration</code> is a fluent builder. The minimum: target site root, target site URL, execution mode.</p>',
            ('configure.php', php('''use WordPress\\Blueprints\\Runner;
use WordPress\\Blueprints\\RunnerConfiguration;

$config = ( new RunnerConfiguration() )
\t->set_execution_mode( Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE )
\t->set_target_site_root( '/wordpress' )
\t->set_target_site_url( 'http://playground.test/' );

echo "mode: " . $config->get_execution_mode() . "\\n";
echo "root: " . $config->get_target_site_root() . "\\n";
echo "url:  " . $config->get_target_site_url() . "\\n";'''))),
        ('Generate blueprint JSON from PHP',
            '<p>CI jobs and tests stay clearer when PHP builds the blueprint from data instead of hand-writing JSON. Keep the structure plain: <code>version</code>, then a list of step arrays.</p>',
            ('build-json.php', php('''$site_name = 'Demo Site';
$plugins   = array( 'gutenberg', 'classic-editor' );

$blueprint = array(
\t'version' => 2,
\t'steps'   => array(
\t\tarray(
\t\t\t'step'    => 'setSiteOptions',
\t\t\t'options' => array(
\t\t\t\t'blogname'              => $site_name,
\t\t\t\t'permalink_structure'   => '/%postname%/',
\t\t\t\t'show_on_front'         => 'page',
\t\t\t),
\t\t),
\t),
);

foreach ( $plugins as $slug ) {
\t$blueprint['steps'][] = array(
\t\t'step'       => 'installPlugin',
\t\t'pluginData' => "https://downloads.wordpress.org/plugin/{$slug}.zip",
\t);
\t$blueprint['steps'][] = array(
\t\t'step'   => 'activatePlugin',
\t\t'plugin' => "{$slug}/{$slug}.php",
\t);
}

echo json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\\n";'''))),
        ('Validate before running',
            '<p>The schema validator returns a human-readable <code>ValidationError</code> instead of a generic "does not match schema" failure. Use it before handing user-authored JSON to a runner.</p>',
            ('validate.php', php('''use WordPress\\Blueprints\\Validator\\HumanFriendlySchemaValidator;

$schema = array(
\t'type'       => 'object',
\t'required'   => array( 'version', 'steps' ),
\t'properties' => array(
\t\t'version' => array( 'type' => 'integer' ),
\t\t'steps'   => array(
\t\t\t'type'  => 'array',
\t\t\t'items' => array(
\t\t\t\t'type'       => 'object',
\t\t\t\t'required'   => array( 'step' ),
\t\t\t\t'properties' => array(
\t\t\t\t\t'step' => array( 'type' => 'string' ),
\t\t\t\t),
\t\t\t),
\t\t),
\t),
);

$blueprint = array(
\t'version' => 2,
\t'steps'   => array(
\t\tarray( 'pluginData' => 'https://downloads.wordpress.org/plugin/gutenberg.zip' ),
\t),
);

$error = ( new HumanFriendlySchemaValidator( $schema ) )->validate( $blueprint );
if ( null === $error ) {
\techo "valid\\n";
} else {
\techo $error->get_pretty_path() . ": " . $error->message . "\\n";
}'''))),
        ('The Blueprint JSON shape',
            '<p>A blueprint is a JSON document with a <code>version</code> field and a <code>steps</code> array. Each step has a <code>"step"</code> discriminator and step-specific fields. This is the same shape used by <a href="https://playground.wordpress.net/">WordPress Playground</a>.</p>'
            '<pre><code>{\n  "version": 2,\n  "steps": [\n    { "step": "setSiteOptions",\n      "options": {\n        "blogname": "Demo Site",\n        "permalink_structure": "/%postname%/"\n      } },\n    { "step": "installPlugin",\n      "pluginData": "https://downloads.wordpress.org/plugin/gutenberg.zip" },\n    { "step": "activatePlugin",\n      "plugin": "gutenberg/gutenberg.php" }\n  ]\n}</code></pre>',
            None),
    ]))

# ===========================================================================
# ToolkitCodingStandards
# ===========================================================================
COMPONENTS.append(('coding-standards', 'ToolkitCodingStandards',
    'PHP_CodeSniffer sniffs used by this project: enforce Yoda comparisons and ban the short ternary where it hides falsy-value bugs.',
    'wp-php-toolkit/toolkit-coding-standards',
    [
        ('Why this exists',
            '<p>This package is not a general-purpose style guide. It holds project-specific PHP_CodeSniffer rules for review comments the toolkit wants automated: comparisons should follow the WordPress Yoda style, and short ternaries should not hide whether a fallback is meant for <code>null</code> only or for all falsy values.</p>'
            '<p>Use it in this monorepo, or in a project that intentionally wants the same review tradeoffs. If your project does not follow WordPress-style comparisons, the Yoda sniff is probably the wrong rule for you.</p>',
            None),
        ('Reference the standard from your phpcs.xml',
            '<p>The component is a PHPCS ruleset, so the useful examples are configuration and before/after code rather than runtime snippets. Activate both sniffs at once by referencing <code>WordPressToolkitCodingStandards</code>:</p>'
            '<pre><code>&lt;?xml version="1.0"?&gt;\n&lt;ruleset name="My Project"&gt;\n  &lt;file&gt;src/&lt;/file&gt;\n\n  &lt;!-- Activate both toolkit sniffs --&gt;\n  &lt;rule ref="WordPressToolkitCodingStandards"/&gt;\n\n  &lt;!-- Or pick them individually --&gt;\n  &lt;!-- &lt;rule ref="WordPressToolkitCodingStandards.PHP.EnforceYodaComparison"/&gt; --&gt;\n  &lt;!-- &lt;rule ref="WordPressToolkitCodingStandards.PHP.DisallowShortTernary"/&gt; --&gt;\n&lt;/ruleset&gt;</code></pre>'
            '<p>Then run phpcs and phpcbf the usual way:</p>'
            '<pre><code>vendor/bin/phpcs --standard=phpcs.xml .\nvendor/bin/phpcbf --standard=phpcs.xml .</code></pre>',
            None),
        ('EnforceYodaComparison: catches accidental assignment',
            '<p>Yoda comparisons (<code>true === $x</code>) make typo-induced assignments easier to catch and match the WordPress style used throughout the toolkit:</p>'
            '<pre><code>// Bug: single = inside a condition. Always truthy, mutates $status.\nif ( $status = \'published\' ) {\n    publish_post( $post );\n}\n\n// Yoda style: writing this typo would be a parse error.\nif ( \'published\' === $status ) {\n    publish_post( $post );\n}</code></pre>'
            '<p>The sniff covers <code>===</code>, <code>!==</code>, <code>==</code>, and <code>!=</code>, and stays quiet when both sides are dynamic.</p>',
            None),
        ('Why ban the short ternary',
            '<p>Developers confuse the short ternary (<code>$a ?: $b</code>) with the null-coalescing operator (<code>$a ?? $b</code>). They differ on falsy-but-not-null values: <code>0 ?: \'fallback\'</code> returns <code>\'fallback\'</code>, but <code>0 ?? \'fallback\'</code> returns <code>0</code>. The sniff bans <code>?:</code> entirely so reviewers don\'t have to relitigate this on every PR.</p>',
            None),
        ('Review-friendly replacements',
            '<p>When the fallback should apply only to <code>null</code>, use <code>??</code>. When the fallback should apply to every falsy value, write the full ternary so the intent is visible in review.</p>'
            '<pre><code>// Only missing values fall back. 0 and "" are preserved.\n$limit = $request_limit ?? 20;\n\n// Any falsy value falls back. The duplicated condition is intentional.\n$title = $raw_title ? $raw_title : \'Untitled\';</code></pre>',
            None),
    ]))
