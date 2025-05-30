import { getBlockType } from '@wordpress/blocks';
import { RichTextData } from '@wordpress/rich-text';
import { BlockInstance } from '@wordpress/blocks';
import { ReactNode } from 'react';
import { diffWords } from 'diff';
import './style.css';

// Highlight only removed text on the left, added text on the right. Identical stays normal.
function highlightText(
	diffs: ReturnType<typeof diffWords>,
	side: 'old' | 'new' = 'new'
): ReactNode {
	return diffs
		.map((part, i) => {
			if (side === 'old' && part.added) return null;
			if (side === 'new' && part.removed) return null;

			let backgroundColor = 'transparent';
			if (side === 'old' && part.removed) {
				backgroundColor = 'salmon';
			} else if (side === 'new' && part.added) {
				backgroundColor = 'lightgreen';
			}
			return `<span style="background-color: ${backgroundColor};">${part.value}</span>`;
		})
		.join('');
}

/**
 * Attempt to match blocks between old and new trees, assigning consistent
 * synthetic IDs for best-effort CRDT-like tracking. We use block name,
 * depth, and some content-based heuristics. This assigns blockIds to matched pairs
 * without modifying the original arrays.
 */
function matchBlocks(
	oldBlocks: BlockInstance[],
	newBlocks: BlockInstance[],
	depth = 0
) {
	// A simple function to generate a signature for matching
	function getBlockSignature(block: BlockInstance, blockDepth: number) {
		const namePart = block.name;
		const attrSnippet = JSON.stringify(block.attributes).slice(0, 50);
		return `${namePart}|depth:${blockDepth}|attrs:${attrSnippet}`;
	}

	// We'll track which newBlocks we've already matched
	const usedIndices = new Set<number>();

    const pairs = [];
	// Find matches for each oldBlock
	for (let i = 0; i < oldBlocks.length; i++) {
		const oldBlock = oldBlocks[i];
		const oldSig = getBlockSignature(oldBlock, depth);
		let bestMatchIndex = -1;
		let bestMatchDistance = Number.MAX_SAFE_INTEGER;

		for (let j = 0; j < newBlocks.length; j++) {
			if (usedIndices.has(j)) {
				continue;
			}
            const candidate = newBlocks[j];
            // Only match blocks with the same type
            if(candidate.name !== oldBlock.name) {
                continue;
            }
			const candidateSig = getBlockSignature(candidate, depth);
			// For demonstration, we'll use a naive distance measure:
			// the string distance between block signatures
			let dist = 0;
			for (
				let k = 0;
				k < Math.max(oldSig.length, candidateSig.length);
				k++
			) {
				if (oldSig[k] !== candidateSig[k]) {
					dist++;
				}
			}

			if (dist < bestMatchDistance) {
				bestMatchDistance = dist;
				bestMatchIndex = j;
			}
		}

		// If we found a match, assign blockId to both blocks
        if (bestMatchIndex > -1) {
            const matched = newBlocks[bestMatchIndex];
            usedIndices.add(bestMatchIndex);

            // Assign same blockId to matched pair
            const blockId = `block-${depth}-${i}`;
            (oldBlock as any).blockId = blockId;
            (matched as any).blockId = blockId;
            pairs.push({ oldBlock, matched });
        }
	}

        // Recursively match inner blocks
    for(const { oldBlock, matched } of pairs) {
        matchBlocks(
            oldBlock.innerBlocks || [],
            matched.innerBlocks || [],
            depth + 1
        );
    }
}

