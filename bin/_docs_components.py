# Component catalog for the runnable docs site.
#
# Per-component content (lede, sections, snippets, credit callouts,
# see-also links, expected snippet outputs) is sourced from each
# components/<Name>/README.md — see bin/_load_catalog.py for the format.
# The README *is* the catalog source: GitHub and Packagist render it as
# a normal README (frontmatter is hidden by GitHub's renderer); the
# build pipeline parses the frontmatter + snippet metadata blocks to
# generate the docs site and run snippets in CI.
#
# This file still owns the small global metadata that doesn't belong in any
# single component's markdown: the landing-page starter paths and the
# per-component mental-model guides used on the landing page.

import os as _os
import sys as _sys

_sys.path.insert(0, _os.path.dirname(_os.path.abspath(__file__)))

from _load_catalog import load_components  # noqa: E402

COMPONENTS = load_components()



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
