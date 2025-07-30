<?php

use WordPress\DataLiberation\Importer\StreamImporter;
use WordPress\DataLiberation\Importer\RetryFrontloadingIterator;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\DataLiberation\DataLiberationException;
use WordPress\DataLiberation\Importer\EntityImporter;
use WordPress\DataLiberation\Importer\ImportSession;

// Cron job: add fake authors and advance state
function ng_importer_get_active() {
	$session = ImportSession::get_active();
	if ( ! $session ) {
		error_log('No active import session');
		_doing_it_wrong(
			__METHOD__,
			'No active import session',
			'1.0.0'
		);
		return false;
	}
	$import = $session->get_metadata();
	
	$wxr_path = get_post_meta($session->get_id(), 'file_name', true);
	if ( false === $wxr_path || ! file_exists( $wxr_path ) ) {
		error_log('Failed to get the WXR file path: ' . $wxr_path);
		throw new DataLiberationException( 'Failed to get the WXR file path' );
	}
	// Get author mappings from session
	$author_mappings = $session->get_meta_by_key('authorMappings') ?? [];
	
	$importer = StreamImporter::create(
		function ( $cursor = null ) use ( $wxr_path ) {
			$stream = FileReadStream::from_path( $wxr_path );
			// Skip the initial PHP guard.
			// @TODO: Don't hardcode the guard string in here.
			$stream->seek( strlen( "<?php !(); ?>\n" ) );
			return WXREntityReader::create( $stream, $cursor );
		},
		array(
			'index_batch_size' => 1,
			'entity_sink' => new EntityImporter( array( 'user_slug_mapping' => $author_mappings ) ),
		),
		$import['cursor'] ?? null
	);
	$importer->set_frontloading_retries_iterator(
		new RetryFrontloadingIterator( $import['post_id'] )
	);
	return ['importer' => $importer, 'session' => $session];
}

function do_ng_importer_next_import_step() {
	$active_import = ng_importer_get_active();
	if ( ! $active_import ) {
		error_log('Failed to create the importer');
		return;
	}
	$importer = $active_import['importer'];
	$session = $active_import['session'];

	// Wait until the user explicitly provides the import configuration.
	if ( $importer->get_stage() === StreamImporter::STAGE_CONFIGURE_IMPORT ) {
		error_log('Waiting for the user to provide the import configuration');
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

		$authorsInFile = $session->get_meta_by_key('authorsInFile') ?? [];
		if ( true !== $importer->next_step() ) {
			$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );

			$should_advance_to_next_stage = null !== $importer->get_next_stage();
			if ( $should_advance_to_next_stage ) {
				if ( StreamImporter::STAGE_FRONTLOAD_ASSETS === $importer->get_stage() ) {
					$resolved_all_failures = $session->count_unfinished_frontloading_stubs() === 0;
					if ( ! $resolved_all_failures ) {
						// Advance anyway.
						// @TODO: Give the user a chance to retry, provide different assets files etc.
						$session->append_to_log_file(
							sprintf(
								'Proceeding without downloading all the media files. %d could not be downloaded, %d succeeded',
								$session->count_unfinished_frontloading_stubs(),
								$session->count_succeeded_frontloading_stubs()
							)
						);
					}
				}
			}
			if ( ! $importer->advance_to_next_stage() ) {
				break;
			}
			$session->set_stage( $importer->get_stage() );
			$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );

			if (
				$importer->get_stage() === StreamImporter::STAGE_FRONTLOAD_ASSETS
				|| $importer->get_stage() === StreamImporter::STAGE_IMPORT_ENTITIES
			) {
				continue;
			}
			return;
		}

		switch ( $importer->get_stage() ) {
			case StreamImporter::STAGE_INDEX_ENTITIES:
				// Bump the total number of entities to import.
				$session->create_frontloading_stubs( $importer->get_indexed_assets_urls() );
				$session->bump_total_number_of_entities(
					$importer->get_indexed_entities_counts()
				);
				// @TODO: Also use email and other information from the entity.
				$entity = $importer->get_current_entity();
				if($entity) {
					$data = $entity->get_data();
					$authors_before = count($authorsInFile);
					switch($entity->get_type()) {
						case 'post':
							$login = $data['post_author'] ?? '';
							$authorsInFile[$login] = [
								'author_display_name' => $login,
								'author_login' => $login,
							];
							break;
						case 'comment':
							$login = $data['comment_author'] ?? '';
							$authorsInFile[$login] = [
								'author_display_name' => $login,
								'author_login' => $login,
							];
							break;
					}
					// @TODO: This is fine for small imports (e.g. up to 10k authors),
					//        but won't scale to larger ones.
					//
					// To support large imports, we need to minimize writes and avoid
					// batching all the authors in memory. Don't write after every
					// entity – there may be a million authors and we'd just drown
					// the database with writes. Instead, either:
					// * Batch all the writes from the batch of indexed entities.
					//   There's a slight risk of data loss if there's a fatal error
					//   in the middle of the batch, though. Maybe we could only
					//   save the updated reentrancy cursor after the entire batch?
					//   Alternatively...
					// * Insert a new record when a new author is detected. It's
					//   still a lot of writes, but it could be less work than
					//   updating the same array over and over again. Or not – we'd
					//   need to measure.
					// 
					if(count($authorsInFile) !== $authors_before) {
						$session->set_meta_by_key('authorsInFile', $authorsInFile);
					}
				}
				break;
			case StreamImporter::STAGE_FRONTLOAD_ASSETS:
				$session->bump_frontloading_progress(
					$importer->get_frontloading_progress(),
					$importer->get_frontloading_events()
				);
				break;
			case StreamImporter::STAGE_IMPORT_ENTITIES:
				$session->bump_imported_entities_counts(
					$importer->get_imported_entities_counts(),
					$importer->get_entity_sink_events()
				);
				break;
		}

		$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );
	}
}
