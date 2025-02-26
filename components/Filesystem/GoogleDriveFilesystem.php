<?php

namespace WordPress\Filesystem;

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\Mixin\CopyFileViaStreaming;
use WordPress\Filesystem\Mixin\GetContentsViaReadStream;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

/**
 * Represents a Google Drive filesystem implementation.
 */
class GoogleDriveFilesystem implements Filesystem {

	use CopyFileViaStreaming;
	use GetContentsViaReadStream;

	private $root = '/';
	private $access_token;
	private $path_id_cache = array();
	private $http_client;

	public static function create( $root = '/', $config = array() ) {
		return new ChrootLayer(
			new GoogleDriveFilesystem( $config ),
			$root
		);
	}

	private function __construct( $config = array() ) {
		$this->access_token = $config['access_token'] ?? '';
		$this->http_client  = $config['http_client'] ?? new Client();
	}

	public function get_root() {
		return $this->root;
	}

	public function ls( $path = '/' ) {
		$query = "'root' in parents and trashed = false";

		if ( $path !== '/' ) {
			$parent_id = $this->path_to_id( $path );
			$query     = "'$parent_id' in parents and trashed = false";
		}

		$response = $this->make_api_request(
			'files',
			'GET',
			array(
				'q' => $query,
				'fields' => 'files(id,name,mimeType)',
				'pageSize' => 1000,
			)
		);

		if ( ! $response || ! isset( $response['files'] ) ) {
			throw new FilesystemException(
				sprintf( 'Google Drive API error: %s', $response['error']['message'] )
			);
		}

		$result = array();
		foreach ( $response['files'] as $file ) {
			$result[] = $file['name'];
		}
		return $result;
	}

	private function path_to_id( $path ) {
		$path = $this->resolve_path( $path );

		if ( isset( $this->path_id_cache[ $path ] ) ) {
			return $this->path_id_cache[ $path ];
		}

		if ( $path === '/' ) {
			$this->path_id_cache[ $path ] = 'root';
			return 'root';
		}

		$path         = trim( $path, '/' );
		$segments     = explode( '/', $path );
		$parent_id    = 'root';
		$current_path = '';

		foreach ( $segments as $segment ) {
			$current_path .= '/' . $segment;

			if ( isset( $this->path_id_cache[ $current_path ] ) ) {
				$parent_id = $this->path_id_cache[ $current_path ];
				continue;
			}

			$query    = "'$parent_id' in parents and name = '$segment' and trashed = false";
			$response = $this->make_api_request(
				'files',
				'GET',
				array(
					'q' => $query,
					'fields' => 'files(id,mimeType)',
					'pageSize' => 1,
				)
			);

			if ( ! $response || empty( $response['files'] ) ) {
				$this->path_id_cache[ $current_path ] = '';
				return '';
			}

			$parent_id                            = $response['files'][0]['id'];
			$this->path_id_cache[ $current_path ] = $parent_id;
		}

		return $parent_id;
	}

	public function is_dir( $path ) {
		$file_id  = $this->path_to_id( $path );
		$response = $this->make_api_request(
			"files/$file_id",
			'GET',
			array(
				'fields' => 'mimeType',
			)
		);
		return $response && $response['mimeType'] === 'application/vnd.google-apps.folder';
	}

	public function is_file( $path ) {
		$file_id  = $this->path_to_id( $path );
		$response = $this->make_api_request(
			"files/$file_id",
			'GET',
			array(
				'fields' => 'mimeType',
			)
		);
		return $response && $response['mimeType'] !== 'application/vnd.google-apps.folder';
	}

	public function exists( $path ) {
		return (bool) $this->path_to_id( $path );
	}

	public function open_read_stream( $path ): ByteReadStream {
		$file_id  = $this->path_to_id( $path );
		$response = $this->make_api_request(
			"files/$file_id",
			'GET',
			array(
				'alt' => 'media',
			)
		);

		return new MemoryPipe( $response );
	}

	public function mkdir( $path, $options = array() ) {
		if ( $this->path_to_id( $path ) ) {
			return true;
		}
		return (bool) $this->make_api_request(
			'files',
			'POST',
			array(),
			array(
				'name' => basename( $path ),
				'mimeType' => 'application/vnd.google-apps.folder',
				'parents' => array( $this->path_to_id( wp_dirname( $path ) ) ),
			)
		);
	}

