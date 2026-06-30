<?php

namespace WordPress\Svn\Protocol;

use WordPress\ByteStream\MemoryPipe;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\Svn\SvnEditor;
use WordPress\Svn\SvnException;
use WordPress\Svn\SvnSession;
use WordPress\XML\XMLProcessor;

/**
 * Subversion repository access over http:// and https:// (mod_dav_svn).
 *
 * Uses Subversion's "HTTP protocol v2", available since Subversion 1.7
 * (2011). An OPTIONS request discovers the stable URI stubs the server
 * exposes; everything else is plain HTTP against those stubs:
 *
 *  - Reads address `{rev-root-stub}/{revision}/{path}` resources with
 *    GET and PROPFIND.
 *  - Checkouts and updates POST an update-report REPORT and stream the
 *    response (see DavUpdateReportParser).
 *  - Commits create a server-side transaction (POST create-txn), build
 *    it with PUT/MKCOL/DELETE/PROPPATCH against the transaction tree,
 *    and commit it with MERGE.
 *
 * Credentials are sent as HTTP Basic authentication. Servers older than
 * Subversion 1.7 (which lack the v2 stubs) are rejected at connect time.
 */
class DavSession implements SvnSession {
	const SVN_PROP_NAMESPACE    = 'http://subversion.tigris.org/xmlns/svn/';
	const CUSTOM_PROP_NAMESPACE = 'http://subversion.tigris.org/xmlns/custom/';
	const SVN_DAV_NAMESPACE     = 'http://subversion.tigris.org/xmlns/dav/';
	const SVN_APACHE_NAMESPACE  = 'http://apache.org/dav/xmlns';
	const DAV_NAMESPACE         = 'DAV:';

	/**
	 * @var Client
	 */
	private $http_client;

	/**
	 * Canonical session URL without credentials.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Scheme plus authority, e.g. "https://develop.svn.wordpress.org".
	 *
	 * @var string
	 */
	private $server_base;

	/**
	 * Server-absolute path of the session URL, e.g. "/repos/repo1/trunk".
	 *
	 * @var string
	 */
	private $session_path;

	/**
	 * Server-absolute path of the repository root, e.g. "/repos/repo1".
	 *
	 * @var string
	 */
	private $root_path;

	/**
	 * Session path relative to the repository root, e.g. "trunk".
	 *
	 * @var string
	 */
	private $session_relative_path;

	/**
	 * Server-absolute v2 protocol stubs discovered via OPTIONS.
	 *
	 * @var string
	 */
	private $me_resource;
	private $rev_root_stub;
	private $txn_root_stub;
	private $txn_stub;

	/**
	 * @var string
	 */
	private $uuid;

	/**
	 * Youngest revision as of the last OPTIONS exchange.
	 *
	 * @var int
	 */
	private $youngest_revision;

	/**
	 * @var string|null
	 */
	private $username;

	/**
	 * @var string|null
	 */
	private $password;

	private function __construct() {
	}

	/**
	 * Opens a session against an http:// or https:// repository URL.
	 *
	 * @param  string $url      Repository URL, e.g. "https://develop.svn.wordpress.org/trunk".
	 * @param  array  $options  {
	 *     @type string $username     Username for HTTP Basic authentication.
	 *     @type string $password     Password for HTTP Basic authentication.
	 *     @type int    $timeout_ms   Network timeout. Default 60000.
	 *     @type Client $http_client  A custom HttpClient\Client instance.
	 * }
	 * @return DavSession
	 * @throws SvnException When the URL is invalid or the server does not
	 *                      speak HTTP protocol v2 (Subversion 1.7+).
	 */
	public static function connect( $url, $options = array() ) {
		$parts = parse_url( $url );
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			throw new SvnException( "Not a valid http(s):// URL: {$url}" );
		}

		$session              = new DavSession();
		$session->username    = isset( $options['username'] ) ? $options['username'] : ( isset( $parts['user'] ) ? rawurldecode( $parts['user'] ) : null );
		$session->password    = isset( $options['password'] ) ? $options['password'] : ( isset( $parts['pass'] ) ? rawurldecode( $parts['pass'] ) : null );
		$session->server_base = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		$session->session_path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		$session->url          = $session->server_base . $session->session_path;
		$session->http_client  = isset( $options['http_client'] ) ? $options['http_client'] : new Client(
			array(
				'timeout_ms' => isset( $options['timeout_ms'] ) ? $options['timeout_ms'] : 60000,
			)
		);

