<?php

namespace WordPress\Blueprints\Steps;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Process;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\ByteStream\ReadStream\ByteReadStream;

class ImportContentStep implements StepInterface {
	/**
	 * @var mixed[]
	 */
	private $content;

	public function __construct( array $content ) {
		$this->content = $content;
	}

	public function run( Runtime $runtime, Tracker $progress ) {
		$progress->setCaption( 'Importing content' );

		$total_files = count( $this->content );
		if ( $total_files === 0 ) {
			$progress->finish();

			return true;
		}

		$progress->split( $total_files );

		foreach ( $this->content as $i => $content_definition ) {
			if ( $content_definition['type'] === 'wxr' ) {
				// @TODO: More useful captions – include the url
				$progress[ $i ]->setCaption( 'Importing WXR file ' );
				$this->importWxr( $runtime, $content_definition, $progress[ $i ] );
			} elseif ( $content_definition['type'] === 'posts' ) {
				$progress[ $i ]->setCaption( 'Importing a post ' );
				$this->importPosts( $runtime, $content_definition['source'] );
			} else {
				throw new RuntimeException( 'Unsupported content type: ' . $content_definition['type'] );
			}

			$progress[ $i ]->finish();
		}

		$progress->finish();
	}

	private function importWxr( Runtime $runtime, array $content_definition, Tracker $progress ): void {
		// @TODO: Make it work when Blueprints are running as phar archive
		$import_script_path = __DIR__ . '/scripts/import-content.php';
		if ( ! file_exists( $import_script_path ) ) {
			throw new BlueprintExecutionException( sprintf(
				'Import script %s does not exist.',
				$import_script_path
			) );
		}

		$importer_script = file_get_contents( $import_script_path );
		$import_process = $runtime->createPhpSubProcess(
			$importer_script . 
			<<<'PHP'
<?php
run_content_import([
	'mode' => 'wxr',
	'execution_context_root' => getenv('EXECUTION_CONTEXT') ? getenv('EXECUTION_CONTEXT') : null,
	'source' => json_decode(getenv('DATA_SOURCE_DEFINITION'), true),
	// @TODO: Support arbitrary media URLs to enable fetching assets during import.
	// 'media_url' => 'https://pd.w.org/'
]);
?>
PHP
			,
			[
				'DATA_SOURCE_DEFINITION' => json_encode( $content_definition['source']->original_definition ),
				'EXECUTION_CONTEXT' => $runtime->getExecutionContextRoot(),
			]
		);
		$import_process->start();

		$output = $import_process->getOutputStream(Process::OUTPUT_FILE);
		foreach ( $this->output_lines( $output ) as $line ) {
			$data = @json_decode($line, true);
			if(!is_array($data)) {
				// Non-JSON output is treated as a crash. We use a dedicated file pipe
				// for communication and it should never contain a non-JSON line.
				$import_process->stop();
				throw new ProcessFailedException( $import_process );
			}
			// Report progress, errors, etc.
			switch($data['type'] ?? '**MISSING**') {
				case 'progress':
					$progress->set( $data['progress'], 'Importing WXR file: ' . $data['caption'] );
					break;
				case 'error':
					throw new BlueprintExecutionException( $data['message'] );
				case 'completion':
					$progress->finish();
					break;
				default:
					throw new BlueprintExecutionException( 'Unknown message type: ' . $data['type'] );
			}
		}

		if($import_process->getExitCode() !== 0) {
			throw new ProcessFailedException( $import_process );
		}

		// @TODO: remove the Process::OUTPUT_FILE pipe
	}

	private function output_lines( ByteReadStream $output ) {
		$buffer = '';
		while ( !$output->reached_end_of_data() ) {
			$bytes_ready = $output->pull(1024);
			if ( ! $bytes_ready ) {
				continue;
			}
			$buffer .= $output->consume( $bytes_ready );
			while ( ( $newline_pos = strpos( $buffer, "\n" ) ) !== false ) {
				$line = substr( $buffer, 0, $newline_pos + 1 );
				yield $line;
				$buffer = substr( $buffer, $newline_pos + 1 );
			}
		}
		// Output any remaining data as the last line (if not empty)
		if ( strlen( $buffer ) > 0 ) {
			yield $buffer;
		}
	}

	private function importPosts( Runtime $runtime, $post ): void {
		// @TODO: Use the Data Liberation importer here.
		$resolved = $runtime->resolve( $post );
		if ( ! $resolved instanceof File ) {
			throw new BlueprintExecutionException( sprintf(
				'Imported content reference must be a file, but %s was a Directory.',
				$post->get_human_readable_name()
			) );
		}

		$runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
foreach (json_decode(getenv('POSTS'), true) as $post) {
	$result = wp_insert_post(wp_slash($post));
	if (is_wp_error($result)) {
		throw new Exception( $result->get_error_message() );
	}
}
PHP
			,
			[
				'POSTS' => json_encode( [
					[
						'post_title'   => 'Test Post',
						'post_content' => $resolved->getStream()->consume_all(),
						'post_status'  => 'publish',
						'post_type'    => 'post',
					],
				] ),
			]
		);
	}
}
