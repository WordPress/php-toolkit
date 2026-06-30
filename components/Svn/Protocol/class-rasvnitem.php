<?php

namespace WordPress\Svn\Protocol;

use WordPress\Svn\SvnException;

/**
 * A single value decoded from the svn:// (ra_svn) wire protocol.
 *
 * The protocol encodes four data types:
 *
 *     word    – bare token, e.g. `success` or `edit-pipeline`
 *     number  – ASCII digits terminated by whitespace, e.g. `42 `
 *     string  – length-prefixed bytes, e.g. `5:hello`
 *     list    – parenthesized sequence of items, e.g. `( 1 2:hi word )`
 *
 * Lists are represented as PHP arrays of RaSvnItem instances.
 */
class RaSvnItem {
	const TYPE_WORD   = 'word';
	const TYPE_NUMBER = 'number';
	const TYPE_STRING = 'string';
	const TYPE_LIST   = 'list';

	/**
	 * One of the TYPE_* constants.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * The decoded value: string for words and strings, int for
	 * numbers, RaSvnItem[] for lists.
	 *
	 * @var string|int|RaSvnItem[]
	 */
	public $value;

	public function __construct( $type, $value ) {
		$this->type  = $type;
		$this->value = $value;
	}

	/**
	 * @param  string $word  The expected word, or null to accept any word.
	 * @return bool Whether this item is the given word.
	 */
	public function is_word( $word = null ) {
		return self::TYPE_WORD === $this->type && ( null === $word || $this->value === $word );
	}

	/**
	 * @return string
	 * @throws SvnException When the item is not a word.
	 */
	public function get_word() {
		if ( self::TYPE_WORD !== $this->type ) {
			throw new SvnException( "Protocol error: expected a word, got a {$this->type}." );
		}

		return $this->value;
	}

	/**
	 * @return string
	 * @throws SvnException When the item is not a string.
	 */
	public function get_string() {
		if ( self::TYPE_STRING !== $this->type ) {
			throw new SvnException( "Protocol error: expected a string, got a {$this->type}." );
		}

		return $this->value;
	}

	/**
	 * Reads the item as a string, also accepting words. A few servers
	 * emit words where strings are expected, e.g. for author names.
	 *
	 * @return string
	 * @throws SvnException When the item is neither a string nor a word.
	 */
	public function get_string_or_word() {
		if ( self::TYPE_STRING !== $this->type && self::TYPE_WORD !== $this->type ) {
			throw new SvnException( "Protocol error: expected a string or a word, got a {$this->type}." );
		}

		return $this->value;
	}

	/**
	 * @return int
	 * @throws SvnException When the item is not a number.
	 */
	public function get_number() {
		if ( self::TYPE_NUMBER !== $this->type ) {
			throw new SvnException( "Protocol error: expected a number, got a {$this->type}." );
		}

		return $this->value;
	}

	/**
	 * @return RaSvnItem[]
	 * @throws SvnException When the item is not a list.
	 */
	public function get_list() {
		if ( self::TYPE_LIST !== $this->type ) {
			throw new SvnException( "Protocol error: expected a list, got a {$this->type}." );
		}

		return $this->value;
	}

	/**
	 * Reads a protocol boolean – the words `true` or `false`.
	 *
	 * @return bool
	 * @throws SvnException When the item is not a boolean word.
	 */
	public function get_boolean() {
		$word = $this->get_word();
		if ( 'true' === $word ) {
			return true;
		}
		if ( 'false' === $word ) {
			return false;
		}
		throw new SvnException( "Protocol error: expected a boolean word, got '{$word}'." );
	}

	/**
	 * Unwraps the protocol idiom for optional values: a list that is
	 * either empty or holds exactly one item, e.g. `( )` or `( 42 )`.
	 *
	 * @return RaSvnItem|null The single item, or null when the list is empty.
	 */
	public function get_optional() {
		$list = $this->get_list();
		if ( 0 === count( $list ) ) {
			return null;
		}

		return $list[0];
	}
}
