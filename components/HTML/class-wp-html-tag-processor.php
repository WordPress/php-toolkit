<?php
/**
 * HTML API: WP_HTML_Tag_Processor class
 *
 * Scans through an HTML document to find specific tags, then
 * transforms those tags by adding, removing, or updating the
 * values of the HTML attributes within that tag (opener).
 *
 * Does not fully parse HTML or _recurse_ into the HTML structure
 * Instead this scans linearly through a document and only parses
 * the HTML tag openers.
 *
 * ### Possible future direction for this module
 *
 *  - Prune the whitespace when removing classes/attributes: e.g. "a b c" -> "c" not " c".
 *    This would increase the size of the changes for some operations but leave more
 *    natural-looking output HTML.
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 6.2.0
 */

/**
 * Core class used to modify attributes in an HTML document for tags matching a query.
 *
 * ## Usage
 *
 * Use of this class requires three steps:
 *
 *  1. Create a new class instance with your input HTML document.
 *  2. Find the tag(s) you are looking for.
 *  3. Request changes to the attributes in those tag(s).
 *
 * Example:
 *
 *     $tags = new WP_HTML_Tag_Processor( $html );
 *     if ( $tags->next_tag( 'option' ) ) {
 *         $tags->set_attribute( 'selected', true );
 *     }
 *
 * ### Finding tags
 *
 * The `next_tag()` function moves the internal cursor through
 * your input HTML document until it finds a tag meeting any of
 * the supplied restrictions in the optional query argument. If
 * no argument is provided then it will find the next HTML tag,
 * regardless of what kind it is.
 *
 * If you want to _find whatever the next tag is_:
 *
 *     $tags->next_tag();
 *
 * | Goal                                                      | Query                                                                           |
 * |-----------------------------------------------------------|---------------------------------------------------------------------------------|
 * | Find any tag.                                             | `$tags->next_tag();`                                                            |
 * | Find next image tag.                                      | `$tags->next_tag( array( 'tag_name' => 'img' ) );`                              |
 * | Find next image tag (without passing the array).          | `$tags->next_tag( 'img' );`                                                     |
 * | Find next tag containing the `fullwidth` CSS class.       | `$tags->next_tag( array( 'class_name' => 'fullwidth' ) );`                      |
 * | Find next image tag containing the `fullwidth` CSS class. | `$tags->next_tag( array( 'tag_name' => 'img', 'class_name' => 'fullwidth' ) );` |
 *
 * If a tag was found meeting your criteria then `next_tag()`
 * will return `true` and you can proceed to modify it. If it
 * returns `false`, however, it failed to find the tag and
 * moved the cursor to the end of the file.
 *
 * Once the cursor reaches the end of the file the processor
 * is done and if you want to reach an earlier tag you will
 * need to recreate the processor and start over, as it's
 * unable to back up or move in reverse.
 *
 * See the section on bookmarks for an exception to this
 * no-backing-up rule.
 *
 * #### Custom queries
 *
 * Sometimes it's necessary to further inspect an HTML tag than
 * the query syntax here permits. In these cases one may further
 * inspect the search results using the read-only functions
 * provided by the processor or external state or variables.
 *
 * Example:
 *
 *     // Paint up to the first five DIV or SPAN tags marked with the "jazzy" style.
 *     $remaining_count = 5;
 *     while ( $remaining_count > 0 && $tags->next_tag() ) {
 *         if (
 *              ( 'DIV' === $tags->get_tag() || 'SPAN' === $tags->get_tag() ) &&
 *              'jazzy' === $tags->get_attribute( 'data-style' )
 *         ) {
 *             $tags->add_class( 'theme-style-everest-jazz' );
 *             $remaining_count--;
 *         }
 *     }
 *
 * `get_attribute()` will return `null` if the attribute wasn't present
 * on the tag when it was called. It may return `""` (the empty string)
 * in cases where the attribute was present but its value was empty.
 * For boolean attributes, those whose name is present but no value is
 * given, it will return `true` (the only way to set `false` for an
 * attribute is to remove it).
 *
 * #### When matching fails
 *
 * When `next_tag()` returns `false` it could mean different things:
 *
 *  - The requested tag wasn't found in the input document.
 *  - The input document ended in the middle of an HTML syntax element.
 *
 * When a document ends in the middle of a syntax element it will pause
 * the processor. This is to make it possible in the future to extend the
 * input document and proceed - an important requirement for chunked
 * streaming parsing of a document.
 *
 * Example:
 *
 *     $processor = new WP_HTML_Tag_Processor( 'This <div is="a" partial="token' );
 *     false === $processor->next_tag();
 *
 * If a special element (see next section) is encountered but no closing tag
 * is found it will count as an incomplete tag. The parser will pause as if
 * the opening tag were incomplete.
 *
 * Example:
 *
 *     $processor = new WP_HTML_Tag_Processor( '<style>// there could be more styling to come' );
 *     false === $processor->next_tag();
 *
 *     $processor = new WP_HTML_Tag_Processor( '<style>// this is everything</style><div>' );
 *     true === $processor->next_tag( 'DIV' );
 *
 * #### Special self-contained elements
 *
 * Some HTML elements are handled in a special way; their start and end tags
 * act like a void tag. These are special because their contents can't contain
 * HTML markup. Everything inside these elements is handled in a special way
 * and content that _appears_ like HTML tags inside of them isn't. There can
 * be no nesting in these elements.
 *
 * In the following list, "raw text" means that all of the content in the HTML
 * until the matching closing tag is treated verbatim without any replacements
 * and without any parsing.
 *
 *  - IFRAME allows no content but requires a closing tag.
 *  - NOEMBED (deprecated) content is raw text.
 *  - NOFRAMES (deprecated) content is raw text.
 *  - SCRIPT content is plaintext apart from legacy rules allowing `</script>` inside an HTML comment.
 *  - STYLE content is raw text.
 *  - TITLE content is plain text but character references are decoded.
 *  - TEXTAREA content is plain text but character references are decoded.
 *  - XMP (deprecated) content is raw text.
 *
 * ### Modifying HTML attributes for a found tag
 *
 * Once you've found the start of an opening tag you can modify
 * any number of the attributes on that tag. You can set a new
 * value for an attribute, remove the entire attribute, or do
 * nothing and move on to the next opening tag.
 *
 * Example:
 *
 *     if ( $tags->next_tag( array( 'class_name' => 'wp-group-block' ) ) ) {
 *         $tags->set_attribute( 'title', 'This groups the contained content.' );
 *         $tags->remove_attribute( 'data-test-id' );
 *     }
 *
 * If `set_attribute()` is called for an existing attribute it will
 * overwrite the existing value. Similarly, calling `remove_attribute()`
 * for a non-existing attribute has no effect on the document. Both
 * of these methods are safe to call without knowing if a given attribute
 * exists beforehand.
 *
 * ### Modifying CSS classes for a found tag
 *
 * The tag processor treats the `class` attribute as a special case.
 * Because it's a common operation to add or remove CSS classes, this
 * interface adds helper methods to make that easier.
 *
 * As with attribute values, adding or removing CSS classes is a safe
 * operation that doesn't require checking if the attribute or class
 * exists before making changes. If removing the only class then the
 * entire `class` attribute will be removed.
 *
 * Example:
 *
 *     // from `<span>Yippee!</span>`
 *     //   to `<span class="is-active">Yippee!</span>`
 *     $tags->add_class( 'is-active' );
 *
 *     // from `<span class="excited">Yippee!</span>`
 *     //   to `<span class="excited is-active">Yippee!</span>`
 *     $tags->add_class( 'is-active' );
 *
 *     // from `<span class="is-active heavy-accent">Yippee!</span>`
 *     //   to `<span class="is-active heavy-accent">Yippee!</span>`
 *     $tags->add_class( 'is-active' );
 *
 *     // from `<input type="text" class="is-active rugby not-disabled" length="24">`
 *     //   to `<input type="text" class="is-active not-disabled" length="24">
 *     $tags->remove_class( 'rugby' );
 *
 *     // from `<input type="text" class="rugby" length="24">`
 *     //   to `<input type="text" length="24">
 *     $tags->remove_class( 'rugby' );
 *
 *     // from `<input type="text" length="24">`
 *     //   to `<input type="text" length="24">
 *     $tags->remove_class( 'rugby' );
 *
 * When class changes are enqueued but a direct change to `class` is made via
 * `set_attribute` then the changes to `set_attribute` (or `remove_attribute`)
 * will take precedence over those made through `add_class` and `remove_class`.
 *
 * ### Bookmarks
 *
 * While scanning through the input HTMl document it's possible to set
 * a named bookmark when a particular tag is found. Later on, after
 * continuing to scan other tags, it's possible to `seek` to one of
 * the set bookmarks and then proceed again from that point forward.
 *
 * Because bookmarks create processing overhead one should avoid
 * creating too many of them. As a rule, create only bookmarks
 * of known string literal names; avoid creating "mark_{$index}"
 * and so on. It's fine from a performance standpoint to create a
 * bookmark and update it frequently, such as within a loop.
 *
 *     $total_todos = 0;
 *     while ( $p->next_tag( array( 'tag_name' => 'UL', 'class_name' => 'todo' ) ) ) {
 *         $p->set_bookmark( 'list-start' );
 *         while ( $p->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
 *             if ( 'UL' === $p->get_tag() && $p->is_tag_closer() ) {
 *                 $p->set_bookmark( 'list-end' );
 *                 $p->seek( 'list-start' );
 *                 $p->set_attribute( 'data-contained-todos', (string) $total_todos );
 *                 $total_todos = 0;
 *                 $p->seek( 'list-end' );
 *                 break;
 *             }
 *
 *             if ( 'LI' === $p->get_tag() && ! $p->is_tag_closer() ) {
 *                 $total_todos++;
 *             }
 *         }
 *     }
 *
 * ## Tokens and finer-grained processing.
 *
 * It's possible to scan through every lexical token in the
 * HTML document using the `next_token()` function. This
 * alternative form takes no argument and provides no built-in
 * query syntax.
 *
 * Example:
 *
 *      $title = '(untitled)';
 *      $text  = '';
 *      while ( $processor->next_token() ) {
 *          switch ( $processor->get_token_name() ) {
 *              case '#text':
 *                  $text .= $processor->get_modifiable_text();
 *                  break;
 *
 *              case 'BR':
 *                  $text .= "\n";
 *                  break;
 *
 *              case 'TITLE':
 *                  $title = $processor->get_modifiable_text();
 *                  break;
 *          }
 *      }
 *      return trim( "# {$title}\n\n{$text}" );
 *
 * ### Tokens and _modifiable text_.
 *
 * #### Special "atomic" HTML elements.
 *
 * Not all HTML elements are able to contain other elements inside of them.
 * For instance, the contents inside a TITLE element are plaintext (except
 * that character references like &amp; will be decoded). This means that
 * if the string `<img>` appears inside a TITLE element, then it's not an
 * image tag, but rather it's text describing an image tag. Likewise, the
 * contents of a SCRIPT or STYLE element are handled entirely separately in
 * a browser than the contents of other elements because they represent a
 * different language than HTML.
 *
 * For these elements the Tag Processor treats the entire sequence as one,
 * from the opening tag, including its contents, through its closing tag.
 * This means that the it's not possible to match the closing tag for a
 * SCRIPT element unless it's unexpected; the Tag Processor already matched
 * it when it found the opening tag.
 *
 * The inner contents of these elements are that element's _modifiable text_.
 *
 * The special elements are:
 *  - `SCRIPT` whose contents are treated as raw plaintext but supports a legacy
 *    style of including JavaScript inside of HTML comments to avoid accidentally
 *    closing the SCRIPT from inside a JavaScript string. E.g. `console.log( '</script>' )`.
 *  - `TITLE` and `TEXTAREA` whose contents are treated as plaintext and then any
 *    character references are decoded. E.g. `1 &lt; 2 < 3` becomes `1 < 2 < 3`.
 *  - `IFRAME`, `NOSCRIPT`, `NOEMBED`, `NOFRAME`, `STYLE` whose contents are treated as
 *    raw plaintext and left as-is. E.g. `1 &lt; 2 < 3` remains `1 &lt; 2 < 3`.
 *
 * #### Other tokens with modifiable text.
 *
 * There are also non-elements which are void/self-closing in nature and contain
 * modifiable text that is part of that individual syntax token itself.
 *
 *  - `#text` nodes, whose entire token _is_ the modifiable text.
 *  - HTML comments and tokens that become comments due to some syntax error. The
 *    text for these tokens is the portion of the comment inside of the syntax.
 *    E.g. for `<!-- comment -->` the text is `" comment "` (note the spaces are included).
 *  - `CDATA` sections, whose text is the content inside of the section itself. E.g. for
 *    `<![CDATA[some content]]>` the text is `"some content"` (with restrictions [1]).
 *  - "Funky comments," which are a special case of invalid closing tags whose name is
 *    invalid. The text for these nodes is the text that a browser would transform into
 *    an HTML comment when parsing. E.g. for `</%post_author>` the text is `%post_author`.
 *  - `DOCTYPE` declarations like `<DOCTYPE html>` which have no closing tag.
 *  - XML Processing instruction nodes like `<?wp __( "Like" ); ?>` (with restrictions [2]).
 *  - The empty end tag `</>` which is ignored in the browser and DOM.
 *
 * [1]: There are no CDATA sections in HTML. When encountering `<![CDATA[`, everything
 *      until the next `>` becomes a bogus HTML comment, meaning there can be no CDATA
 *      section in an HTML document containing `>`. The Tag Processor will first find
 *      all valid and bogus HTML comments, and then if the comment _would_ have been a
 *      CDATA section _were they to exist_, it will indicate this as the type of comment.
 *
 * [2]: XML allows a broader range of characters in a processing instruction's target name
 *      and disallows "xml" as a name, since it's special. The Tag Processor only recognizes
 *      target names with an ASCII-representable subset of characters. It also exhibits the
 *      same constraint as with CDATA sections, in that `>` cannot exist within the token
 *      since Processing Instructions do no exist within HTML and their syntax transforms
 *      into a bogus comment in the DOM.
 *
 * ## Design and limitations
 *
 * The Tag Processor is designed to linearly scan HTML documents and tokenize
 * HTML tags and their attributes. It's designed to do this as efficiently as
 * possible without compromising parsing integrity. Therefore it will be
 * slower than some methods of modifying HTML, such as those incorporating
 * over-simplified PCRE patterns, but will not introduce the defects and
 * failures that those methods bring in, which lead to broken page renders
 * and often to security vulnerabilities. On the other hand, it will be faster
 * than full-blown HTML parsers such as DOMDocument and use considerably
 * less memory. It requires a negligible memory overhead, enough to consider
 * it a zero-overhead system.
 *
 * The performance characteristics are maintained by avoiding tree construction
 * and semantic cleanups which are specified in HTML5. Because of this, for
 * example, it's not possible for the Tag Processor to associate any given
 * opening tag with its corresponding closing tag, or to return the inner markup
 * inside an element. Systems may be built on top of the Tag Processor to do
 * this, but the Tag Processor is and should be constrained so it can remain an
 * efficient, low-level, and reliable HTML scanner.
 *
 * The Tag Processor's design incorporates a "garbage-in-garbage-out" philosophy.
 * HTML5 specifies that certain invalid content be transformed into different forms
 * for display, such as removing null bytes from an input document and replacing
 * invalid characters with the Unicode replacement character `U+FFFD` (visually "�").
 * Where errors or transformations exist within the HTML5 specification, the Tag Processor
 * leaves those invalid inputs untouched, passing them through to the final browser
 * to handle. While this implies that certain operations will be non-spec-compliant,
 * such as reading the value of an attribute with invalid content, it also preserves a
 * simplicity and efficiency for handling those error cases.
 *
 * Most operations within the Tag Processor are designed to minimize the difference
 * between an input and output document for any given change. For example, the
 * `add_class` and `remove_class` methods preserve whitespace and the class ordering
 * within the `class` attribute; and when encountering tags with duplicated attributes,
 * the Tag Processor will leave those invalid duplicate attributes where they are but
 * update the proper attribute which the browser will read for parsing its value. An
 * exception to this rule is that all attribute updates store their values as
 * double-quoted strings, meaning that attributes on input with single-quoted or
 * unquoted values will appear in the output with double-quotes.
 *
 * ### Scripting Flag
 *
 * The Tag Processor parses HTML with the "scripting flag" disabled. This means
 * that it doesn't run any scripts while parsing the page. In a browser with
 * JavaScript enabled, for example, the script can change the parse of the
 * document as it loads. On the server, however, evaluating JavaScript is not
 * only impractical, but also unwanted.
 *
 * Practically this means that the Tag Processor will descend into NOSCRIPT
 * elements and process its child tags. Were the scripting flag enabled, such
 * as in a typical browser, the contents of NOSCRIPT are skipped entirely.
 *
 * This allows the HTML API to process the content that will be presented in
 * a browser when scripting is disabled, but it offers a different view of a
 * page than most browser sessions will experience. E.g. the tags inside the
 * NOSCRIPT disappear.
 *
 * ### Text Encoding
 *
 * The Tag Processor assumes that the input HTML document is encoded with a
 * text encoding compatible with 7-bit ASCII's '<', '>', '&', ';', '/', '=',
 * "'", '"', 'a' - 'z', 'A' - 'Z', and the whitespace characters ' ', tab,
 * carriage-return, newline, and form-feed.
 *
 * In practice, this includes almost every single-byte encoding as well as
 * UTF-8. Notably, however, it does not include UTF-16. If providing input
 * that's incompatible, then convert the encoding beforehand.
 *
 * @since 6.2.0
 * @since 6.2.1 Fix: Support for various invalid comments; attribute updates are case-insensitive.
 * @since 6.3.2 Fix: Skip HTML-like content inside rawtext elements such as STYLE.
 * @since 6.5.0 Pauses processor when input ends in an incomplete syntax token.
 *              Introduces "special" elements which act like void elements, e.g. TITLE, STYLE.
 *              Allows scanning through all tokens and processing modifiable text, where applicable.
 */
class WP_HTML_Tag_Processor {
	/**
	 * The maximum number of bookmarks allowed to exist at
	 * any given time.
	 *
	 * @since 6.2.0
	 * @var int
	 *
	 * @see WP_HTML_Tag_Processor::set_bookmark()
	 */
	const MAX_BOOKMARKS = 10;

	/**
	 * Maximum number of times seek() can be called.
	 * Prevents accidental infinite loops.
	 *
	 * @since 6.2.0
	 * @var int
	 *
	 * @see WP_HTML_Tag_Processor::seek()
	 */
	const MAX_SEEK_OPS = 1000;

	/**
	 * The HTML document to parse.
	 *
	 * @since 6.2.0
	 * @var string
	 */
	protected $html;

	/**
	 * The last query passed to next_tag().
	 *
	 * @since 6.2.0
	 * @var array|null
	 */
	private $last_query;

	/**
	 * The tag name this processor currently scans for.
	 *
	 * @since 6.2.0
	 * @var string|null
	 */
	private $sought_tag_name;

	/**
	 * The CSS class name this processor currently scans for.
	 *
	 * @since 6.2.0
	 * @var string|null
	 */
	private $sought_class_name;

	/**
	 * The match offset this processor currently scans for.
	 *
	 * @since 6.2.0
	 * @var int|null
	 */
	private $sought_match_offset;

	/**
	 * Whether to visit tag closers, e.g. </div>, when walking an input document.
	 *
	 * @since 6.2.0
	 * @var bool
	 */
	private $stop_on_tag_closers;

	/**
	 * Specifies mode of operation of the parser at any given time.
	 *
	 * | State           | Meaning                                                              |
	 * | ----------------|----------------------------------------------------------------------|
	 * | *Ready*         | The parser is ready to run.                                          |
	 * | *Complete*      | There is nothing left to parse.                                      |
	 * | *Incomplete*    | The HTML ended in the middle of a token; nothing more can be parsed. |
	 * | *Matched tag*   | Found an HTML tag; it's possible to modify its attributes.           |
	 * | *Text node*     | Found a #text node; this is plaintext and modifiable.                |
	 * | *CDATA node*    | Found a CDATA section; this is modifiable.                           |
	 * | *Comment*       | Found a comment or bogus comment; this is modifiable.                |
	 * | *Presumptuous*  | Found an empty tag closer: `</>`.                                    |
	 * | *Funky comment* | Found a tag closer with an invalid tag name; this is modifiable.     |
	 *
	 * @since 6.5.0
	 *
	 * @see WP_HTML_Tag_Processor::STATE_READY
	 * @see WP_HTML_Tag_Processor::STATE_COMPLETE
	 * @see WP_HTML_Tag_Processor::STATE_INCOMPLETE_INPUT
	 * @see WP_HTML_Tag_Processor::STATE_MATCHED_TAG
	 * @see WP_HTML_Tag_Processor::STATE_TEXT_NODE
	 * @see WP_HTML_Tag_Processor::STATE_CDATA_NODE
	 * @see WP_HTML_Tag_Processor::STATE_COMMENT
	 * @see WP_HTML_Tag_Processor::STATE_DOCTYPE
	 * @see WP_HTML_Tag_Processor::STATE_PRESUMPTUOUS_TAG
	 * @see WP_HTML_Tag_Processor::STATE_FUNKY_COMMENT
	 *
	 * @var string
	 */
	protected $parser_state = self::STATE_READY;

	/**
	 * Indicates if the document is in quirks mode or no-quirks mode.
	 *
	 *  Impact on HTML parsing:
	 *
	 *   - In `NO_QUIRKS_MODE` (also known as "standard mode"):
	 *       - CSS class and ID selectors match byte-for-byte (case-sensitively).
	 *       - A TABLE start tag `<table>` implicitly closes any open `P` element.
	 *
	 *   - In `QUIRKS_MODE`:
	 *       - CSS class and ID selectors match match in an ASCII case-insensitive manner.
	 *       - A TABLE start tag `<table>` opens a `TABLE` element as a child of a `P`
	 *         element if one is open.
	 *
	 * Quirks and no-quirks mode are thus mostly about styling, but have an impact when
	 * tables are found inside paragraph elements.
	 *
	 * @see self::QUIRKS_MODE
	 * @see self::NO_QUIRKS_MODE
	 *
	 * @since 6.7.0
	 *
	 * @var string
	 */
	protected $compat_mode = self::NO_QUIRKS_MODE;

	/**
	 * Indicates whether the parser is inside foreign content,
	 * e.g. inside an SVG or MathML element.
	 *
	 * One of 'html', 'svg', or 'math'.
	 *
	 * Several parsing rules change based on whether the parser
	 * is inside foreign content, including whether CDATA sections
	 * are allowed and whether a self-closing flag indicates that
	 * an element has no content.
	 *
	 * @since 6.7.0
	 *
	 * @var string
	 */
	private $parsing_namespace = 'html';

	/**
	 * What kind of syntax token became an HTML comment.
	 *
	 * Since there are many ways in which HTML syntax can create an HTML comment,
	 * this indicates which of those caused it. This allows the Tag Processor to
	 * represent more from the original input document than would appear in the DOM.
	 *
	 * @since 6.5.0
	 *
	 * @var string|null
	 */
	protected $comment_type = null;

	/**
	 * What kind of text the matched text node represents, if it was subdivided.
	 *
	 * @see self::TEXT_IS_NULL_SEQUENCE
	 * @see self::TEXT_IS_WHITESPACE
	 * @see self::TEXT_IS_GENERIC
	 * @see self::subdivide_text_appropriately
	 *
	 * @since 6.7.0
	 *
	 * @var string
	 */
	protected $text_node_classification = self::TEXT_IS_GENERIC;

	/**
	 * How many bytes from the original HTML document have been read and parsed.
	 *
	 * This value points to the latest byte offset in the input document which
	 * has been already parsed. It is the internal cursor for the Tag Processor
	 * and updates while scanning through the HTML tokens.
	 *
	 * @since 6.2.0
	 * @var int
	 */
	private $bytes_already_parsed = 0;

	/**
	 * Byte offset in input document where current token starts.
	 *
	 * Example:
	 *
	 *     <div id="test">...
	 *     01234
	 *     - token starts at 0
	 *
	 * @since 6.5.0
	 *
	 * @var int|null
	 */
	private $token_starts_at;

	/**
	 * Byte length of current token.
	 *
	 * Example:
	 *
	 *     <div id="test">...
	 *     012345678901234
	 *     - token length is 14 - 0 = 14
	 *
	 *     a <!-- comment --> is a token.
	 *     0123456789 123456789 123456789
	 *     - token length is 17 - 2 = 15
	 *
	 * @since 6.5.0
	 *
	 * @var int|null
	 */
	private $token_length;

	/**
	 * Byte offset in input document where current tag name starts.
	 *
	 * Example:
	 *
	 *     <div id="test">...
	 *     01234
	 *      - tag name starts at 1
	 *
	 * @since 6.2.0
	 *
	 * @var int|null
	 */
	private $tag_name_starts_at;

	/**
	 * Byte length of current tag name.
	 *
	 * Example:
	 *
	 *     <div id="test">...
	 *     01234
	 *      --- tag name length is 3
	 *
	 * @since 6.2.0
	 *
	 * @var int|null
	 */
	private $tag_name_length;

	/**
	 * Byte offset into input document where current modifiable text starts.
	 *
	 * @since 6.5.0
	 *
	 * @var int
	 */
	private $text_starts_at;

	/**
	 * Byte length of modifiable text.
	 *
	 * @since 6.5.0
	 *
	 * @var int
	 */
	private $text_length;

	/**
	 * Whether the current tag is an opening tag, e.g. <div>, or a closing tag, e.g. </div>.
	 *
	 * @var bool
	 */
	private $is_closing_tag;

	/**
	 * Lazily-built index of attributes found within an HTML tag, keyed by the attribute name.
	 *
	 * Example:
	 *
	 *     // Supposing the parser is working through this content
	 *     // and stops after recognizing the `id` attribute.
	 *     // <div id="test-4" class=outline title="data:text/plain;base64=asdk3nk1j3fo8">
	 *     //                 ^ parsing will continue from this point.
	 *     $this->attributes = array(
	 *         'id' => new WP_HTML_Attribute_Token( 'id', 9, 6, 5, 11, false )
	 *     );
	 *
	 *     // When picking up parsing again, or when asking to find the
	 *     // `class` attribute we will continue and add to this array.
	 *     $this->attributes = array(
	 *         'id'    => new WP_HTML_Attribute_Token( 'id', 9, 6, 5, 11, false ),
	 *         'class' => new WP_HTML_Attribute_Token( 'class', 23, 7, 17, 13, false )
	 *     );
	 *
	 *     // Note that only the `class` attribute value is stored in the index.
	 *     // That's because it is the only value used by this class at the moment.
	 *
	 * @since 6.2.0
	 * @var WP_HTML_Attribute_Token[]
	 */
	private $attributes = array();

	/**
	 * Tracks spans of duplicate attributes on a given tag, used for removing
	 * all copies of an attribute when calling `remove_attribute()`.
	 *
	 * @since 6.3.2
	 *
	 * @var (WP_HTML_Span[])[]|null
	 */
	private $duplicate_attributes = null;

	/**
	 * Which class names to add or remove from a tag.
	 *
	 * These are tracked separately from attribute updates because they are
	 * semantically distinct, whereas this interface exists for the common
	 * case of adding and removing class names while other attributes are
	 * generally modified as with DOM `setAttribute` calls.
	 *
	 * When modifying an HTML document these will eventually be collapsed
	 * into a single `set_attribute( 'class', $changes )` call.
	 *
	 * Example:
	 *
	 *     // Add the `wp-block-group` class, remove the `wp-group` class.
	 *     $classname_updates = array(
	 *         // Indexed by a comparable class name.
	 *         'wp-block-group' => WP_HTML_Tag_Processor::ADD_CLASS,
	 *         'wp-group'       => WP_HTML_Tag_Processor::REMOVE_CLASS
	 *     );
	 *
	 * @since 6.2.0
	 * @var bool[]
	 */
	private $classname_updates = array();

