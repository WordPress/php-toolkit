<?php

namespace WordPress\HttpClient;

use Exception;
use RuntimeException;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemException;

final class FilesystemCache implements CacheStorage {
	/**
	 * @var Filesystem
	 */
	private $fs;
	/** @var array<string, string> Maps URL to temporary body file path during streaming */
	private $body_paths = [];

	public function __construct( Filesystem $fs ) {
		$this->fs = $fs;
	}

	private function get_body_path( string $url ): string {
		$key = hash( 'sha256', $url );

		return "$key.bin";
	}

	private function get_meta_path( string $url ): string {
		$key = hash( 'sha256', $url );

		return "$key.json";
	}

	public function lookup( string $url ): ?CacheEntry {
		$meta_path = $this->get_meta_path( $url );
		$body_path = $this->get_body_path( $url );

		// Check for metadata first, as body without metadata is useless.
		if ( ! $this->fs->exists( $meta_path ) ) {
			return null;
		}

		// If metadata exists, but body doesn't, invalidate and return null.
		if ( ! $this->fs->exists( $body_path ) ) {
			$this->invalidate( $url );

			return null;
		}

		$data  = json_decode( $this->fs->get_contents( $meta_path ), true );
		$entry = new CacheEntry();
		foreach ( $data as $k => $v ) {
			// Skip body_path if it somehow exists in old cache files
			if ( $k === 'body_path' ) {
				continue;
			}
			$entry->$k = $v;
		}

		// Re-check URL consistency in case of hash collisions (unlikely but possible)
		if ( $entry->url !== $url ) {
			// Log potential hash collision
			$this->invalidate( $url ); // Invalidate the conflicting entry

			return null;
		}

		return $entry;
	}

	public function open_body_write_stream( string $url ): ByteWriteStream {
		$body_path                = $this->get_body_path( $url );
		$this->body_paths[ $url ] = $body_path;

		return $this->fs->open_write_stream( $body_path );
	}

	public function get_body( CacheEntry $entry ): string {
		$body_path = $this->get_body_path( $entry->url );
		if ( ! $this->fs->exists( $body_path ) ) {
			// Invalidate metadata if body is missing
			$this->invalidate( $entry->url );
			throw new RuntimeException( "Cache body file not found for URL: {$entry->url}" );
		}

		return $this->fs->get_contents( $body_path );
	}

	public function store( CacheEntry $e ): void {
		$meta_path = $this->get_meta_path( $e->url );

		$jsonData = json_encode( $e, JSON_PRETTY_PRINT );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( json_last_error_msg() );
		}
		$this->fs->put_contents( $meta_path, $jsonData );
	}

	public function invalidate( string $url ): void {
		$meta_path = $this->get_meta_path( $url );
		$body_path = $this->get_body_path( $url );
		try {
			$this->fs->rm( $meta_path );
			$this->fs->rm( $body_path );
		} catch ( FilesystemException $e ) {
			// Ignore
		}
		// Also remove from temporary tracking if invalidate is called mid-stream
		unset( $this->body_paths[ $url ] );
	}
}
