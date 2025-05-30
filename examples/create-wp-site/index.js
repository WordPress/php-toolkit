#!/usr/bin/env node

import { runCLI } from '@wp-playground/cli';
import path from 'path';
import { fileURLToPath } from 'url';
import yargs from 'yargs/yargs';
import { hideBin } from 'yargs/helpers';
import fs from 'fs';
import { createPlaygroundProtocolHandler } from './playground-protocol/playground-protocol-handler.js';

const readFile = (relativePath, encoding) => {
	return fs.readFileSync(
		path.join(import.meta.dirname, relativePath),
		encoding
	);
};

const argv = yargs(hideBin(process.argv))
	.option('output-dir', {
		type: 'string',
		description: 'Create the new site in the specified directory'
	})
	.argv;

if (argv['output-dir']) {
	if (!fs.existsSync(argv['output-dir'])) {
		console.error(`Error: Output directory does not exist: ${argv['output-dir']}`);
		process.exit(1);
	}

	if (!fs.statSync(argv['output-dir']).isDirectory()) {
		console.error(`Error: Output path must be a directory: ${argv['output-dir']}`);
		process.exit(1); 
	}

	const files = fs.readdirSync(argv['output-dir']);
	if (files.length > 0) {
		console.error(`Error: Output directory must be empty: ${argv['output-dir']}`);
		process.exit(1);
	}
}

// Production
const isDevelopment = process.env.NODE_ENV === 'development';
const { requestHandler } = await runCLI({
	command: 'server',
	port: 9400,
	mount: isDevelopment
		? [
				`${path.join(
					import.meta.dirname,
					'../../components'
				)}:/wordpress/wp-content/components`,
				`${path.join(
					import.meta.dirname,
					'../../vendor'
				)}:/wordpress/wp-content/vendor`,
				`${path.join(
					import.meta.dirname,
					'../../plugins/data-liberation'
				)}:/wordpress/wp-content/plugins/data-liberation`,
		  ]
		: [],
	mountBeforeInstall: argv['output-dir'] ? [
		`${argv['output-dir']}:/wordpress`
	] : [],
	blueprint: {
		$schema: 'https://playground.wordpress.net/blueprint-schema.json',
		login: true,
		landingPage: '/wp-admin/edit.php?post_type=local_file',
		constants: {
			WP_DEBUG: true,
			WP_DEBUG_LOG: true,
			WP_DEBUG_DISPLAY: true,
		},
		steps: [
			{
				step: 'installPlugin',
				pluginData: {
					resource: 'literal',
					name: 'data-liberation.zip',
					contents: readFile('./data-liberation.zip'),
				},
				options: {
					activate: true,
				},
			},
			{
				step: 'resetData',
			},
			{
				step: 'runPHP',
				code: readFile('./flush-rewrite-rules.php', 'utf-8'),
			},
			{
				step: 'writeFiles',
				writeToPath:
					'/wordpress/wp-content/plugins/static-files-importer',
				filesTree: {
					resource: 'literal:directory',
					name: 'static-files-importer',
					files: {
						'import-markdown-directory.php': readFile(
							'./import-markdown-directory.php', 'utf-8'
						),
						'playground-protocol/PlaygroundProtocolClient.php':
							readFile(
								'./playground-protocol/PlaygroundProtocolClient.php',
								'utf-8'
							),
						'cli/Parser.php': readFile('./cli/Parser.php', 'utf-8'),
						'cli/ConsoleWriter.php': readFile(
							'./cli/ConsoleWriter.php', 'utf-8'
						),
						'cli/ProgressBar.php': readFile(
							'./cli/ProgressBar.php', 'utf-8'
						),
					},
				},
			},
		],
	},
});

const php = await requestHandler.getPrimaryPhp();
// @TODO: Explore running the Blueprint from the PHP script after validating the CLI args
php.onMessage(createPlaygroundProtocolHandler(php));

try {
	const result = await php.run({
		code: `<?php 
		/**
		 * Workaround to pass the CLI args from Node.js to the PHP script.
		 * 
		 * @TODO: Support passing $argv to the script at the platform level
		 */
		$argv = json_decode(getenv('JS_ARGV'), true);

		require_once '/wordpress/wp-content/plugins/static-files-importer/import-markdown-directory.php';
		
		?>`,
		env: {
			JS_ARGV: JSON.stringify(process.argv.slice(1)),
		},
	});
} catch (error) {
	// @TODO: remove silencing asyncify errors
	if ((error + '').includes('Unreachable code should not be executed')) {
		process.exit(0);
	}

	console.log('Error running the import script');
	if ('response' in error) {
		console.log(error.response.text);
		console.log(error.response.errors);
	} else {
		console.error(error);
	}

	process.exit(1);
}