	/**
	 * Tracks a semantic location in the original HTML which
	 * shifts with updates as they are applied to the document.
	 *
	 * @since 6.2.0
	 * @var WP_HTML_Span[]
	 */
	protected $bookmarks = array();

	const ADD_CLASS    = true;
	const REMOVE_CLASS = false;
	const SKIP_CLASS   = null;

	/**
	 * Lexical replacements to apply to input HTML document.
	 *
	 * "Lexical" in this class refers to the part of this class which
	 * operates on pure text _as text_ and not as HTML. There's a line
	 * between the public interface, with HTML-semantic methods like
	 * `set_attribute` and `add_class`, and an internal state that tracks
	 * text offsets in the input document.
	 *
	 * When higher-level HTML methods are called, those have to transform their
	 * operations (such as setting an attribute's value) into text diffing
	 * operations (such as replacing the sub-string from indices A to B with
	 * some given new string). These text-diffing operations are the lexical
	 * updates.
	 *
	 * As new higher-level methods are added they need to collapse their
	 * operations into these lower-level lexical updates since that's the
	 * Tag Processor's internal language of change. Any code which creates
	 * these lexical updates must ensure that they do not cross HTML syntax
	 * boundaries, however, so these should never be exposed outside of this
	 * class or any classes which intentionally expand its functionality.
	 *
	 * These are enqueued while editing the document instead of being immediately
	 * applied to avoid processing overhead, string allocations, and string
	 * copies when applying many updates to a single document.
	 *
	 * Example:
	 *
	 *     // Replace an attribute stored with a new value, indices
	 *     // sourced from the lazily-parsed HTML recognizer.
	 *     $start  = $attributes['src']->start;
	 *     $length = $attributes['src']->length;
	 *     $modifications[] = new WP_HTML_Text_Replacement( $start, $length, $new_value );
	 *
	 *     // Correspondingly, something like this will appear in this array.
	 *     $lexical_updates = array(
	 *         WP_HTML_Text_Replacement( 14, 28, 'https://my-site.my-domain/wp-content/uploads/2014/08/kittens.jpg' )
	 *     );
	 *
	 * @since 6.2.0
	 * @var WP_HTML_Text_Replacement[]
	 */
	protected $lexical_updates = array();

	/**
	 * Tracks and limits `seek()` calls to prevent accidental infinite loops.
	 *
	 * @since 6.2.0
	 * @var int
	 *
	 * @see WP_HTML_Tag_Processor::seek()
	 */
	protected $seek_count = 0;

	/**
	 * Whether the parser should skip over an immediately-following linefeed
	 * character, as is the case with LISTING, PRE, and TEXTAREA.
	 *
	 * > If the next token is a U+000A LINE FEED (LF) character token, then
	 * > ignore that token and move on to the next one. (Newlines at the start
	 * > of [these] elements are ignored as an authoring convenience.)
	 *
	 * @since 6.7.0
	 *
	 * @var int|null
	 */
	private $skip_newline_at = null;

	/**
	 * Native implementation used when the Rust extension is loaded.
	 *
	 * The native implementation currently covers the read-only streaming API.
	 * Public object identity stays on WP_HTML_Tag_Processor so existing callers
	 * continue to interact with the userland class.
	 *
	 * @since WP_VERSION
	 *
	 * @var object|null
	 */
	protected $native_processor = null;

	/**
	 * Cached token metadata from the native tag processor for the current token.
	 *
	 * @since WP_VERSION
	 *
	 * @var string|null
	 */
	private $native_tag_token_type = null;

	/**
	 * Cached native tag token name.
	 *
	 * @since WP_VERSION
	 *
	 * @var string|null
	 */
	private $native_tag_token_name = null;

	/**
	 * Cached native tag-closer state.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_tag_token_is_closer = false;

	/**
	 * Whether the loaded native tag processor can batch current-token metadata.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_tag_metadata = false;

	/**
	 * Whether the loaded native tag processor can return compact tag-kind rows.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_tag_kind = false;

	/**
	 * Whether the loaded native tag processor can return compact tag-kind rows
	 * with an attribute-name initial bitmask.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_tag_kind_with_attribute_initials = false;

	/**
	 * Whether the loaded native tag processor can return compact tag-kind rows
	 * for unrestricted `next_tag()` queries without argument marshaling.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_tag_kind_with_attribute_initials_fast = false;

	/**
	 * Whether the loaded native tag processor can return prefix-name strings.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_prefix_name_string = false;

	/**
	 * Whether the loaded native tag processor can count prefix-name matches.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_prefix_name_count = false;

	/**
	 * Whether the loaded native tag processor can remove prefix-name matches.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_prefix_name_removal = false;

	/**
	 * Whether the loaded native tag processor can summarize prefix-name matches
	 * across the remaining document in a single call.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_prefix_name_summary = false;

	/**
	 * Whether the loaded native tag processor can return chunked prefix-name
	 * summaries across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_prefix_name_summary_batch = false;

	/**
	 * Whether the loaded native tag processor can return chunked prefix-name
	 * count summaries across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_prefix_name_count_batch = false;

	/**
	 * Whether the loaded native tag processor can return chunked tag summaries
	 * across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_tag_summary_batch = false;

	/**
	 * Whether the loaded native tag processor can summarize tag inventory
	 * across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_tag_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize attribute
	 * inventory across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_attribute_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize heading inventory
	 * across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_heading_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize ID inventory
	 * across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_id_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize data-attribute
	 * inventory across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_data_attribute_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize ARIA attribute
	 * inventory across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_aria_attribute_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize class inventory
	 * across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_class_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize resource
	 * inventory across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_resource_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize image inventory
	 * across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_image_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize script inventory
	 * across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_script_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can summarize form inventory
	 * across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_form_inventory_summary = false;

	/**
	 * Whether the loaded native tag processor can return chunked tag-name
	 * summaries across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_matching_tag_summary_batch = false;

	/**
	 * Whether the loaded native tag processor can return chunked tag-name and
	 * attribute summaries across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_matching_tag_attribute_summary_batch = false;

	/**
	 * Whether the loaded native tag processor can return chunked tag-name and
	 * multi-attribute summaries across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_matching_tag_attributes_summary_batch = false;

	/**
	 * Whether the loaded native tag processor can summarize tag-name and
	 * multi-attribute matches across the remaining document.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_matching_tag_attributes_summary = false;

	/**
	 * Whether the loaded native tag processor can remove prefix-name matches
	 * across the remaining document in a single call.
	 *
	 * @since WP_VERSION
	 *
	 * @var bool
	 */
	private $native_supports_prefix_name_document_removal = false;

	/**
	 * Cached lowercase ASCII attribute-name initial bitmask for the current
	 * native tag, or null when unavailable.
	 *
	 * @since WP_VERSION
	 *
	 * @var int|null
	 */
	private $native_tag_attribute_name_initials = null;

	/**
	 * Constructor.
	 *
	 * @param  string $html  HTML to process.
	 *
	 * @since 6.2.0
	 */
	public function __construct( $html ) {
		$this->html = $html;

		if (
			self::should_use_native_processors() &&
			class_exists( 'WP_HTML_Native_Tag_Processor', false )
		) {
			$this->set_native_processor( new WP_HTML_Native_Tag_Processor( $html ) );
		}
	}

	/**
	 * Determines whether native HTML processors should back public classes.
	 *
	 * Define WP_NATIVE_APIS_DISABLE_DEFAULTS before loading the component to
	 * force the PHP implementation even when the extension is loaded.
	 *
	 * Define WP_NATIVE_APIS_ENABLE_HTML_DEFAULTS as false, or set the matching
	 * environment variable to 0, false, no, or off, to keep the HTML public
	 * classes on PHP fallback.
	 *
	 * @return bool Whether to use native processors when available.
	 */
	protected static function should_use_native_processors(): bool {
		if ( defined( 'WP_NATIVE_APIS_DISABLE_DEFAULTS' ) && WP_NATIVE_APIS_DISABLE_DEFAULTS ) {
			return false;
		}

		if ( defined( 'WP_NATIVE_APIS_ENABLE_HTML_DEFAULTS' ) ) {
			return (bool) WP_NATIVE_APIS_ENABLE_HTML_DEFAULTS;
		}

		$enable_html_defaults = getenv( 'WP_NATIVE_APIS_ENABLE_HTML_DEFAULTS' );
		if ( false !== $enable_html_defaults ) {
			return ! in_array( strtolower( $enable_html_defaults ), array( '0', 'false', 'no', 'off' ), true );
		}

		return true;
	}

	/**
	 * Replaces the native delegate used by this public wrapper.
	 *
	 * @param object|null $native_processor Native processor object.
	 */
	protected function set_native_processor( $native_processor ) {
		$this->native_processor = $native_processor;
		$this->clear_native_tag_metadata();
		$this->native_supports_tag_metadata                          = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_tag_any_metadata' )
			&& method_exists( $native_processor, 'current_token_metadata' );
		$this->native_supports_tag_kind                              = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_tag_any_kind' );
		$this->native_supports_tag_kind_with_attribute_initials      = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_tag_any_kind_and_attribute_name_initials' );
		$this->native_supports_tag_kind_with_attribute_initials_fast = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_tag_any_kind_and_attribute_name_initials_skip' )
			&& method_exists( $native_processor, 'next_tag_any_kind_and_attribute_name_initials_visit' );
		$this->native_supports_prefix_name_string                    = is_object( $native_processor )
			&& method_exists( $native_processor, 'get_attribute_names_with_prefix_string' );
		$this->native_supports_prefix_name_count                     = is_object( $native_processor )
			&& method_exists( $native_processor, 'count_attribute_names_with_prefix' );
		$this->native_supports_prefix_name_removal                   = is_object( $native_processor )
			&& method_exists( $native_processor, 'remove_attributes_with_prefix' );
		$this->native_supports_prefix_name_summary                   = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_attribute_names_with_prefix' );
		$this->native_supports_prefix_name_summary_batch             = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_tag_prefix_summary_batch' );
		$this->native_supports_prefix_name_count_batch               = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_tag_prefix_count_compact_batch' );
		$this->native_supports_tag_summary_batch                     = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_tag_compact_summary_batch' );
		$this->native_supports_tag_inventory_summary                 = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_tag_inventory' );
		$this->native_supports_attribute_inventory_summary           = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_attribute_inventory' );
		$this->native_supports_heading_inventory_summary             = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_heading_inventory' );
		$this->native_supports_id_inventory_summary                  = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_id_inventory' );
		$this->native_supports_data_attribute_inventory_summary      = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_data_attribute_inventory' );
		$this->native_supports_aria_attribute_inventory_summary      = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_aria_attribute_inventory' );
		$this->native_supports_class_inventory_summary               = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_class_inventory' );
		$this->native_supports_resource_inventory_summary            = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_resource_inventory' );
		$this->native_supports_image_inventory_summary               = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_image_inventory' );
		$this->native_supports_script_inventory_summary              = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_script_inventory' );
		$this->native_supports_form_inventory_summary                = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_form_inventory' );
		$this->native_supports_matching_tag_summary_batch            = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_matching_tag_compact_summary_batch' );
		$this->native_supports_matching_tag_attribute_summary_batch  = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_matching_tag_attribute_compact_summary_batch' );
		$this->native_supports_matching_tag_attributes_summary_batch = is_object( $native_processor )
			&& method_exists( $native_processor, 'next_matching_tag_attributes_compact_summary_batch' );
		$this->native_supports_matching_tag_attributes_summary       = is_object( $native_processor )
			&& method_exists( $native_processor, 'summarize_matching_tag_attributes' );
		$this->native_supports_prefix_name_document_removal          = is_object( $native_processor )
			&& method_exists( $native_processor, 'remove_attributes_with_prefix_from_document' );
	}

	/**
	 * Checks whether this processor is backed by a native implementation.
	 *
	 * @return bool Whether a native processor is active.
	 */
	protected function has_native_processor(): bool {
		return null !== $this->native_processor;
	}

	/**
	 * Clears cached native tag metadata for the current token.
	 */
	private function clear_native_tag_metadata() {
		$this->native_tag_token_type              = null;
		$this->native_tag_token_name              = null;
		$this->native_tag_token_is_closer         = false;
		$this->native_tag_attribute_name_initials = null;
	}

	/**
	 * Caches a metadata row exported by the native tag processor.
	 *
	 * The native row is exported as a unit-separator-delimited string:
	 * token type, token name, closer marker, then optional breadcrumbs.
	 *
	 * @param string[]|string|null $metadata Native metadata row.
	 * @return bool Whether metadata was cached.
	 */
	private function cache_native_tag_metadata( $metadata ) {
		if ( is_string( $metadata ) ) {
			$metadata = explode( "\x1f", $metadata );
		}

		if ( ! is_array( $metadata ) || count( $metadata ) < 3 ) {
			$this->clear_native_tag_metadata();

			return false;
		}

		$this->native_tag_token_type              = (string) $metadata[0];
		$this->native_tag_token_name              = (string) $metadata[1];
		$this->native_tag_token_is_closer         = '1' === (string) $metadata[2];
		$this->native_tag_attribute_name_initials = null;

		return true;
	}

	/**
	 * Maps the current native token type to the public parser state.
	 */
	private function update_parser_state_from_native_token() {
		$token_type = null !== $this->native_tag_token_type
			? $this->native_tag_token_type
			: $this->native_processor->get_token_type();

		switch ( $token_type ) {
			case '#tag':
				$this->parser_state = self::STATE_MATCHED_TAG;
				return;

			case '#text':
				$this->parser_state = self::STATE_TEXT_NODE;
				return;

			case '#comment':
				$this->parser_state = self::STATE_COMMENT;
				return;

			case '#doctype':
				$this->parser_state = self::STATE_DOCTYPE;
				return;

			case '#cdata-section':
				$this->parser_state = self::STATE_CDATA_NODE;
				return;

			case '#funky-comment':
				$this->parser_state = self::STATE_FUNKY_COMMENT;
				return;

			case '#presumptuous-tag':
				$this->parser_state = self::STATE_PRESUMPTUOUS_TAG;
				return;
		}

		$this->parser_state = self::STATE_READY;
	}

	/**
	 * Maps native exhaustion or pause status to the public parser state.
	 */
	private function update_parser_state_after_native_no_match() {
		$this->parser_state = (
			method_exists( $this->native_processor, 'paused_at_incomplete_token' ) &&
			$this->native_processor->paused_at_incomplete_token()
		)
			? self::STATE_INCOMPLETE_INPUT
			: self::STATE_COMPLETE;
	}

	/**
	 * Caches the final compact tag-summary row as the current native tag.
	 *
	 * @param string $batch Compact tag-summary batch.
	 * @return void
	 */
	private function cache_native_final_compact_tag_summary( $batch ) {
		$last_record_at = strrpos( $batch, "\x1e" );
		$last_record    = false === $last_record_at ? $batch : substr( $batch, $last_record_at + 1 );
		$parts          = explode( "\x1f", $last_record, 3 );

		if ( count( $parts ) < 2 ) {
			$this->clear_native_tag_metadata();
			$this->parser_state = self::STATE_READY;

			return;
		}

		$this->native_tag_token_type              = '#tag';
		$this->native_tag_token_name              = $parts[0];
		$this->native_tag_token_is_closer         = '1' === $parts[1];
		$this->native_tag_attribute_name_initials = null;
		$this->parser_state                       = self::STATE_MATCHED_TAG;
	}

	/**
	 * Checks whether a native compact row batch ended before reaching the limit.
	 *
	 * @param string $batch    Compact row batch.
	 * @param int    $max_rows Maximum requested rows.
	 * @return bool Whether the native scan reached the end of input.
	 */
	private function native_compact_batch_reached_end( $batch, $max_rows ) {
		return substr_count( $batch, "\x1e" ) + 1 < $max_rows;
	}

	/**
	 * Switches parsing mode into a new namespace, such as when
	 * encountering an SVG tag and entering foreign content.
	 *
	 * @param  string $new_namespace  One of 'html', 'svg', or 'math' indicating into what
	 *                             namespace the next tokens will be processed.
	 *
	 * @return bool Whether the namespace was valid and changed.
	 * @since 6.7.0
	 */
	public function change_parsing_namespace( string $new_namespace ): bool {
		if ( ! in_array( $new_namespace, array( 'html', 'math', 'svg' ), true ) ) {
			return false;
		}

		$this->parsing_namespace = $new_namespace;

		return true;
	}

	/**
	 * Finds the next tag matching the $query.
	 *
	 * @param  array|string|null $query  {
	 *    Optional. Which tag name to find, having which class, etc. Default is to find any tag.
	 *
	 * @type string|null $tag_name Which tag to find, or `null` for "any tag."
	 * @type int|null $match_offset Find the Nth tag matching all search criteria.
	 *                                     1 for "first" tag, 3 for "third," etc.
	 *                                     Defaults to first tag.
	 * @type string|null $class_name Tag must contain this whole class name to match.
	 * @type string|null $tag_closers "visit" or "skip": whether to stop on tag closers, e.g. </div>.
	 * }
	 * @return bool Whether a tag was matched.
	 * @since 6.2.0
	 * @since 6.5.0 No longer processes incomplete tokens at end of document; pauses the processor at start of token.
	 */
	public function next_tag( $query = null ): bool {
		if ( null !== $this->native_processor ) {
			if (
				$this->native_supports_tag_kind_with_attribute_initials &&
				array( 'tag_closers' => 'visit' ) === $query
			) {
				$tag_kind_row = $this->native_supports_tag_kind_with_attribute_initials_fast
					? $this->native_processor->next_tag_any_kind_and_attribute_name_initials_visit()
					: $this->native_processor->next_tag_any_kind_and_attribute_name_initials( true, 1 );

				$tag_kind = $tag_kind_row & 3;
				if ( 0 === $tag_kind ) {
					$this->clear_native_tag_metadata();
					$this->update_parser_state_after_native_no_match();

					return false;
				}

				$this->native_tag_token_type              = '#tag';
				$this->native_tag_token_name              = null;
				$this->native_tag_token_is_closer         = 2 === $tag_kind;
				$this->native_tag_attribute_name_initials = $tag_kind_row >> 2;
				$this->parser_state                       = self::STATE_MATCHED_TAG;

				return true;
			}

			return $this->native_next_tag( $query );
		}

		$this->parse_query( $query );
		$already_found = 0;

		do {
			if ( false === $this->next_token() ) {
				return false;
			}

			if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
				continue;
			}

			if ( $this->matches() ) {
				++$already_found;
			}
		} while ( $already_found < $this->sought_match_offset );

