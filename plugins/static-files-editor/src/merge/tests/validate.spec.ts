import { validateMergedBlockMarkup } from '../validate';
import { InvalidMergeException } from '../types';
import * as fs from 'fs';
import * as path from 'path';

describe('validateBlockMarkup', () => {
	const testCasesDir = path.join(__dirname, 'test-data', 'corrupted-merge-results');

	const loadTestCases = () => {
		const cases: Array<{ fileName: string; content: string }> = [];
		const testFiles = fs.readdirSync(testCasesDir);

		for (const fileName of testFiles) {
			const content = fs.readFileSync(path.join(testCasesDir, fileName), 'utf-8');
			cases.push({ fileName, content });
		}

		return cases;
	};

	const corruptedMergeResults = loadTestCases();

	corruptedMergeResults.forEach(({ fileName, content }) => {
		it(`should detect invalid block markup in case ${fileName}`, () => {
			expect(() => {
				validateMergedBlockMarkup(content);
			}).toThrow(InvalidMergeException);
		});
	});
});
