<?php

use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\DataLiberation\DataLiberationException;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\DataLiberation\Importer\ImportSession;
use WordPress\DataLiberation\Importer\RetryFrontloadingIterator;
use WordPress\DataLiberation\Importer\StreamImporter;
use WordPress\HttpClient\Request;
use WordPress\Markdown\MarkdownImporter;

// Process import in the background
function data_liberation_process_import() {
	$session = ImportSession::get_active();
	if ( ! $session ) {
		_doing_it_wrong(
			__METHOD__,
			'No active import session',
			'1.0.0'
		);
		return false;
	}
	return data_liberation_import_step( $session );
}
add_action( 'data_liberation_process_import', 'data_liberation_process_import' );

function data_liberation_import_step( $session, $importer = null ) {
	$metadata = $session->get_metadata();
    if(!$importer) {
        $importer = data_liberation_create_importer( $metadata );
    }
	if ( ! $importer ) {
		return;
	}

	/**
	 * @TODO: Fix this error we get after a few steps:
	 * Notice:  Function WP_XML_Processor::step_in_element was called incorrectly. A tag was not closed. Please see Debugging in WordPress for more information. (This message was added in version WP_VERSION.) in /wordpress/wp-includes/functions.php on line 6114
	 */
	$soft_time_limit_seconds = 15;
	$hard_time_limit_seconds = 25;
	$start_time              = microtime( true );
	$fetched_files           = 0;
	while ( true ) {
		$time_taken = microtime( true ) - $start_time;
		if ( $time_taken >= $soft_time_limit_seconds ) {
			// If we're frontloading and don't have any files fetched yet,
			// we need to give it more time. Otherwise every time we retry,
			// we'll start from the beginning and never advance past the
			// frontloading stage.
			if ( $importer->get_stage() === StreamImporter::STAGE_FRONTLOAD_ASSETS ) {
				if ( $fetched_files > 0 ) {
					break;
				}
			} else {
				break;
			}
		}
		if ( $time_taken >= $hard_time_limit_seconds ) {
			// No negotiation, we're done.
			// @TODO: Make it easily configurable
			// @TODO: Bump the number of download attempts for the placeholders,
			//        set the status to `error` in each interrupted download.
			break;
		}

		if ( true !== $importer->next_step() ) {
			$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );

			$should_advance_to_next_stage = null !== $importer->get_next_stage();
			if ( $should_advance_to_next_stage ) {
				if ( StreamImporter::STAGE_FRONTLOAD_ASSETS === $importer->get_stage() ) {
					$resolved_all_failures = $session->count_unfinished_frontloading_stubs() === 0;
					if ( ! $resolved_all_failures ) {
						break;
					}
				}
			}
			if ( ! $importer->advance_to_next_stage() ) {
				break;
			}
			$session->set_stage( $importer->get_stage() );
			$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );
			continue;
		}

		switch ( $importer->get_stage() ) {
			case StreamImporter::STAGE_INDEX_ENTITIES:
				// Bump the total number of entities to import.
				$session->create_frontloading_stubs( $importer->get_indexed_assets_urls() );
				$session->bump_total_number_of_entities(
					$importer->get_indexed_entities_counts()
				);
				break;
			case StreamImporter::STAGE_FRONTLOAD_ASSETS:
				$session->bump_frontloading_progress(
					$importer->get_frontloading_progress(),
					$importer->get_frontloading_events()
				);
				break;
			case StreamImporter::STAGE_IMPORT_ENTITIES:
				$session->bump_imported_entities_counts(
					$importer->get_imported_entities_counts()
				);
				break;
		}

		$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );
	}
}

/**
 * @throws DataLiberationException If the import arguments are invalid.
 * @return StreamImporter The created importer instance.
 */
function data_liberation_create_importer( $import ) {
	$wxr_path = get_attached_file( $import['attachment_id'] );
	if ( false === $wxr_path ) {
		throw new DataLiberationException( 'Failed to get the WXR file path' );
	}
	$importer = StreamImporter::create(
		function ( $cursor = null ) use ( $wxr_path ) {
			$stream = FileReadStream::from_path( $wxr_path );
			// Skip the initial PHP guard.
			// @TODO: Don't hardcode the guard string in here.
			$stream->seek( strlen( "<?php !(); ?>\n" ) );
			return WXREntityReader::create( $stream, $cursor );
		},
		array(),
		$import['cursor'] ?? null
	);
	$importer->set_frontloading_retries_iterator(
		new RetryFrontloadingIterator( $import['post_id'] )
	);
	return $importer;
}
