<?php

namespace WordPress\Git\Protocol\Parser;

use WordPress\Git\GitException;
use WordPress\Git\Model\Commit;

class CommitParser {

	/**
	 * Raw bytes of the commit data being processed
	 *
	 * @var string
	 */
	protected $bytes;

	/**
	 * Current commit commit data
	 *
	 * @var Commit
	 */
	protected $commit;

	public static function parse( string $bytes ) {
		$parser = new CommitParser( $bytes );
		$parser->next();

		return $parser->get_commit();
	}

	public function __construct( $bytes ) {
		$this->bytes  = $bytes;
		$this->commit = new Commit();
	}

	/**
	 * Parse the next commit field
	 *
	 * @return bool Whether a field was successfully commit
	 */
	public function next() {
		if ( ! $this->bytes ) {
			return false;
		}
		$offset    = 0;
		$bytes_len = strlen( $this->bytes );

		while ( $offset < $bytes_len ) {
			// Find length of line.
			$line_len = strcspn( $this->bytes, "\n", $offset );
			$line     = substr( $this->bytes, $offset, $line_len );

			// Skip empty lines.
			if ( strspn( $line, " \t" ) === strlen( $line ) ) {
				// The rest is commit message.
				$this->commit->message = substr( $this->bytes, $offset + $line_len + 1 );
				$this->commit->hash    = sha1(
					'commit ' . strlen( $this->bytes ) . "\x00" .
					$this->bytes
				);
				$this->bytes           = '';

				return true;
			}

			$type_len = strcspn( $line, ' ' );
			$type     = substr( $line, 0, $type_len );
			$value    = substr( $line, $type_len + 1 );

			if ( 'author' === $type ) {
				$author_date_starts        = strpos( $value, '>' ) + 1;
				$this->commit->author      = substr( $value, 0, $author_date_starts );
				$this->commit->author_date = substr( $value, $author_date_starts + 1 );
			} elseif ( 'committer' === $type ) {
				$committer_date_starts        = strpos( $value, '>' ) + 1;
				$this->commit->committer      = substr( $value, 0, $committer_date_starts );
				$this->commit->committer_date = substr( $value, $committer_date_starts + 1 );
			} elseif ( 'parent' === $type ) {
				$this->commit->parents[] = $value;
			} elseif ( property_exists( $this->commit, $type ) ) {
				$this->commit->$type = $value;
			} else {
				throw new GitException( 'Unrecognized commit field: ' . $type );
			}

			$offset += $line_len + 1;
		}
	}

	/**
	 * Get the commit commit data
	 *
	 * @return Commit The commit commit data
	 */
	public function get_commit() {
		return $this->commit;
	}
}