		return true;
	}

	/**
	 * Finds the next tag using the native delegate.
	 *
	 * This supports the same first-slice query fields covered by the native
	 * conformance suite: tag name, class name, match offset, and tag closers.
	 *
	 * @param  array|string|null $query Which tag to find.
	 * @return bool Whether a tag was matched.
	 */
	private function native_next_tag( $query = null ): bool {
		if ( $this->native_supports_tag_kind_with_attribute_initials ) {
			if ( null === $query ) {
				$tag_kind_row = $this->native_supports_tag_kind_with_attribute_initials_fast
					? $this->native_processor->next_tag_any_kind_and_attribute_name_initials_skip()
					: $this->native_processor->next_tag_any_kind_and_attribute_name_initials( false, 1 );

				$tag_kind = $tag_kind_row & 3;
				if ( 0 === $tag_kind ) {
					$this->clear_native_tag_metadata();
					$this->update_parser_state_after_native_no_match();

					return false;
				}

				$this->native_tag_token_type              = '#tag';
				$this->native_tag_token_name              = null;
				$this->native_tag_token_is_closer         = 2 === $tag_kind;
				$this->native_tag_attribute_name_initials = $tag_kind_row >> 2;
				$this->parser_state                       = self::STATE_MATCHED_TAG;

				return true;
			}

			if (
				is_array( $query ) &&
				isset( $query['tag_closers'] ) &&
				'visit' === $query['tag_closers'] &&
				! isset( $query['tag_name'] ) &&
				! isset( $query['class_name'] ) &&
				! isset( $query['match_offset'] )
			) {
				$tag_kind_row = $this->native_supports_tag_kind_with_attribute_initials_fast
					? $this->native_processor->next_tag_any_kind_and_attribute_name_initials_visit()
					: $this->native_processor->next_tag_any_kind_and_attribute_name_initials( true, 1 );

				$tag_kind = $tag_kind_row & 3;
				if ( 0 === $tag_kind ) {
					$this->clear_native_tag_metadata();
					$this->update_parser_state_after_native_no_match();

					return false;
				}

				$this->native_tag_token_type              = '#tag';
				$this->native_tag_token_name              = null;
				$this->native_tag_token_is_closer         = 2 === $tag_kind;
				$this->native_tag_attribute_name_initials = $tag_kind_row >> 2;
				$this->parser_state                       = self::STATE_MATCHED_TAG;

				return true;
			}

			if ( is_array( $query ) ) {
				$has_tag_name     = isset( $query['tag_name'] ) && is_string( $query['tag_name'] );
				$has_class_name   = isset( $query['class_name'] ) && is_string( $query['class_name'] );
				$has_match_offset = isset( $query['match_offset'] ) && is_int( $query['match_offset'] ) && 0 < $query['match_offset'];

				if ( ! $has_tag_name && ! $has_class_name && ! $has_match_offset ) {
					$visit_closers = isset( $query['tag_closers'] ) && 'visit' === $query['tag_closers'];
					$tag_kind_row  = $this->native_supports_tag_kind_with_attribute_initials_fast && ! $visit_closers
						? $this->native_processor->next_tag_any_kind_and_attribute_name_initials_skip()
						: $this->native_processor->next_tag_any_kind_and_attribute_name_initials( $visit_closers, 1 );

					$tag_kind = $tag_kind_row & 3;
					if ( 0 === $tag_kind ) {
						$this->clear_native_tag_metadata();
						$this->update_parser_state_after_native_no_match();

						return false;
					}

					$this->native_tag_token_type              = '#tag';
					$this->native_tag_token_name              = null;
					$this->native_tag_token_is_closer         = 2 === $tag_kind;
					$this->native_tag_attribute_name_initials = $tag_kind_row >> 2;
					$this->parser_state                       = self::STATE_MATCHED_TAG;

					return true;
				}
			}
		}

		$this->clear_native_tag_metadata();

		if ( is_string( $query ) ) {
			$query = array( 'tag_name' => $query );
		}

		if ( null === $query ) {
			$query = array();
		}

		if ( ! is_array( $query ) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Please pass a query array to this function.' ),
				'6.2.0'
			);
			$query = array();
		}

		$tag_name      = isset( $query['tag_name'] ) && is_string( $query['tag_name'] ) ? strtoupper( $query['tag_name'] ) : null;
		$class_name    = isset( $query['class_name'] ) && is_string( $query['class_name'] ) ? $query['class_name'] : null;
		$match_offset  = isset( $query['match_offset'] ) && is_int( $query['match_offset'] ) && 0 < $query['match_offset'] ? $query['match_offset'] : 1;
		$visit_closers = isset( $query['tag_closers'] ) && 'visit' === $query['tag_closers'];

		if ( null === $tag_name && null === $class_name && $this->native_supports_tag_kind_with_attribute_initials ) {
			$tag_kind_row = $this->native_processor->next_tag_any_kind_and_attribute_name_initials( $visit_closers, $match_offset );
			$tag_kind     = $tag_kind_row & 3;
			if ( 0 === $tag_kind ) {
				$this->update_parser_state_after_native_no_match();

				return false;
			}

			$this->native_tag_token_type              = '#tag';
			$this->native_tag_token_name              = null;
			$this->native_tag_token_is_closer         = 2 === $tag_kind;
			$this->native_tag_attribute_name_initials = $tag_kind_row >> 2;
			$this->parser_state                       = self::STATE_MATCHED_TAG;

			return true;
		}

		if ( null === $tag_name && null === $class_name && $this->native_supports_tag_kind ) {
			$tag_kind = $this->native_processor->next_tag_any_kind( $visit_closers, $match_offset );
			if ( 0 === $tag_kind ) {
				$this->update_parser_state_after_native_no_match();

				return false;
			}

			$this->native_tag_token_type              = '#tag';
			$this->native_tag_token_name              = null;
			$this->native_tag_token_is_closer         = 2 === $tag_kind;
			$this->native_tag_attribute_name_initials = null;
			$this->parser_state                       = self::STATE_MATCHED_TAG;

			return true;
		}

		if ( null === $tag_name && null === $class_name && $this->native_supports_tag_metadata ) {
			$matched = $this->cache_native_tag_metadata(
				$this->native_processor->next_tag_any_metadata( $visit_closers, $match_offset )
			);

			if ( ! $matched ) {
				$this->update_parser_state_after_native_no_match();

				return false;
			}

			$this->parser_state = self::STATE_MATCHED_TAG;

			return true;
		}

		if ( null === $tag_name && null === $class_name && method_exists( $this->native_processor, 'next_tag_any' ) ) {
			$matched = $this->native_processor->next_tag_any( $visit_closers, $match_offset );
			if ( ! $matched ) {
				$this->update_parser_state_after_native_no_match();

				return false;
			}

			$this->parser_state = self::STATE_MATCHED_TAG;

			return true;
		}

		while ( $this->native_processor->next_token() ) {
			if ( '#tag' !== $this->native_processor->get_token_type() ) {
				continue;
			}

			if ( ! $visit_closers && $this->native_processor->is_tag_closer() ) {
				continue;
			}

			if ( null !== $tag_name && $tag_name !== $this->native_processor->get_tag() ) {
				continue;
			}

			if ( null !== $class_name && ! $this->native_has_class( $class_name ) ) {
				continue;
			}

			if ( 0 === --$match_offset ) {
				if ( $this->native_supports_tag_metadata ) {
					$this->cache_native_tag_metadata( $this->native_processor->current_token_metadata() );
				}

				$this->parser_state = self::STATE_MATCHED_TAG;

				return true;
			}
		}

		$this->clear_native_tag_metadata();
		$this->update_parser_state_after_native_no_match();

		return false;
	}

	/**
	 * Checks for a class name on the current native token.
	 *
	 * @param string $wanted_class Class name to find.
	 * @return bool Whether the class is present.
	 */
	private function native_has_class( $wanted_class ): bool {
		$class_attribute = $this->native_processor->get_attribute( 'class' );
		if ( ! is_string( $class_attribute ) ) {
			return false;
		}

		$classes = preg_split( '/[\t\n\f\r ]+/', $class_attribute, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $classes ) {
			return false;
		}

		return in_array( $wanted_class, $classes, true );
	}

	/**
	 * Finds the next token in the HTML document.
	 *
	 * An HTML document can be viewed as a stream of tokens,
	 * where tokens are things like HTML tags, HTML comments,
	 * text nodes, etc. This method finds the next token in
	 * the HTML document and returns whether it found one.
	 *
	 * If it starts parsing a token and reaches the end of the
	 * document then it will seek to the start of the last
	 * token and pause, returning `false` to indicate that it
	 * failed to find a complete token.
	 *
	 * Possible token types, based on the HTML specification:
	 *
	 *  - an HTML tag, whether opening, closing, or void.
	 *  - a text node - the plaintext inside tags.
	 *  - an HTML comment.
	 *  - a DOCTYPE declaration.
	 *  - a processing instruction, e.g. `<?xml version="1.0" ?>`.
	 *
	 * The Tag Processor currently only supports the tag token.
	 *
	 * @return bool Whether a token was parsed.
	 * @since 6.7.0 Recognizes CDATA sections within foreign content.
	 *
	 * @since 6.5.0
	 */
	public function next_token(): bool {
		if ( $this->has_native_processor() ) {
			$this->clear_native_tag_metadata();

			$matched = $this->native_processor->next_token();
			if ( $matched ) {
				$this->update_parser_state_from_native_token();

				return true;
			}

			$this->update_parser_state_after_native_no_match();

			return false;
		}

		return $this->base_class_next_token();
	}

	/**
	 * Internal method which finds the next token in the HTML document.
	 *
	 * This method is a protected internal function which implements the logic for
	 * finding the next token in a document. It exists so that the parser can update
	 * its state without affecting the location of the cursor in the document and
	 * without triggering subclass methods for things like `next_token()`, e.g. when
	 * applying patches before searching for the next token.
	 *
	 * @return bool Whether a token was parsed.
	 * @since 6.5.0
	 *
	 * @access private
	 */
	private function base_class_next_token(): bool {
		$was_at = $this->bytes_already_parsed;
		$this->after_tag();

		// Don't proceed if there's nothing more to scan.
		if (
			self::STATE_COMPLETE === $this->parser_state ||
			self::STATE_INCOMPLETE_INPUT === $this->parser_state
		) {
			return false;
		}

		/*
		 * The next step in the parsing loop determines the parsing state;
		 * clear it so that state doesn't linger from the previous step.
		 */
		$this->parser_state = self::STATE_READY;

		if ( $this->bytes_already_parsed >= strlen( $this->html ) ) {
			$this->parser_state = self::STATE_COMPLETE;

			return false;
		}

		// Find the next tag if it exists.
		if ( false === $this->parse_next_tag() ) {
			if ( self::STATE_INCOMPLETE_INPUT === $this->parser_state ) {
				$this->bytes_already_parsed = $was_at;
			}

			return false;
		}

		/*
		 * For legacy reasons the rest of this function handles tags and their
		 * attributes. If the processor has reached the end of the document
		 * or if it matched any other token then it should return here to avoid
		 * attempting to process tag-specific syntax.
		 */
		if (
			self::STATE_INCOMPLETE_INPUT !== $this->parser_state &&
			self::STATE_COMPLETE !== $this->parser_state &&
			self::STATE_MATCHED_TAG !== $this->parser_state
		) {
			return true;
		}

		// Parse all of its attributes.
		while ( $this->parse_next_attribute() ) {
			continue;
		}

		// Ensure that the tag closes before the end of the document.
		if (
			self::STATE_INCOMPLETE_INPUT === $this->parser_state ||
			$this->bytes_already_parsed >= strlen( $this->html )
		) {
			// Does this appropriately clear state (parsed attributes)?
			$this->parser_state         = self::STATE_INCOMPLETE_INPUT;
			$this->bytes_already_parsed = $was_at;

			return false;
		}

		$tag_ends_at = strpos( $this->html, '>', $this->bytes_already_parsed );
		if ( false === $tag_ends_at ) {
			$this->parser_state         = self::STATE_INCOMPLETE_INPUT;
			$this->bytes_already_parsed = $was_at;

			return false;
		}
		$this->parser_state         = self::STATE_MATCHED_TAG;
		$this->bytes_already_parsed = $tag_ends_at + 1;
		$this->token_length         = $this->bytes_already_parsed - $this->token_starts_at;

		/*
		 * Certain tags require additional processing. The first-letter pre-check
		 * avoids unnecessary string allocation when comparing the tag names.
		 *
		 *  - IFRAME
		 *  - LISTING (deprecated)
		 *  - NOEMBED (deprecated)
		 *  - NOFRAMES (deprecated)
		 *  - PRE
		 *  - SCRIPT
		 *  - STYLE
		 *  - TEXTAREA
		 *  - TITLE
		 *  - XMP (deprecated)
		 */
		if (
			$this->is_closing_tag ||
			'html' !== $this->parsing_namespace ||
			1 !== strspn( $this->html, 'iIlLnNpPsStTxX', $this->tag_name_starts_at, 1 )
		) {
			return true;
		}

		$tag_name = $this->get_tag();

		/*
		 * For LISTING, PRE, and TEXTAREA, the first linefeed of an immediately-following
		 * text node is ignored as an authoring convenience.
		 *
		 * @see static::skip_newline_at
		 */
		if ( 'LISTING' === $tag_name || 'PRE' === $tag_name ) {
			$this->skip_newline_at = $this->bytes_already_parsed;

			return true;
		}

		/*
		 * There are certain elements whose children are not DATA but are instead
		 * RCDATA or RAWTEXT. These cannot contain other elements, and the contents
		 * are parsed as plaintext, with character references decoded in RCDATA but
		 * not in RAWTEXT.
		 *
		 * These elements are described here as "self-contained" or special atomic
		 * elements whose end tag is consumed with the opening tag, and they will
		 * contain modifiable text inside of them.
		 *
		 * Preserve the opening tag pointers, as these will be overwritten
		 * when finding the closing tag. They will be reset after finding
		 * the closing to tag to point to the opening of the special atomic
		 * tag sequence.
		 */
		$tag_name_starts_at   = $this->tag_name_starts_at;
		$tag_name_length      = $this->tag_name_length;
		$tag_ends_at          = $this->token_starts_at + $this->token_length;
		$attributes           = $this->attributes;
		$duplicate_attributes = $this->duplicate_attributes;

		// Find the closing tag if necessary.
		switch ( $tag_name ) {
			case 'SCRIPT':
				$found_closer = $this->skip_script_data();
				break;

			case 'TEXTAREA':
			case 'TITLE':
				$found_closer = $this->skip_rcdata( $tag_name );
				break;

			/*
			 * In the browser this list would include the NOSCRIPT element,
			 * but the Tag Processor is an environment with the scripting
			 * flag disabled, meaning that it needs to descend into the
			 * NOSCRIPT element to be able to properly process what will be
			 * sent to a browser.
			 *
			 * Note that this rule makes HTML5 syntax incompatible with XML,
			 * because the parsing of this token depends on client application.
			 * The NOSCRIPT element cannot be represented in the XHTML syntax.
			 */
			case 'IFRAME':
			case 'NOEMBED':
			case 'NOFRAMES':
			case 'STYLE':
			case 'XMP':
				$found_closer = $this->skip_rawtext( $tag_name );
				break;

			// No other tags should be treated in their entirety here.
			default:
				return true;
		}

		if ( ! $found_closer ) {
			$this->parser_state         = self::STATE_INCOMPLETE_INPUT;
			$this->bytes_already_parsed = $was_at;

			return false;
		}

		/*
		 * The values here look like they reference the opening tag but they reference
		 * the closing tag instead. This is why the opening tag values were stored
		 * above in a variable. It reads confusingly here, but that's because the
		 * functions that skip the contents have moved all the internal cursors past
		 * the inner content of the tag.
		 */
		$this->token_starts_at      = $was_at;
		$this->token_length         = $this->bytes_already_parsed - $this->token_starts_at;
		$this->text_starts_at       = $tag_ends_at;
		$this->text_length          = $this->tag_name_starts_at - $this->text_starts_at;
		$this->tag_name_starts_at   = $tag_name_starts_at;
		$this->tag_name_length      = $tag_name_length;
		$this->attributes           = $attributes;
		$this->duplicate_attributes = $duplicate_attributes;

		return true;
	}

	/**
	 * Whether the processor paused because the input HTML document ended
	 * in the middle of a syntax element, such as in the middle of a tag.
	 *
	 * Example:
	 *
	 *     $processor = new WP_HTML_Tag_Processor( '<input type="text" value="Th' );
	 *     false      === $processor->get_next_tag();
	 *     true       === $processor->paused_at_incomplete_token();
	 *
	 * @return bool Whether the parse paused at the start of an incomplete token.
	 * @since 6.5.0
	 */
	public function paused_at_incomplete_token(): bool {
		return self::STATE_INCOMPLETE_INPUT === $this->parser_state;
	}

	/**
	 * Generator for a foreach loop to step through each class name for the matched tag.
	 *
	 * This generator function is designed to be used inside a "foreach" loop.
	 *
	 * Example:
	 *
	 *     $p = new WP_HTML_Tag_Processor( "<div class='free &lt;egg&lt;\tlang-en'>" );
	 *     $p->next_tag();
	 *     foreach ( $p->class_list() as $class_name ) {
	 *         echo "{$class_name} ";
	 *     }
	 *     // Outputs: "free <egg> lang-en "
	 *
	 * @since 6.4.0
	 */
	public function class_list() {
		if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
			return;
		}

		/** @var string $class contains the string value of the class attribute, with character references decoded. */
		$class = $this->get_attribute( 'class' );

		if ( ! is_string( $class ) ) {
			return;
		}

		$seen = array();

		$is_quirks = self::QUIRKS_MODE === $this->compat_mode;

		$at = 0;
		while ( $at < strlen( $class ) ) {
			// Skip past any initial boundary characters.
			$at += strspn( $class, " \t\f\r\n", $at );
			if ( $at >= strlen( $class ) ) {
				return;
			}

			// Find the byte length until the next boundary.
			$length = strcspn( $class, " \t\f\r\n", $at );
			if ( 0 === $length ) {
				return;
			}

			$name = str_replace( "\x00", "\u{FFFD}", substr( $class, $at, $length ) );
			if ( $is_quirks ) {
				$name = strtolower( $name );
			}
			$at += $length;

			/*
			 * It's expected that the number of class names for a given tag is relatively small.
			 * Given this, it is probably faster overall to scan an array for a value rather
			 * than to use the class name as a key and check if it's a key of $seen.
			 */
			if ( in_array( $name, $seen, true ) ) {
				continue;
			}

			$seen[] = $name;
			yield $name;
		}
	}


	/**
	 * Returns if a matched tag contains the given ASCII case-insensitive class name.
	 *
	 * @param  string $wanted_class  Look for this CSS class name, ASCII case-insensitive.
	 *
	 * @return bool|null Whether the matched tag contains the given class name, or null if not matched.
	 * @since 6.4.0
	 */
	public function has_class( $wanted_class ): ?bool {
		if ( $this->has_native_processor() ) {
			if (
				null !== $this->native_tag_token_type &&
				'#tag' !== $this->native_tag_token_type
			) {
				return null;
			}

			if ( null !== $this->native_tag_token_type && $this->native_tag_token_is_closer ) {
				return false;
			}

			if (
				null === $this->native_tag_token_type &&
				'#tag' !== $this->native_processor->get_token_type()
			) {
				return null;
			}

			if ( null === $this->native_tag_token_type && $this->native_processor->is_tag_closer() ) {
				return false;
			}

			return $this->native_has_class( $wanted_class );
		}

		if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
			return null;
		}

		$case_insensitive = self::QUIRKS_MODE === $this->compat_mode;

		$wanted_length = strlen( $wanted_class );
		foreach ( $this->class_list() as $class_name ) {
			if (
				strlen( $class_name ) === $wanted_length &&
				0 === substr_compare( $class_name, $wanted_class, 0, strlen( $wanted_class ), $case_insensitive )
			) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Sets a bookmark in the HTML document.
	 *
	 * Bookmarks represent specific places or tokens in the HTML
	 * document, such as a tag opener or closer. When applying
	 * edits to a document, such as setting an attribute, the
	 * text offsets of that token may shift; the bookmark is
	 * kept updated with those shifts and remains stable unless
	 * the entire span of text in which the token sits is removed.
	 *
	 * Release bookmarks when they are no longer needed.
	 *
	 * Example:
	 *
	 *     <main><h2>Surprising fact you may not know!</h2></main>
	 *           ^  ^
	 *            \-|-- this `H2` opener bookmark tracks the token
	 *
	 *     <main class="clickbait"><h2>Surprising fact you may no…
	 *                             ^  ^
	 *                              \-|-- it shifts with edits
	 *
	 * Bookmarks provide the ability to seek to a previously-scanned
	 * place in the HTML document. This avoids the need to re-scan
	 * the entire document.
	 *
	 * Example:
	 *
	 *     <ul><li>One</li><li>Two</li><li>Three</li></ul>
	 *                                 ^^^^
	 *                                 want to note this last item
	 *
	 *     $p = new WP_HTML_Tag_Processor( $html );
	 *     $in_list = false;
	 *     while ( $p->next_tag( array( 'tag_closers' => $in_list ? 'visit' : 'skip' ) ) ) {
	 *         if ( 'UL' === $p->get_tag() ) {
	 *             if ( $p->is_tag_closer() ) {
	 *                 $in_list = false;
	 *                 $p->set_bookmark( 'resume' );
	 *                 if ( $p->seek( 'last-li' ) ) {
	 *                     $p->add_class( 'last-li' );
	 *                 }
	 *                 $p->seek( 'resume' );
	 *                 $p->release_bookmark( 'last-li' );
	 *                 $p->release_bookmark( 'resume' );
	 *             } else {
	 *                 $in_list = true;
	 *             }
	 *         }
	 *
	 *         if ( 'LI' === $p->get_tag() ) {
	 *             $p->set_bookmark( 'last-li' );
	 *         }
	 *     }
	 *
	 * Bookmarks intentionally hide the internal string offsets
	 * to which they refer. They are maintained internally as
	 * updates are applied to the HTML document and therefore
	 * retain their "position" - the location to which they
	 * originally pointed. The inability to use bookmarks with
	 * functions like `substr` is therefore intentional to guard
	 * against accidentally breaking the HTML.
	 *
	 * Because bookmarks allocate memory and require processing
	 * for every applied update, they are limited and require
	 * a name. They should not be created with programmatically-made
	 * names, such as "li_{$index}" with some loop. As a general
	 * rule they should only be created with string-literal names
	 * like "start-of-section" or "last-paragraph".
	 *
	 * Bookmarks are a powerful tool to enable complicated behavior.
	 * Consider double-checking that you need this tool if you are
	 * reaching for it, as inappropriate use could lead to broken
	 * HTML structure or unwanted processing overhead.
	 *
	 * @param  string $name  Identifies this particular bookmark.
	 *
	 * @return bool Whether the bookmark was successfully created.
	 * @since 6.2.0
	 */
	public function set_bookmark( $name ): bool {
		// It only makes sense to set a bookmark if the parser has paused on a concrete token.
		if (
			self::STATE_COMPLETE === $this->parser_state ||
			self::STATE_INCOMPLETE_INPUT === $this->parser_state
		) {
			return false;
		}

		if ( ! array_key_exists( $name, $this->bookmarks ) && count( $this->bookmarks ) >= static::MAX_BOOKMARKS ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Too many bookmarks: cannot create any more.' ),
				'6.2.0'
			);

			return false;
		}

		if (
			$this->has_native_processor() &&
			method_exists( $this->native_processor, 'set_bookmark' )
		) {
			if ( ! $this->native_processor->set_bookmark( $name ) ) {
				return false;
			}

			$this->bookmarks[ $name ] = new WP_HTML_Span( 0, 0 );

			return true;
		}

		$this->bookmarks[ $name ] = new WP_HTML_Span( $this->token_starts_at, $this->token_length );

		return true;
	}


	/**
	 * Removes a bookmark that is no longer needed.
	 *
	 * Releasing a bookmark frees up the small
	 * performance overhead it requires.
	 *
	 * @param  string $name  Name of the bookmark to remove.
	 *
	 * @return bool Whether the bookmark already existed before removal.
	 */
	public function release_bookmark( $name ): bool {
		if (
			$this->has_native_processor() &&
			method_exists( $this->native_processor, 'release_bookmark' )
		) {
			$released = $this->native_processor->release_bookmark( $name );
			if ( $released ) {
				unset( $this->bookmarks[ $name ] );
			}

			return $released;
		}

		if ( ! array_key_exists( $name, $this->bookmarks ) ) {
			return false;
		}

		unset( $this->bookmarks[ $name ] );

		return true;
	}

	/**
	 * Skips contents of generic rawtext elements.
	 *
	 * @param  string $tag_name  The uppercase tag name which will close the RAWTEXT region.
	 *
	 * @return bool Whether an end to the RAWTEXT region was found before the end of the document.
	 * @since 6.3.2
	 *
	 * @see https://html.spec.whatwg.org/#generic-raw-text-element-parsing-algorithm
	 */
	private function skip_rawtext( string $tag_name ): bool {
		/*
		 * These two functions distinguish themselves on whether character references are
		 * decoded, and since functionality to read the inner markup isn't supported, it's
		 * not necessary to implement these two functions separately.
		 */
		return $this->skip_rcdata( $tag_name );
	}

	/**
	 * Skips contents of RCDATA elements, namely title and textarea tags.
	 *
	 * @param  string $tag_name  The uppercase tag name which will close the RCDATA region.
	 *
	 * @return bool Whether an end to the RCDATA region was found before the end of the document.
	 * @since 6.2.0
	 *
	 * @see https://html.spec.whatwg.org/multipage/parsing.html#rcdata-state
	 */
	private function skip_rcdata( string $tag_name ): bool {
		$html       = $this->html;
		$doc_length = strlen( $html );
		$tag_length = strlen( $tag_name );

		$at = $this->bytes_already_parsed;

		while ( false !== $at && $at < $doc_length ) {
			$at                       = strpos( $this->html, '</', $at );
			$this->tag_name_starts_at = $at;

			// Fail if there is no possible tag closer.
			if ( false === $at || ( $at + $tag_length ) >= $doc_length ) {
				return false;
			}

			$at += 2;

			/*
			 * Find a case-insensitive match to the tag name.
			 *
			 * Because tag names are limited to US-ASCII there is no
			 * need to perform any kind of Unicode normalization when
			 * comparing; any character which could be impacted by such
			 * normalization could not be part of a tag name.
			 */
			for ( $i = 0; $i < $tag_length; $i++ ) {
				$tag_char  = $tag_name[ $i ];
				$html_char = $html[ $at + $i ];

				if ( $html_char !== $tag_char && strtoupper( $html_char ) !== $tag_char ) {
					$at += $i;
					continue 2;
				}
			}

			$at                        += $tag_length;
			$this->bytes_already_parsed = $at;

			if ( $at >= strlen( $html ) ) {
				return false;
			}

			/*
			 * Ensure that the tag name terminates to avoid matching on
			 * substrings of a longer tag name. For example, the sequence
			 * "</textarearug" should not match for "</textarea" even
			 * though "textarea" is found within the text.
			 */
			$c = $html[ $at ];
			if ( ' ' !== $c && "\t" !== $c && "\r" !== $c && "\n" !== $c && '/' !== $c && '>' !== $c ) {
				continue;
			}

			while ( $this->parse_next_attribute() ) {
				continue;
			}

			$at = $this->bytes_already_parsed;
			if ( $at >= strlen( $this->html ) ) {
				return false;
			}

			if ( '>' === $html[ $at ] ) {
				$this->bytes_already_parsed = $at + 1;

				return true;
			}

			if ( $at + 1 >= strlen( $this->html ) ) {
				return false;
			}

			if ( '/' === $html[ $at ] && '>' === $html[ $at + 1 ] ) {
				$this->bytes_already_parsed = $at + 2;

				return true;
			}
		}

		return false;
	}

	/**
	 * Skips contents of script tags.
	 *
	 * @return bool Whether the script tag was closed before the end of the document.
	 * @since 6.2.0
	 */
	private function skip_script_data(): bool {
		$state      = 'unescaped';
		$html       = $this->html;
		$doc_length = strlen( $html );
		$at         = $this->bytes_already_parsed;

		while ( false !== $at && $at < $doc_length ) {
			$at += strcspn( $html, '-<', $at );

			/*
			 * For all script states a "-->"  transitions
			 * back into the normal unescaped script mode,
			 * even if that's the current state.
			 */
			if (
				$at + 2 < $doc_length &&
				'-' === $html[ $at ] &&
				'-' === $html[ $at + 1 ] &&
				'>' === $html[ $at + 2 ]
			) {
				$at   += 3;
				$state = 'unescaped';
				continue;
			}

			if ( $at + 1 >= $doc_length ) {
				return false;
			}

			/*
			 * Everything of interest past here starts with "<".
			 * Check this character and advance position regardless.
			 */
			if ( '<' !== $html[ $at++ ] ) {
				continue;
			}

			/*
			 * Unlike with "-->", the "<!--" only transitions
			 * into the escaped mode if not already there.
			 *
			 * Inside the escaped modes it will be ignored; and
			 * should never break out of the double-escaped
			 * mode and back into the escaped mode.
			 *
			 * While this requires a mode change, it does not
			 * impact the parsing otherwise, so continue
			 * parsing after updating the state.
			 */
			if (
				$at + 2 < $doc_length &&
				'!' === $html[ $at ] &&
				'-' === $html[ $at + 1 ] &&
				'-' === $html[ $at + 2 ]
			) {
				$at   += 3;
				$state = 'unescaped' === $state ? 'escaped' : $state;
				continue;
			}

			if ( '/' === $html[ $at ] ) {
				$closer_potentially_starts_at = $at - 1;
				$is_closing                   = true;
				++$at;
			} else {
				$is_closing = false;
			}

			/*
			 * At this point the only remaining state-changes occur with the
			 * <script> and </script> tags; unless one of these appears next,
			 * proceed scanning to the next potential token in the text.
			 */
			if ( ! (
				$at + 6 < $doc_length &&
				( 's' === $html[ $at ] || 'S' === $html[ $at ] ) &&
				( 'c' === $html[ $at + 1 ] || 'C' === $html[ $at + 1 ] ) &&
				( 'r' === $html[ $at + 2 ] || 'R' === $html[ $at + 2 ] ) &&
				( 'i' === $html[ $at + 3 ] || 'I' === $html[ $at + 3 ] ) &&
				( 'p' === $html[ $at + 4 ] || 'P' === $html[ $at + 4 ] ) &&
				( 't' === $html[ $at + 5 ] || 'T' === $html[ $at + 5 ] )
			) ) {
				++$at;
				continue;
			}

			/*
			 * Ensure that the script tag terminates to avoid matching on
			 * substrings of a non-match. For example, the sequence
			 * "<script123" should not end a script region even though
			 * "<script" is found within the text.
			 */
			if ( $at + 6 >= $doc_length ) {
				continue;
			}
			$at += 6;
			$c   = $html[ $at ];
			if ( ' ' !== $c && "\t" !== $c && "\r" !== $c && "\n" !== $c && '/' !== $c && '>' !== $c ) {
				++$at;
				continue;
			}

			if ( 'escaped' === $state && ! $is_closing ) {
				$state = 'double-escaped';
				continue;
			}

			if ( 'double-escaped' === $state && $is_closing ) {
				$state = 'escaped';
				continue;
			}

			if ( $is_closing ) {
				$this->bytes_already_parsed = $closer_potentially_starts_at;
				$this->tag_name_starts_at   = $closer_potentially_starts_at;
				if ( $this->bytes_already_parsed >= $doc_length ) {
					return false;
				}

				while ( $this->parse_next_attribute() ) {
					continue;
				}

				if ( $this->bytes_already_parsed >= $doc_length ) {
					$this->parser_state = self::STATE_INCOMPLETE_INPUT;

					return false;
				}

				if ( '>' === $html[ $this->bytes_already_parsed ] ) {
					++$this->bytes_already_parsed;

					return true;
				}
			}

			++$at;
		}

		return false;
	}

	/**
	 * Parses the next tag.
	 *
	 * This will find and start parsing the next tag, including
	 * the opening `<`, the potential closer `/`, and the tag
	 * name. It does not parse the attributes or scan to the
	 * closing `>`; these are left for other methods.
	 *
	 * @return bool Whether a tag was found before the end of the document.
	 * @since 6.2.1 Support abruptly-closed comments, invalid-tag-closer-comments, and empty elements.
	 *
	 * @since 6.2.0
	 */
	private function parse_next_tag(): bool {
		$this->after_tag();

		$html       = $this->html;
		$doc_length = strlen( $html );
		$was_at     = $this->bytes_already_parsed;
		$at         = $was_at;

		while ( $at < $doc_length ) {
			$at = strpos( $html, '<', $at );
			if ( false === $at ) {
				break;
			}

			if ( $at > $was_at ) {
				/*
				 * A "<" normally starts a new HTML tag or syntax token, but in cases where the
				 * following character can't produce a valid token, the "<" is instead treated
				 * as plaintext and the parser should skip over it. This avoids a problem when
				 * following earlier practices of typing emoji with text, e.g. "<3". This
				 * should be a heart, not a tag. It's supposed to be rendered, not hidden.
				 *
				 * At this point the parser checks if this is one of those cases and if it is
				 * will continue searching for the next "<" in search of a token boundary.
				 *
				 * @see https://html.spec.whatwg.org/#tag-open-state
				 */
				if ( 1 !== strspn( $html, '!/?abcdefghijklmnopqrstuvwxyzABCEFGHIJKLMNOPQRSTUVWXYZ', $at + 1, 1 ) ) {
					++$at;
					continue;
				}

				$this->parser_state         = self::STATE_TEXT_NODE;
				$this->token_starts_at      = $was_at;
				$this->token_length         = $at - $was_at;
				$this->text_starts_at       = $was_at;
				$this->text_length          = $this->token_length;
				$this->bytes_already_parsed = $at;

				return true;
			}

			$this->token_starts_at = $at;

			if ( $at + 1 < $doc_length && '/' === $this->html[ $at + 1 ] ) {
				$this->is_closing_tag = true;
				++$at;
			} else {
				$this->is_closing_tag = false;
			}

			/*
			 * HTML tag names must start with [a-zA-Z] otherwise they are not tags.
			 * For example, "<3" is rendered as text, not a tag opener. If at least
			 * one letter follows the "<" then _it is_ a tag, but if the following
			 * character is anything else it _is not a tag_.
			 *
			 * It's not uncommon to find non-tags starting with `<` in an HTML
			 * document, so it's good for performance to make this pre-check before
			 * continuing to attempt to parse a tag name.
			 *
			 * Reference:
			 * * https://html.spec.whatwg.org/multipage/parsing.html#data-state
			 * * https://html.spec.whatwg.org/multipage/parsing.html#tag-open-state
			 */
			$tag_name_prefix_length = strspn( $html, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $at + 1 );
			if ( $tag_name_prefix_length > 0 ) {
				++$at;
				$this->parser_state         = self::STATE_MATCHED_TAG;
				$this->tag_name_starts_at   = $at;
				$this->tag_name_length      = $tag_name_prefix_length + strcspn( $html, " \t\f\r\n/>", $at + $tag_name_prefix_length );
				$this->bytes_already_parsed = $at + $this->tag_name_length;

				return true;
			}

			/*
			 * Abort if no tag is found before the end of
			 * the document. There is nothing left to parse.
			 */
			if ( $at + 1 >= $doc_length ) {
				$this->parser_state = self::STATE_INCOMPLETE_INPUT;

				return false;
			}

			/*
			 * `<!` transitions to markup declaration open state
			 * https://html.spec.whatwg.org/multipage/parsing.html#markup-declaration-open-state
			 */
			if ( ! $this->is_closing_tag && '!' === $html[ $at + 1 ] ) {
				/*
				 * `<!--` transitions to a comment state – apply further comment rules.
				 * https://html.spec.whatwg.org/multipage/parsing.html#tag-open-state
				 */
				if ( 0 === substr_compare( $html, '--', $at + 2, 2 ) ) {
					$closer_at = $at + 4;
					// If it's not possible to close the comment then there is nothing more to scan.
					if ( $doc_length <= $closer_at ) {
						$this->parser_state = self::STATE_INCOMPLETE_INPUT;

						return false;
					}

					// Abruptly-closed empty comments are a sequence of dashes followed by `>`.
					$span_of_dashes = strspn( $html, '-', $closer_at );
					if ( '>' === $html[ $closer_at + $span_of_dashes ] ) {
						/*
						 * @todo When implementing `set_modifiable_text()` ensure that updates to this token
						 *       don't break the syntax for short comments, e.g. `<!--->`. Unlike other comment
						 *       and bogus comment syntax, these leave no clear insertion point for text and
						 *       they need to be modified specially in order to contain text. E.g. to store
						 *       `?` as the modifiable text, the `<!--->` needs to become `<!--?-->`, which
						 *       involves inserting an additional `-` into the token after the modifiable text.
						 */
						$this->parser_state = self::STATE_COMMENT;
						$this->comment_type = self::COMMENT_AS_ABRUPTLY_CLOSED_COMMENT;
						$this->token_length = $closer_at + $span_of_dashes + 1 - $this->token_starts_at;

						// Only provide modifiable text if the token is long enough to contain it.
						if ( $span_of_dashes >= 2 ) {
							$this->comment_type   = self::COMMENT_AS_HTML_COMMENT;
							$this->text_starts_at = $this->token_starts_at + 4;
							$this->text_length    = $span_of_dashes - 2;
						}

						$this->bytes_already_parsed = $closer_at + $span_of_dashes + 1;

						return true;
					}

					/*
					 * Comments may be closed by either a --> or an invalid --!>.
					 * The first occurrence closes the comment.
					 *
					 * See https://html.spec.whatwg.org/#parse-error-incorrectly-closed-comment
					 */
					--$closer_at; // Pre-increment inside condition below reduces risk of accidental infinite looping.
					while ( ++$closer_at < $doc_length ) {
						$closer_at = strpos( $html, '--', $closer_at );
						if ( false === $closer_at ) {
							$this->parser_state = self::STATE_INCOMPLETE_INPUT;

							return false;
						}

						if ( $closer_at + 2 < $doc_length && '>' === $html[ $closer_at + 2 ] ) {
							$this->parser_state         = self::STATE_COMMENT;
							$this->comment_type         = self::COMMENT_AS_HTML_COMMENT;
							$this->token_length         = $closer_at + 3 - $this->token_starts_at;
							$this->text_starts_at       = $this->token_starts_at + 4;
							$this->text_length          = $closer_at - $this->text_starts_at;
							$this->bytes_already_parsed = $closer_at + 3;

							return true;
						}

						if (
							$closer_at + 3 < $doc_length &&
							'!' === $html[ $closer_at + 2 ] &&
							'>' === $html[ $closer_at + 3 ]
						) {
							$this->parser_state         = self::STATE_COMMENT;
							$this->comment_type         = self::COMMENT_AS_HTML_COMMENT;
							$this->token_length         = $closer_at + 4 - $this->token_starts_at;
							$this->text_starts_at       = $this->token_starts_at + 4;
							$this->text_length          = $closer_at - $this->text_starts_at;
							$this->bytes_already_parsed = $closer_at + 4;

							return true;
						}
					}
				}

				/*
				 * `<!DOCTYPE` transitions to DOCTYPE state – skip to the nearest >
				 * These are ASCII-case-insensitive.
				 * https://html.spec.whatwg.org/multipage/parsing.html#tag-open-state
				 */
				if (
					$doc_length > $at + 8 &&
					( 'D' === $html[ $at + 2 ] || 'd' === $html[ $at + 2 ] ) &&
					( 'O' === $html[ $at + 3 ] || 'o' === $html[ $at + 3 ] ) &&
					( 'C' === $html[ $at + 4 ] || 'c' === $html[ $at + 4 ] ) &&
					( 'T' === $html[ $at + 5 ] || 't' === $html[ $at + 5 ] ) &&
					( 'Y' === $html[ $at + 6 ] || 'y' === $html[ $at + 6 ] ) &&
					( 'P' === $html[ $at + 7 ] || 'p' === $html[ $at + 7 ] ) &&
					( 'E' === $html[ $at + 8 ] || 'e' === $html[ $at + 8 ] )
				) {
					$closer_at = strpos( $html, '>', $at + 9 );
					if ( false === $closer_at ) {
						$this->parser_state = self::STATE_INCOMPLETE_INPUT;

						return false;
					}

					$this->parser_state         = self::STATE_DOCTYPE;
					$this->token_length         = $closer_at + 1 - $this->token_starts_at;
					$this->text_starts_at       = $this->token_starts_at + 9;
					$this->text_length          = $closer_at - $this->text_starts_at;
					$this->bytes_already_parsed = $closer_at + 1;

					return true;
				}

				if (
					'html' !== $this->parsing_namespace &&
					strlen( $html ) > $at + 8 &&
					'[' === $html[ $at + 2 ] &&
					'C' === $html[ $at + 3 ] &&
					'D' === $html[ $at + 4 ] &&
					'A' === $html[ $at + 5 ] &&
					'T' === $html[ $at + 6 ] &&
					'A' === $html[ $at + 7 ] &&
					'[' === $html[ $at + 8 ]
				) {
					$closer_at = strpos( $html, ']]>', $at + 9 );
					if ( false === $closer_at ) {
						$this->parser_state = self::STATE_INCOMPLETE_INPUT;

						return false;
					}

					$this->parser_state         = self::STATE_CDATA_NODE;
					$this->text_starts_at       = $at + 9;
					$this->text_length          = $closer_at - $this->text_starts_at;
					$this->token_length         = $closer_at + 3 - $this->token_starts_at;
					$this->bytes_already_parsed = $closer_at + 3;

					return true;
				}

				/*
				 * Anything else here is an incorrectly-opened comment and transitions
				 * to the bogus comment state - skip to the nearest >. If no closer is
				 * found then the HTML was truncated inside the markup declaration.
				 */
				$closer_at = strpos( $html, '>', $at + 1 );
				if ( false === $closer_at ) {
					$this->parser_state = self::STATE_INCOMPLETE_INPUT;

					return false;
				}

				$this->parser_state         = self::STATE_COMMENT;
				$this->comment_type         = self::COMMENT_AS_INVALID_HTML;
				$this->token_length         = $closer_at + 1 - $this->token_starts_at;
				$this->text_starts_at       = $this->token_starts_at + 2;
				$this->text_length          = $closer_at - $this->text_starts_at;
				$this->bytes_already_parsed = $closer_at + 1;

				/*
				 * Identify nodes that would be CDATA if HTML had CDATA sections.
				 *
				 * This section must occur after identifying the bogus comment end
				 * because in an HTML parser it will span to the nearest `>`, even
				 * if there's no `]]>` as would be required in an XML document. It
				 * is therefore not possible to parse a CDATA section containing
				 * a `>` in the HTML syntax.
				 *
				 * Inside foreign elements there is a discrepancy between browsers
				 * and the specification on this.
				 *
				 * @todo Track whether the Tag Processor is inside a foreign element
				 *       and require the proper closing `]]>` in those cases.
				 */
				if (
					$this->token_length >= 10 &&
					'[' === $html[ $this->token_starts_at + 2 ] &&
					'C' === $html[ $this->token_starts_at + 3 ] &&
					'D' === $html[ $this->token_starts_at + 4 ] &&
					'A' === $html[ $this->token_starts_at + 5 ] &&
					'T' === $html[ $this->token_starts_at + 6 ] &&
					'A' === $html[ $this->token_starts_at + 7 ] &&
					'[' === $html[ $this->token_starts_at + 8 ] &&
					']' === $html[ $closer_at - 1 ] &&
					']' === $html[ $closer_at - 2 ]
				) {
					$this->parser_state    = self::STATE_COMMENT;
					$this->comment_type    = self::COMMENT_AS_CDATA_LOOKALIKE;
					$this->text_starts_at += 7;
					$this->text_length    -= 9;
				}

				return true;
			}

			/*
			 * </> is a missing end tag name, which is ignored.
			 *
			 * This was also known as the "presumptuous empty tag"
			 * in early discussions as it was proposed to close
			 * the nearest previous opening tag.
			 *
			 * See https://html.spec.whatwg.org/#parse-error-missing-end-tag-name
			 */
			if ( '>' === $html[ $at + 1 ] ) {
				// `<>` is interpreted as plaintext.
				if ( ! $this->is_closing_tag ) {
					++$at;
					continue;
				}

				$this->parser_state         = self::STATE_PRESUMPTUOUS_TAG;
				$this->token_length         = $at + 2 - $this->token_starts_at;
				$this->bytes_already_parsed = $at + 2;

				return true;
			}

			/*
			 * `<?` transitions to a bogus comment state – skip to the nearest >
			 * See https://html.spec.whatwg.org/multipage/parsing.html#tag-open-state
			 */
			if ( ! $this->is_closing_tag && '?' === $html[ $at + 1 ] ) {
				$closer_at = strpos( $html, '>', $at + 2 );
				if ( false === $closer_at ) {
					$this->parser_state = self::STATE_INCOMPLETE_INPUT;

					return false;
				}

				$this->parser_state         = self::STATE_COMMENT;
				$this->comment_type         = self::COMMENT_AS_INVALID_HTML;
				$this->token_length         = $closer_at + 1 - $this->token_starts_at;
				$this->text_starts_at       = $this->token_starts_at + 2;
				$this->text_length          = $closer_at - $this->text_starts_at;
				$this->bytes_already_parsed = $closer_at + 1;

				/*
				 * Identify a Processing Instruction node were HTML to have them.
				 *
				 * This section must occur after identifying the bogus comment end
				 * because in an HTML parser it will span to the nearest `>`, even
				 * if there's no `?>` as would be required in an XML document. It
				 * is therefore not possible to parse a Processing Instruction node
				 * containing a `>` in the HTML syntax.
				 *
				 * XML allows for more target names, but this code only identifies
				 * those with ASCII-representable target names. This means that it
				 * may identify some Processing Instruction nodes as bogus comments,
				 * but it will not misinterpret the HTML structure. By limiting the
				 * identification to these target names the Tag Processor can avoid
				 * the need to start parsing UTF-8 sequences.
				 *
				 * > NameStartChar ::= ":" | [A-Z] | "_" | [a-z] | [#xC0-#xD6] | [#xD8-#xF6] | [#xF8-#x2FF] |
				 *                     [#x370-#x37D] | [#x37F-#x1FFF] | [#x200C-#x200D] | [#x2070-#x218F] |
				 *                     [#x2C00-#x2FEF] | [#x3001-#xD7FF] | [#xF900-#xFDCF] | [#xFDF0-#xFFFD] |
				 *                     [#x10000-#xEFFFF]
				 * > NameChar      ::= NameStartChar | "-" | "." | [0-9] | #xB7 | [#x0300-#x036F] | [#x203F-#x2040]
				 *
				 * @todo Processing instruction nodes in SGML may contain any kind of markup. XML defines a
				 *       special case with `<?xml ... ?>` syntax, but the `?` is part of the bogus comment.
				 *
				 * @see https://www.w3.org/TR/2006/REC-xml11-20060816/#NT-PITarget
				 */
				if ( $this->token_length >= 5 && '?' === $html[ $closer_at - 1 ] ) {
					$comment_text     = substr( $html, $this->token_starts_at + 2, $this->token_length - 4 );
					$pi_target_length = strspn( $comment_text, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ:_' );

					if ( 0 < $pi_target_length ) {
						$pi_target_length += strspn(
							$comment_text,
							'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789:_-.',
							$pi_target_length
						);

						$this->comment_type       = self::COMMENT_AS_PI_NODE_LOOKALIKE;
						$this->tag_name_starts_at = $this->token_starts_at + 2;
						$this->tag_name_length    = $pi_target_length;
						$this->text_starts_at    += $pi_target_length;
						$this->text_length       -= $pi_target_length + 1;
					}
				}

				return true;
			}

			/*
			 * If a non-alpha starts the tag name in a tag closer it's a comment.
			 * Find the first `>`, which closes the comment.
			 *
			 * This parser classifies these particular comments as special "funky comments"
			 * which are made available for further processing.
			 *
			 * See https://html.spec.whatwg.org/#parse-error-invalid-first-character-of-tag-name
			 */
			if ( $this->is_closing_tag ) {
				// No chance of finding a closer.
				if ( $at + 3 > $doc_length ) {
					$this->parser_state = self::STATE_INCOMPLETE_INPUT;

					return false;
				}

				$closer_at = strpos( $html, '>', $at + 2 );
				if ( false === $closer_at ) {
					$this->parser_state = self::STATE_INCOMPLETE_INPUT;

					return false;
				}

				$this->parser_state         = self::STATE_FUNKY_COMMENT;
				$this->token_length         = $closer_at + 1 - $this->token_starts_at;
				$this->text_starts_at       = $this->token_starts_at + 2;
				$this->text_length          = $closer_at - $this->text_starts_at;
				$this->bytes_already_parsed = $closer_at + 1;

				return true;
			}

			++$at;
		}

		/*
		 * This does not imply an incomplete parse; it indicates that there
		 * can be nothing left in the document other than a #text node.
		 */
		$this->parser_state         = self::STATE_TEXT_NODE;
		$this->token_starts_at      = $was_at;
		$this->token_length         = $doc_length - $was_at;
		$this->text_starts_at       = $was_at;
		$this->text_length          = $this->token_length;
		$this->bytes_already_parsed = $doc_length;

		return true;
	}

	/**
	 * Parses the next attribute.
	 *
	 * @return bool Whether an attribute was found before the end of the document.
	 * @since 6.2.0
	 */
	private function parse_next_attribute(): bool {
		$doc_length = strlen( $this->html );

		// Skip whitespace and slashes.
		$this->bytes_already_parsed += strspn( $this->html, " \t\f\r\n/", $this->bytes_already_parsed );
		if ( $this->bytes_already_parsed >= $doc_length ) {
			$this->parser_state = self::STATE_INCOMPLETE_INPUT;

			return false;
		}

		/*
		 * Treat the equal sign as a part of the attribute
		 * name if it is the first encountered byte.
		 *
		 * @see https://html.spec.whatwg.org/multipage/parsing.html#before-attribute-name-state
		 */
		$name_length = '=' === $this->html[ $this->bytes_already_parsed ]
			? 1 + strcspn( $this->html, "=/> \t\f\r\n", $this->bytes_already_parsed + 1 )
			: strcspn( $this->html, "=/> \t\f\r\n", $this->bytes_already_parsed );

		// No attribute, just tag closer.
		if ( 0 === $name_length || $this->bytes_already_parsed + $name_length >= $doc_length ) {
			return false;
		}

		$attribute_start             = $this->bytes_already_parsed;
		$attribute_name              = substr( $this->html, $attribute_start, $name_length );
		$this->bytes_already_parsed += $name_length;
		if ( $this->bytes_already_parsed >= $doc_length ) {
			$this->parser_state = self::STATE_INCOMPLETE_INPUT;

			return false;
		}

		$this->skip_whitespace();
		if ( $this->bytes_already_parsed >= $doc_length ) {
			$this->parser_state = self::STATE_INCOMPLETE_INPUT;

			return false;
		}

		$has_value = '=' === $this->html[ $this->bytes_already_parsed ];
		if ( $has_value ) {
			++$this->bytes_already_parsed;
			$this->skip_whitespace();
			if ( $this->bytes_already_parsed >= $doc_length ) {
				$this->parser_state = self::STATE_INCOMPLETE_INPUT;

				return false;
			}

			switch ( $this->html[ $this->bytes_already_parsed ] ) {
				case "'":
				case '"':
					$quote                      = $this->html[ $this->bytes_already_parsed ];
					$value_start                = $this->bytes_already_parsed + 1;
					$end_quote_at               = strpos( $this->html, $quote, $value_start );
					$end_quote_at               = false === $end_quote_at ? $doc_length : $end_quote_at;
					$value_length               = $end_quote_at - $value_start;
					$attribute_end              = $end_quote_at + 1;
					$this->bytes_already_parsed = $attribute_end;
					break;

				default:
					$value_start                = $this->bytes_already_parsed;
					$value_length               = strcspn( $this->html, "> \t\f\r\n", $value_start );
					$attribute_end              = $value_start + $value_length;
					$this->bytes_already_parsed = $attribute_end;
			}
		} else {
			$value_start   = $this->bytes_already_parsed;
			$value_length  = 0;
			$attribute_end = $attribute_start + $name_length;
		}

		if ( $attribute_end >= $doc_length ) {
			$this->parser_state = self::STATE_INCOMPLETE_INPUT;

			return false;
		}

		if ( $this->is_closing_tag ) {
			return true;
		}

		/*
		 * > There must never be two or more attributes on
		 * > the same start tag whose names are an ASCII
		 * > case-insensitive match for each other.
		 *     - HTML 5 spec
		 *
		 * @see https://html.spec.whatwg.org/multipage/syntax.html#attributes-2:ascii-case-insensitive
		 */
		$comparable_name = strtolower( $attribute_name );

		// If an attribute is listed many times, only use the first declaration and ignore the rest.
		if ( ! isset( $this->attributes[ $comparable_name ] ) ) {
			$this->attributes[ $comparable_name ] = new WP_HTML_Attribute_Token(
				$attribute_name,
				$value_start,
				$value_length,
				$attribute_start,
				$attribute_end - $attribute_start,
				! $has_value
			);

			return true;
		}

		/*
		 * Track the duplicate attributes so if we remove it, all disappear together.
		 *
		 * While `$this->duplicated_attributes` could always be stored as an `array()`,
		 * which would simplify the logic here, storing a `null` and only allocating
		 * an array when encountering duplicates avoids needless allocations in the
		 * normative case of parsing tags with no duplicate attributes.
		 */
		$duplicate_span = new WP_HTML_Span( $attribute_start, $attribute_end - $attribute_start );
		if ( null === $this->duplicate_attributes ) {
			$this->duplicate_attributes = array( $comparable_name => array( $duplicate_span ) );
		} elseif ( ! isset( $this->duplicate_attributes[ $comparable_name ] ) ) {
			$this->duplicate_attributes[ $comparable_name ] = array( $duplicate_span );
		} else {
			$this->duplicate_attributes[ $comparable_name ][] = $duplicate_span;
		}

		return true;
	}

	/**
	 * Move the internal cursor past any immediate successive whitespace.
	 *
	 * @since 6.2.0
	 */
	private function skip_whitespace(): void {
		$this->bytes_already_parsed += strspn( $this->html, " \t\f\r\n", $this->bytes_already_parsed );
	}

	/**
	 * Applies attribute updates and cleans up once a tag is fully parsed.
	 *
	 * @since 6.2.0
	 */
	private function after_tag(): void {
		/*
		 * There could be lexical updates enqueued for an attribute that
		 * also exists on the next tag. In order to avoid conflating the
		 * attributes across the two tags, lexical updates with names
		 * need to be flushed to raw lexical updates.
		 */
		$this->class_name_updates_to_attributes_updates();

		/*
		 * Purge updates if there are too many. The actual count isn't
		 * scientific, but a few values from 100 to a few thousand were
		 * tests to find a practically-useful limit.
		 *
		 * If the update queue grows too big, then the Tag Processor
		 * will spend more time iterating through them and lose the
		 * efficiency gains of deferring applying them.
		 */
		if ( 1000 < count( $this->lexical_updates ) ) {
			$this->get_updated_html();
		}

		foreach ( $this->lexical_updates as $name => $update ) {
			/*
			 * Any updates appearing after the cursor should be applied
			 * before proceeding, otherwise they may be overlooked.
			 */
			if ( $update->start >= $this->bytes_already_parsed ) {
				$this->get_updated_html();
				break;
			}

			if ( is_int( $name ) ) {
				continue;
			}

			$this->lexical_updates[] = $update;
			unset( $this->lexical_updates[ $name ] );
		}

		$this->token_starts_at          = null;
		$this->token_length             = null;
		$this->tag_name_starts_at       = null;
		$this->tag_name_length          = null;
		$this->text_starts_at           = 0;
		$this->text_length              = 0;
		$this->is_closing_tag           = null;
		$this->attributes               = array();
		$this->comment_type             = null;
		$this->text_node_classification = self::TEXT_IS_GENERIC;
		$this->duplicate_attributes     = null;
	}

	/**
	 * Converts class name updates into tag attributes updates
	 * (they are accumulated in different data formats for performance).
	 *
	 * @since 6.2.0
	 *
	 * @see WP_HTML_Tag_Processor::$lexical_updates
	 * @see WP_HTML_Tag_Processor::$classname_updates
	 */
	private function class_name_updates_to_attributes_updates(): void {
		if ( 0 === count( $this->classname_updates ) ) {
			return;
		}

		$existing_class = $this->get_enqueued_attribute_value( 'class' );
		if ( null === $existing_class || true === $existing_class ) {
			$existing_class = '';
		}

		if ( false === $existing_class && isset( $this->attributes['class'] ) ) {
			$existing_class = substr(
				$this->html,
				$this->attributes['class']->value_starts_at,
				$this->attributes['class']->value_length
			);
		}

		if ( false === $existing_class ) {
			$existing_class = '';
		}

		/**
		 * Updated "class" attribute value.
		 *
		 * This is incrementally built while scanning through the existing class
		 * attribute, skipping removed classes on the way, and then appending
		 * added classes at the end. Only when finished processing will the
		 * value contain the final new value.
		 *
		 * @var string $class
		 */
		$class = '';

		/**
		 * Tracks the cursor position in the existing
		 * class attribute value while parsing.
		 *
		 * @var int $at
		 */
		$at = 0;

		/**
		 * Indicates if there's any need to modify the existing class attribute.
		 *
		 * If a call to `add_class()` and `remove_class()` wouldn't impact
		 * the `class` attribute value then there's no need to rebuild it.
		 * For example, when adding a class that's already present or
		 * removing one that isn't.
		 *
		 * This flag enables a performance optimization when none of the enqueued
		 * class updates would impact the `class` attribute; namely, that the
		 * processor can continue without modifying the input document, as if
		 * none of the `add_class()` or `remove_class()` calls had been made.
		 *
		 * This flag is set upon the first change that requires a string update.
		 *
		 * @var bool $modified
		 */
		$modified = false;

		$seen      = array();
		$to_remove = array();
		$is_quirks = self::QUIRKS_MODE === $this->compat_mode;
		if ( $is_quirks ) {
			foreach ( $this->classname_updates as $updated_name => $action ) {
				if ( self::REMOVE_CLASS === $action ) {
					$to_remove[] = strtolower( $updated_name );
				}
			}
		} else {
			foreach ( $this->classname_updates as $updated_name => $action ) {
				if ( self::REMOVE_CLASS === $action ) {
					$to_remove[] = $updated_name;
				}
			}
		}

		// Remove unwanted classes by only copying the new ones.
		$existing_class_length = strlen( $existing_class );
		while ( $at < $existing_class_length ) {
			// Skip to the first non-whitespace character.
			$ws_at     = $at;
			$ws_length = strspn( $existing_class, " \t\f\r\n", $ws_at );
			$at       += $ws_length;

			// Capture the class name – it's everything until the next whitespace.
			$name_length = strcspn( $existing_class, " \t\f\r\n", $at );
			if ( 0 === $name_length ) {
				// If no more class names are found then that's the end.
				break;
			}

			$name                  = substr( $existing_class, $at, $name_length );
			$comparable_class_name = $is_quirks ? strtolower( $name ) : $name;
			$at                   += $name_length;

			// If this class is marked for removal, remove it and move on to the next one.
			if ( in_array( $comparable_class_name, $to_remove, true ) ) {
				$modified = true;
				continue;
			}

			// If a class has already been seen then skip it; it should not be added twice.
			if ( in_array( $comparable_class_name, $seen, true ) ) {
				continue;
			}

			$seen[] = $comparable_class_name;

			/*
			 * Otherwise, append it to the new "class" attribute value.
			 *
			 * There are options for handling whitespace between tags.
			 * Preserving the existing whitespace produces fewer changes
			 * to the HTML content and should clarify the before/after
			 * content when debugging the modified output.
			 *
			 * This approach contrasts normalizing the inter-class
			 * whitespace to a single space, which might appear cleaner
			 * in the output HTML but produce a noisier change.
			 */
			if ( '' !== $class ) {
				$class .= substr( $existing_class, $ws_at, $ws_length );
			}
			$class .= $name;
		}

		// Add new classes by appending those which haven't already been seen.
		foreach ( $this->classname_updates as $name => $operation ) {
			$comparable_name = $is_quirks ? strtolower( $name ) : $name;
			if ( self::ADD_CLASS === $operation && ! in_array( $comparable_name, $seen, true ) ) {
				$modified = true;

				$class .= strlen( $class ) > 0 ? ' ' : '';
				$class .= $name;
			}
		}

		$this->classname_updates = array();
		if ( ! $modified ) {
			return;
		}

		if ( strlen( $class ) > 0 ) {
			$this->set_attribute( 'class', $class );
		} else {
			$this->remove_attribute( 'class' );
		}
	}

	/**
	 * Applies attribute updates to HTML document.
	 *
	 * @param  int $shift_this_point  Accumulate and return shift for this position.
	 *
	 * @return int How many bytes the given pointer moved in response to the updates.
	 * @since 6.3.0 Invalidate any bookmarks whose targets are overwritten.
	 *
	 * @since 6.2.0
	 * @since 6.2.1 Accumulates shift for internal cursor and passed pointer.
	 */
	private function apply_attributes_updates( int $shift_this_point ): int {
		if ( ! count( $this->lexical_updates ) ) {
			return 0;
		}

		$accumulated_shift_for_given_point = 0;

		/*
		 * Attribute updates can be enqueued in any order but updates
		 * to the document must occur in lexical order; that is, each
		 * replacement must be made before all others which follow it
		 * at later string indices in the input document.
		 *
		 * Sorting avoid making out-of-order replacements which
		 * can lead to mangled output, partially-duplicated
		 * attributes, and overwritten attributes.
		 */
		usort( $this->lexical_updates, array( self::class, 'sort_start_ascending' ) );

		$bytes_already_copied = 0;
		$output_buffer        = '';
		foreach ( $this->lexical_updates as $diff ) {
			$shift = strlen( $diff->text ) - $diff->length;

			// Adjust the cursor position by however much an update affects it.
			if ( $diff->start < $this->bytes_already_parsed ) {
				$this->bytes_already_parsed += $shift;
			}

			// Accumulate shift of the given pointer within this function call.
			if ( $diff->start < $shift_this_point ) {
				$accumulated_shift_for_given_point += $shift;
			}

			$output_buffer       .= substr( $this->html, $bytes_already_copied, $diff->start - $bytes_already_copied );
			$output_buffer       .= $diff->text;
			$bytes_already_copied = $diff->start + $diff->length;
		}

		$this->html = $output_buffer . substr( $this->html, $bytes_already_copied );

		/*
		 * Adjust bookmark locations to account for how the text
		 * replacements adjust offsets in the input document.
		 */
		foreach ( $this->bookmarks as $bookmark_name => $bookmark ) {
			$bookmark_end = $bookmark->start + $bookmark->length;

			/*
			 * Each lexical update which appears before the bookmark's endpoints
			 * might shift the offsets for those endpoints. Loop through each change
			 * and accumulate the total shift for each bookmark, then apply that
			 * shift after tallying the full delta.
			 */
			$head_delta = 0;
			$tail_delta = 0;

			foreach ( $this->lexical_updates as $diff ) {
				$diff_end = $diff->start + $diff->length;

				if ( $bookmark->start < $diff->start && $bookmark_end < $diff->start ) {
					break;
				}

				if ( $bookmark->start >= $diff->start && $bookmark_end < $diff_end ) {
					$this->release_bookmark( $bookmark_name );
					continue 2;
				}

				$delta = strlen( $diff->text ) - $diff->length;

				if ( $bookmark->start >= $diff->start ) {
					$head_delta += $delta;
				}

				if ( $bookmark_end >= $diff_end ) {
					$tail_delta += $delta;
				}
			}

			$bookmark->start  += $head_delta;
			$bookmark->length += $tail_delta - $head_delta;
		}

		$this->lexical_updates = array();

		return $accumulated_shift_for_given_point;
	}

	/**
	 * Checks whether a bookmark with the given name exists.
	 *
	 * @param  string $bookmark_name  Name to identify a bookmark that potentially exists.
	 *
	 * @return bool Whether that bookmark exists.
	 * @since 6.3.0
	 */
	public function has_bookmark( $bookmark_name ): bool {
		if (
			$this->has_native_processor() &&
			method_exists( $this->native_processor, 'has_bookmark' )
		) {
			return $this->native_processor->has_bookmark( $bookmark_name );
		}

		return array_key_exists( $bookmark_name, $this->bookmarks );
	}

	/**
	 * Move the internal cursor in the Tag Processor to a given bookmark's location.
	 *
	 * In order to prevent accidental infinite loops, there's a
	 * maximum limit on the number of times seek() can be called.
	 *
	 * @param  string $bookmark_name  Jump to the place in the document identified by this bookmark name.
	 *
	 * @return bool Whether the internal cursor was successfully moved to the bookmark's location.
	 * @since 6.2.0
	 */
	public function seek( $bookmark_name ): bool {
		if ( ! $this->has_bookmark( $bookmark_name ) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Unknown bookmark name.' ),
				'6.2.0'
			);

			return false;
		}

		if ( ++$this->seek_count > static::MAX_SEEK_OPS ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Too many calls to seek() - this can lead to performance issues.' ),
				'6.2.0'
			);

			return false;
		}

		// Flush out any pending updates to the document.
		$this->get_updated_html();

		if (
			$this->has_native_processor() &&
			method_exists( $this->native_processor, 'seek' )
		) {
			if ( ! $this->native_processor->seek( $bookmark_name ) ) {
				return false;
			}

			$this->clear_native_tag_metadata();
			if (
				method_exists( $this->native_processor, 'current_token_metadata' ) &&
				$this->cache_native_tag_metadata( $this->native_processor->current_token_metadata() )
			) {
				$this->update_parser_state_from_native_token();
			} else {
				$this->update_parser_state_from_native_token();
			}

			return true;
		}

		// Point this tag processor before the sought tag opener and consume it.
		$this->bytes_already_parsed = $this->bookmarks[ $bookmark_name ]->start;
		$this->parser_state         = self::STATE_READY;

		return $this->next_token();
	}

	/**
	 * Compare two WP_HTML_Text_Replacement objects.
	 *
	 * @param  WP_HTML_Text_Replacement $a  First attribute update.
	 * @param  WP_HTML_Text_Replacement $b  Second attribute update.
	 *
	 * @return int Comparison value for string order.
	 * @since 6.2.0
	 */
	private static function sort_start_ascending( WP_HTML_Text_Replacement $a, WP_HTML_Text_Replacement $b ): int {
		$by_start = $a->start - $b->start;
		if ( 0 !== $by_start ) {
			return $by_start;
		}

		$by_text = isset( $a->text, $b->text ) ? strcmp( $a->text, $b->text ) : 0;
		if ( 0 !== $by_text ) {
			return $by_text;
		}

		/*
		 * This code should be unreachable, because it implies the two replacements
		 * start at the same location and contain the same text.
		 */

		return $a->length - $b->length;
	}

	/**
	 * Return the enqueued value for a given attribute, if one exists.
	 *
	 * Enqueued updates can take different data types:
	 *  - If an update is enqueued and is boolean, the return will be `true`
	 *  - If an update is otherwise enqueued, the return will be the string value of that update.
	 *  - If an attribute is enqueued to be removed, the return will be `null` to indicate that.
	 *  - If no updates are enqueued, the return will be `false` to differentiate from "removed."
	 *
	 * @param  string $comparable_name  The attribute name in its comparable form.
	 *
	 * @return string|boolean|null Value of enqueued update if present, otherwise false.
	 * @since 6.2.0
	 */
	private function get_enqueued_attribute_value( string $comparable_name ) {
		if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
			return false;
		}

		if ( ! isset( $this->lexical_updates[ $comparable_name ] ) ) {
			return false;
		}

		$enqueued_text = $this->lexical_updates[ $comparable_name ]->text;

		// Removed attributes erase the entire span.
		if ( '' === $enqueued_text ) {
			return null;
		}

		/*
		 * Boolean attribute updates are just the attribute name without a corresponding value.
		 *
		 * This value might differ from the given comparable name in that there could be leading
		 * or trailing whitespace, and that the casing follows the name given in `set_attribute`.
		 *
		 * Example:
		 *
		 *     $p->set_attribute( 'data-TEST-id', 'update' );
		 *     'update' === $p->get_enqueued_attribute_value( 'data-test-id' );
		 *
		 * Detect this difference based on the absence of the `=`, which _must_ exist in any
		 * attribute containing a value, e.g. `<input type="text" enabled />`.
		 *                                            ¹           ²
		 *                                       1. Attribute with a string value.
		 *                                       2. Boolean attribute whose value is `true`.
		 */
		$equals_at = strpos( $enqueued_text, '=' );
		if ( false === $equals_at ) {
			return true;
		}

		/*
		 * Finally, a normal update's value will appear after the `=` and
		 * be double-quoted, as performed incidentally by `set_attribute`.
		 *
		 * e.g. `type="text"`
		 *           ¹²    ³
		 *        1. Equals is here.
		 *        2. Double-quoting starts one after the equals sign.
		 *        3. Double-quoting ends at the last character in the update.
		 */
		$enqueued_value = substr( $enqueued_text, $equals_at + 2, - 1 );

		return WP_HTML_Decoder::decode_attribute( $enqueued_value );
	}

	/**
	 * Returns the value of a requested attribute from a matched tag opener if that attribute exists.
	 *
	 * Example:
	 *
	 *     $p = new WP_HTML_Tag_Processor( '<div enabled class="test" data-test-id="14">Test</div>' );
	 *     $p->next_tag( array( 'class_name' => 'test' ) ) === true;
	 *     $p->get_attribute( 'data-test-id' ) === '14';
	 *     $p->get_attribute( 'enabled' ) === true;
	 *     $p->get_attribute( 'aria-label' ) === null;
	 *
	 *     $p->next_tag() === false;
	 *     $p->get_attribute( 'class' ) === null;
	 *
	 * @param  string $name  Name of attribute whose value is requested.
	 *
	 * @return string|true|null Value of attribute or `null` if not available. Boolean attributes return `true`.
	 * @since 6.2.0
	 */
	public function get_attribute( $name ) {
		if ( $this->has_native_processor() ) {
			return $this->native_processor->get_attribute( $name );
		}

		if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
			return null;
		}

		$comparable = strtolower( $name );

		/*
		 * For every attribute other than `class` it's possible to perform a quick check if
		 * there's an enqueued lexical update whose value takes priority over what's found in
		 * the input document.
		 *
		 * The `class` attribute is special though because of the exposed helpers `add_class`
		 * and `remove_class`. These form a builder for the `class` attribute, so an additional
		 * check for enqueued class changes is required in addition to the check for any enqueued
		 * attribute values. If any exist, those enqueued class changes must first be flushed out
		 * into an attribute value update.
		 */
		if ( 'class' === $name ) {
			$this->class_name_updates_to_attributes_updates();
		}

		// Return any enqueued attribute value updates if they exist.
		$enqueued_value = $this->get_enqueued_attribute_value( $comparable );
		if ( false !== $enqueued_value ) {
			return $enqueued_value;
		}

		if ( ! isset( $this->attributes[ $comparable ] ) ) {
			return null;
		}

		$attribute = $this->attributes[ $comparable ];

		/*
		 * This flag distinguishes an attribute with no value
		 * from an attribute with an empty string value. For
		 * unquoted attributes this could look very similar.
		 * It refers to whether an `=` follows the name.
		 *
		 * e.g. <div boolean-attribute empty-attribute=></div>
		 *           ¹                 ²
		 *        1. Attribute `boolean-attribute` is `true`.
		 *        2. Attribute `empty-attribute` is `""`.
		 */
		if ( true === $attribute->is_true ) {
			return true;
		}

		$raw_value = substr( $this->html, $attribute->value_starts_at, $attribute->value_length );

		return WP_HTML_Decoder::decode_attribute( $raw_value );
	}

	/**
	 * Gets lowercase names of all attributes matching a given prefix in the current tag.
	 *
	 * Note that matching is case-insensitive. This is in accordance with the spec:
	 *
	 * > There must never be two or more attributes on
	 * > the same start tag whose names are an ASCII
	 * > case-insensitive match for each other.
	 *     - HTML 5 spec
	 *
	 * Example:
	 *
	 *     $p = new WP_HTML_Tag_Processor( '<div data-ENABLED class="test" DATA-test-id="14">Test</div>' );
	 *     $p->next_tag( array( 'class_name' => 'test' ) ) === true;
	 *     $p->get_attribute_names_with_prefix( 'data-' ) === array( 'data-enabled', 'data-test-id' );
	 *
	 *     $p->next_tag() === false;
	 *     $p->get_attribute_names_with_prefix( 'data-' ) === null;
	 *
	 * @param  string $prefix  Prefix of requested attribute names.
	 *
	 * @return array|null List of attribute names, or `null` when no tag opener is matched.
	 * @since 6.2.0
	 *
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#attributes-2:ascii-case-insensitive
	 */
	public function get_attribute_names_with_prefix( $prefix ): ?array {
		if ( null !== $this->native_processor ) {
			if (
				null !== $this->native_tag_token_type &&
				( '#tag' !== $this->native_tag_token_type || $this->native_tag_token_is_closer )
			) {
				return null;
			}

			if (
				null !== $this->native_tag_attribute_name_initials &&
				is_string( $prefix )
			) {
				if ( 0 === $this->native_tag_attribute_name_initials ) {
					return array();
				}

				if ( '' !== $prefix ) {
					$first_prefix_byte = ord( $prefix[0] );
					if ( $first_prefix_byte >= 65 && $first_prefix_byte <= 90 ) {
						$first_prefix_byte += 32;
					}

					if ( $first_prefix_byte >= 97 && $first_prefix_byte <= 122 ) {
						$prefix_initial_bit = 1 << ( $first_prefix_byte - 97 );
						if ( 0 === ( $this->native_tag_attribute_name_initials & $prefix_initial_bit ) ) {
							return array();
						}
					}
				}
			}

			if ( $this->native_supports_prefix_name_string ) {
				$attribute_names = $this->native_processor->get_attribute_names_with_prefix_string( $prefix );
				if ( null === $attribute_names ) {
					return null;
				}

				return '' === $attribute_names ? array() : explode( "\x1f", $attribute_names );
			}

			return $this->native_processor->get_attribute_names_with_prefix( $prefix );
		}

		if (
			self::STATE_MATCHED_TAG !== $this->parser_state ||
			$this->is_closing_tag
		) {
			return null;
		}

		$comparable = strtolower( $prefix );

		$matches = array();
		foreach ( array_keys( $this->attributes ) as $attr_name ) {
			if ( 0 === strncmp( $attr_name, $comparable, strlen( $comparable ) ) ) {
				$matches[] = $attr_name;
			}
		}

		return $matches;
	}

	/**
	 * Counts lowercase names of all attributes matching a given prefix in the current tag.
	 *
	 * @param  string $prefix Prefix of requested attribute names.
	 *
	 * @return int|null Number of matching attribute names, or `null` when no tag opener is matched.
	 *
	 * @since WP_VERSION
	 */
	public function count_attribute_names_with_prefix( $prefix ): ?int {
		if ( null !== $this->native_processor ) {
			if (
				null !== $this->native_tag_token_type &&
				( '#tag' !== $this->native_tag_token_type || $this->native_tag_token_is_closer )
			) {
				return null;
			}

			if (
				null !== $this->native_tag_attribute_name_initials &&
				is_string( $prefix )
			) {
				if ( 0 === $this->native_tag_attribute_name_initials ) {
					return 0;
				}

				if ( '' !== $prefix ) {
					$first_prefix_byte = ord( $prefix[0] );
					if ( $first_prefix_byte >= 65 && $first_prefix_byte <= 90 ) {
						$first_prefix_byte += 32;
					}

					if ( $first_prefix_byte >= 97 && $first_prefix_byte <= 122 ) {
						$prefix_initial_bit = 1 << ( $first_prefix_byte - 97 );
						if ( 0 === ( $this->native_tag_attribute_name_initials & $prefix_initial_bit ) ) {
							return 0;
						}
					}
				}
			}

			if ( $this->native_supports_prefix_name_count ) {
				return $this->native_processor->count_attribute_names_with_prefix( $prefix );
			}
		}

		$attribute_names = $this->get_attribute_names_with_prefix( $prefix );
		if ( null === $attribute_names ) {
			return null;
		}

		return count( $attribute_names );
	}

	/**
	 * Summarizes tags and prefixed attributes across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and
	 * `count_attribute_names_with_prefix()`, but native implementations may
	 * process the remaining document in one call.
	 *
	 * @param string $prefix        Prefix of requested attribute names.
	 * @param bool   $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with `tag_count` and `attribute_count`.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_attribute_names_with_prefix( $prefix, bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_prefix_name_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_attribute_names_with_prefix( $prefix, $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 2 );
				if ( 2 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'       => (int) $parts[0],
						'attribute_count' => (int) $parts[1],
					);
				}
			}
		}

		$tag_count       = 0;
		$attribute_count = 0;
		$query           = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			$count = $this->count_attribute_names_with_prefix( $prefix );
			if ( is_int( $count ) ) {
				$attribute_count += $count;
			}
		}

		return array(
			'tag_count'       => $tag_count,
			'attribute_count' => $attribute_count,
		);
	}

	/**
	 * Returns summaries for the next chunk of tags and matching attributes.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and
	 * `count_attribute_names_with_prefix()`, but native implementations may
	 * amortize PHP/native crossings across a chunk while preserving incremental
	 * consumption.
	 *
	 * Each row contains `tag_name`, `is_tag_closer`, and `attribute_count`.
	 *
	 * @param string $prefix        Prefix of requested attribute names.
	 * @param int    $max_tags      Maximum number of tag summaries to return.
	 * @param bool   $visit_closers Whether closing tags should be included.
	 * @return array[] Tag summary rows.
	 *
	 * @since WP_VERSION
	 */
	public function next_tag_prefix_summary_batch( $prefix, $max_tags = 64, bool $visit_closers = false ): array {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return array();
		}

		$max_tags = min( 256, $max_tags );
		$batch    = $this->next_tag_prefix_compact_summary_batch( $prefix, $max_tags, $visit_closers );
		if ( ! is_string( $batch ) || '' === $batch ) {
			return array();
		}

		$rows         = array();
		$offset       = 0;
		$batch_length = strlen( $batch );

		while ( $offset < $batch_length && count( $rows ) < $max_tags ) {
			$row_end = strpos( $batch, "\x1e", $offset );
			if ( false === $row_end ) {
				$row_end = $batch_length;
			}

			$parts = explode( "\x1f", substr( $batch, $offset, $row_end - $offset ), 3 );
			if ( 3 !== count( $parts ) ) {
				break;
			}

			$rows[] = array(
				'tag_name'        => $parts[0],
				'is_tag_closer'   => '1' === $parts[1],
				'attribute_count' => (int) $parts[2],
			);
			$offset = $row_end + 1;
		}

		return $rows;
	}

	/**
	 * Returns compact summaries for the next chunk of tags and matching attributes.
	 *
	 * This is the compact-string form of `next_tag_prefix_summary_batch()`,
	 * using unit separators between fields and record separators between rows.
	 * It is intended for high-throughput read-only scans where callers can
	 * aggregate tag metadata without allocating one PHP array per tag.
	 *
	 * @param string $prefix        Prefix of requested attribute names.
	 * @param int    $max_tags      Maximum number of tag summaries to return.
	 * @param bool   $visit_closers Whether closing tags should be included.
	 * @return string|null Compact tag summary batch, or null when exhausted.
	 *
	 * @since WP_VERSION
	 */
	public function next_tag_prefix_compact_summary_batch( $prefix, $max_tags = 64, bool $visit_closers = false ) {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return null;
		}

		$max_tags = min( 256, $max_tags );

		if ( $this->has_native_processor() && $this->native_supports_prefix_name_summary_batch ) {
			$batch = $this->native_processor->next_tag_prefix_summary_batch( $prefix, $max_tags, $visit_closers );
			if ( ! is_string( $batch ) || '' === $batch ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();

				return null;
			}

			if ( $this->native_compact_batch_reached_end( $batch, $max_tags ) ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();
			} else {
				$this->cache_native_final_compact_tag_summary( $batch );
			}

			return $batch;
		}

		$rows  = '';
		$query = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;
		$count = 0;

		while ( $count < $max_tags && $this->next_tag( $query ) ) {
			$attribute_count = $this->count_attribute_names_with_prefix( $prefix );
			if ( '' !== $rows ) {
				$rows .= "\x1e";
			}

			$rows .= implode(
				"\x1f",
				array(
					$this->get_tag(),
					$this->is_tag_closer() ? '1' : '0',
					is_int( $attribute_count ) ? (string) $attribute_count : '0',
				)
			);
			++$count;
		}

		return '' === $rows ? null : $rows;
	}

	/**
	 * Returns summaries for the next chunk of tags.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and reading
	 * `get_tag()`/`is_tag_closer()`, but native implementations may amortize
	 * PHP/native crossings across a chunk while preserving incremental
	 * consumption.
	 *
	 * Each row contains `tag_name` and `is_tag_closer`.
	 *
	 * @param int  $max_tags      Maximum number of tag summaries to return.
	 * @param bool $visit_closers Whether closing tags should be included.
	 * @return array[] Tag summary rows.
	 *
	 * @since WP_VERSION
	 */
	public function next_tag_summary_batch( $max_tags = 64, bool $visit_closers = false ): array {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return array();
		}

		$max_tags = min( 256, $max_tags );
		$batch    = $this->next_tag_compact_summary_batch( $max_tags, $visit_closers );
		if ( ! is_string( $batch ) || '' === $batch ) {
			return array();
		}

		$rows         = array();
		$offset       = 0;
		$batch_length = strlen( $batch );

		while ( $offset < $batch_length && count( $rows ) < $max_tags ) {
			$row_end = strpos( $batch, "\x1e", $offset );
			if ( false === $row_end ) {
				$row_end = $batch_length;
			}

			$parts = explode( "\x1f", substr( $batch, $offset, $row_end - $offset ), 2 );
			if ( 2 !== count( $parts ) ) {
				break;
			}

			$rows[] = array(
				'tag_name'      => $parts[0],
				'is_tag_closer' => '1' === $parts[1],
			);
			$offset = $row_end + 1;
		}

		return $rows;
	}

	/**
	 * Returns compact summaries for the next chunk of tags.
	 *
	 * This is the compact-string form of `next_tag_summary_batch()`, using
	 * unit separators between fields and record separators between rows. It is
	 * intended for high-throughput read-only scans where callers can aggregate
	 * tag metadata without allocating one PHP array per tag.
	 *
	 * @param int  $max_tags      Maximum number of tag summaries to return.
	 * @param bool $visit_closers Whether closing tags should be included.
	 * @return string|null Compact tag summary batch, or null when exhausted.
	 *
	 * @since WP_VERSION
	 */
	public function next_tag_compact_summary_batch( $max_tags = 64, bool $visit_closers = false ) {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return null;
		}

		$max_tags = min( 256, $max_tags );

		if ( $this->has_native_processor() && $this->native_supports_tag_summary_batch ) {
			$batch = $this->native_processor->next_tag_compact_summary_batch( $max_tags, $visit_closers );
			if ( ! is_string( $batch ) || '' === $batch ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();

				return null;
			}

			if ( $this->native_compact_batch_reached_end( $batch, $max_tags ) ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();
			} else {
				$this->cache_native_final_compact_tag_summary( $batch );
			}

			return $batch;
		}

		$rows  = '';
		$query = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;
		$count = 0;

		while ( $count < $max_tags && $this->next_tag( $query ) ) {
			if ( '' !== $rows ) {
				$rows .= "\x1e";
			}

			$rows .= implode(
				"\x1f",
				array(
					$this->get_tag(),
					$this->is_tag_closer() ? '1' : '0',
				)
			);
			++$count;
		}

		return '' === $rows ? null : $rows;
	}

	/**
	 * Returns aggregate tag and matching-attribute counts for the next chunk of tags.
	 *
	 * This is the count-only compact form of `next_tag_prefix_summary_batch()`.
	 * It preserves incremental consumption but avoids returning one row per tag
	 * when callers only need aggregate counts.
	 *
	 * @param string $prefix        Prefix of requested attribute names.
	 * @param int    $max_tags      Maximum number of tags to consume.
	 * @param bool   $visit_closers Whether closing tags should be included.
	 * @return string|null Compact `tag_count` and `attribute_count`, or null when exhausted.
	 *
	 * @since WP_VERSION
	 */
	public function next_tag_prefix_count_compact_batch( $prefix, $max_tags = 64, bool $visit_closers = false ) {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return null;
		}

		$max_tags = min( 256, $max_tags );

		if ( $this->has_native_processor() && $this->native_supports_prefix_name_count_batch ) {
			$summary = $this->native_processor->next_tag_prefix_count_compact_batch( $prefix, $max_tags, $visit_closers );
			if ( ! is_string( $summary ) || '' === $summary ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();

				return null;
			}

			$parts = explode( "\x1f", $summary, 2 );
			$this->clear_native_tag_metadata();
			if ( isset( $parts[0] ) && (int) $parts[0] < $max_tags ) {
				$this->update_parser_state_after_native_no_match();
			} else {
				$this->update_parser_state_from_native_token();
			}

			return $summary;
		}

		$tag_count       = 0;
		$attribute_count = 0;
		$query           = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $tag_count < $max_tags && $this->next_tag( $query ) ) {
			++$tag_count;

			$count = $this->count_attribute_names_with_prefix( $prefix );
			if ( is_int( $count ) ) {
				$attribute_count += $count;
			}
		}

		return 0 === $tag_count ? null : $tag_count . "\x1f" . $attribute_count;
	}

	/**
	 * Summarizes tag inventory counts across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and reading tag
	 * names, closer state, and attribute names, but native implementations may
	 * aggregate the remaining document in one call.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, opener, closer, attribute, and unique-name counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_tag_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_tag_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_tag_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 5 );
				if ( 5 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'             => (int) $parts[0],
						'open_tag_count'        => (int) $parts[1],
						'closing_tag_count'     => (int) $parts[2],
						'attribute_count'       => (int) $parts[3],
						'unique_tag_name_count' => (int) $parts[4],
					);
				}
			}
		}

		$tag_count         = 0;
		$open_tag_count    = 0;
		$closing_tag_count = 0;
		$attribute_count   = 0;
		$tag_names         = array();
		$query             = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;
			$tag_names[ $this->get_tag() ] = true;

			if ( $this->is_tag_closer() ) {
				++$closing_tag_count;
				continue;
			}

			++$open_tag_count;
			$attribute_names = $this->get_attribute_names_with_prefix( '' );
			if ( is_array( $attribute_names ) ) {
				$attribute_count += count( $attribute_names );
			}
		}

		return array(
			'tag_count'             => $tag_count,
			'open_tag_count'        => $open_tag_count,
			'closing_tag_count'     => $closing_tag_count,
			'attribute_count'       => $attribute_count,
			'unique_tag_name_count' => count( $tag_names ),
		);
	}

	/**
	 * Summarizes heading tag usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and `get_tag()`,
	 * but native implementations may aggregate the remaining document in one
	 * call.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, heading, and per-level heading counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_heading_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_heading_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_heading_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 8 );
				if ( 8 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'     => (int) $parts[0],
						'heading_count' => (int) $parts[1],
						'h1_count'      => (int) $parts[2],
						'h2_count'      => (int) $parts[3],
						'h3_count'      => (int) $parts[4],
						'h4_count'      => (int) $parts[5],
						'h5_count'      => (int) $parts[6],
						'h6_count'      => (int) $parts[7],
					);
				}
			}
		}

		$tag_count     = 0;
		$heading_count = 0;
		$h1_count      = 0;
		$h2_count      = 0;
		$h3_count      = 0;
		$h4_count      = 0;
		$h5_count      = 0;
		$h6_count      = 0;
		$query         = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() ) {
				continue;
			}

			switch ( $this->get_tag() ) {
				case 'H1':
					++$heading_count;
					++$h1_count;
					break;
				case 'H2':
					++$heading_count;
					++$h2_count;
					break;
				case 'H3':
					++$heading_count;
					++$h3_count;
					break;
				case 'H4':
					++$heading_count;
					++$h4_count;
					break;
				case 'H5':
					++$heading_count;
					++$h5_count;
					break;
				case 'H6':
					++$heading_count;
					++$h6_count;
					break;
			}
		}

		return array(
			'tag_count'     => $tag_count,
			'heading_count' => $heading_count,
			'h1_count'      => $h1_count,
			'h2_count'      => $h2_count,
			'h3_count'      => $h3_count,
			'h4_count'      => $h4_count,
			'h5_count'      => $h5_count,
			'h6_count'      => $h6_count,
		);
	}

	/**
	 * Summarizes ID attribute usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and
	 * `get_attribute( 'id' )`, but native implementations may aggregate the
	 * remaining document in one call.
	 *
	 * Boolean ID attributes count as ID-bearing tags with zero value bytes and
	 * no unique ID value.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, ID-bearing tag, unique ID, duplicate ID, and value-byte counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_id_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_id_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_id_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 5 );
				if ( 5 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'          => (int) $parts[0],
						'id_tag_count'       => (int) $parts[1],
						'unique_id_count'    => (int) $parts[2],
						'duplicate_id_count' => (int) $parts[3],
						'id_value_bytes'     => (int) $parts[4],
					);
				}
			}
		}

		$tag_count          = 0;
		$id_tag_count       = 0;
		$duplicate_id_count = 0;
		$id_value_bytes     = 0;
		$ids                = array();
		$query              = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() ) {
				continue;
			}

			$id = $this->get_attribute( 'id' );
			if ( null === $id ) {
				continue;
			}

			++$id_tag_count;

			if ( is_string( $id ) ) {
				$id_value_bytes += strlen( $id );
				if ( isset( $ids[ $id ] ) ) {
					++$duplicate_id_count;
				} else {
					$ids[ $id ] = true;
				}
			}
		}

		return array(
			'tag_count'          => $tag_count,
			'id_tag_count'       => $id_tag_count,
			'unique_id_count'    => count( $ids ),
			'duplicate_id_count' => $duplicate_id_count,
			'id_value_bytes'     => $id_value_bytes,
		);
	}

	/**
	 * Summarizes attribute usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()`,
	 * `get_attribute_names_with_prefix( '' )`, and `get_attribute()`, but
	 * native implementations may aggregate the remaining document in one call.
	 *
	 * The `attribute_count` count follows `get_attribute_names_with_prefix( '' )`
	 * semantics and counts each unique attribute name once per tag.
	 * `attribute_value_bytes` uses decoded attribute values. Boolean
	 * attributes count as present with zero value bytes.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, attribute, unique-attribute, and value-byte counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_attribute_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_attribute_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_attribute_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 4 );
				if ( 4 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'                   => (int) $parts[0],
						'attribute_count'             => (int) $parts[1],
						'unique_attribute_name_count' => (int) $parts[2],
						'attribute_value_bytes'       => (int) $parts[3],
					);
				}
			}
		}

		$tag_count             = 0;
		$attribute_count       = 0;
		$attribute_value_bytes = 0;
		$attribute_names       = array();
		$query                 = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() ) {
				continue;
			}

			$current_attribute_names = $this->get_attribute_names_with_prefix( '' );
			if ( ! is_array( $current_attribute_names ) ) {
				continue;
			}

			foreach ( $current_attribute_names as $attribute_name ) {
				++$attribute_count;
				$attribute_names[ $attribute_name ] = true;

				$attribute_value = $this->get_attribute( $attribute_name );
				if ( null !== $attribute_value && true !== $attribute_value ) {
					$attribute_value_bytes += strlen( $attribute_value );
				}
			}
		}

		return array(
			'tag_count'                   => $tag_count,
			'attribute_count'             => $attribute_count,
			'unique_attribute_name_count' => count( $attribute_names ),
			'attribute_value_bytes'       => $attribute_value_bytes,
		);
	}

	/**
	 * Summarizes `data-*` attribute usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()`,
	 * `get_attribute_names_with_prefix( 'data-' )`, and `get_attribute()` for
	 * each matching attribute, but native implementations may aggregate the
	 * remaining document in one call.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, data-tag, attribute, unique-name, and value-byte counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_data_attribute_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_data_attribute_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_data_attribute_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 5 );
				if ( 5 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'                        => (int) $parts[0],
						'data_attribute_tag_count'         => (int) $parts[1],
						'data_attribute_count'             => (int) $parts[2],
						'unique_data_attribute_name_count' => (int) $parts[3],
						'data_attribute_value_bytes'       => (int) $parts[4],
					);
				}
			}
		}

		$tag_count                  = 0;
		$data_attribute_tag_count   = 0;
		$data_attribute_count       = 0;
		$data_attribute_value_bytes = 0;
		$data_attribute_names       = array();
		$query                      = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() ) {
				continue;
			}

			$attribute_names = $this->get_attribute_names_with_prefix( 'data-' );
			if ( ! is_array( $attribute_names ) || array() === $attribute_names ) {
				continue;
			}

			++$data_attribute_tag_count;
			foreach ( $attribute_names as $attribute_name ) {
				++$data_attribute_count;
				$data_attribute_names[ $attribute_name ] = true;

				$attribute_value = $this->get_attribute( $attribute_name );
				if ( is_string( $attribute_value ) ) {
					$data_attribute_value_bytes += strlen( $attribute_value );
				}
			}
		}

		return array(
			'tag_count'                        => $tag_count,
			'data_attribute_tag_count'         => $data_attribute_tag_count,
			'data_attribute_count'             => $data_attribute_count,
			'unique_data_attribute_name_count' => count( $data_attribute_names ),
			'data_attribute_value_bytes'       => $data_attribute_value_bytes,
		);
	}

	/**
	 * Summarizes `aria-*` attribute usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()`,
	 * `get_attribute_names_with_prefix( 'aria-' )`, and `get_attribute()` for
	 * each matching attribute, but native implementations may aggregate the
	 * remaining document in one call.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, ARIA-tag, attribute, unique-name, and value-byte counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_aria_attribute_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_aria_attribute_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_aria_attribute_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 5 );
				if ( 5 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'                        => (int) $parts[0],
						'aria_attribute_tag_count'         => (int) $parts[1],
						'aria_attribute_count'             => (int) $parts[2],
						'unique_aria_attribute_name_count' => (int) $parts[3],
						'aria_attribute_value_bytes'       => (int) $parts[4],
					);
				}
			}
		}

		$tag_count                  = 0;
		$aria_attribute_tag_count   = 0;
		$aria_attribute_count       = 0;
		$aria_attribute_value_bytes = 0;
		$aria_attribute_names       = array();
		$query                      = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() ) {
				continue;
			}

			$attribute_names = $this->get_attribute_names_with_prefix( 'aria-' );
			if ( ! is_array( $attribute_names ) || array() === $attribute_names ) {
				continue;
			}

			++$aria_attribute_tag_count;
			foreach ( $attribute_names as $attribute_name ) {
				++$aria_attribute_count;
				$aria_attribute_names[ $attribute_name ] = true;

				$attribute_value = $this->get_attribute( $attribute_name );
				if ( is_string( $attribute_value ) ) {
					$aria_attribute_value_bytes += strlen( $attribute_value );
				}
			}
		}

		return array(
			'tag_count'                        => $tag_count,
			'aria_attribute_tag_count'         => $aria_attribute_tag_count,
			'aria_attribute_count'             => $aria_attribute_count,
			'unique_aria_attribute_name_count' => count( $aria_attribute_names ),
			'aria_attribute_value_bytes'       => $aria_attribute_value_bytes,
		);
	}

	/**
	 * Summarizes class attribute usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()`, `get_attribute( 'class' )`,
	 * and `class_list()`, but native implementations may aggregate the
	 * remaining document in one call.
	 *
	 * The `class_name_count` count follows `class_list()` semantics and
	 * counts each unique class name once per tag. `class_value_bytes` uses the
	 * decoded class attribute value. Boolean class attributes count as present
	 * with zero value bytes and zero class names.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, class-attribute, class-name, and unique-class counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_class_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_class_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_class_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 5 );
				if ( 5 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'                => (int) $parts[0],
						'class_attribute_count'    => (int) $parts[1],
						'class_name_count'         => (int) $parts[2],
						'unique_class_name_count'  => (int) $parts[3],
						'class_value_bytes'        => (int) $parts[4],
					);
				}
			}
		}

		$tag_count             = 0;
		$class_attribute_count = 0;
		$class_name_count      = 0;
		$class_value_bytes     = 0;
		$class_names           = array();
		$query                 = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() ) {
				continue;
			}

			$class_attribute = $this->get_attribute( 'class' );
			if ( null === $class_attribute ) {
				continue;
			}

			++$class_attribute_count;
			if ( true !== $class_attribute ) {
				$class_value_bytes += strlen( $class_attribute );
			}

			foreach ( $this->class_list() as $class_name ) {
				++$class_name_count;
				$class_names[ $class_name ] = true;
			}
		}

		return array(
			'tag_count'                => $tag_count,
			'class_attribute_count'    => $class_attribute_count,
			'class_name_count'         => $class_name_count,
			'unique_class_name_count'  => count( $class_names ),
			'class_value_bytes'        => $class_value_bytes,
		);
	}

	/**
	 * Summarizes resource-link usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and reading
	 * resource-bearing attributes from `a[href]`, `img[src]`, `script[src]`,
	 * `link[href]`, and `source[src]`, but native implementations may
	 * aggregate the remaining document in one call.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, resource tag, attribute, unique resource tag, and value-byte counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_resource_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_resource_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_resource_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 5 );
				if ( 5 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'                      => (int) $parts[0],
						'resource_tag_count'             => (int) $parts[1],
						'resource_attribute_count'       => (int) $parts[2],
						'unique_resource_tag_name_count' => (int) $parts[3],
						'resource_value_bytes'           => (int) $parts[4],
					);
				}
			}
		}

		$tag_count                      = 0;
		$resource_tag_count             = 0;
		$resource_attribute_count       = 0;
		$resource_value_bytes           = 0;
		$unique_resource_tag_name_count = array();
		$query                          = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() ) {
				continue;
			}

			$tag_name       = $this->get_tag();
			$attribute_name = null;
			switch ( $tag_name ) {
				case 'A':
				case 'LINK':
					$attribute_name = 'href';
					break;
				case 'IMG':
				case 'SCRIPT':
				case 'SOURCE':
					$attribute_name = 'src';
					break;
			}

			if ( null === $attribute_name ) {
				continue;
			}

			$attribute_value = $this->get_attribute( $attribute_name );
			if ( null === $attribute_value ) {
				continue;
			}

			++$resource_tag_count;
			++$resource_attribute_count;
			$unique_resource_tag_name_count[ $tag_name ] = true;
			if ( true !== $attribute_value ) {
				$resource_value_bytes += strlen( $attribute_value );
			}
		}

		return array(
			'tag_count'                      => $tag_count,
			'resource_tag_count'             => $resource_tag_count,
			'resource_attribute_count'       => $resource_attribute_count,
			'unique_resource_tag_name_count' => count( $unique_resource_tag_name_count ),
			'resource_value_bytes'           => $resource_value_bytes,
		);
	}

	/**
	 * Summarizes image usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and reading `src`,
	 * `alt`, `width`, and `height` attributes from `img` elements, but native
	 * implementations may aggregate the remaining document in one call.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, image, src, alt, empty-alt, dimension, and value-byte counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_image_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_image_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_image_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 8 );
				if ( 8 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'        => (int) $parts[0],
						'image_count'      => (int) $parts[1],
						'src_count'        => (int) $parts[2],
						'alt_count'        => (int) $parts[3],
						'empty_alt_count'  => (int) $parts[4],
						'dimension_count'  => (int) $parts[5],
						'src_value_bytes'  => (int) $parts[6],
						'alt_value_bytes'  => (int) $parts[7],
					);
				}
			}
		}

		$tag_count       = 0;
		$image_count     = 0;
		$src_count       = 0;
		$alt_count       = 0;
		$empty_alt_count = 0;
		$dimension_count = 0;
		$src_value_bytes = 0;
		$alt_value_bytes = 0;
		$query           = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() || 'IMG' !== $this->get_tag() ) {
				continue;
			}

			++$image_count;

			$src = $this->get_attribute( 'src' );
			if ( null !== $src ) {
				++$src_count;
				if ( true !== $src ) {
					$src_value_bytes += strlen( $src );
				}
			}

			$alt = $this->get_attribute( 'alt' );
			if ( null !== $alt ) {
				++$alt_count;
				if ( true === $alt || '' === $alt ) {
					++$empty_alt_count;
				}

				if ( true !== $alt ) {
					$alt_value_bytes += strlen( $alt );
				}
			}

			if ( null !== $this->get_attribute( 'width' ) && null !== $this->get_attribute( 'height' ) ) {
				++$dimension_count;
			}
		}

		return array(
			'tag_count'        => $tag_count,
			'image_count'      => $image_count,
			'src_count'        => $src_count,
			'alt_count'        => $alt_count,
			'empty_alt_count'  => $empty_alt_count,
			'dimension_count'  => $dimension_count,
			'src_value_bytes'  => $src_value_bytes,
			'alt_value_bytes'  => $alt_value_bytes,
		);
	}

	/**
	 * Summarizes script usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and reading `src`,
	 * `type`, `async`, and `defer` attributes from `script` elements, but
	 * native implementations may aggregate the remaining document in one call.
	 * Inline script bytes count text content for scripts without a `src`
	 * attribute.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, script, src, module, async, defer, inline byte, and src byte counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_script_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_script_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_script_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 8 );
				if ( 8 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'           => (int) $parts[0],
						'script_count'        => (int) $parts[1],
						'src_count'           => (int) $parts[2],
						'module_count'        => (int) $parts[3],
						'async_count'         => (int) $parts[4],
						'defer_count'         => (int) $parts[5],
						'inline_script_bytes' => (int) $parts[6],
						'src_value_bytes'     => (int) $parts[7],
					);
				}
			}
		}

		$tag_count           = 0;
		$script_count        = 0;
		$src_count           = 0;
		$module_count        = 0;
		$async_count         = 0;
		$defer_count         = 0;
		$inline_script_bytes = 0;
		$src_value_bytes     = 0;
		$query               = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() || 'SCRIPT' !== $this->get_tag() ) {
				continue;
			}

			++$script_count;

			$src = $this->get_attribute( 'src' );
			if ( null !== $src ) {
				++$src_count;
				if ( true !== $src ) {
					$src_value_bytes += strlen( $src );
				}
			} else {
				$inline_script_bytes += strlen( $this->get_modifiable_text() );
			}

			$type = $this->get_attribute( 'type' );
			if ( is_string( $type ) && 'module' === strtolower( $type ) ) {
				++$module_count;
			}

			if ( null !== $this->get_attribute( 'async' ) ) {
				++$async_count;
			}

			if ( null !== $this->get_attribute( 'defer' ) ) {
				++$defer_count;
			}
		}

		return array(
			'tag_count'           => $tag_count,
			'script_count'        => $script_count,
			'src_count'           => $src_count,
			'module_count'        => $module_count,
			'async_count'         => $async_count,
			'defer_count'         => $defer_count,
			'inline_script_bytes' => $inline_script_bytes,
			'src_value_bytes'     => $src_value_bytes,
		);
	}

	/**
	 * Summarizes form/control usage across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` and reading
	 * control `name` attributes from `input`, `select`, `textarea`, and
	 * `button` elements, but native implementations may aggregate the
	 * remaining document in one call.
	 *
	 * @param bool $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with tag, form, control, named-control, unique-name, and name-byte counts.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_form_inventory( bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_form_inventory_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_form_inventory( $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 6 );
				if ( 6 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'                 => (int) $parts[0],
						'form_count'                => (int) $parts[1],
						'control_count'             => (int) $parts[2],
						'named_control_count'       => (int) $parts[3],
						'unique_control_name_count' => (int) $parts[4],
						'control_name_value_bytes'  => (int) $parts[5],
					);
				}
			}
		}

		$tag_count                = 0;
		$form_count               = 0;
		$control_count            = 0;
		$named_control_count      = 0;
		$control_name_value_bytes = 0;
		$control_names            = array();
		$query                    = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() ) {
				continue;
			}

			switch ( $this->get_tag() ) {
				case 'FORM':
					++$form_count;
					continue 2;
				case 'INPUT':
				case 'SELECT':
				case 'TEXTAREA':
				case 'BUTTON':
					++$control_count;
					break;
				default:
					continue 2;
			}

			$control_name = $this->get_attribute( 'name' );
			if ( null === $control_name ) {
				continue;
			}

			++$named_control_count;
			if ( true !== $control_name ) {
				$control_name_value_bytes      += strlen( $control_name );
				$control_names[ $control_name ] = true;
			} else {
				$control_names[''] = true;
			}
		}

		return array(
			'tag_count'                 => $tag_count,
			'form_count'                => $form_count,
			'control_count'             => $control_count,
			'named_control_count'       => $named_control_count,
			'unique_control_name_count' => count( $control_names ),
			'control_name_value_bytes'  => $control_name_value_bytes,
		);
	}

	/**
	 * Returns summaries for the next chunk of tags matching a tag name.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` with a tag-name
	 * query and reading `get_tag()`/`is_tag_closer()`, but native
	 * implementations may skip non-matching tags without crossing back into
	 * PHP for each one.
	 *
	 * Each row contains `tag_name` and `is_tag_closer`.
	 *
	 * @param string $tag_name      Tag name to match.
	 * @param int    $max_tags      Maximum number of tag summaries to return.
	 * @param bool   $visit_closers Whether closing tags should be included.
	 * @return array[] Tag summary rows.
	 *
	 * @since WP_VERSION
	 */
	public function next_matching_tag_summary_batch( $tag_name, $max_tags = 64, bool $visit_closers = false ): array {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return array();
		}

		$max_tags = min( 256, $max_tags );
		$batch    = $this->next_matching_tag_compact_summary_batch( $tag_name, $max_tags, $visit_closers );
		if ( ! is_string( $batch ) || '' === $batch ) {
			return array();
		}

		$rows         = array();
		$offset       = 0;
		$batch_length = strlen( $batch );

		while ( $offset < $batch_length && count( $rows ) < $max_tags ) {
			$row_end = strpos( $batch, "\x1e", $offset );
			if ( false === $row_end ) {
				$row_end = $batch_length;
			}

			$parts = explode( "\x1f", substr( $batch, $offset, $row_end - $offset ), 2 );
			if ( 2 !== count( $parts ) ) {
				break;
			}

			$rows[] = array(
				'tag_name'      => $parts[0],
				'is_tag_closer' => '1' === $parts[1],
			);
			$offset = $row_end + 1;
		}

		return $rows;
	}

	/**
	 * Returns compact summaries for the next chunk of tags matching a tag name.
	 *
	 * This is the compact-string form of `next_matching_tag_summary_batch()`,
	 * using unit separators between fields and record separators between rows.
	 *
	 * @param string $tag_name      Tag name to match.
	 * @param int    $max_tags      Maximum number of tag summaries to return.
	 * @param bool   $visit_closers Whether closing tags should be included.
	 * @return string|null Compact tag summary batch, or null when exhausted.
	 *
	 * @since WP_VERSION
	 */
	public function next_matching_tag_compact_summary_batch( $tag_name, $max_tags = 64, bool $visit_closers = false ) {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return null;
		}

		$max_tags = min( 256, $max_tags );

		if ( $this->has_native_processor() && $this->native_supports_matching_tag_summary_batch ) {
			$batch = $this->native_processor->next_matching_tag_compact_summary_batch( $tag_name, $max_tags, $visit_closers );
			if ( ! is_string( $batch ) || '' === $batch ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();

				return null;
			}

			if ( $this->native_compact_batch_reached_end( $batch, $max_tags ) ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();
			} else {
				$this->cache_native_final_compact_tag_summary( $batch );
			}

			return $batch;
		}

		$rows  = '';
		$query = array( 'tag_name' => $tag_name );
		if ( $visit_closers ) {
			$query['tag_closers'] = 'visit';
		}
		$count = 0;

		while ( $count < $max_tags && $this->next_tag( $query ) ) {
			if ( '' !== $rows ) {
				$rows .= "\x1e";
			}

			$rows .= implode(
				"\x1f",
				array(
					$this->get_tag(),
					$this->is_tag_closer() ? '1' : '0',
				)
			);
			++$count;
		}

		return '' === $rows ? null : $rows;
	}

	/**
	 * Returns summaries for the next chunk of tags matching a tag name and one attribute.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` with a tag-name
	 * query and then reading `get_tag()`, `is_tag_closer()`, and
	 * `get_attribute()`, but native implementations may skip non-matching tags
	 * and read the requested attribute without crossing back into PHP for each
	 * matching tag.
	 *
	 * Each row contains `tag_name`, `is_tag_closer`, and `attribute_value`.
	 * Missing attributes are represented as `null`.
	 *
	 * @param string $tag_name       Tag name to match.
	 * @param string $attribute_name Attribute name to read from matching tags.
	 * @param int    $max_tags       Maximum number of tag summaries to return.
	 * @param bool   $visit_closers  Whether closing tags should be included.
	 * @return array[] Tag summary rows.
	 *
	 * @since WP_VERSION
	 */
	public function next_matching_tag_attribute_summary_batch( $tag_name, $attribute_name, $max_tags = 64, bool $visit_closers = false ): array {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return array();
		}

		$max_tags = min( 256, $max_tags );
		$batch    = $this->next_matching_tag_attribute_compact_summary_batch( $tag_name, $attribute_name, $max_tags, $visit_closers );
		if ( ! is_string( $batch ) || '' === $batch ) {
			return array();
		}

		$rows         = array();
		$offset       = 0;
		$batch_length = strlen( $batch );

		while ( $offset < $batch_length && count( $rows ) < $max_tags ) {
			$row_end = strpos( $batch, "\x1e", $offset );
			if ( false === $row_end ) {
				$row_end = $batch_length;
			}

			$parts = explode( "\x1f", substr( $batch, $offset, $row_end - $offset ), 3 );
			if ( 3 !== count( $parts ) ) {
				break;
			}

			$rows[] = array(
				'tag_name'        => $parts[0],
				'is_tag_closer'   => '1' === $parts[1],
				'attribute_value' => '1' === substr( $parts[2], 0, 1 ) ? substr( $parts[2], 1 ) : null,
			);
			$offset = $row_end + 1;
		}

		return $rows;
	}

	/**
	 * Returns compact summaries for the next chunk of tags matching a tag name and one attribute.
	 *
	 * This is the compact-string form of
	 * `next_matching_tag_attribute_summary_batch()`, using unit separators
	 * between fields and record separators between rows. The attribute field is
	 * `0` when missing and `1<value>` when present.
	 *
	 * @param string $tag_name       Tag name to match.
	 * @param string $attribute_name Attribute name to read from matching tags.
	 * @param int    $max_tags       Maximum number of tag summaries to return.
	 * @param bool   $visit_closers  Whether closing tags should be included.
	 * @return string|null Compact tag summary batch, or null when exhausted.
	 *
	 * @since WP_VERSION
	 */
	public function next_matching_tag_attribute_compact_summary_batch( $tag_name, $attribute_name, $max_tags = 64, bool $visit_closers = false ) {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return null;
		}

		$max_tags = min( 256, $max_tags );

		if ( $this->has_native_processor() && $this->native_supports_matching_tag_attribute_summary_batch ) {
			$batch = $this->native_processor->next_matching_tag_attribute_compact_summary_batch( $tag_name, $attribute_name, $max_tags, $visit_closers );
			if ( ! is_string( $batch ) || '' === $batch ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();

				return null;
			}

			if ( $this->native_compact_batch_reached_end( $batch, $max_tags ) ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();
			} else {
				$this->cache_native_final_compact_tag_summary( $batch );
			}

			return $batch;
		}

		$rows  = '';
		$query = array( 'tag_name' => $tag_name );
		if ( $visit_closers ) {
			$query['tag_closers'] = 'visit';
		}
		$count = 0;

		while ( $count < $max_tags && $this->next_tag( $query ) ) {
			$attribute_value = $this->get_attribute( $attribute_name );
			if ( '' !== $rows ) {
				$rows .= "\x1e";
			}

			$rows .= implode(
				"\x1f",
				array(
					$this->get_tag(),
					$this->is_tag_closer() ? '1' : '0',
					null === $attribute_value ? '0' : '1' . ( true === $attribute_value ? '' : $attribute_value ),
				)
			);
			++$count;
		}

		return '' === $rows ? null : $rows;
	}

	/**
	 * Returns summaries for the next chunk of tags matching a tag name and attributes.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` with a tag-name
	 * query and then reading `get_tag()`, `is_tag_closer()`, and several
	 * attributes, but native implementations may skip non-matching tags and
	 * read requested attributes without crossing back into PHP for each
	 * matching tag.
	 *
	 * Each row contains `tag_name`, `is_tag_closer`, and `attribute_values`.
	 * Missing attributes are represented as `null`.
	 *
	 * @param string $tag_name        Tag name to match.
	 * @param array  $attribute_names Attribute names to read from matching tags.
	 * @param int    $max_tags        Maximum number of tag summaries to return.
	 * @param bool   $visit_closers   Whether closing tags should be included.
	 * @return array[] Tag summary rows.
	 *
	 * @since WP_VERSION
	 */
	public function next_matching_tag_attributes_summary_batch( $tag_name, $attribute_names, $max_tags = 64, bool $visit_closers = false ): array {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return array();
		}

		$max_tags        = min( 256, $max_tags );
		$attribute_names = $this->normalize_batch_attribute_names( $attribute_names );
		$batch           = $this->next_matching_tag_attributes_compact_summary_batch( $tag_name, $attribute_names, $max_tags, $visit_closers );
		if ( ! is_string( $batch ) || '' === $batch ) {
			return array();
		}

		$rows         = array();
		$offset       = 0;
		$batch_length = strlen( $batch );

		while ( $offset < $batch_length && count( $rows ) < $max_tags ) {
			$row_end = strpos( $batch, "\x1e", $offset );
			if ( false === $row_end ) {
				$row_end = $batch_length;
			}

			$parts = explode( "\x1f", substr( $batch, $offset, $row_end - $offset ), count( $attribute_names ) + 3 );
			if ( count( $parts ) < 2 + count( $attribute_names ) ) {
				break;
			}

			$attribute_values = array();
			foreach ( $attribute_names as $index => $attribute_name ) {
				$field                               = $parts[ $index + 2 ];
				$attribute_values[ $attribute_name ] = '1' === substr( $field, 0, 1 ) ? substr( $field, 1 ) : null;
			}

			$rows[] = array(
				'tag_name'         => $parts[0],
				'is_tag_closer'    => '1' === $parts[1],
				'attribute_values' => $attribute_values,
			);
			$offset = $row_end + 1;
		}

		return $rows;
	}

	/**
	 * Returns compact summaries for the next chunk of tags matching a tag name and attributes.
	 *
	 * This is the compact-string form of
	 * `next_matching_tag_attributes_summary_batch()`, using unit separators
	 * between fields and record separators between rows. Each attribute field
	 * is `0` when missing and `1<value>` when present.
	 *
	 * @param string $tag_name        Tag name to match.
	 * @param array  $attribute_names Attribute names to read from matching tags.
	 * @param int    $max_tags        Maximum number of tag summaries to return.
	 * @param bool   $visit_closers   Whether closing tags should be included.
	 * @return string|null Compact tag summary batch, or null when exhausted.
	 *
	 * @since WP_VERSION
	 */
	public function next_matching_tag_attributes_compact_summary_batch( $tag_name, $attribute_names, $max_tags = 64, bool $visit_closers = false ) {
		$max_tags = (int) $max_tags;
		if ( $max_tags <= 0 ) {
			return null;
		}

		$max_tags        = min( 256, $max_tags );
		$attribute_names = $this->normalize_batch_attribute_names( $attribute_names );

		if ( $this->has_native_processor() && $this->native_supports_matching_tag_attributes_summary_batch ) {
			$batch = $this->native_processor->next_matching_tag_attributes_compact_summary_batch( $tag_name, implode( "\x1f", $attribute_names ), $max_tags, $visit_closers );
			if ( ! is_string( $batch ) || '' === $batch ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();

				return null;
			}

			if ( $this->native_compact_batch_reached_end( $batch, $max_tags ) ) {
				$this->clear_native_tag_metadata();
				$this->update_parser_state_after_native_no_match();
			} else {
				$this->cache_native_final_compact_tag_summary( $batch );
			}

			return $batch;
		}

		$rows  = '';
		$query = array( 'tag_name' => $tag_name );
		if ( $visit_closers ) {
			$query['tag_closers'] = 'visit';
		}
		$count = 0;

		while ( $count < $max_tags && $this->next_tag( $query ) ) {
			if ( '' !== $rows ) {
				$rows .= "\x1e";
			}

			$fields = array(
				$this->get_tag(),
				$this->is_tag_closer() ? '1' : '0',
			);
			foreach ( $attribute_names as $attribute_name ) {
				$attribute_value = $this->get_attribute( $attribute_name );
				$fields[]        = null === $attribute_value ? '0' : '1' . ( true === $attribute_value ? '' : $attribute_value );
			}

			$rows .= implode( "\x1f", $fields );
			++$count;
		}

		return '' === $rows ? null : $rows;
	}

	/**
	 * Summarizes matching tags and selected attributes across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()` with a tag-name
	 * query and then reading several attributes, but native implementations may
	 * aggregate the remaining document in one call.
	 *
	 * The `attribute_value_bytes` count uses decoded attribute values. Boolean
	 * attributes count as present with zero value bytes.
	 *
	 * @param string $tag_name        Tag name to match.
	 * @param array  $attribute_names Attribute names to read from matching tags.
	 * @param bool   $visit_closers   Whether closing tags should count as matching tags.
	 * @return array Summary with `tag_count`, `attribute_count`, and `attribute_value_bytes`.
	 *
	 * @since WP_VERSION
	 */
	public function summarize_matching_tag_attributes( $tag_name, $attribute_names, bool $visit_closers = false ): array {
		$attribute_names = $this->normalize_batch_attribute_names( $attribute_names );

		if ( $this->has_native_processor() && $this->native_supports_matching_tag_attributes_summary ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->summarize_matching_tag_attributes( $tag_name, implode( "\x1f", $attribute_names ), $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 3 );
				if ( 3 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'             => (int) $parts[0],
						'attribute_count'       => (int) $parts[1],
						'attribute_value_bytes' => (int) $parts[2],
					);
				}
			}
		}

		$tag_count             = 0;
		$attribute_count       = 0;
		$attribute_value_bytes = 0;
		$query                 = array( 'tag_name' => $tag_name );
		if ( $visit_closers ) {
			$query['tag_closers'] = 'visit';
		}

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			if ( $this->is_tag_closer() ) {
				continue;
			}

			foreach ( $attribute_names as $attribute_name ) {
				$attribute_value = $this->get_attribute( $attribute_name );
				if ( null === $attribute_value ) {
					continue;
				}

				++$attribute_count;
				if ( true !== $attribute_value ) {
					$attribute_value_bytes += strlen( (string) $attribute_value );
				}
			}
		}

		return array(
			'tag_count'             => $tag_count,
			'attribute_count'       => $attribute_count,
			'attribute_value_bytes' => $attribute_value_bytes,
		);
	}

	/**
	 * Normalizes attribute names used by compact batch helpers.
	 *
	 * @param array $attribute_names Attribute names to normalize.
	 * @return array Normalized attribute names.
	 */
	private function normalize_batch_attribute_names( $attribute_names ) {
		if ( ! is_array( $attribute_names ) ) {
			$attribute_names = array( $attribute_names );
		}

		$normalized = array();
		foreach ( $attribute_names as $attribute_name ) {
			$attribute_name = strtolower( (string) $attribute_name );
			if ( '' === $attribute_name || in_array( $attribute_name, $normalized, true ) ) {
				continue;
			}

			$normalized[] = $attribute_name;
		}

		return $normalized;
	}

	/**
	 * Removes all attributes whose lowercase names match a given prefix.
	 *
	 * @param  string $prefix Prefix of attribute names to remove.
	 *
	 * @return int|null Number of removed attribute names, or `null` when no tag opener is matched.
	 *
	 * @since WP_VERSION
	 */
	public function remove_attributes_with_prefix( $prefix ): ?int {
		if ( $this->has_native_processor() ) {
			if (
				null !== $this->native_tag_token_type &&
				( '#tag' !== $this->native_tag_token_type || $this->native_tag_token_is_closer )
			) {
				return null;
			}

			if (
				null !== $this->native_tag_attribute_name_initials &&
				is_string( $prefix ) &&
				'' !== $prefix
			) {
				$first_prefix_byte = ord( $prefix[0] );
				if ( $first_prefix_byte >= 65 && $first_prefix_byte <= 90 ) {
					$first_prefix_byte += 32;
				}

				if ( $first_prefix_byte >= 97 && $first_prefix_byte <= 122 ) {
					$prefix_initial_bit = 1 << ( $first_prefix_byte - 97 );
					if ( 0 === ( $this->native_tag_attribute_name_initials & $prefix_initial_bit ) ) {
						return 0;
					}
				}
			}

			if ( $this->native_supports_prefix_name_removal ) {
				return $this->native_processor->remove_attributes_with_prefix( $prefix );
			}
		}

		$attribute_names = $this->get_attribute_names_with_prefix( $prefix );
		if ( null === $attribute_names ) {
			return null;
		}

		$removed_count = 0;
		foreach ( $attribute_names as $attribute_name ) {
			if ( null === $this->get_attribute( $attribute_name ) ) {
				continue;
			}

			if ( $this->remove_attribute( $attribute_name ) ) {
				++$removed_count;
			}
		}

		return $removed_count;
	}

	/**
	 * Removes all prefixed attributes across the remaining document.
	 *
	 * This is equivalent to repeatedly calling `next_tag()`,
	 * `remove_attributes_with_prefix()`, and `get_updated_html()`, but native
	 * implementations may process the remaining document and produce updated
	 * HTML in one call.
	 *
	 * @param string $prefix        Prefix of attribute names to remove.
	 * @param bool   $visit_closers Whether closing tags should count as tags.
	 * @return array Summary with `tag_count`, `removed_count`, and `html`.
	 *
	 * @since WP_VERSION
	 */
	public function remove_attributes_with_prefix_from_document( $prefix, bool $visit_closers = false ): array {
		if ( $this->has_native_processor() && $this->native_supports_prefix_name_document_removal ) {
			$this->clear_native_tag_metadata();

			$summary = $this->native_processor->remove_attributes_with_prefix_from_document( $prefix, $visit_closers );
			if ( is_string( $summary ) ) {
				$parts = explode( "\x1f", $summary, 3 );
				if ( 3 === count( $parts ) ) {
					$this->update_parser_state_after_native_no_match();

					return array(
						'tag_count'     => (int) $parts[0],
						'removed_count' => (int) $parts[1],
						'html'          => $parts[2],
					);
				}
			}
		}

		$tag_count     = 0;
		$removed_count = 0;
		$query         = $visit_closers ? array( 'tag_closers' => 'visit' ) : null;

		while ( $this->next_tag( $query ) ) {
			++$tag_count;

			$count = $this->remove_attributes_with_prefix( $prefix );
			if ( is_int( $count ) ) {
				$removed_count += $count;
			}
		}

		return array(
			'tag_count'     => $tag_count,
			'removed_count' => $removed_count,
			'html'          => $this->get_updated_html(),
		);
	}

	/**
	 * Returns the namespace of the matched token.
	 *
	 * @return string One of 'html', 'math', or 'svg'.
	 * @since 6.7.0
	 */
	public function get_namespace(): string {
		if ( $this->has_native_processor() ) {
			return 'html';
		}

		return $this->parsing_namespace;
	}

	/**
	 * Returns the uppercase name of the matched tag.
	 *
	 * Example:
	 *
	 *     $p = new WP_HTML_Tag_Processor( '<div class="test">Test</div>' );
	 *     $p->next_tag() === true;
	 *     $p->get_tag() === 'DIV';
	 *
	 *     $p->next_tag() === false;
	 *     $p->get_tag() === null;
	 *
	 * @return string|null Name of currently matched tag in input HTML, or `null` if none found.
	 * @since 6.2.0
	 */
	public function get_tag(): ?string {
		if ( $this->has_native_processor() ) {
			if ( null !== $this->native_tag_token_type ) {
				if ( '#tag' !== $this->native_tag_token_type ) {
					return $this->native_processor->get_tag();
				}

				return null !== $this->native_tag_token_name
					? $this->native_tag_token_name
					: $this->native_processor->get_tag();
			}

			return $this->native_processor->get_tag();
		}

		if ( null === $this->tag_name_starts_at ) {
			return null;
		}

		$tag_name = substr( $this->html, $this->tag_name_starts_at, $this->tag_name_length );

		if ( self::STATE_MATCHED_TAG === $this->parser_state ) {
			return strtoupper( $tag_name );
		}

		if (
			self::STATE_COMMENT === $this->parser_state &&
			self::COMMENT_AS_PI_NODE_LOOKALIKE === $this->get_comment_type()
		) {
			return $tag_name;
		}

		return null;
	}

	/**
	 * Returns the adjusted tag name for a given token, taking into
	 * account the current parsing context, whether HTML, SVG, or MathML.
	 *
	 * @return string|null Name of current tag name.
	 * @since 6.7.0
	 */
	public function get_qualified_tag_name(): ?string {
		$tag_name = $this->get_tag();
		if ( null === $tag_name ) {
			return null;
		}

		if ( 'html' === $this->get_namespace() ) {
			return $tag_name;
		}

		$lower_tag_name = strtolower( $tag_name );
		if ( 'math' === $this->get_namespace() ) {
			return $lower_tag_name;
		}

		if ( 'svg' === $this->get_namespace() ) {
			switch ( $lower_tag_name ) {
				case 'altglyph':
					return 'altGlyph';

				case 'altglyphdef':
					return 'altGlyphDef';

				case 'altglyphitem':
					return 'altGlyphItem';

				case 'animatecolor':
					return 'animateColor';

				case 'animatemotion':
					return 'animateMotion';

				case 'animatetransform':
					return 'animateTransform';

				case 'clippath':
					return 'clipPath';

				case 'feblend':
					return 'feBlend';

				case 'fecolormatrix':
					return 'feColorMatrix';

				case 'fecomponenttransfer':
					return 'feComponentTransfer';

				case 'fecomposite':
					return 'feComposite';

				case 'feconvolvematrix':
					return 'feConvolveMatrix';

				case 'fediffuselighting':
					return 'feDiffuseLighting';

				case 'fedisplacementmap':
					return 'feDisplacementMap';

				case 'fedistantlight':
					return 'feDistantLight';

				case 'fedropshadow':
					return 'feDropShadow';

				case 'feflood':
					return 'feFlood';

				case 'fefunca':
					return 'feFuncA';

				case 'fefuncb':
					return 'feFuncB';

				case 'fefuncg':
					return 'feFuncG';

				case 'fefuncr':
					return 'feFuncR';

				case 'fegaussianblur':
					return 'feGaussianBlur';

				case 'feimage':
					return 'feImage';

				case 'femerge':
					return 'feMerge';

				case 'femergenode':
					return 'feMergeNode';

				case 'femorphology':
					return 'feMorphology';

				case 'feoffset':
					return 'feOffset';

				case 'fepointlight':
					return 'fePointLight';

				case 'fespecularlighting':
					return 'feSpecularLighting';

				case 'fespotlight':
					return 'feSpotLight';

				case 'fetile':
					return 'feTile';

				case 'feturbulence':
					return 'feTurbulence';

				case 'foreignobject':
					return 'foreignObject';

				case 'glyphref':
					return 'glyphRef';

				case 'lineargradient':
					return 'linearGradient';

				case 'radialgradient':
					return 'radialGradient';

				case 'textpath':
					return 'textPath';

				default:
					return $lower_tag_name;
			}
		}

		// This unnecessary return prevents tools from inaccurately reporting type errors.
		return $tag_name;
	}

	/**
	 * Returns the adjusted attribute name for a given attribute, taking into
	 * account the current parsing context, whether HTML, SVG, or MathML.
	 *
	 * @param  string $attribute_name  Which attribute to adjust.
	 *
	 * @return string|null
	 * @since 6.7.0
	 */
	public function get_qualified_attribute_name( $attribute_name ): ?string {
		if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
			return null;
		}

		$namespace  = $this->get_namespace();
		$lower_name = strtolower( $attribute_name );

		if ( 'math' === $namespace && 'definitionurl' === $lower_name ) {
			return 'definitionURL';
		}

		if ( 'svg' === $this->get_namespace() ) {
			switch ( $lower_name ) {
				case 'attributename':
					return 'attributeName';

				case 'attributetype':
					return 'attributeType';

				case 'basefrequency':
					return 'baseFrequency';

				case 'baseprofile':
					return 'baseProfile';

				case 'calcmode':
					return 'calcMode';

				case 'clippathunits':
					return 'clipPathUnits';

				case 'diffuseconstant':
					return 'diffuseConstant';

				case 'edgemode':
					return 'edgeMode';

				case 'filterunits':
					return 'filterUnits';

				case 'glyphref':
					return 'glyphRef';

				case 'gradienttransform':
					return 'gradientTransform';

				case 'gradientunits':
					return 'gradientUnits';

				case 'kernelmatrix':
					return 'kernelMatrix';

				case 'kernelunitlength':
					return 'kernelUnitLength';

				case 'keypoints':
					return 'keyPoints';

				case 'keysplines':
					return 'keySplines';

				case 'keytimes':
					return 'keyTimes';

				case 'lengthadjust':
					return 'lengthAdjust';

				case 'limitingconeangle':
					return 'limitingConeAngle';

				case 'markerheight':
					return 'markerHeight';

				case 'markerunits':
					return 'markerUnits';

				case 'markerwidth':
					return 'markerWidth';

				case 'maskcontentunits':
					return 'maskContentUnits';

				case 'maskunits':
					return 'maskUnits';

				case 'numoctaves':
					return 'numOctaves';

				case 'pathlength':
					return 'pathLength';

				case 'patterncontentunits':
					return 'patternContentUnits';

				case 'patterntransform':
					return 'patternTransform';

				case 'patternunits':
					return 'patternUnits';

				case 'pointsatx':
					return 'pointsAtX';

				case 'pointsaty':
					return 'pointsAtY';

				case 'pointsatz':
					return 'pointsAtZ';

				case 'preservealpha':
					return 'preserveAlpha';

				case 'preserveaspectratio':
					return 'preserveAspectRatio';

				case 'primitiveunits':
					return 'primitiveUnits';

				case 'refx':
					return 'refX';

				case 'refy':
					return 'refY';

				case 'repeatcount':
					return 'repeatCount';

				case 'repeatdur':
					return 'repeatDur';

				case 'requiredextensions':
					return 'requiredExtensions';

				case 'requiredfeatures':
					return 'requiredFeatures';

				case 'specularconstant':
					return 'specularConstant';

				case 'specularexponent':
					return 'specularExponent';

				case 'spreadmethod':
					return 'spreadMethod';

				case 'startoffset':
					return 'startOffset';

				case 'stddeviation':
					return 'stdDeviation';

				case 'stitchtiles':
					return 'stitchTiles';

				case 'surfacescale':
					return 'surfaceScale';

				case 'systemlanguage':
					return 'systemLanguage';

				case 'tablevalues':
					return 'tableValues';

				case 'targetx':
					return 'targetX';

				case 'targety':
					return 'targetY';

				case 'textlength':
					return 'textLength';

				case 'viewbox':
					return 'viewBox';

				case 'viewtarget':
					return 'viewTarget';

				case 'xchannelselector':
					return 'xChannelSelector';

				case 'ychannelselector':
					return 'yChannelSelector';

				case 'zoomandpan':
					return 'zoomAndPan';
			}
		}

		if ( 'html' !== $namespace ) {
			switch ( $lower_name ) {
				case 'xlink:actuate':
					return 'xlink actuate';

				case 'xlink:arcrole':
					return 'xlink arcrole';

				case 'xlink:href':
					return 'xlink href';

				case 'xlink:role':
					return 'xlink role';

				case 'xlink:show':
					return 'xlink show';

				case 'xlink:title':
					return 'xlink title';

				case 'xlink:type':
					return 'xlink type';

				case 'xml:lang':
					return 'xml lang';

				case 'xml:space':
					return 'xml space';

				case 'xmlns':
					return 'xmlns';

				case 'xmlns:xlink':
					return 'xmlns xlink';
			}
		}

		return $attribute_name;
	}

	/**
	 * Indicates if the currently matched tag contains the self-closing flag.
	 *
	 * No HTML elements ought to have the self-closing flag and for those, the self-closing
	 * flag will be ignored. For void elements this is benign because they "self close"
	 * automatically. For non-void HTML elements though problems will appear if someone
	 * intends to use a self-closing element in place of that element with an empty body.
	 * For HTML foreign elements and custom elements the self-closing flag determines if
	 * they self-close or not.
	 *
	 * This function does not determine if a tag is self-closing,
	 * but only if the self-closing flag is present in the syntax.
	 *
	 * @return bool Whether the currently matched tag contains the self-closing flag.
	 * @since 6.3.0
	 */
	public function has_self_closing_flag(): bool {
		if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
			return false;
		}

		/*
		 * The self-closing flag is the solidus at the _end_ of the tag, not the beginning.
		 *
		 * Example:
		 *
		 *     <figure />
		 *             ^ this appears one character before the end of the closing ">".
		 */

		return '/' === $this->html[ $this->token_starts_at + $this->token_length - 2 ];
	}

	/**
	 * Indicates if the current tag token is a tag closer.
	 *
	 * Example:
	 *
	 *     $p = new WP_HTML_Tag_Processor( '<div></div>' );
	 *     $p->next_tag( array( 'tag_name' => 'div', 'tag_closers' => 'visit' ) );
	 *     $p->is_tag_closer() === false;
	 *
	 *     $p->next_tag( array( 'tag_name' => 'div', 'tag_closers' => 'visit' ) );
	 *     $p->is_tag_closer() === true;
	 *
	 * @return bool Whether the current tag is a tag closer.
	 * @since 6.7.0 Reports all BR tags as opening tags.
	 *
	 * @since 6.2.0
	 */
	public function is_tag_closer(): bool {
		if ( $this->has_native_processor() ) {
			if ( null !== $this->native_tag_token_type ) {
				return $this->native_tag_token_is_closer;
			}

			return $this->native_processor->is_tag_closer();
		}

		return (
			self::STATE_MATCHED_TAG === $this->parser_state &&
			$this->is_closing_tag &&

			/*
			 * The BR tag can only exist as an opening tag. If something like `</br>`
			 * appears then the HTML parser will treat it as an opening tag with no
			 * attributes. The BR tag is unique in this way.
			 *
			 * @see https://html.spec.whatwg.org/#parsing-main-inbody
			 */
			'BR' !== $this->get_tag()
		);
	}

	/**
	 * Indicates the kind of matched token, if any.
	 *
	 * This differs from `get_token_name()` in that it always
	 * returns a static string indicating the type, whereas
	 * `get_token_name()` may return values derived from the
	 * token itself, such as a tag name or processing
	 * instruction tag.
	 *
	 * Possible values:
	 *  - `#tag` when matched on a tag.
	 *  - `#text` when matched on a text node.
	 *  - `#cdata-section` when matched on a CDATA node.
	 *  - `#comment` when matched on a comment.
	 *  - `#doctype` when matched on a DOCTYPE declaration.
	 *  - `#presumptuous-tag` when matched on an empty tag closer.
	 *  - `#funky-comment` when matched on a funky comment.
	 *
	 * @return string|null What kind of token is matched, or null.
	 * @since 6.5.0
	 */
	public function get_token_type(): ?string {
		if ( $this->has_native_processor() ) {
			if ( null !== $this->native_tag_token_type ) {
				return $this->native_tag_token_type;
			}

			return $this->native_processor->get_token_type();
		}

		switch ( $this->parser_state ) {
			case self::STATE_MATCHED_TAG:
				return '#tag';

			case self::STATE_DOCTYPE:
				return '#doctype';

			default:
				return $this->get_token_name();
		}
	}

	/**
	 * Returns the node name represented by the token.
	 *
	 * This matches the DOM API value `nodeName`. Some values
	 * are static, such as `#text` for a text node, while others
	 * are dynamically generated from the token itself.
	 *
	 * Dynamic names:
	 *  - Uppercase tag name for tag matches.
	 *  - `html` for DOCTYPE declarations.
	 *
	 * Note that if the Tag Processor is not matched on a token
	 * then this function will return `null`, either because it
	 * hasn't yet found a token or because it reached the end
	 * of the document without matching a token.
	 *
	 * @return string|null Name of the matched token.
	 * @since 6.5.0
	 */
	public function get_token_name(): ?string {
		if ( $this->has_native_processor() ) {
			if ( null !== $this->native_tag_token_name ) {
				return $this->native_tag_token_name;
			}

			return $this->native_processor->get_token_name();
		}

		switch ( $this->parser_state ) {
			case self::STATE_MATCHED_TAG:
				return $this->get_tag();

			case self::STATE_TEXT_NODE:
				return '#text';

			case self::STATE_CDATA_NODE:
				return '#cdata-section';

			case self::STATE_COMMENT:
				return '#comment';

			case self::STATE_DOCTYPE:
				return 'html';

			case self::STATE_PRESUMPTUOUS_TAG:
				return '#presumptuous-tag';

			case self::STATE_FUNKY_COMMENT:
				return '#funky-comment';
		}

		return null;
	}

	/**
	 * Indicates what kind of comment produced the comment node.
	 *
	 * Because there are different kinds of HTML syntax which produce
	 * comments, the Tag Processor tracks and exposes this as a type
	 * for the comment. Nominally only regular HTML comments exist as
	 * they are commonly known, but a number of unrelated syntax errors
	 * also produce comments.
	 *
	 * @return string|null
	 * @see self::COMMENT_AS_CDATA_LOOKALIKE
	 * @see self::COMMENT_AS_INVALID_HTML
	 * @see self::COMMENT_AS_HTML_COMMENT
	 * @see self::COMMENT_AS_PI_NODE_LOOKALIKE
	 *
	 * @since 6.5.0
	 *
	 * @see self::COMMENT_AS_ABRUPTLY_CLOSED_COMMENT
	 */
	public function get_comment_type(): ?string {
		if ( $this->has_native_processor() ) {
			return $this->native_processor->get_comment_type();
		}

		if ( self::STATE_COMMENT !== $this->parser_state ) {
			return null;
		}

		return $this->comment_type;
	}

	/**
	 * Returns the text of a matched comment or null if not on a comment type node.
	 *
	 * This method returns the entire text content of a comment node as it
	 * would appear in the browser.
	 *
	 * This differs from {@see ::get_modifiable_text()} in that certain comment
	 * types in the HTML API cannot allow their entire comment text content to
	 * be modified. Namely, "bogus comments" of the form `<?not allowed in html>`
	 * will create a comment whose text content starts with `?`. Note that if
	 * that character were modified, it would be possible to change the node
	 * type.
	 *
	 * @return string|null The comment text as it would appear in the browser or null
	 *                     if not on a comment type node.
	 * @since 6.7.0
	 */
	public function get_full_comment_text(): ?string {
		if ( $this->has_native_processor() ) {
			return $this->native_processor->get_full_comment_text();
		}

		if ( self::STATE_FUNKY_COMMENT === $this->parser_state ) {
			return $this->get_modifiable_text();
		}

		if ( self::STATE_COMMENT !== $this->parser_state ) {
			return null;
		}

		switch ( $this->get_comment_type() ) {
			case self::COMMENT_AS_HTML_COMMENT:
			case self::COMMENT_AS_ABRUPTLY_CLOSED_COMMENT:
				return $this->get_modifiable_text();

			case self::COMMENT_AS_CDATA_LOOKALIKE:
				return "[CDATA[{$this->get_modifiable_text()}]]";

			case self::COMMENT_AS_PI_NODE_LOOKALIKE:
				return "?{$this->get_tag()}{$this->get_modifiable_text()}?";

			/*
			 * This represents "bogus comments state" from HTML tokenization.
			 * This can be entered by `<?` or `<!`, where `?` is included in
			 * the comment text but `!` is not.
			 */
			case self::COMMENT_AS_INVALID_HTML:
				$preceding_character = $this->html[ $this->text_starts_at - 1 ];
				$comment_start       = '?' === $preceding_character ? '?' : '';

				return "{$comment_start}{$this->get_modifiable_text()}";
		}

		return null;
	}

	/**
	 * Subdivides a matched text node, splitting NULL byte sequences and decoded whitespace as
	 * distinct nodes prefixes.
	 *
	 * Note that once anything that's neither a NULL byte nor decoded whitespace is
	 * encountered, then the remainder of the text node is left intact as generic text.
	 *
	 *  - The HTML Processor uses this to apply distinct rules for different kinds of text.
	 *  - Inter-element whitespace can be detected and skipped with this method.
	 *
	 * Text nodes aren't eagerly subdivided because there's no need to split them unless
	 * decisions are being made on NULL byte sequences or whitespace-only text.
	 *
	 * Example:
	 *
	 *     $processor = new WP_HTML_Tag_Processor( "\x00Apples & Oranges" );
	 *     true  === $processor->next_token();                   // Text is "Apples & Oranges".
	 *     true  === $processor->subdivide_text_appropriately(); // Text is "".
	 *     true  === $processor->next_token();                   // Text is "Apples & Oranges".
	 *     false === $processor->subdivide_text_appropriately();
	 *
	 *     $processor = new WP_HTML_Tag_Processor( "&#x13; \r\n\tMore" );
	 *     true  === $processor->next_token();                   // Text is "␤ ␤␉More".
	 *     true  === $processor->subdivide_text_appropriately(); // Text is "␤ ␤␉".
	 *     true  === $processor->next_token();                   // Text is "More".
	 *     false === $processor->subdivide_text_appropriately();
	 *
	 * @return bool Whether the text node was subdivided.
	 * @since 6.7.0
	 */
	public function subdivide_text_appropriately(): bool {
		if ( $this->has_native_processor() ) {
			return $this->native_processor->subdivide_text_appropriately();
		}

		if ( self::STATE_TEXT_NODE !== $this->parser_state ) {
			return false;
		}

		$this->text_node_classification = self::TEXT_IS_GENERIC;

		/*
		 * NULL bytes are treated categorically different than numeric character
		 * references whose number is zero. `&#x00;` is not the same as `"\x00"`.
		 */
		$leading_nulls = strspn( $this->html, "\x00", $this->text_starts_at, $this->text_length );
		if ( $leading_nulls > 0 ) {
			$this->token_length             = $leading_nulls;
			$this->text_length              = $leading_nulls;
			$this->bytes_already_parsed     = $this->token_starts_at + $leading_nulls;
			$this->text_node_classification = self::TEXT_IS_NULL_SEQUENCE;

			return true;
		}

		/*
		 * Start a decoding loop to determine the point at which the
		 * text subdivides. This entails raw whitespace bytes and any
		 * character reference that decodes to the same.
		 */
		$at  = $this->text_starts_at;
		$end = $this->text_starts_at + $this->text_length;
		while ( $at < $end ) {
			$skipped = strspn( $this->html, " \t\f\r\n", $at, $end - $at );
			$at     += $skipped;

			if ( $at < $end && '&' === $this->html[ $at ] ) {
				$matched_byte_length = null;
				$replacement         = WP_HTML_Decoder::read_character_reference( 'data', $this->html, $at, $matched_byte_length );
				if ( isset( $replacement ) && 1 === strspn( $replacement, " \t\f\r\n" ) ) {
					$at += $matched_byte_length;
					continue;
				}
			}

			break;
		}

		if ( $at > $this->text_starts_at ) {
			$new_length                     = $at - $this->text_starts_at;
			$this->text_length              = $new_length;
			$this->token_length             = $new_length;
			$this->bytes_already_parsed     = $at;
			$this->text_node_classification = self::TEXT_IS_WHITESPACE;

			return true;
		}

		return false;
	}

	/**
	 * Returns the modifiable text for a matched token, or an empty string.
	 *
	 * Modifiable text is text content that may be read and changed without
	 * changing the HTML structure of the document around it. This includes
	 * the contents of `#text` nodes in the HTML as well as the inner
	 * contents of HTML comments, Processing Instructions, and others, even
	 * though these nodes aren't part of a parsed DOM tree. They also contain
	 * the contents of SCRIPT and STYLE tags, of TEXTAREA tags, and of any
	 * other section in an HTML document which cannot contain HTML markup (DATA).
	 *
	 * If a token has no modifiable text then an empty string is returned to
	 * avoid needless crashing or type errors. An empty string does not mean
	 * that a token has modifiable text, and a token with modifiable text may
	 * have an empty string (e.g. a comment with no contents).
	 *
	 * Limitations:
	 *
	 *  - This function will not strip the leading newline appropriately
	 *    after seeking into a LISTING or PRE element. To ensure that the
	 *    newline is treated properly, seek to the LISTING or PRE opening
	 *    tag instead of to the first text node inside the element.
	 *
	 * @return string
	 * @since 6.7.0 Replaces NULL bytes (U+0000) and newlines appropriately.
	 *
	 * @since 6.5.0
	 */
	public function get_modifiable_text(): string {
		if ( $this->has_native_processor() ) {
			return $this->native_processor->get_modifiable_text();
		}

		$has_enqueued_update = isset( $this->lexical_updates['modifiable text'] );

		if ( ! $has_enqueued_update && ( null === $this->text_starts_at || 0 === $this->text_length ) ) {
			return '';
		}

		$text = $has_enqueued_update
			? $this->lexical_updates['modifiable text']->text
			: substr( $this->html, $this->text_starts_at, $this->text_length );

		/*
		 * Pre-processing the input stream would normally happen before
		 * any parsing is done, but deferring it means it's possible to
		 * skip in most cases. When getting the modifiable text, however
		 * it's important to apply the pre-processing steps, which is
		 * normalizing newlines.
		 *
		 * @see https://html.spec.whatwg.org/#preprocessing-the-input-stream
		 * @see https://infra.spec.whatwg.org/#normalize-newlines
		 */
		$text = str_replace( "\r\n", "\n", $text );
		$text = str_replace( "\r", "\n", $text );

		// Comment data is not decoded.
		if (
			self::STATE_CDATA_NODE === $this->parser_state ||
			self::STATE_COMMENT === $this->parser_state ||
			self::STATE_DOCTYPE === $this->parser_state ||
			self::STATE_FUNKY_COMMENT === $this->parser_state
		) {
			return str_replace( "\x00", "\u{FFFD}", $text );
		}

		$tag_name = $this->get_token_name();
		if (
			// Script data is not decoded.
			'SCRIPT' === $tag_name ||

			// RAWTEXT data is not decoded.
			'IFRAME' === $tag_name ||
			'NOEMBED' === $tag_name ||
			'NOFRAMES' === $tag_name ||
			'STYLE' === $tag_name ||
			'XMP' === $tag_name
		) {
			return str_replace( "\x00", "\u{FFFD}", $text );
		}

		$decoded = WP_HTML_Decoder::decode_text_node( $text );

		/*
		 * Skip the first line feed after LISTING, PRE, and TEXTAREA opening tags.
		 *
		 * Note that this first newline may come in the form of a character
		 * reference, such as `&#x0a;`, and so it's important to perform
		 * this transformation only after decoding the raw text content.
		 */
		if (
			( "\n" === ( $decoded[0] ?? '' ) ) &&
			( ( $this->skip_newline_at === $this->token_starts_at && '#text' === $tag_name ) || 'TEXTAREA' === $tag_name )
		) {
			$decoded = substr( $decoded, 1 );
		}

		/*
		 * Only in normative text nodes does the NULL byte (U+0000) get removed.
		 * In all other contexts it's replaced by the replacement character (U+FFFD)
		 * for security reasons (to avoid joining together strings that were safe
		 * when separated, but not when joined).
		 *
		 * @todo Inside HTML integration points and MathML integration points, the
		 *       text is processed according to the insertion mode, not according
		 *       to the foreign content rules. This should strip the NULL bytes.
		 */

		return ( '#text' === $tag_name && 'html' === $this->get_namespace() )
			? str_replace( "\x00", '', $decoded )
			: str_replace( "\x00", "\u{FFFD}", $decoded );
	}

	/**
	 * Sets the modifiable text for the matched token, if matched.
	 *
	 * Modifiable text is text content that may be read and changed without
	 * changing the HTML structure of the document around it. This includes
	 * the contents of `#text` nodes in the HTML as well as the inner
	 * contents of HTML comments, Processing Instructions, and others, even
	 * though these nodes aren't part of a parsed DOM tree. They also contain
	 * the contents of SCRIPT and STYLE tags, of TEXTAREA tags, and of any
	 * other section in an HTML document which cannot contain HTML markup (DATA).
	 *
	 * Not all modifiable text may be set by this method, and not all content
	 * may be set as modifiable text. In the case that this fails it will return
	 * `false` indicating as much. For instance, it will not allow inserting the
	 * string `</script` into a SCRIPT element, because the rules for escaping
	 * that safely are complicated. Similarly, it will not allow setting content
	 * into a comment which would prematurely terminate the comment.
	 *
	 * Example:
	 *
	 *     // Add a preface to all STYLE contents.
	 *     while ( $processor->next_tag( 'STYLE' ) ) {
	 *         $style = $processor->get_modifiable_text();
	 *         $processor->set_modifiable_text( "// Made with love on the World Wide Web\n{$style}" );
	 *     }
	 *
	 *     // Replace smiley text with Emoji smilies.
	 *     while ( $processor->next_token() ) {
	 *         if ( '#text' !== $processor->get_token_name() ) {
	 *             continue;
	 *         }
	 *
	 *         $chunk = $processor->get_modifiable_text();
	 *         if ( ! str_contains( $chunk, ':)' ) ) {
	 *             continue;
	 *         }
	 *
	 *         $processor->set_modifiable_text( str_replace( ':)', '🙂', $chunk ) );
	 *     }
	 *
	 * @param  string $plaintext_content  New text content to represent in the matched token.
	 *
	 * @return bool Whether the text was able to update.
	 * @since 6.7.0
	 */
	public function set_modifiable_text( string $plaintext_content ): bool {
		if ( $this->has_native_processor() ) {
			return $this->native_processor->set_modifiable_text( $plaintext_content );
		}

		if ( self::STATE_TEXT_NODE === $this->parser_state ) {
			$this->lexical_updates['modifiable text'] = new WP_HTML_Text_Replacement(
				$this->text_starts_at,
				$this->text_length,
				htmlspecialchars( $plaintext_content, ENT_QUOTES | ENT_HTML5 )
			);

			return true;
		}

		// Comment data is not encoded.
		if (
			self::STATE_COMMENT === $this->parser_state &&
			self::COMMENT_AS_HTML_COMMENT === $this->comment_type
		) {
			// Check if the text could close the comment.
			if ( 1 === preg_match( '/--!?>/', $plaintext_content ) ) {
				return false;
			}

			$this->lexical_updates['modifiable text'] = new WP_HTML_Text_Replacement(
				$this->text_starts_at,
				$this->text_length,
				$plaintext_content
			);

			return true;
		}

		if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
			return false;
		}

		switch ( $this->get_tag() ) {
			case 'SCRIPT':
				/*
				 * This is over-protective, but ensures the update doesn't break
				 * out of the SCRIPT element. A more thorough check would need to
				 * ensure that the script closing tag doesn't exist, and isn't
				 * also "hidden" inside the script double-escaped state.
				 *
				 * It may seem like replacing `</script` with `<\/script` would
				 * properly escape these things, but this could mask regex patterns
				 * that previously worked. Resolve this by not sending `</script`
				 */
				if ( false !== stripos( $plaintext_content, '</script' ) ) {
					return false;
				}

				$this->lexical_updates['modifiable text'] = new WP_HTML_Text_Replacement(
					$this->text_starts_at,
					$this->text_length,
					$plaintext_content
				);

				return true;

			case 'STYLE':
				$plaintext_content = preg_replace_callback(
					'~</(?P<TAG_NAME>style)~i',
					static function ( $tag_match ) {
						return "\\3c\\2f{$tag_match['TAG_NAME']}";
					},
					$plaintext_content
				);

				$this->lexical_updates['modifiable text'] = new WP_HTML_Text_Replacement(
					$this->text_starts_at,
					$this->text_length,
					$plaintext_content
				);

				return true;

			case 'TEXTAREA':
			case 'TITLE':
				$plaintext_content = preg_replace_callback(
					"~</(?P<TAG_NAME>{$this->get_tag()})~i",
					static function ( $tag_match ) {
						return "&lt;/{$tag_match['TAG_NAME']}";
					},
					$plaintext_content
				);

				/*
				 * These don't _need_ to be escaped, but since they are decoded it's
				 * safe to leave them escaped and this can prevent other code from
				 * naively detecting tags within the contents.
				 *
				 * @todo It would be useful to prefix a multiline replacement text
				 *       with a newline, but not necessary. This is for aesthetics.
				 */
				$this->lexical_updates['modifiable text'] = new WP_HTML_Text_Replacement(
					$this->text_starts_at,
					$this->text_length,
					$plaintext_content
				);

				return true;
		}

		return false;
	}

	/**
	 * Updates or creates a new attribute on the currently matched tag with the passed value.
	 *
	 * For boolean attributes special handling is provided:
	 *  - When `true` is passed as the value, then only the attribute name is added to the tag.
	 *  - When `false` is passed, the attribute gets removed if it existed before.
	 *
	 * For string attributes, the value is escaped using the `esc_attr` function.
	 *
	 * @param  string      $name  The attribute name to target.
	 * @param  string|bool $value  The new attribute value.
	 *
	 * @return bool Whether an attribute value was set.
	 * @since 6.2.1 Fix: Only create a single update for multiple calls with case-variant attribute names.
	 *
	 * @since 6.2.0
	 */
	public function set_attribute( $name, $value ): bool {
		if (
			self::STATE_MATCHED_TAG !== $this->parser_state ||
			$this->is_closing_tag
		) {
			return false;
		}

		/*
		 * WordPress rejects more characters than are strictly forbidden
		 * in HTML5. This is to prevent additional security risks deeper
		 * in the WordPress and plugin stack. Specifically the
		 * less-than (<) greater-than (>) and ampersand (&) aren't allowed.
		 *
		 * The use of a PCRE match enables looking for specific Unicode
		 * code points without writing a UTF-8 decoder. Whereas scanning
		 * for one-byte characters is trivial (with `strcspn`), scanning
		 * for the longer byte sequences would be more complicated. Given
		 * that this shouldn't be in the hot path for execution, it's a
		 * reasonable compromise in efficiency without introducing a
		 * noticeable impact on the overall system.
		 *
		 * @see https://html.spec.whatwg.org/#attributes-2
		 *
		 * @todo As the only regex pattern maybe we should take it out?
		 *       Are Unicode patterns available broadly in Core?
		 */
		if ( preg_match(
			'~[' .
			// Syntax-like characters.
			'"\'>&</ =' .
			// Control characters.
			'\x{00}-\x{1F}' .
			// HTML noncharacters.
			'\x{FDD0}-\x{FDEF}' .
			'\x{FFFE}\x{FFFF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}' .
			'\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}' .
			'\x{8FFFE}\x{8FFFF}\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}' .
			'\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}' .
			'\x{10FFFE}\x{10FFFF}' .
			']~Ssu',
			$name
		) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Invalid attribute name.' ),
				'6.2.0'
			);

			return false;
		}

		if ( $this->has_native_processor() ) {
			return method_exists( $this->native_processor, 'set_attribute' )
				? $this->native_processor->set_attribute( $name, $value )
				: false;
		}

		/*
		 * > The values "true" and "false" are not allowed on boolean attributes.
		 * > To represent a false value, the attribute has to be omitted altogether.
		 *     - HTML5 spec, https://html.spec.whatwg.org/#boolean-attributes
		 */
		if ( false === $value ) {
			return $this->remove_attribute( $name );
		}

		if ( true === $value ) {
			$updated_attribute = $name;
		} else {
			$comparable_name = strtolower( $name );

			/*
			 * Escape URL attributes.
			 *
			 * @see https://html.spec.whatwg.org/#attributes-3
			 */
			$escaped_new_value = in_array( $comparable_name, wp_kses_uri_attributes(), true ) ? esc_url( $value ) : esc_attr( $value );

			// If the escaping functions wiped out the update, reject it and indicate it was rejected.
			if ( '' === $escaped_new_value && '' !== $value ) {
				return false;
			}

			$updated_attribute = "{$name}=\"{$escaped_new_value}\"";
		}

		/*
		 * > There must never be two or more attributes on
		 * > the same start tag whose names are an ASCII
		 * > case-insensitive match for each other.
		 *     - HTML 5 spec
		 *
		 * @see https://html.spec.whatwg.org/multipage/syntax.html#attributes-2:ascii-case-insensitive
		 */
		$comparable_name = strtolower( $name );

		if ( isset( $this->attributes[ $comparable_name ] ) ) {
			/*
			 * Update an existing attribute.
			 *
			 * Example – set attribute id to "new" in <div id="initial_id" />:
			 *
			 *     <div id="initial_id"/>
			 *          ^-------------^
			 *          start         end
			 *     replacement: `id="new"`
			 *
			 *     Result: <div id="new"/>
			 */
			$existing_attribute                        = $this->attributes[ $comparable_name ];
			$this->lexical_updates[ $comparable_name ] = new WP_HTML_Text_Replacement(
				$existing_attribute->start,
				$existing_attribute->length,
				$updated_attribute
			);
		} else {
			/*
			 * Create a new attribute at the tag's name end.
			 *
			 * Example – add attribute id="new" to <div />:
			 *
			 *     <div/>
			 *         ^
			 *         start and end
			 *     replacement: ` id="new"`
			 *
			 *     Result: <div id="new"/>
			 */
			$this->lexical_updates[ $comparable_name ] = new WP_HTML_Text_Replacement(
				$this->tag_name_starts_at + $this->tag_name_length,
				0,
				' ' . $updated_attribute
			);
		}

		/*
		 * Any calls to update the `class` attribute directly should wipe out any
		 * enqueued class changes from `add_class` and `remove_class`.
		 */
		if ( 'class' === $comparable_name && ! empty( $this->classname_updates ) ) {
			$this->classname_updates = array();
		}

		return true;
	}

	/**
	 * Remove an attribute from the currently-matched tag.
	 *
	 * @param  string $name  The attribute name to remove.
	 *
	 * @return bool Whether an attribute was removed.
	 * @since 6.2.0
	 */
	public function remove_attribute( $name ): bool {
		if ( $this->has_native_processor() ) {
			return method_exists( $this->native_processor, 'remove_attribute' )
				? $this->native_processor->remove_attribute( $name )
				: false;
		}

		if (
			self::STATE_MATCHED_TAG !== $this->parser_state ||
			$this->is_closing_tag
		) {
			return false;
		}

		/*
		 * > There must never be two or more attributes on
		 * > the same start tag whose names are an ASCII
		 * > case-insensitive match for each other.
		 *     - HTML 5 spec
		 *
		 * @see https://html.spec.whatwg.org/multipage/syntax.html#attributes-2:ascii-case-insensitive
		 */
		$name = strtolower( $name );

		/*
		 * Any calls to update the `class` attribute directly should wipe out any
		 * enqueued class changes from `add_class` and `remove_class`.
		 */
		if ( 'class' === $name && 0 !== count( $this->classname_updates ) ) {
			$this->classname_updates = array();
		}

		/*
		 * If updating an attribute that didn't exist in the input
		 * document, then remove the enqueued update and move on.
		 *
		 * For example, this might occur when calling `remove_attribute()`
		 * after calling `set_attribute()` for the same attribute
		 * and when that attribute wasn't originally present.
		 */
		if ( ! isset( $this->attributes[ $name ] ) ) {
			if ( isset( $this->lexical_updates[ $name ] ) ) {
				unset( $this->lexical_updates[ $name ] );
			}

			return false;
		}

		/*
		 * Removes an existing tag attribute.
		 *
		 * Example – remove the attribute id from <div id="main"/>:
		 *    <div id="initial_id"/>
		 *         ^-------------^
		 *         start         end
		 *    replacement: ``
		 *
		 *    Result: <div />
		 */
		$this->lexical_updates[ $name ] = new WP_HTML_Text_Replacement(
			$this->attributes[ $name ]->start,
			$this->attributes[ $name ]->length,
			''
		);

		// Removes any duplicated attributes if they were also present.
		foreach ( $this->duplicate_attributes[ $name ] ?? array() as $attribute_token ) {
			$this->lexical_updates[] = new WP_HTML_Text_Replacement(
				$attribute_token->start,
				$attribute_token->length,
				''
			);
		}

		return true;
	}

	/**
	 * Adds a new class name to the currently matched tag.
	 *
	 * @param  string $class_name  The class name to add.
	 *
	 * @return bool Whether the class was set to be added.
	 * @since 6.2.0
	 */
	public function add_class( $class_name ): bool {
		if (
			self::STATE_MATCHED_TAG !== $this->parser_state ||
			$this->is_closing_tag
		) {
			return false;
		}

		if ( $this->has_native_processor() ) {
			return method_exists( $this->native_processor, 'add_class' )
				? $this->native_processor->add_class( $class_name )
				: false;
		}

		if ( self::QUIRKS_MODE !== $this->compat_mode ) {
			$this->classname_updates[ $class_name ] = self::ADD_CLASS;

			return true;
		}

		/*
		 * Because class names are matched ASCII-case-insensitively in quirks mode,
		 * this needs to see if a case variant of the given class name is already
		 * enqueued and update that existing entry, if so. This picks the casing of
		 * the first-provided class name for all lexical variations.
		 */
		$class_name_length = strlen( $class_name );
		foreach ( $this->classname_updates as $updated_name => $action ) {
			if (
				strlen( $updated_name ) === $class_name_length &&
				0 === substr_compare( $updated_name, $class_name, 0, $class_name_length, true )
			) {
				$this->classname_updates[ $updated_name ] = self::ADD_CLASS;

				return true;
			}
		}

		$this->classname_updates[ $class_name ] = self::ADD_CLASS;

		return true;
	}

	/**
	 * Removes a class name from the currently matched tag.
	 *
	 * @param  string $class_name  The class name to remove.
	 *
	 * @return bool Whether the class was set to be removed.
	 * @since 6.2.0
	 */
	public function remove_class( $class_name ): bool {
		if (
			self::STATE_MATCHED_TAG !== $this->parser_state ||
			$this->is_closing_tag
		) {
			return false;
		}

		if ( $this->has_native_processor() ) {
			return method_exists( $this->native_processor, 'remove_class' )
				? $this->native_processor->remove_class( $class_name )
				: false;
		}

		if ( self::QUIRKS_MODE !== $this->compat_mode ) {
			$this->classname_updates[ $class_name ] = self::REMOVE_CLASS;

			return true;
		}

		/*
		 * Because class names are matched ASCII-case-insensitively in quirks mode,
		 * this needs to see if a case variant of the given class name is already
		 * enqueued and update that existing entry, if so. This picks the casing of
		 * the first-provided class name for all lexical variations.
		 */
		$class_name_length = strlen( $class_name );
		foreach ( $this->classname_updates as $updated_name => $action ) {
			if (
				strlen( $updated_name ) === $class_name_length &&
				0 === substr_compare( $updated_name, $class_name, 0, $class_name_length, true )
			) {
				$this->classname_updates[ $updated_name ] = self::REMOVE_CLASS;

				return true;
			}
		}

		$this->classname_updates[ $class_name ] = self::REMOVE_CLASS;

		return true;
	}

	/**
	 * Returns the string representation of the HTML Tag Processor.
	 *
	 * @return string The processed HTML.
	 * @see WP_HTML_Tag_Processor::get_updated_html()
	 *
	 * @since 6.2.0
	 */
	public function __toString(): string {
		return $this->get_updated_html();
	}

	/**
	 * Returns the string representation of the HTML Tag Processor.
	 *
	 * @return string The processed HTML.
	 * @since 6.2.1 Shifts the internal cursor corresponding to the applied updates.
	 * @since 6.4.0 No longer calls subclass method `next_tag()` after updating HTML.
	 *
	 * @since 6.2.0
	 */
	public function get_updated_html(): string {
		if (
			$this->has_native_processor() &&
			method_exists( $this->native_processor, 'get_updated_html' )
		) {
			return $this->native_processor->get_updated_html();
		}

		$requires_no_updating = 0 === count( $this->classname_updates ) && 0 === count( $this->lexical_updates );

		/*
		 * When there is nothing more to update and nothing has already been
		 * updated, return the original document and avoid a string copy.
		 */
		if ( $requires_no_updating ) {
			return $this->html;
		}

		/*
		 * Keep track of the position right before the current tag. This will
		 * be necessary for reparsing the current tag after updating the HTML.
		 */
		$before_current_tag = $this->token_starts_at ?? 0;

		/*
		 * 1. Apply the enqueued edits and update all the pointers to reflect those changes.
		 */
		$this->class_name_updates_to_attributes_updates();
		$before_current_tag += $this->apply_attributes_updates( $before_current_tag );

		/*
		 * 2. Rewind to before the current tag and reparse to get updated attributes.
		 *
		 * At this point the internal cursor points to the end of the tag name.
		 * Rewind before the tag name starts so that it's as if the cursor didn't
		 * move; a call to `next_tag()` will reparse the recently-updated attributes
		 * and additional calls to modify the attributes will apply at this same
		 * location, but in order to avoid issues with subclasses that might add
		 * behaviors to `next_tag()`, the internal methods should be called here
		 * instead.
		 *
		 * It's important to note that in this specific place there will be no change
		 * because the processor was already at a tag when this was called and it's
		 * rewinding only to the beginning of this very tag before reprocessing it
		 * and its attributes.
		 *
		 * <p>Previous HTML<em>More HTML</em></p>
		 *                 ↑  │ back up by the length of the tag name plus the opening <
		 *                 └←─┘ back up by strlen("em") + 1 ==> 3
		 */
		$this->bytes_already_parsed = $before_current_tag;
		$this->base_class_next_token();

		return $this->html;
	}

	/**
	 * Parses tag query input into internal search criteria.
	 *
	 * @param  array|string|null $query  {
	 *    Optional. Which tag name to find, having which class, etc. Default is to find any tag.
	 *
	 * @type string|null $tag_name Which tag to find, or `null` for "any tag."
	 * @type int|null $match_offset Find the Nth tag matching all search criteria.
	 *                                     1 for "first" tag, 3 for "third," etc.
	 *                                     Defaults to first tag.
	 * @type string|null $class_name Tag must contain this class name to match.
	 * @type string $tag_closers "visit" or "skip": whether to stop on tag closers, e.g. </div>.
	 * }
	 * @since 6.2.0
	 */
	private function parse_query( $query ) {
		if ( null !== $query && $query === $this->last_query ) {
			return;
		}

		$this->last_query          = $query;
		$this->sought_tag_name     = null;
		$this->sought_class_name   = null;
		$this->sought_match_offset = 1;
		$this->stop_on_tag_closers = false;

		// A single string value means "find the tag of this name".
		if ( is_string( $query ) ) {
			$this->sought_tag_name = $query;

			return;
		}

		// An empty query parameter applies no restrictions on the search.
		if ( null === $query ) {
			return;
		}

		// If not using the string interface, an associative array is required.
		if ( ! is_array( $query ) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'The query argument must be an array or a tag name.' ),
				'6.2.0'
			);

			return;
		}

		if ( isset( $query['tag_name'] ) && is_string( $query['tag_name'] ) ) {
			$this->sought_tag_name = $query['tag_name'];
		}

		if ( isset( $query['class_name'] ) && is_string( $query['class_name'] ) ) {
			$this->sought_class_name = $query['class_name'];
		}

		if ( isset( $query['match_offset'] ) && is_int( $query['match_offset'] ) && 0 < $query['match_offset'] ) {
			$this->sought_match_offset = $query['match_offset'];
		}

		if ( isset( $query['tag_closers'] ) ) {
			$this->stop_on_tag_closers = 'visit' === $query['tag_closers'];
		}
	}


	/**
	 * Checks whether a given tag and its attributes match the search criteria.
	 *
	 * @return bool Whether the given tag and its attribute match the search criteria.
	 * @since 6.2.0
	 */
	private function matches(): bool {
		if ( $this->is_closing_tag && ! $this->stop_on_tag_closers ) {
			return false;
		}

		// Does the tag name match the requested tag name in a case-insensitive manner?
		if (
			isset( $this->sought_tag_name ) &&
			(
				strlen( $this->sought_tag_name ) !== $this->tag_name_length ||
				0 !== substr_compare( $this->html, $this->sought_tag_name, $this->tag_name_starts_at, $this->tag_name_length, true )
			)
		) {
			return false;
		}

		if ( null !== $this->sought_class_name && ! $this->has_class( $this->sought_class_name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Gets DOCTYPE declaration info from a DOCTYPE token.
	 *
	 * DOCTYPE tokens may appear in many places in an HTML document. In most places, they are
	 * simply ignored. The main parsing functions find the basic shape of DOCTYPE tokens but
	 * do not perform detailed parsing.
	 *
	 * This method can be called to perform a full parse of the DOCTYPE token and retrieve
	 * its information.
	 *
	 * @return WP_HTML_Doctype_Info|null The DOCTYPE declaration information or `null` if not
	 *                                   currently at a DOCTYPE node.
	 */
	public function get_doctype_info(): ?WP_HTML_Doctype_Info {
		if ( self::STATE_DOCTYPE !== $this->parser_state ) {
			return null;
		}

		return WP_HTML_Doctype_Info::from_doctype_token( substr( $this->html, $this->token_starts_at, $this->token_length ) );
	}

	/**
	 * Parser Ready State.
	 *
	 * Indicates that the parser is ready to run and waiting for a state transition.
	 * It may not have started yet, or it may have just finished parsing a token and
	 * is ready to find the next one.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_READY = 'STATE_READY';

	/**
	 * Parser Complete State.
	 *
	 * Indicates that the parser has reached the end of the document and there is
	 * nothing left to scan. It finished parsing the last token completely.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_COMPLETE = 'STATE_COMPLETE';

	/**
	 * Parser Incomplete Input State.
	 *
	 * Indicates that the parser has reached the end of the document before finishing
	 * a token. It started parsing a token but there is a possibility that the input
	 * HTML document was truncated in the middle of a token.
	 *
	 * The parser is reset at the start of the incomplete token and has paused. There
	 * is nothing more than can be scanned unless provided a more complete document.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_INCOMPLETE_INPUT = 'STATE_INCOMPLETE_INPUT';

	/**
	 * Parser Matched Tag State.
	 *
	 * Indicates that the parser has found an HTML tag and it's possible to get
	 * the tag name and read or modify its attributes (if it's not a closing tag).
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_MATCHED_TAG = 'STATE_MATCHED_TAG';

	/**
	 * Parser Text Node State.
	 *
	 * Indicates that the parser has found a text node and it's possible
	 * to read and modify that text.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_TEXT_NODE = 'STATE_TEXT_NODE';

	/**
	 * Parser CDATA Node State.
	 *
	 * Indicates that the parser has found a CDATA node and it's possible
	 * to read and modify its modifiable text. Note that in HTML there are
	 * no CDATA nodes outside of foreign content (SVG and MathML). Outside
	 * of foreign content, they are treated as HTML comments.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_CDATA_NODE = 'STATE_CDATA_NODE';

	/**
	 * Indicates that the parser has found an HTML comment and it's
	 * possible to read and modify its modifiable text.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_COMMENT = 'STATE_COMMENT';

	/**
	 * Indicates that the parser has found a DOCTYPE node and it's
	 * possible to read its DOCTYPE information via `get_doctype_info()`.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_DOCTYPE = 'STATE_DOCTYPE';

	/**
	 * Indicates that the parser has found an empty tag closer `</>`.
	 *
	 * Note that in HTML there are no empty tag closers, and they
	 * are ignored. Nonetheless, the Tag Processor still
	 * recognizes them as they appear in the HTML stream.
	 *
	 * These were historically discussed as a "presumptuous tag
	 * closer," which would close the nearest open tag, but were
	 * dismissed in favor of explicitly-closing tags.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_PRESUMPTUOUS_TAG = 'STATE_PRESUMPTUOUS_TAG';

	/**
	 * Indicates that the parser has found a "funky comment"
	 * and it's possible to read and modify its modifiable text.
	 *
	 * Example:
	 *
	 *     </%url>
	 *     </{"wp-bit":"query/post-author"}>
	 *     </2>
	 *
	 * Funky comments are tag closers with invalid tag names. Note
	 * that in HTML these are turn into bogus comments. Nonetheless,
	 * the Tag Processor recognizes them in a stream of HTML and
	 * exposes them for inspection and modification.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 */
	const STATE_FUNKY_COMMENT = 'STATE_WP_FUNKY';

	/**
	 * Indicates that a comment was created when encountering abruptly-closed HTML comment.
	 *
	 * Example:
	 *
	 *     <!-->
	 *     <!--->
	 *
	 * @since 6.5.0
	 */
	const COMMENT_AS_ABRUPTLY_CLOSED_COMMENT = 'COMMENT_AS_ABRUPTLY_CLOSED_COMMENT';

	/**
	 * Indicates that a comment would be parsed as a CDATA node,
	 * were HTML to allow CDATA nodes outside of foreign content.
	 *
	 * Example:
	 *
	 *     <![CDATA[This is a CDATA node.]]>
	 *
	 * This is an HTML comment, but it looks like a CDATA node.
	 *
	 * @since 6.5.0
	 */
	const COMMENT_AS_CDATA_LOOKALIKE = 'COMMENT_AS_CDATA_LOOKALIKE';

	/**
	 * Indicates that a comment was created when encountering
	 * normative HTML comment syntax.
	 *
	 * Example:
	 *
	 *     <!-- this is a comment -->
	 *
	 * @since 6.5.0
	 */
	const COMMENT_AS_HTML_COMMENT = 'COMMENT_AS_HTML_COMMENT';

	/**
	 * Indicates that a comment would be parsed as a Processing
	 * Instruction node, were they to exist within HTML.
	 *
	 * Example:
	 *
	 *     <?wp __( 'Like' ) ?>
	 *
	 * This is an HTML comment, but it looks like a CDATA node.
	 *
	 * @since 6.5.0
	 */
	const COMMENT_AS_PI_NODE_LOOKALIKE = 'COMMENT_AS_PI_NODE_LOOKALIKE';

	/**
	 * Indicates that a comment was created when encountering invalid
	 * HTML input, a so-called "bogus comment."
	 *
	 * Example:
	 *
	 *     <?nothing special>
	 *     <!{nothing special}>
	 *
	 * @since 6.5.0
	 */
	const COMMENT_AS_INVALID_HTML = 'COMMENT_AS_INVALID_HTML';

	/**
	 * No-quirks mode document compatability mode.
	 *
	 * > In no-quirks mode, the behavior is (hopefully) the desired behavior
	 * > described by the modern HTML and CSS specifications.
	 *
	 * @see self::$compat_mode
	 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Quirks_Mode_and_Standards_Mode
	 *
	 * @since 6.7.0
	 *
	 * @var string
	 */
	const NO_QUIRKS_MODE = 'no-quirks-mode';

	/**
	 * Quirks mode document compatability mode.
	 *
	 * > In quirks mode, layout emulates behavior in Navigator 4 and Internet
	 * > Explorer 5. This is essential in order to support websites that were
	 * > built before the widespread adoption of web standards.
	 *
	 * @see self::$compat_mode
	 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Quirks_Mode_and_Standards_Mode
	 *
	 * @since 6.7.0
	 *
	 * @var string
	 */
	const QUIRKS_MODE = 'quirks-mode';

	/**
	 * Indicates that a span of text may contain any combination of significant
	 * kinds of characters: NULL bytes, whitespace, and others.
	 *
	 * @see self::$text_node_classification
	 * @see self::subdivide_text_appropriately
	 *
	 * @since 6.7.0
	 */
	const TEXT_IS_GENERIC = 'TEXT_IS_GENERIC';

	/**
	 * Indicates that a span of text comprises a sequence only of NULL bytes.
	 *
	 * @see self::$text_node_classification
	 * @see self::subdivide_text_appropriately
	 *
	 * @since 6.7.0
	 */
	const TEXT_IS_NULL_SEQUENCE = 'TEXT_IS_NULL_SEQUENCE';

	/**
	 * Indicates that a span of decoded text comprises only whitespace.
	 *
	 * @see self::$text_node_classification
	 * @see self::subdivide_text_appropriately
	 *
	 * @since 6.7.0
	 */
	const TEXT_IS_WHITESPACE = 'TEXT_IS_WHITESPACE';
}
