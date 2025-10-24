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

// Convert JavaScript value to PHP array/value syntax
function toPHP(value, indent = '') {
	if (value === null) {
		return 'null';
	}
	if (typeof value === 'boolean') {
		return value ? 'true' : 'false';
	}
	if (typeof value === 'number') {
		return String(value);
	}
	if (typeof value === 'string') {
		// Escape PHP string - use double quotes for proper escape sequence handling
		return '"' + value
			.replace(/\\/g, '\\\\')
			.replace(/"/g, '\\"')
			.replace(/\$/g, '\\$')  // Escape $ in double-quoted strings
			.replace(/\n/g, '\\n')
			.replace(/\r/g, '\\r')
			.replace(/\t/g, '\\t')
			.replace(/\f/g, '\\f')
			.replace(/\0/g, '\\0')
			+ '"';
	}
	if (Array.isArray(value)) {
		if (value.length === 0) {
			return 'array()';
		}
		const items = value.map(item => indent + '\t' + toPHP(item, indent + '\t'));
		return 'array(\n' + items.join(',\n') + '\n' + indent + ')';
	}
	if (typeof value === 'object') {
		const entries = Object.entries(value);
		if (entries.length === 0) {
			return 'array()';
		}
		const items = entries.map(([key, val]) =>
			indent + '\t' + toPHP(key) + ' => ' + toPHP(val, indent + '\t')
		);
		return 'array(\n' + items.join(',\n') + '\n' + indent + ')';
	}
	return 'null';
}

// Generate PHP test cases
console.log('<?php');
console.log('');
console.log('/**');
console.log(' * CSS Tokenizer Test Cases');
console.log(' * Generated from @csstools/css-tokenizer-tests');
console.log(' * DO NOT EDIT MANUALLY - regenerate using generate-css-tests.mjs');
console.log(' */');
console.log('');
console.log('return array(');

const testKeys = Object.keys(testCorpus).sort();
let first = true;

for (const testKey of testKeys) {
	if (!first) {
		console.log(',');
	}
	first = false;

	const testCase = testCorpus[testKey];

	console.log('\t' + toPHP(testKey) + ' => array(');
	console.log('\t\t\'css\' => ' + toPHP(testCase.css) + ',');
	console.log('\t\t\'tokens\' => ' + toPHP(testCase.tokens, '\t\t'));
	console.log('\t)', '');
}

console.log(');');
