<?php

namespace WordPress\Blueprints\DataReference;

use InvalidArgumentException;
use Nette\NotImplementedException;

class DataReference {

	/**
	 * @var int
	 */
	public $id;
	/**
	 * @var int
	 */
	private static $instanceCounter = 0;

	public function __construct() {
		$this->id = self::$instanceCounter ++;
	}

	static public function create( $reference, array $additional_reference_classes = [] ) {
		$classes = array_merge( [
			URLReference::class,
			GitPath::class,
			InlineDirectory::class,
			InlineFile::class,
		], $additional_reference_classes );
		foreach ( $classes as $class ) {
			if ( $class::is_valid( $reference ) ) {
				if ( method_exists( $class, 'from_blueprint_data' ) ) {
					return $class::from_blueprint_data( $reference );
				} else {
					return new $class( $reference );
				}
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
