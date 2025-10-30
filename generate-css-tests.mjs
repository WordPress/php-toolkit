#!/usr/bin/env node

/**
 * Script to fetch CSS tokenizer tests from @rmenke/css-tokenizer-tests
 * and convert them to PHP format for PHPUnit.
 *
 * Usage:
 *   npm install @rmenke/css-tokenizer-tests
 *   node generate-css-tests.mjs > components/DataLiberation/Tests/css-test-cases.php
 */

import { testCorpus } from '@rmenke/css-tokenizer-tests';

const processedTestCorpus = {};

for (const key in testCorpus) {
	processedTestCorpus[key] = {
		css: testCorpus[key].css,
		tokens: testCorpus[key].tokens.map((token) => {
			const processedToken = {
				type: token.type,
				raw: token.raw,
				startIndex: token.startIndex,
				endIndex: token.endIndex,
				unit: token.structured?.unit,
			};

			processedToken.value = token.structured?.value ?? null;
			return processedToken;
		}),
	};
}

console.log(JSON.stringify(processedTestCorpus, null, 2));