/* 
   Produces a new block tree that includes diff annotations on both blocks and their attributes,
   using the blockId to determine which blocks are created, deleted, or updated.
*/
export function diffBlockTree(
	oldBlockTree: BlockInstance[],
	newBlockTree: BlockInstance[]
): BlockInstance[] {
	matchBlocks(oldBlockTree, newBlockTree, 0);

	/**
	 * Recursively compare two lists of blocks by their blockId, determining whether each block
	 * is created, updated, or deleted. Descendant blocks are also compared the same way.
	 */
	function diffBlocksRecursivelyById(
		oldBlocks: BlockInstance[],
		newBlocks: BlockInstance[]
	): BlockInstance[] {
		const result: BlockInstance[] = [];
		const oldBlocksMap = new Map<string, BlockInstance>();

		// Map old blocks by blockId
		for (const oldBlock of oldBlocks) {
			oldBlocksMap.set((oldBlock as any).blockId, oldBlock);
		}

		// Match new blocks with old blocks by blockId
		for (const newBlock of newBlocks) {
			const matchingOldBlock = oldBlocksMap.get(
				(newBlock as any).blockId
			);
			result.push(compareSingleBlockById(matchingOldBlock, newBlock));
			if (matchingOldBlock) {
				oldBlocksMap.delete((newBlock as any).blockId);
			}
		}

		return result;
	}

	/**
	 * Compare a single old and new block, returning a new block with
	 * annotations to indicate whether it was created, deleted, or updated.
	 * Descendant blocks are also compared by blockId.
	 */
	function compareSingleBlockById(
		oldBlock: BlockInstance | undefined,
		newBlock: BlockInstance | undefined
	): BlockInstance {
		// If only newBlock => created
        if (!oldBlock && newBlock) {
            return {
                ...newBlock,
                diff: 'created',
                attributes: newBlock.attributes,
                innerBlocks: diffBlocksRecursivelyById([], newBlock.innerBlocks),
            }
		}

		// If only oldBlock => deleted
		if (oldBlock && !newBlock) {
			return {
                ...oldBlock,
                diff: 'deleted',
                attributes: oldBlock.attributes,
                innerBlocks: diffBlocksRecursivelyById([], oldBlock.innerBlocks),
            }
		}

		// Otherwise => updated
        const diffResult = {
            ...newBlock!,
            diff: 'updated',
            changedNonRichTextAttributes: [],
        };

		// Compare attributes
		const oldAttrs = oldBlock!.attributes || {};
		const newAttrs = newBlock!.attributes || {};
		const allKeys = new Set([
			...Object.keys(oldAttrs),
			...Object.keys(newAttrs),
        ]);

        for (const key of allKeys) {
			const oldVal = oldAttrs[key];
			const newVal = newAttrs[key];            

			if (JSON.stringify(oldVal) === JSON.stringify(newVal)) {
				continue;
			}

			const blockType = getBlockType(newBlock.name);
			const attributeDef = blockType?.attributes?.[key];
			const isRichTextOrHTML =
				attributeDef?.source === 'html' ||
                attributeDef?.source === 'rich-text';

            // Possibly diff text for "rich-text"/string source
            if (!isRichTextOrHTML) {
                diffResult.changedNonRichTextAttributes.push(key);
                continue;
            }
        
            const textDiffs = diffWords(
                richTextAsString(oldVal),
                richTextAsString(newVal)
            );
            const highlightedHTML = highlightText(textDiffs, 'new');
            diffResult.attributes[key] =
                RichTextData.fromHTMLString(highlightedHTML);
            if (attributeDef?.role === 'content') {
                diffResult.originalContent = highlightedHTML;
            }
        }
		(diffResult as any).innerBlocks = diffBlocksRecursivelyById(
			oldBlock!.innerBlocks,
			newBlock!.innerBlocks
		);

		return diffResult;
	}

	// Compare the top-level blocks by blockId, which also initiates recursive comparison.
	return diffBlocksRecursivelyById(oldBlockTree, newBlockTree);
}

function richTextAsString(richTextMaybe: any | string) {
    if (!richTextMaybe) {
        return '';
    }
    return typeof richTextMaybe === 'string'
        ? richTextMaybe
        : richTextMaybe.originalHTML || richTextMaybe.text || '';
}


