<?php

namespace WordPress\Git\Model;

use DateTime;
use WordPress\Git\GitException;

class Commit {

	public const NULL_HASH   = '0000000000000000000000000000000000000000';
	public const DATE_FORMAT = 'U +0000';

	/**
	 * The commit hash
	 *
	 * @var string
	 */
	public $hash;

	/**
	 * The tree hash this commit points to
	 *
	 * @var string
	 */
	public $tree;

	/**
	 * Array of parent commit hashes
	 *
	 * @var array
	 */
	public $parents = array();

	/**
	 * The commit author details
	 *
	 * @var string
	 */
	public $author;

	/**
	 * The author date
	 *
	 * @var string
	 */
	public $author_date;

	/**
	 * The committer details
	 *
	 * @var string
	 */
	public $committer;

	/**
	 * The committer date
	 *
	 * @var string
	 */
	public $committer_date;

	/**
	 * The commit message
	 *
	 * @var string
	 */
	public $message;

	/**
	 * The GPG signature
	 *
	 * @var string
	 */
	public $gpgsig;

	public static function is_null_hash( $oid ) {
		return null === $oid || self::NULL_HASH === $oid;
	}

	public function __construct( $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				throw new GitException( "Invalid commit property: $key" );
			}
			$this->$key = $value;
		}
		if ( ! isset( $this->author ) ) {
			$this->author = 'Admin <adam@adamziel.com>';
		}
		if ( ! isset( $this->author_date ) ) {
			$this->author_date = date( self::DATE_FORMAT );
		}
		if ( ! isset( $this->committer ) ) {
			$this->committer = 'Admin <adam@adamziel.com>';
		}
		if ( ! isset( $this->committer_date ) ) {
			$this->committer_date = date( self::DATE_FORMAT );
		}
	}

	public function get_author_date_time() {
		// Workaround: We can't use $head_commit_time->getTimestamp() on 32bit systems.
		if ( preg_match( '/^(\d+)\s+([+-]\d{2})(\d{2})$/', $this->author_date, $matches ) ) {
			$timestamp = $matches[1];

			return DateTime::createFromFormat( 'U', $timestamp );
		}

		return new DateTime( $this->author_date );
	}

	public function get_first_parent_hash() {
		if ( is_array( $this->parents ) ) {
			return $this->parents[0];
		}

		return $this->parents;
	}

	public function get_commit_string() {
		if ( ! $this->message ) {
			throw new GitException( 'Cannot create a commit string when the "message" field is empty' );
		}
		$commit_message   = array();
		$commit_message[] = 'tree ' . $this->tree;
		if ( isset( $this->parents ) ) {
			foreach ( $this->parents as $parent ) {
				$commit_message[] = 'parent ' . $parent;
			}
		}
		$commit_message[] = 'author ' . $this->author . ' ' . $this->author_date;
		$commit_message[] = 'committer ' . $this->committer . ' ' . $this->committer_date;
		$commit_message[] = "\n" . $this->message;

		return implode( "\n", $commit_message );
	}
}