	public function rm( $path ) {
		$file_id = $this->path_to_id( $path );
		return (bool) $this->make_api_request( "files/$file_id", 'DELETE' );
	}

	public function rmdir( $path, $options = array() ) {
		$recursive = $options['recursive'] ?? false;
		if ( $recursive ) {
			$path = rtrim( $path, '/' );
			foreach ( $this->ls( $path ) as $child ) {
				$child_path = $path . '/' . $child;
				if ( $this->is_dir( $child_path ) ) {
					$this->rmdir( $child_path, $options );
				} else {
					$this->rm( $child_path );
				}
			}
		}
		return (bool) $this->rm( $path );
	}

	public function rename( $path, $new_path, $options = array() ) {
		$file_id = $this->path_to_id( $path );
		return (bool) $this->make_api_request(
			"files/$file_id",
			'PATCH',
			array(
				'addParents' => $this->path_to_id( wp_dirname( $new_path ) ),
				'removeParents' => $this->path_to_id( wp_dirname( $path ) ),
			)
		);
	}

	public function put_contents( $path, $data, $options = array() ) {
		$file_id = $this->path_to_id( $path );
		if ( $file_id ) {
			return $this->make_api_request(
				'https://www.googleapis.com/upload/drive/v3/files/' . $file_id,
				'PATCH',
				array( 'uploadType' => 'media' ),
				$data
			);
		}
		$metadata_json = json_encode(
			array(
				'name' => basename( $path ),
				'parents' => array( $this->path_to_id( wp_dirname( $path ) ) ),
			)
		);
		$body          = <<<BODY
--BOUNDARY_STRING
Content-Type: application/json; charset=UTF-8

$metadata_json

--BOUNDARY_STRING
Content-Type: text/plain

$data

--BOUNDARY_STRING--
BODY;
		return $this->make_api_request(
			'https://www.googleapis.com/upload/drive/v3/files',
			'POST',
			array(
				'uploadType' => 'multipart',
			),
			$body
		);
	}

	public function open_write_stream( $path ): ByteWriteStream {
		throw new FilesystemException( 'Not implemented' );
	}

	private function resolve_path( $path ) {
		return wp_join_paths( $this->root, wp_canonicalize_path( $path ) );
	}

	private function make_api_request( $endpoint, $method = 'GET', $params = array(), $data = null ) {
		if ( str_starts_with( $endpoint, 'https://' ) ) {
			$url = $endpoint;
		} else {
			$url = 'https://www.googleapis.com/drive/v3/' . $endpoint;
		}
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}
		$request_info = array(
			'method' => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Accept' => 'application/json',
			),
		);
		if ( $data ) {
			if ( is_string( $data ) ) {
				$request_info['body_stream'] = new MemoryPipe(
					$data
				);
				if ( isset( $params['uploadType'] ) && $params['uploadType'] === 'multipart' ) {
					$request_info['headers']['Content-Type'] = 'multipart/form-data; boundary=BOUNDARY_STRING';
				}
			} else {
				$request_info['body_stream']             = new MemoryPipe(
					json_encode( $data )
				);
				$request_info['headers']['Content-Type'] = 'application/json';
			}
		}
		$request = new Request( $url, $request_info );
		$this->http_client->enqueue( $request );

		$buffered_response = '';
		while ( $this->http_client->await_next_event() ) {
			$event = $this->http_client->get_event();
			switch ( $event ) {
				case Client::EVENT_BODY_CHUNK_AVAILABLE:
					$buffered_response .= $this->http_client->get_response_body_chunk();
					break;
				case Client::EVENT_FAILED:
					return false;
			}
		}
		$response_json = json_decode( $buffered_response, true );
		if ( isset( $response_json['error'] ) ) {
			throw new FilesystemException(
				sprintf( 'Google Drive API error: %s', $response_json['error']['message'] )
			);
		}
		if ( ! isset( $response_json ) ) {
			throw new FilesystemException(
				sprintf( 'Google Drive API error: %s', $buffered_response )
			);
		}
		return $response_json;
	}
}
