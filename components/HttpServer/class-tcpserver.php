<?php

namespace WordPress\HttpServer;

use Exception;
use RuntimeException;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\HttpServer\Response\TcpResponseWriteStream;

/**
 * Server that listens on a TCP port and handles HTTP requests directly.
 * Note: This is a minimal, blocking implementation for demonstration purposes.
 */
class TcpServer {

	private $handler;
	private $host;
	private $port;

	/**
	 * @param  string $host
	 * @param  int    $port
	 */
	public function __construct( $host = '127.0.0.1', $port = 8080 ) {
		$this->host = $host;
		$this->port = $port;
	}

	public function set_handler( callable $handler ) {
		$this->handler = $handler;
	}

	public function serve( ?callable $on_accept = null ) {
		if ( ! is_callable( $this->handler ) ) {
			throw new RuntimeException( 'No request handler set. Call set_handler() before serve().' );
		}

		$socket = stream_socket_server(
			"tcp://{$this->host}:{$this->port}",
			$errno,
			$errstr
		);

		if ( ! $socket ) {
			throw new RuntimeException( "Failed to bind to {$this->host}:{$this->port} - $errstr ($errno)" );
		}

		if ( $on_accept ) {
			$on_accept( $this->host, $this->port );
		}

		while ( true ) {
			$client = @stream_socket_accept( $socket );
			if ( $client === false ) {
				continue;
			}

			// Initialize to null to avoid undefined variable errors
			$socket_write_stream = null;
			$response_writer     = null;

			try {
				$request = IncomingRequest::from_resource( $client );
				if ( ! is_callable( $this->handler ) ) {
					fwrite( $client, "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nNo handler set." );
					fclose( $client );
					continue;
				}

				$socket_write_stream = FileWriteStream::from_resource_handle(
					$client
				);
				$response_writer     = new TcpResponseWriteStream( $socket_write_stream );
				call_user_func( $this->handler, $request, $response_writer, $client );
			} catch ( Exception $e ) {
				error_log( 'Error: ' . $e->getMessage() );
			} finally {
				try {
					if ( $response_writer && ! $response_writer->is_writing_closed() ) {
						$response_writer->close_writing();
					}
				} catch ( Exception $e ) {
					error_log( 'Error closing response writer: ' . $e->getMessage() );
				}

				try {
					if ( $socket_write_stream ) {
						$socket_write_stream->close_writing();
					}
				} catch ( Exception $e ) {
					error_log( 'Error closing socket write stream: ' . $e->getMessage() );
				}
				if ( isset( $response_writer, $request ) && $response_writer ) {
					echo '[' . date( 'Y-m-d H:i:s' ) . '] ' . $response_writer->http_code . ' ' . $request->method . ' ' . $request->get_parsed_url()->pathname . "\n";
				}
			}
		}
	}
}
