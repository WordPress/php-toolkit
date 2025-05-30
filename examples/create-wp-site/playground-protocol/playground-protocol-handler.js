import fs from 'fs';
import path from 'path';
import { createNodeFsMountHandler } from '@php-wasm/node';

/**
 * Handles incoming messages from the PHP environment to
 * allow the sandboxed PHP runtime to control its own
 * execution context.
 * 
 * @param {string} message - The incoming message from PHP
 * @returns {Promise<string>} A promise that resolves to the response
 */
export const createPlaygroundProtocolHandler = (php) => async (message) => {
	try {
		const data = JSON.parse(message);
		
		if (data.command === 'mount') {
			try {
				const hostPath = path.resolve(process.cwd(), data.hostPath);
				
				if (!fs.existsSync(hostPath)) {
					return JSON.stringify({
						error: `Directory ${hostPath} does not exist`
					});
				} else if(!fs.statSync(hostPath).isDirectory()) {
					return JSON.stringify({
						error: `Path ${relativePath} is not a directory`
					});
				}
				
				if (typeof data.playgroundVfsPath !== 'string' || !data.playgroundVfsPath.startsWith('/')) {
					return JSON.stringify({
						error: 'Target path must be an absolute path'
					});
				}

				await php.mount(
					data.playgroundVfsPath,
					createNodeFsMountHandler(hostPath)
				);
				return JSON.stringify({ success: true });
			} catch (err) {
				console.error(err);
				process.exit(1);
				return JSON.stringify({
					error: `Failed to mount directory: ${err.message}`
				});
			}
		}

		if (data.command === 'exit') {
			process.exit(data.exitCode);
		}
		
		if (data.command === 'stdout') {
			if (!process.stdout.isTTY) {
				return JSON.stringify({ success: true }); // Silent in non-TTY mode
			}
			
			try {
				switch (data.method) {
					case 'cursorTo':
						if (typeof data.x !== 'number') {
							throw new Error('Invalid x coordinate');
						}
						process.stdout.cursorTo(data.x);
						break;
						
					case 'write':
						if (typeof data.text !== 'string') {
							throw new Error('Invalid text');
						}
						process.stdout.write(data.text);
						break;
						
					case 'clearLine':
						if (data.dir !== 1) {
							throw new Error('Invalid direction');
						}
						process.stdout.clearLine(data.dir);
						break;
						
					default:
						throw new Error('Unknown stdout method');
				}
				return JSON.stringify({ success: true });
			} catch (err) {
				return JSON.stringify({
					error: `Stdout operation failed: ${err.message}`
				});
			}
		}
		
		// Handle unknown command
		return JSON.stringify({
			error: `Unknown command: ${data.command}`
		});
	} catch (err) {
		return JSON.stringify({
			error: `Invalid message format: ${err.message}`
		});
	}
}