		$session->discover_capabilities();

		return $session;
	}

	public function get_session_url() {
		return $this->url;
	}

	public function get_repository_root() {
		return $this->server_base . $this->root_path;
	}

	public function get_uuid() {
		return $this->uuid;
	}

	public function get_latest_revision() {
		// Re-run the discovery so long-lived sessions see new commits.
		$this->discover_capabilities();

		return $this->youngest_revision;
	}

	public function check_path( $path, $revision = null ) {
		$response = $this->request(
			'PROPFIND',
			$this->revision_resource_url( $path, $revision ),
			array(
				'Depth'        => '0',
				'Content-Type' => 'text/xml',
			),
			'<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:"><D:prop><D:resourcetype/></D:prop></D:propfind>',
			array( 207, 404 )
		);
		$status   = $response['response']->status_code;
		if ( 404 === $status ) {
			return 'none';
		}

		$is_directory = false;
		$xml          = XMLProcessor::create_from_string( $response['body'] );
		while ( $xml->next_tag() ) {
			if ( 'collection' === $xml->get_tag_local_name() && self::DAV_NAMESPACE === $xml->get_tag_namespace() ) {
				$is_directory = true;
			}
		}

		return $is_directory ? 'dir' : 'file';
	}

	public function list_directory( $path, $revision = null ) {
		$response = $this->request(
			'PROPFIND',
			$this->revision_resource_url( $path, $revision ),
			array(
				'Depth'        => '1',
				'Content-Type' => 'text/xml',
			),
			'<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:"><D:prop><D:resourcetype/><D:getcontentlength/><D:version-name/></D:prop></D:propfind>',
			array( 207 )
		);

		$entries = array();
		foreach ( $this->parse_multistatus( $response['body'] ) as $resource ) {
			if ( $resource['is_self'] ) {
				continue;
			}
			$entries[] = array(
				'name'        => $resource['name'],
				'kind'        => $resource['is_collection'] ? 'dir' : 'file',
				'size'        => isset( $resource['dav_props']['getcontentlength'] ) ? (int) $resource['dav_props']['getcontentlength'] : 0,
				'created_rev' => isset( $resource['dav_props']['version-name'] ) ? (int) $resource['dav_props']['version-name'] : 0,
			);
		}

		return $entries;
	}

	public function get_file( $path, $revision = null ) {
		$revision = $this->resolve_revision( $revision );
		$get      = $this->request( 'GET', $this->revision_resource_url( $path, $revision ), array(), null, array( 200 ) );

		$propfind  = $this->request(
			'PROPFIND',
			$this->revision_resource_url( $path, $revision ),
			array(
				'Depth'        => '0',
				'Content-Type' => 'text/xml',
			),
			'<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:"><D:allprop/></D:propfind>',
			array( 207 )
		);
		$resources = $this->parse_multistatus( $propfind['body'] );
		$resource  = count( $resources ) > 0 ? $resources[0] : array(
			'svn_props' => array(),
			'dav_props' => array(),
		);

		return array(
			'contents'   => $get['body'],
			'checksum'   => isset( $resource['dav_props']['md5-checksum'] ) ? $resource['dav_props']['md5-checksum'] : null,
			'properties' => $resource['svn_props'],
		);
	}

	public function get_properties( $path, $revision = null ) {
		$response  = $this->request(
			'PROPFIND',
			$this->revision_resource_url( $path, $revision ),
			array(
				'Depth'        => '0',
				'Content-Type' => 'text/xml',
			),
			'<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:"><D:allprop/></D:propfind>',
			array( 207 )
		);
		$resources = $this->parse_multistatus( $response['body'] );

		return count( $resources ) > 0 ? $resources[0]['svn_props'] : array();
	}

	public function drive_update( $target_revision, $report, SvnEditor $editor, $options = array() ) {
		$depth = isset( $options['depth'] ) ? $options['depth'] : 'infinity';

		$body  = '<S:update-report xmlns:S="svn:" send-all="true">';
		$body .= '<S:src-path>' . $this->escape_xml( $this->session_path ) . '</S:src-path>';
		$body .= '<S:target-revision>' . (int) $target_revision . '</S:target-revision>';
		$body .= '<S:depth>' . $this->escape_xml( $depth ) . '</S:depth>';
		$body .= '<S:include-props>yes</S:include-props>';
		foreach ( $report as $report_entry ) {
			$entry_depth = isset( $report_entry['depth'] ) ? $report_entry['depth'] : 'infinity';
			$body       .= '<S:entry rev="' . (int) $report_entry['revision'] . '" depth="' . $this->escape_xml( $entry_depth ) . '"';
			if ( ! empty( $report_entry['start_empty'] ) ) {
				$body .= ' start-empty="true"';
			}
			$body .= '>' . $this->escape_xml( $report_entry['path'] ) . '</S:entry>';
		}
		$body .= '</S:update-report>';

		$request = new Request(
			$this->server_base . $this->me_resource,
			array(
				'method'      => 'REPORT',
				'headers'     => $this->build_headers(
					array(
						'Content-Type' => 'text/xml',
						'Depth'        => '0',
					)
				),
				'body_stream' => new MemoryPipe( $body ),
			)
		);

		$stream   = $this->http_client->fetch( $request );
		$response = $stream->await_response();
		if ( 200 !== $response->status_code ) {
			throw $this->error_from_response( 'REPORT', $this->server_base . $this->me_resource, $response->status_code, $stream->consume_all() );
		}

		$parser = new DavUpdateReportParser( $editor );
		while ( ! $stream->reached_end_of_data() ) {
			$pulled = $stream->pull( 65536 );
			if ( 0 === $pulled ) {
				continue;
			}
			$parser->append_bytes( $stream->consume( $pulled ) );
		}
		$parser->finish();
	}

	public function commit( $message, $operations ) {
		$transaction_name = $this->create_transaction();
		try {
			$this->set_transaction_property( $transaction_name, 'svn:log', $message );

			// Deletes go first so replaced paths drive correctly, parents
			// before children so MKCOL works, all sorted for determinism.
			$sorted = $operations;
			usort(
				$sorted,
				function ( $a, $b ) {
					$a_is_delete = 'delete' === $a['op'] ? 0 : 1;
					$b_is_delete = 'delete' === $b['op'] ? 0 : 1;
					if ( $a_is_delete !== $b_is_delete ) {
						return $a_is_delete - $b_is_delete;
					}

					return strcmp( $a['path'], $b['path'] );
				}
			);

			foreach ( $sorted as $operation ) {
				$this->apply_commit_operation( $transaction_name, $operation );
			}

			return $this->merge_transaction( $transaction_name );
		} catch ( SvnException $exception ) {
			$this->abort_transaction( $transaction_name );
			throw $exception;
		}
	}

	public function close() {
		// HTTP is stateless; there is nothing to tear down.
	}

	/**
	 * Issues the OPTIONS request that discovers the protocol v2 stubs,
	 * the repository UUID, and the youngest revision.
	 *
	 * @throws SvnException When the server does not speak HTTP protocol v2.
	 */
	private function discover_capabilities() {
		$response = $this->request(
			'OPTIONS',
			$this->url,
			array( 'Content-Type' => 'text/xml' ),
			'<?xml version="1.0" encoding="utf-8"?><D:options xmlns:D="DAV:"><D:activity-collection-set/></D:options>',
			array( 200 )
		);
		$headers  = $response['response'];

		$rev_root_stub = $headers->get_header( 'SVN-Rev-Root-Stub' );
		$me_resource   = $headers->get_header( 'SVN-Me-Resource' );
		if ( null === $rev_root_stub || null === $me_resource ) {
			throw new SvnException(
				"The server at {$this->url} does not support Subversion's HTTP protocol v2. " .
				'Servers running Subversion 1.7 (2011) or newer are required.'
			);
		}

		$this->rev_root_stub = $rev_root_stub;
		$this->me_resource   = $me_resource;
		$this->txn_root_stub = $headers->get_header( 'SVN-Txn-Root-Stub' );
		$this->txn_stub      = $headers->get_header( 'SVN-Txn-Stub' );
		$this->uuid          = $headers->get_header( 'SVN-Repository-UUID' );

		$youngest = $headers->get_header( 'SVN-Youngest-Rev' );
		if ( null !== $youngest ) {
			$this->youngest_revision = (int) trim( $youngest );
		}

		// The stubs are server-absolute and always end in "/!svn/<stub>",
		// which makes them the most reliable way to find the repository
		// root path – more so than the SVN-Repository-Root header, which
		// some proxies drop.
		$stub_position   = strrpos( $rev_root_stub, '/!svn/' );
		$this->root_path = false === $stub_position ? '' : substr( $rev_root_stub, 0, $stub_position );

		if ( 0 !== strpos( $this->session_path . '/', $this->root_path . '/' ) ) {
			throw new SvnException( "The session URL {$this->url} lies outside the repository root {$this->server_base}{$this->root_path}." );
		}
		$this->session_relative_path = trim( substr( $this->session_path, strlen( $this->root_path ) ), '/' );
	}

	/**
	 * Builds the URL of a path at a revision under the rev-root stub.
	 *
	 * @param  string   $path      Path relative to the session URL.
	 * @param  int|null $revision  The revision, or null for the latest.
	 * @return string The absolute URL.
	 */
	private function revision_resource_url( $path, $revision ) {
		$revision  = $this->resolve_revision( $revision );
		$full_path = $this->session_relative_path;
		if ( '' !== $path ) {
			$full_path = '' === $full_path ? $path : $full_path . '/' . $path;
		}

		return $this->server_base . $this->rev_root_stub . '/' . $revision . ( '' === $full_path ? '' : '/' . $this->encode_url_path( $full_path ) );
	}

	private function resolve_revision( $revision ) {
		if ( null !== $revision ) {
			return (int) $revision;
		}
		if ( null === $this->youngest_revision ) {
			$this->discover_capabilities();
		}

		return $this->youngest_revision;
	}

	/**
	 * Percent-encodes a path for use in a URL, segment by segment.
	 *
	 * @param  string $path  A forward-slash path.
	 * @return string The encoded path.
	 */
	private function encode_url_path( $path ) {
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			$segments[] = rawurlencode( $segment );
		}

		return implode( '/', $segments );
	}

	/**
	 * Performs a buffered HTTP request.
	 *
	 * @param  string      $method            The HTTP method.
	 * @param  string      $url               The absolute URL.
	 * @param  array       $headers           Extra request headers.
	 * @param  string|null $body              The request body, if any.
	 * @param  int[]       $expected_statuses Status codes that are not errors.
	 * @return array { @type Response $response  @type string $body }
	 * @throws SvnException When the response status is unexpected.
	 */
	private function request( $method, $url, $headers = array(), $body = null, $expected_statuses = array( 200 ) ) {
		$request_info = array(
			'method'  => $method,
			'headers' => $this->build_headers( $headers ),
		);
		if ( null !== $body ) {
			$request_info['body_stream'] = new MemoryPipe( $body );
		}

		$stream   = $this->http_client->fetch( new Request( $url, $request_info ) );
		$response = $stream->await_response();
		$contents = $stream->consume_all();

		if ( ! in_array( $response->status_code, $expected_statuses, true ) ) {
			throw $this->error_from_response( $method, $url, $response->status_code, $contents );
		}

		return array(
			'response' => $response,
			'body'     => $contents,
		);
	}

	private function build_headers( $headers ) {
		$headers['User-Agent'] = 'php-toolkit-svn/1.0';
		if ( null !== $this->username && null !== $this->password ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $this->username . ':' . $this->password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
		}

		return $headers;
	}

	/**
	 * Builds a useful exception from an HTTP error response, surfacing
	 * the human-readable message mod_dav_svn embeds in error bodies.
	 *
	 * @param  string $method       The request method.
	 * @param  string $url          The request URL.
	 * @param  int    $status_code  The response status.
	 * @param  string $body         The response body.
	 * @return SvnException
	 */
	private function error_from_response( $method, $url, $status_code, $body ) {
		$detail = '';
		if ( '' !== $body && false !== strpos( $body, 'human-readable' ) ) {
			$xml = XMLProcessor::create_from_string( $body );
			while ( $xml->next_tag() ) {
				if ( 'human-readable' === $xml->get_tag_local_name() ) {
					$xml->next_token();
					$detail = trim( $xml->get_modifiable_text() );
					break;
				}
			}
		}
		if ( 401 === $status_code && '' === $detail ) {
			$detail = null === $this->username
				? 'The server requires authentication. Provide a username and a password.'
				: 'The server rejected the provided credentials.';
		}

		return new SvnException(
			"{$method} {$url} failed with HTTP {$status_code}" . ( '' !== $detail ? ": {$detail}" : '.' )
		);
	}

	/**
	 * Parses a DAV multistatus response.
	 *
	 * @param  string $body  The 207 response body.
	 * @return array[] One entry per resource: {
	 *     @type string $name           Resource basename ('' for the root).
	 *     @type bool   $is_self        Whether this is the requested resource itself.
	 *     @type bool   $is_collection  Whether the resource is a directory.
	 *     @type array  $svn_props      Versioned svn properties, name => value.
	 *     @type array  $dav_props      DAV-level properties, local name => value.
	 * }
	 */
	private function parse_multistatus( $body ) {
		$resources = array();
		$xml       = XMLProcessor::create_from_string( $body );

		$resource         = null;
		$first_href       = null;
		$collecting       = null;
		$collecting_value = '';
		$prop_is_base64   = false;

		while ( $xml->next_token() ) {
			$token_type = $xml->get_token_type();
			if ( '#text' === $token_type || '#cdata-section' === $token_type ) {
				if ( null !== $collecting ) {
					$collecting_value .= $xml->get_modifiable_text();
				}
				continue;
			}
			if ( '#tag' !== $token_type ) {
				continue;
			}

			$xml_namespace = $xml->get_tag_namespace();
			$local_name    = $xml->get_tag_local_name();
			// Empty elements such as <D:collection/> report neither
			// is_tag_opener() nor is_tag_closer(); they act as both.
			$is_opener = $xml->is_tag_opener() || $xml->is_empty_element();
			$is_closer = $xml->is_tag_closer() || $xml->is_empty_element();

			if ( self::DAV_NAMESPACE === $xml_namespace && 'response' === $local_name ) {
				if ( $is_opener && ! $xml->is_empty_element() ) {
					$resource = array(
						'href'          => '',
						'is_collection' => false,
						'svn_props'     => array(),
						'dav_props'     => array(),
					);
				}
				if ( $is_closer && null !== $resource ) {
					if ( null === $first_href ) {
						$first_href = $resource['href'];
					}
					$href                = rtrim( $resource['href'], '/' );
					$resource['is_self'] = rtrim( $first_href, '/' ) === $href && 0 === count( $resources );
					$basename            = strrpos( $href, '/' );
					$resource['name']    = rawurldecode( false === $basename ? $href : substr( $href, $basename + 1 ) );
					$resources[]         = $resource;
					$resource            = null;
				}
				continue;
			}
			if ( null === $resource ) {
				continue;
			}

			if ( $is_opener && self::DAV_NAMESPACE === $xml_namespace && 'collection' === $local_name ) {
				$resource['is_collection'] = true;
				continue;
			}

			if ( $is_opener && ! $xml->is_empty_element() ) {
				if ( self::DAV_NAMESPACE === $xml_namespace && 'href' === $local_name && '' === $resource['href'] ) {
					$collecting       = array( 'href', null );
					$collecting_value = '';
					continue;
				}
				if ( self::SVN_PROP_NAMESPACE === $xml_namespace || self::CUSTOM_PROP_NAMESPACE === $xml_namespace ) {
					$prop_name        = self::SVN_PROP_NAMESPACE === $xml_namespace ? 'svn:' . $local_name : $local_name;
					$collecting       = array( 'svn_prop', $prop_name );
					$collecting_value = '';
					$prop_is_base64   = 'base64' === $xml->get_attribute( self::SVN_DAV_NAMESPACE, 'encoding' );
					continue;
				}
				if ( self::DAV_NAMESPACE === $xml_namespace || self::SVN_DAV_NAMESPACE === $xml_namespace ) {
					$collecting       = array( 'dav_prop', $local_name );
					$collecting_value = '';
					continue;
				}
			}

			if ( $is_closer && null !== $collecting ) {
				list( $kind, $prop_name ) = $collecting;
				if ( 'href' === $kind && 'href' === $local_name ) {
					$resource['href'] = trim( $collecting_value );
				} elseif ( 'svn_prop' === $kind && ( self::SVN_PROP_NAMESPACE === $xml_namespace || self::CUSTOM_PROP_NAMESPACE === $xml_namespace ) ) {
					$value = $collecting_value;
					if ( $prop_is_base64 ) {
						$value = base64_decode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding wire data, not code.
					}
					$resource['svn_props'][ $prop_name ] = $value;
				} elseif ( 'dav_prop' === $kind && ( self::DAV_NAMESPACE === $xml_namespace || self::SVN_DAV_NAMESPACE === $xml_namespace ) ) {
					if ( ! isset( $resource['dav_props'][ $prop_name ] ) || '' !== trim( $collecting_value ) ) {
						$resource['dav_props'][ $prop_name ] = trim( $collecting_value );
					}
				}
				$collecting       = null;
				$collecting_value = '';
				$prop_is_base64   = false;
			}
		}

		return $resources;
	}

	/**
	 * POSTs a create-txn request and returns the new transaction's name.
	 *
	 * @return string The transaction name from the SVN-Txn-Name header.
	 */
	private function create_transaction() {
		if ( null === $this->txn_root_stub || null === $this->txn_stub ) {
			throw new SvnException( 'The server does not expose transaction stubs; commits over HTTP are not possible.' );
		}
		$response         = $this->request(
			'POST',
			$this->server_base . $this->me_resource,
			array( 'Content-Type' => 'application/vnd.svn-skel' ),
			'( create-txn )',
			array( 201 )
		);
		$transaction_name = $response['response']->get_header( 'SVN-Txn-Name' );
		if ( null === $transaction_name ) {
			throw new SvnException( 'The server accepted the transaction but did not return an SVN-Txn-Name header.' );
		}

		return trim( $transaction_name );
	}

	/**
	 * Sets a revision property (such as svn:log) on an open transaction.
	 *
	 * @param string $transaction_name  The transaction to modify.
	 * @param string $name              Property name, e.g. "svn:log".
	 * @param string $value             Property value.
	 */
	private function set_transaction_property( $transaction_name, $name, $value ) {
		$this->request(
			'PROPPATCH',
			$this->server_base . $this->txn_stub . '/' . $transaction_name,
			array( 'Content-Type' => 'text/xml' ),
			'<?xml version="1.0" encoding="utf-8"?><D:propertyupdate xmlns:D="DAV:"><D:set><D:prop>' .
				$this->property_xml( $name, $value ) .
			'</D:prop></D:set></D:propertyupdate>',
			array( 207 )
		);
	}

	/**
	 * Applies one commit operation to the transaction tree.
	 *
	 * @param string $transaction_name  The open transaction.
	 * @param array  $operation         See SvnSession::commit().
	 */
	private function apply_commit_operation( $transaction_name, $operation ) {
		$url = $this->server_base . $this->txn_root_stub . '/' . $transaction_name . '/' .
			$this->encode_url_path(
				'' === $this->session_relative_path
					? $operation['path']
					: $this->session_relative_path . '/' . $operation['path']
			);

		switch ( $operation['op'] ) {
			case 'delete':
				$headers = array();
				if ( isset( $operation['base_revision'] ) ) {
					$headers['X-SVN-Version-Name'] = (string) (int) $operation['base_revision'];
				}
				$this->request( 'DELETE', $url, $headers, null, array( 204 ) );
				break;

			case 'add-directory':
				$this->request( 'MKCOL', $url, array(), null, array( 201 ) );
				$this->apply_property_changes( $url, isset( $operation['properties'] ) ? $operation['properties'] : array() );
				break;

			case 'add-file':
			case 'modify-file':
				$headers = array( 'Content-Type' => 'application/octet-stream' );
				if ( 'modify-file' === $operation['op'] && isset( $operation['base_revision'] ) ) {
					$headers['X-SVN-Version-Name'] = (string) (int) $operation['base_revision'];
				}
				$this->request( 'PUT', $url, $headers, $operation['contents'], array( 201, 204 ) );
				$this->apply_property_changes( $url, isset( $operation['properties'] ) ? $operation['properties'] : array() );
				break;

			case 'modify-properties':
				$this->apply_property_changes( $url, $operation['properties'] );
				break;

			default:
				throw new SvnException( "Unknown commit operation '{$operation['op']}'." );
		}
	}

	/**
	 * PROPPATCHes versioned property changes onto a transaction resource.
	 *
	 * @param string $url         The transaction resource URL.
	 * @param array  $properties  Property name => value, null value deletes.
	 */
	private function apply_property_changes( $url, $properties ) {
		if ( 0 === count( $properties ) ) {
			return;
		}
		$sets    = '';
		$removes = '';
		foreach ( $properties as $name => $value ) {
			if ( null === $value ) {
				$removes .= $this->property_xml( $name, null );
			} else {
				$sets .= $this->property_xml( $name, $value );
			}
		}
		$body = '<?xml version="1.0" encoding="utf-8"?><D:propertyupdate xmlns:D="DAV:">';
		if ( '' !== $sets ) {
			$body .= '<D:set><D:prop>' . $sets . '</D:prop></D:set>';
		}
		if ( '' !== $removes ) {
			$body .= '<D:remove><D:prop>' . $removes . '</D:prop></D:remove>';
		}
		$body .= '</D:propertyupdate>';

		$this->request( 'PROPPATCH', $url, array( 'Content-Type' => 'text/xml' ), $body, array( 207 ) );
	}

	/**
	 * Serializes one property for a PROPPATCH body. svn:* properties live
	 * in the svn property namespace, everything else in the custom one.
	 *
	 * @param  string      $name   Property name, e.g. "svn:eol-style".
	 * @param  string|null $value  Property value; null emits an empty element for D:remove.
	 * @return string The XML fragment.
	 */
	private function property_xml( $name, $value ) {
		if ( 0 === strpos( $name, 'svn:' ) ) {
			$element_namespace = self::SVN_PROP_NAMESPACE;
			$local_name        = substr( $name, 4 );
		} else {
			$element_namespace = self::CUSTOM_PROP_NAMESPACE;
			$local_name        = $name;
		}
		if ( null === $value ) {
			return '<P:' . $local_name . ' xmlns:P="' . $element_namespace . '"/>';
		}

		return '<P:' . $local_name . ' xmlns:P="' . $element_namespace . '">' . $this->escape_xml( $value ) . '</P:' . $local_name . '>';
	}

	/**
	 * MERGEs a transaction into the repository, creating the revision.
	 *
	 * @param  string $transaction_name  The transaction to commit.
	 * @return array { @type int $revision  @type string|null $author  @type string|null $date }
	 */
	private function merge_transaction( $transaction_name ) {
		$response = $this->request(
			'MERGE',
			$this->server_base . ( '' === $this->session_path ? '/' : $this->session_path ),
			array( 'Content-Type' => 'text/xml' ),
			'<?xml version="1.0" encoding="utf-8"?><D:merge xmlns:D="DAV:"><D:source><D:href>' .
				$this->escape_xml( $this->txn_stub . '/' . $transaction_name ) .
			'</D:href></D:source><D:no-auto-merge/><D:no-checkout/><D:prop>' .
			'<D:checked-in/><D:version-name/><D:resourcetype/><D:creationdate/><D:creator-displayname/>' .
			'</D:prop></D:merge>',
			array( 200 )
		);

		$revision   = null;
		$date       = null;
		$author     = null;
		$xml        = XMLProcessor::create_from_string( $response['body'] );
		$collecting = null;
		while ( $xml->next_token() ) {
			if ( '#text' === $xml->get_token_type() ) {
				if ( null !== $collecting ) {
					$value = trim( $xml->get_modifiable_text() );
					if ( 'version-name' === $collecting && null === $revision && '' !== $value ) {
						$revision = (int) $value;
					}
					if ( 'creationdate' === $collecting && null === $date && '' !== $value ) {
						$date = $value;
					}
					if ( 'creator-displayname' === $collecting && null === $author && '' !== $value ) {
						$author = $value;
					}
				}
				continue;
			}
			if ( '#tag' !== $xml->get_token_type() ) {
				continue;
			}
			if ( $xml->is_tag_opener() && in_array( $xml->get_tag_local_name(), array( 'version-name', 'creationdate', 'creator-displayname' ), true ) ) {
				$collecting = $xml->get_tag_local_name();
			} elseif ( $xml->is_tag_closer() ) {
				$collecting = null;
			}
		}
		if ( null === $revision ) {
			throw new SvnException( 'The server merged the transaction but reported no new revision number.' );
		}

		return array(
			'revision' => $revision,
			'author'   => null !== $author ? $author : $this->username,
			'date'     => $date,
		);
	}

	/**
	 * Deletes a transaction after a failed commit so the server can
	 * reclaim it.
	 *
	 * @param string $transaction_name  The transaction to abort.
	 */
	private function abort_transaction( $transaction_name ) {
		try {
			$this->request(
				'DELETE',
				$this->server_base . $this->txn_stub . '/' . $transaction_name,
				array(),
				null,
				array( 204 )
			);
		} catch ( SvnException $exception ) {
			// The commit error is the interesting one; a failed cleanup
			// only leaves a stray transaction behind on the server.
		}
	}

	private function escape_xml( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
