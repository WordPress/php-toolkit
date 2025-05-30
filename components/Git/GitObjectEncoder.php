<?php

namespace WordPress\Git;

use WordPress\ByteStream\ByteTransformer\ChecksumTransformer;
use WordPress\ByteStream\ByteTransformer\DeflateTransformer;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\ByteStream\WriteStream\TransformedWriteStream;

use function WordPress\Filesystem\wp_unix_dirname;

class GitObjectEncoder implements ByteWriteStream {

	private $downstream;
	private $repository;

	public function __construct( $repository, $object_type_name, $object_length ) {
		switch ( $object_type_name ) {
			case 'blob':
			case 'tree':
			case 'commit':
				break;
			default:
				throw new GitException(
					sprintf(
						'Invalid object type passed to GitObjectWriter: %s. Only blob, tree and commit are supported.',
						$object_type_name
					)
				);
		}

		$this->repository            = $repository;
		$this->downstream            = new TransformedWriteStream(
			$repository->get_object_storage_filesystem()->open_write_stream( 'objects/.tmp' ),
			array(
				'checksum' => new ChecksumTransformer( 'sha1' ),
			)
		);
		$this->downstream['deflate'] = new DeflateTransformer( ZLIB_ENCODING_DEFLATE );
		$this->downstream->append_bytes( "$object_type_name $object_length\x00" );
	}

	public function append_bytes( $data ): void {
		$this->downstream->append_bytes( $data );
	}

	public function get_hash(): string {
		return $this->downstream['checksum']->get_hash();
	}

	public function close_writing(): void {
		$this->downstream->close_writing();
		$hash = $this->downstream['checksum']->get_hash();
		$this->downstream->get_downstream_writer()->close_writing();
		$target_path = $this->repository->get_storage_path( $hash );
		$target_dir  = wp_unix_dirname( $target_path );
		$fs          = $this->repository->get_object_storage_filesystem();
		if ( ! $fs->is_dir( $target_dir ) ) {
			$fs->mkdir( $target_dir, array( 'recursive' => true ) );
		}
		$fs->rename( 'objects/.tmp', $target_path );
	}
}
