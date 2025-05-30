import {
	diff_match_patch as DiffMatchPatch,
	Diff,
	DIFF_EQUAL,
	DIFF_DELETE,
	DIFF_INSERT,
} from 'diff-match-patch';
import { MergeResult, MergeException, MergeConflict } from './types';

/**
 * Creates a diff between two strings using diff-match-patch
 */
export function createDiff(base: string, branch: string): Diff[] {
	const dmp = new DiffMatchPatch();
	const diff = dmp.diff_main(base, branch);
	dmp.diff_cleanupSemantic(diff);
	return diff;
}

/**
 * Extracts boundaries from two diffs
 */
function extractBoundaries(diffA: Diff[], diffB: Diff[]): number[] {
	const boundaries: { [key: number]: boolean } = {};
	for (const diff of [diffA, diffB]) {
		let offset = 0;
		for (const [op, text] of diff) {
			if (op === DIFF_INSERT) {
				continue;
			}
			if (offset !== 0) {
				boundaries[offset] = true;
			}
			offset += text.length;
		}
	}
	const boundaryKeys = Object.keys(boundaries).map(Number);
	boundaryKeys.sort((a, b) => a - b);
	return boundaryKeys;
}

/**
 * Reslices a diff based on boundaries
 */
function resliceDiff(diff: Diff[], boundaries: number[]): Diff[] {
	const resliced: Diff[] = [];
	let baseCursor = 0;
	let boundaryIndex = 0;

	for (let [op, text] of diff) {
		if (!text) {
			continue;
		}
		if (op === DIFF_INSERT) {
			resliced.push([op, text]);
			continue;
		}

		let textLength = text.length;
		let startOffset = baseCursor;

		while (
			boundaryIndex < boundaries.length &&
			boundaries[boundaryIndex] <= startOffset
		) {
			boundaryIndex++;
		}

		while (
			boundaryIndex < boundaries.length &&
			boundaries[boundaryIndex] <= startOffset + textLength
		) {
			const boundary = boundaries[boundaryIndex];
			const sliceLength = boundary - startOffset;
			if (sliceLength > 0 && text.length > 0) {
				const sliceText = text.slice(0, sliceLength);
				resliced.push([op, sliceText]);
			}

			text = text.slice(sliceLength);
			startOffset += sliceLength;
			boundaryIndex++;
			if (!text) {
				break;
			}
		}

		if (text !== '') {
			resliced.push([op, text]);
		}

		baseCursor += textLength;
	}

	return resliced;
}

/**
 * Creates chunks from a diff that can be used for merging
 */
export function createChunks(diff: Diff[]): Array<{
	base: string | null;
	inserted: string;
	deleted: boolean;
}> {
	const chunks: Array<{
		base: string | null;
		inserted: string;
		deleted: boolean;
	}> = [];

	let currentChunk = {
		base: null,
		inserted: '',
		deleted: false,
	};

	for (const [operation, text] of diff) {
		if (operation === DIFF_DELETE || operation === DIFF_EQUAL) {
			if (currentChunk.base !== null || currentChunk.inserted !== '') {
				chunks.push(currentChunk);
				currentChunk = {
					base: null,
					inserted: '',
					deleted: false,
				};
			}
			currentChunk.base = text;
			currentChunk.deleted = operation === DIFF_DELETE;
		} else if (operation === DIFF_INSERT) {
			currentChunk.inserted += text;
		}
	}

	if (currentChunk.base !== null || currentChunk.inserted !== '') {
		chunks.push(currentChunk);
	}

	return chunks;
}

/**
 * Ensures chunks are equally sliced
 */
export function ensureChunks(
	diffA: Diff[],
	diffB: Diff[]
): [
	Array<{
		base: string;
		inserted: string;
		deleted: boolean;
	}>,
	Array<{
		base: string;
		inserted: string;
		deleted: boolean;
	}>,
] {
	const boundaries = extractBoundaries(diffA, diffB);

	const reslicedDiffA = resliceDiff(diffA, boundaries);
	const reslicedDiffB = resliceDiff(diffB, boundaries);

	const chunksA = createChunks(reslicedDiffA);
	const chunksB = createChunks(reslicedDiffB);

	return [chunksA, chunksB];
}

/**
 * Merges chunks from two branches into a single result
 */
export function mergeChunks(
	chunksA: ReturnType<typeof createChunks>,
	chunksB: ReturnType<typeof createChunks>
): MergeResult {
	const results: (string | MergeConflict)[] = [];
	const maxLength = Math.max(chunksA.length, chunksB.length);

	for (let i = 0; i < maxLength; i++) {
		const chunkA = chunksA[i] || {
			base: null,
			inserted: '',
			deleted: false,
		};
		const chunkB = chunksB[i] || {
			base: null,
			inserted: '',
			deleted: false,
		};

		// Handle conflicting insertions
		if (
			chunkA.inserted &&
			chunkB.inserted &&
			chunkA.inserted !== chunkB.inserted
		) {
			results.push(
				new MergeConflict(chunkA.inserted, chunkB.inserted, {
					message: 'Conflicting insertions',
				})
			);
			continue;
		}

		// Handle null base values (chunks that only exist in one branch)
		if (chunkA.base === null || chunkB.base === null) {
			if (chunkA.base !== null) {
				results.push(chunkA.base + chunkA.inserted);
			} else if (chunkB.base !== null) {
				results.push(chunkB.base + chunkB.inserted);
			}
			continue;
		}

		// Handle mismatched base lines
		if (chunkA.base !== chunkB.base) {
			results.push(
				new MergeConflict(chunkA.base, chunkB.base, {
					message: 'Mismatched base lines',
				})
			);
			continue;
		}

		// Handle deletions
		if (chunkA.deleted || chunkB.deleted) {
			if (chunkA.deleted && chunkB.deleted) {
				continue; // Both deleted the same content
			}

			const deletion = chunkA.deleted ? chunkA : chunkB;
			const nonDeletion = chunkA.deleted ? chunkB : chunkA;

			if (deletion.inserted) {
				if (nonDeletion.inserted) {
					results.push(
						new MergeConflict(
							deletion.inserted,
							nonDeletion.inserted,
							{ message: 'Deletion with conflicting insertion' }
						)
					);
					continue;
				} else {
					results.push(deletion.inserted);
				}
			}
			continue;
		}

		// Handle normal case (no conflicts)
		results.push(chunkA.base);
		const onlyInsertion = chunkA.inserted || chunkB.inserted;
		if (onlyInsertion) {
			results.push(onlyInsertion);
		}
	}

	return new MergeResult(results);
}

/**
 * Performs a three-way merge between a base version and two branches
 */
export function threeWayMerge(
	base: string,
	branchA: string,
	branchB: string,
	validator?: (content: string) => void
): MergeResult {
	try {
		// Create diffs between base and each branch
		const diffA = createDiff(base, branchA);
		const diffB = createDiff(base, branchB);

		// Ensure chunks are equally sliced
		const [chunksA, chunksB] = ensureChunks(diffA, diffB);

		// Merge chunks
		const mergedContent = mergeChunks(chunksA, chunksB);

		// Validate merged content
        if (validator) {
            try {
                validator(mergedContent.toString());
            } catch (error) {
                return new MergeResult([
                    new MergeConflict(branchA, branchB, {
                        message: 'Merged content contains invalid block markup',
                        mergedContent: mergedContent.toString(),
                    }),
                ]);
            }
		}

		return mergedContent;
	} catch (error) {
		if (error instanceof MergeException) {
			return new MergeResult([
				new MergeConflict(branchA, branchB, { message: error.message }),
			]);
		}
		throw error;
	}
}
