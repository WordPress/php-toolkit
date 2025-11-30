<?php

namespace WordPress\Blueprints\DataReference;

use InvalidArgumentException;
use VendorPrefix\Nette\NotImplementedException;

class DataReference {

	/**
	 * @var int
	 */
	public $id;

	/**
	 * @var array
	 */
	public $original_definition;

	/**
	 * @var int
	 */
	private static $instance_counter = 0;

	public function __construct( $original_definition = null ) {
		$this->id                  = self::$instance_counter++;
		$this->original_definition = $original_definition;
	}

	public static function create( $reference, array $additional_reference_classes = array() ) {
		$classes = array_merge(
			array(
				URLReference::class,
				GitPath::class,
				InlineDirectory::class,
				InlineFile::class,
			),
			$additional_reference_classes
		);
		foreach ( $classes as $class ) {
			if ( $class::is_valid( $reference ) ) {
				return new $class( $reference );
			}
		}
		throw new InvalidArgumentException(
			sprintf(
				'Invalid data reference: %s',
				is_string( $reference ) ? $reference : json_encode( $reference )
			)
		);
	}

	public function get_filename(): string {
		throw new NotImplementedException( 'get_filename is not implemented for this data reference' );
	}

	public function get_human_readable_name(): string {
		throw new NotImplementedException( 'get_human_readable_name is not implemented for this data reference' );
	}
}
