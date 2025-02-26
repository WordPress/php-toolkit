/**
 * A TypeScript port of BlockMarkupMergeTest.php
 * 
 * Keep in sync with the PHP version.
 */
import { threeWayMerge } from '../merge';
import { MergeResult, MergeConflict } from '../types';
import * as fs from 'fs';
import * as path from 'path';

describe('threeWayMerge', () => {
	const testCasesDir = path.join(__dirname, 'test-data');

	const loadTestCases = (subdirectory: string) => {
		const cases: Array<{
			parentFile: string;
			parent: string;
			branchA: string;
			branchB: string;
			expected: string;
		}> = [];
		const testFiles = fs
			.readdirSync(path.join(testCasesDir, subdirectory))
			.filter((file) => file.includes('_parent.'));

		for (const parentFile of testFiles) {
			const caseId = parentFile.replace(/_parent\..+$/, '');
			const parent = fs.readFileSync(
				path.join(testCasesDir, subdirectory, parentFile),
				'utf-8'
			);
			const branchA = fs.readFileSync(
				path.join(
					testCasesDir,
					subdirectory,
					`${caseId}_changeA.${parentFile.split('.').pop()}`
				),
				'utf-8'
			);
			const branchB = fs.readFileSync(
				path.join(
					testCasesDir,
					subdirectory,
					`${caseId}_changeB.${parentFile.split('.').pop()}`
				),
				'utf-8'
			);
			const expected = fs.readFileSync(
				path.join(
					testCasesDir,
					subdirectory,
					`${caseId}_merge_result.${parentFile.split('.').pop()}`
				),
				'utf-8'
			);

			cases.push({ parentFile, parent, branchA, branchB, expected });
		}

		return cases;
	};

	const cleanResolutionCases = loadTestCases('clean-resolution');

	cleanResolutionCases.forEach(
		({ parentFile, parent, branchA, branchB, expected }, index) => {
			it(`should correctly merge case ${parentFile}`, () => {
				/**
				 * In this test, we're largely diffing structured text where we can trim whitespace.
				 * This is not true for all document formats. Do not use this approach when trailing
				 * whitespace matters.
				 */
				const result = threeWayMerge(
					parent.trim(),
					branchA.trim(),
					branchB.trim()
                );
                
				expect(result).toBeInstanceOf(MergeResult);
				expect(result.conflicts).toHaveLength(0);
				expect(result.toString().trim()).toBe(expected.trim());
			});
		}
	);

	const conflictCases = loadTestCases('conflicts-during-resolution');

	conflictCases.forEach(({ parentFile, parent, branchA, branchB }, index) => {
		it(`should detect conflicts in case ${parentFile}`, () => {
			const result = threeWayMerge(parent, branchA, branchB);

			expect(result).toBeInstanceOf(MergeResult);
			expect(result.conflicts).toHaveLength(1);
			expect(result.conflicts[0]).toBeInstanceOf(MergeConflict);
		});
	});
});
