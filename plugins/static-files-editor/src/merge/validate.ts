import { parse as parseBlocks } from '@wordpress/block-serialization-default-parser';
import { parseFragment } from './parse5-sax';
import * as parse5 from 'parse5';
import { InvalidMergeException } from './types';

/**
 * Validates block markup using @wordpress packages
 */
export function validateMergedBlockMarkup(content: string): void {
    const blocks = parseBlocks(content);
    assertHTMLIsStructurallySound(content);
    
    const validateBlocksRecursively = (blocks) => {
        blocks.forEach(block => {
            assertHTMLIsStructurallySound(block.innerHTML);
            if (Array.isArray(block.innerBlocks) && block.innerBlocks.length > 0) {
                validateBlocksRecursively(block.innerBlocks);
            }
        });
    };
    validateBlocksRecursively(blocks);
}

/**
 * Asserts that the HTML has no unclosed tags.
 *
 * @param html - The HTML to assert no unclosed tags in.
 */
export function assertHTMLIsStructurallySound(html: string): void {
	const { unclosedTags } = analyzeHTML(html);
	if (unclosedTags.length > 0) {
		const breadcrumbs = unclosedTags.join(', ');
		throw new InvalidMergeException(
			`Merge resulted in unclosed tags – the document likely got corrupted: ${breadcrumbs}`
		);
	}

	const { lastTag } = analyzeHTML(html + '<TERMINATE-PROCESSING/>');
	if (lastTag !== 'TERMINATE-PROCESSING') {
		throw new InvalidMergeException(
			`The last tag differed from the expected canary <TERMINATE-PROCESSING/>. This ` +
				`indicates a structural corruption of the document.`
		);
	}

	if (serializationChangesHTMLStructure(html)) {
		throw new InvalidMergeException(
			`Merge resulted in a non-normative block markup. The inputs are assumed to be normative,` +
            `which means the merge result is likely corrupted.`
		);
	}
}

/**
 * Finds unclosed tags in the HTML.
 *
 * Example:
 *
 *     findUnclosedTags('<div><br><span></div><p></p><p>');
 *     // ['span']
 *
 *     findUnclosedTags('<div></div>');
 *     // []
 *
 * @param html - The HTML to find unclosed tags in.
 * @returns An array of unclosed tags.
 */
export function analyzeHTML(html: string) {
	const unclosedTags: string[] = [];
	const openStack: string[] = [];
	let lastTag: string | null = null;

	parseFragment(html, {
		onStartTag: (token: Token.StartTag) => {
			lastTag = token.tagName.toUpperCase();

            if (is_void_tag(token.tagName)) {
                return;
            }

            openStack.push(token.tagName.toUpperCase());
        },
        onEndTag: (token: Token.EndTag) => {
            lastTag = token.tagName.toUpperCase();

            if (is_void_tag(token.tagName)) {
                return;
            }

            let popped = '';
            do {
                popped = openStack.pop();
                if (popped === token.tagName.toUpperCase()) {
                    break;
                }
                unclosedTags.push(popped);
            } while (openStack.length > 0);
        }
    });

	unclosedTags.push(...openStack);

	return {
		unclosedTags,
		lastTag,
	};
}

// Checks if the normalized DOM structure differs from the raw HTML.
// Returns an object containing: boolean hasChanges, the originalHtml, and the normalizedHtml.
export function serializationChangesHTMLStructure(originalHtml: string) {
	const doc = parse5.parseFragment(originalHtml);
	const normalized = parse5.serialize(doc);

	return originalHtml.trim() !== normalized.trim();
}

function is_void_tag(tagName: string): boolean {
	tagName = tagName.toUpperCase();

	return (
		'AREA' === tagName ||
		'BASE' === tagName ||
		'BASEFONT' === tagName || // Obsolete but still treated as void.
		'BGSOUND' === tagName || // Obsolete but still treated as void.
		'BR' === tagName ||
		'COL' === tagName ||
		'EMBED' === tagName ||
		'FRAME' === tagName ||
		'HR' === tagName ||
		'IMG' === tagName ||
		'INPUT' === tagName ||
		'KEYGEN' === tagName || // Obsolete but still treated as void.
		'LINK' === tagName ||
		'META' === tagName ||
		'PARAM' === tagName || // Obsolete but still treated as void.
		'SOURCE' === tagName ||
		'TRACK' === tagName ||
		'WBR' === tagName
	);
}
