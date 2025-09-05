<?php

namespace WordPress\Blueprints\Validator;

// @TODO: Reconsider the need for the Symbol class. We use it
// as a unique reference that can't be possibly brought
// in with the validated data.
class Symbol {
	/**
	 * @var string
	 */
	public $value;

	public function __construct( string $value ) {
		$this->value = $value;
	}

	public function __toString(): string {
		return $this->value;
	}
}

/**
 * A lite JSON schema validator with human-centric error messages.
 *
 * ## Why a custom validator?
 *
 * Existing JSON validation libraries don't produce user-friendly error messages.
 *
 * Here's a few examples of what most libraries would report for
 * popular invalid Blueprint scenarios.
 *
 * In this Blueprint, the "resource" property should have a "url" key:
 *
 *     {"steps":[{"step":"writeFile","path":"/tmp/media/WordPress-logotype-wmark.png","data":{"resource":"url","path":"https://s.w.org/style/images/about/WordPress-logotype-wmark.png"}}]}'
 *
 * However, a typical error is more like:
 *
 *     must be equal to constant at /steps/0/data/resource
 *
 * An invalid "step" value:
 *
 *     {"steps":[ {"step":"noSuchStep"} ]}
 *
 * Is typically rejected with:
 *
 *     value of tag "step" must be in oneOf at /steps/0
 *
 * It's not terrible, but it isn't great either. It doesn't tell us what the allowed values are.
 *
 * It gets worse for schemas without a clear discriminator (such as Blueprint v2).
 * Imagine the following schema:
 *
 *     {
 *       "type": "object",
 *       "properties": {
 *         "media": {
 *           "anyOf": [
 *             {"type": "string"},
 *             {
 *               "type":"object",
 *               "required":["filename", "content"]
 *             }
 *           ]
 *         }
 *       }
 *     }
 *
 * The following Blueprint is invalid – it's missing the "content" property:
 *
 *     {"media": { "filename": "post.html" } }
 *
 * However, a typical error message is:
 *
 *     #/properties/media/anyOf: JSON does not match any schemas from 'anyOf'.
 *     #/properties/media/anyOf/1/required Required properties are missing from object: dirname, files.
 *     #/properties/media/anyOf/0/required Required properties are missing from object: content.
 *
 * It's awful! Technically, everything is true in it. But it's related to
 * JSON schema concepts. You need to open the schema to understand the error.
 *
 * How much better would it be to have a message similar to this instead?
 *
 *     The required "media.content" property is missing.
 *
 * It points you to the exact location and tell you what the problem is. Most
 * libraries just return all the failures on the way and don't bother with
 * making the output useful.
 *
 * Here's a few other reasons for having a custom validator:
 *
 * * Compatibility – it supports PHP 7.2 and no dependencies.
 * * Small footprint – it only implements what we need to validate Blueprints.
 * * Leniency – it can accept PHP arrays as objects. Steps accept data as arrays,
 *   and this little feature saves us from recursively converting
 *   between objects and arrays.
 */
final class HumanFriendlySchemaValidator {
	/**
	 * @var mixed[]
	 */
	private $schema;
	/**
	 * @var bool
	 */
	private $arrayIsValidObject;

	private $MISSING;

	public function __construct(
		array $schema,
		array $options = array()
	) {
		$this->schema             = $schema;
		$this->arrayIsValidObject = $options['array_is_valid_object'] ?? true;
		$this->MISSING            = new Symbol( 'missing' );
	}

	/**
	 * @param  mixed $data
	 */
	public function validate( $data ): ?ValidationError {
		return $this->validateNode( array( 'root' ), $data, $this->schema );
	}

	private function convertPathToString( array $path ): string {
		if ( empty( $path ) || $path[0] !== 'root' ) {
			array_unshift( $path, '#' ); // JSON pointers start with # or are relative
		} else {
			$path[0] = '#'; // Replace 'root' with '#'
		}
		$imploded = implode( '/', $path );
		if ( $imploded === '#' ) {
			return '#/';
		}

		return $imploded;
	}

	// ─────────────────────────────────────────────────────── helpers ─┐

	/**
	 * @param  mixed $v
	 */
	private function valueSnippet( $v ): string {
		return substr( json_encode( $v, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES ), 0, 80 );
	}

	private function branchLabel( array $s ): string {
		if ( isset( $s['$ref'] ) ) {
			return substr( $s['$ref'], strrpos( $s['$ref'], '/' ) + 1 );
		}

		return $s['title'] ?? ( $s['type'] ?? '<schema>' );
	}

	/**
	 * @param  mixed $data
	 */
	private function typeMatches( $data, ?string $type ): bool {
		$arrayIsListFunction = function ( array $array ): bool {
			if ( function_exists( 'array_is_list' ) ) {
				return array_is_list( $array );
			}
			if ( $array === array() ) {
				return true;
			}
			$current_key = 0;
			foreach ( $array as $key => $noop ) {
				if ( $key !== $current_key ) {
					return false;
				}
				++$current_key;
			}

			return true;
		};
		switch ( $type ) {
			case 'object':
				return is_object( $data ) || ( $this->arrayIsValidObject && is_array( $data ) && ( ! $arrayIsListFunction( $data ) || count( $data ) === 0 ) );
			case 'array':
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};
				$arrayIsListFunction = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( $array === array() ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};

				return is_array( $data ) && $arrayIsListFunction( $data );
			case 'string':
				return is_string( $data );
			case 'integer':
				return is_int( $data );
			case 'number':
				return is_int( $data ) || is_float( $data );
			case 'boolean':
				return is_bool( $data );
			case 'null':
				return is_null( $data );
			case null:
				return true;
			default:
				throw new UnsupportedSchemaException( "Type \"{$type}\" is not supported." );
		}
	}

	/**
	 * @param  mixed $data
	 */
	private function typeMatchesAny( $data, $type_or_types ): bool {
		if ( ! is_array( $type_or_types ) ) {
			return $this->typeMatches( $data, $type_or_types );
		}
		foreach ( $type_or_types as $t ) {
			if ( is_array( $t ) ) {
				$t = $t['type'] ?? null;
			}
			if ( $this->typeMatches( $data, $t ) ) {
				return true;
			}
		}

		return false;
	}

	// ───────────────────────────────────────────────────────── validation ─┐

	/**
	 * @param  mixed $data
	 */
	private function validateNode( array $path, $data, array $schema ): ?ValidationError {
		if ( isset( $schema['$ref'] ) ) {
			$schema = $this->resolveReference( $schema['$ref'] );
		}

		// Check for unsupported keywords
		$unsupportedKeywords = array(
			'not',
			'patternProperties',
			'dependencies',
			'if',
			'then',
			'else',
			'contentMediaType',
			'contentEncoding',
			'contentSchema',
		);
		foreach ( $unsupportedKeywords as $keyword ) {
			if ( isset( $schema[ $keyword ] ) ) {
				// This should remain an exception as it's a schema configuration issue, not a data validation issue.
				throw new UnsupportedSchemaException( "The schema keyword \"{$keyword}\" is not supported." );
			}
		}

		switch ( true ) {
			case isset( $schema['allOf'] ):
				return $this->validateAllOf( $path, $data, $schema );
			case isset( $schema['anyOf'] ):
				return $this->validateAnyOf( $path, $data, $schema );
			case isset( $schema['oneOf'] ):
				return $this->validateOneOf( $path, $data, $schema );
			case isset( $schema['type'] ):
				return $this->validateType( $path, $data, $schema );
			default:
				throw new UnsupportedSchemaException(
					'Every schema rule must have one of "allOf", "anyOf", "oneOf", "type" or be a "$ref". Rule for path ' . json_encode( $path ) . ' did not. Schema snippet: ' . substr(
						json_encode( $schema ),
						0,
						100
					)
				);
		}
	}

	// ───────────────────────────────────────────── anyOf / oneOf ─┐

	/**
	 * @param  mixed $data
	 */
	private function narrowBranches( $data, array $branches, array $schema ): array {
		// 1. filter by declared top‑level type
		$candidates = array_filter(
			$branches,
			function ( $spec ) use ( $data ) {
				while ( isset( $spec['$ref'] ) ) {
					$spec = $this->resolveReference( $spec['$ref'] );
				}

				return $this->typeMatchesAny( $data, $spec['type'] ?? null );
			}
		);

		// 2. filter by discriminator (explicit or inferred)
		$disc = $this->inferDiscriminator( $schema['discriminator'] ?? null, $branches );
		if ( $disc && ( is_array( $data ) || is_object( $data ) ) ) { // Discriminator implies object/array data
			$dataArr            = (array) $data; // Cast to array for consistent access
			[ $prop, $allowed ] = $disc;
			if ( array_key_exists( $prop, $dataArr ) ) {
				$wanted     = $dataArr[ $prop ];
				$candidates = array_values(
					array_filter(
						$candidates,
						function ( $b ) use ( $prop, $wanted ) {
							$r = isset( $b['$ref'] ) ? $this->resolveReference( $b['$ref'] ) : $b;
							// Ensure properties exist before accessing
							if ( isset( $r['properties'][ $prop ]['enum'][0] ) && ( $r['properties'][ $prop ]['enum'][0] === $wanted ) ) {
								return true;
							}
							if ( isset( $r['properties'][ $prop ]['const'] ) && ( $r['properties'][ $prop ]['const'] === $wanted ) ) {
								return true;
							}

							return false;
						}
					)
				);
			}
		}

		return $candidates ?: $branches; // never empty
	}

	private function validateAllOf( array $path, $data, array $schema ): ?ValidationError {
		$branches = $schema['allOf'];
		foreach ( $branches as $b ) {
			$error = $this->validateNode( $path, $data, isset( $b['$ref'] ) ? $this->resolveReference( $b['$ref'] ) : $b );
			if ( $error !== null ) {
				return $error;
			}
		}
		return null;
	}

	/**
	 * @param  mixed $data
	 */
	private function validateAnyOf( array $path, $data, array $schema ): ?ValidationError {
		$branches = $schema['anyOf'];
		$cands    = $this->narrowBranches( $data, $branches, $schema );
		// $narrowed = count($cands) < count($branches); // This logic changes
		$childErrors = array();

		foreach ( $cands as $b ) {
			// $label = $this->branchLabel($b); // branchLabel might be used in error message context
			$error = $this->validateNode( $path, $data, isset( $b['$ref'] ) ? $this->resolveReference( $b['$ref'] ) : $b );
			if ( $error === null ) {
				return null;
			} // Success, one branch matched
			// $this->tagBranch($label, $r); // tagBranch is removed
			$childErrors[] = $error;
		}

		// If we are here, no candidate branch validated successfully.
		// The old logic for $narrowed seems less relevant. We always create a parent error with children.
		// The explanation for aggregate mismatch needs to be adapted.
		return $this->explainAggregateMismatch( $path, $data, $branches, $schema, 'anyOf', $childErrors );
	}

	/**
	 * @param  mixed $data
	 */
	private function validateOneOf( array $path, $data, array $schema ): ?ValidationError {
		$branches = $schema['oneOf'];
		$cands    = $this->narrowBranches( $data, $branches, $schema );
		// $narrowed = count($cands) < count($branches);

		$validResults = array();
		$childErrors  = array();
		foreach ( $cands as $b ) {
			// $label=$this->branchLabel($b);
			$error = $this->validateNode( $path, $data, isset( $b['$ref'] ) ? $this->resolveReference( $b['$ref'] ) : $b );
			if ( $error === null ) {
				$validResults[] = $b; // Store the schema of the valid branch
			} else {
				// $this->tagBranch($label,$r); // tagBranch removed
				$childErrors[] = $error;
			}
		}

		if ( count( $validResults ) === 1 ) {
			return null;
		} // Exactly one schema matched

		if ( count( $validResults ) > 1 ) {
			$matchedShapes = array_map(
				function ( $b ) {
					if ( isset( $b['$ref'] ) ) {
							$resolved = $this->resolveReference( $b['$ref'] );

							return $resolved['title'] ?? $b['$ref'];
					}

					return $this->branchLabel( $b );
				},
				$validResults
			);

			return new ValidationError(
				$this->convertPathToString( $path ),
				'oneOf-multiple-matches',
				'Data matches more than one allowed shape - you need to make it unambiguous. Matched shapes: ' . implode(
					', ',
					$matchedShapes
				) . '.',
				array( 'matchedShapes' => $matchedShapes )
			);
		}

		// No schema matched, or narrowing didn't help / wasn't conclusive
		// The old logic for $narrowed seems less relevant. We always create a parent error with children.
		return $this->explainAggregateMismatch( $path, $data, $branches, $schema, 'oneOf', $childErrors );
	}

	/**
	 * Create a parent error for anyOf/oneOf mismatches.
	 *
	 * @param  mixed $data
	 */
	private function explainAggregateMismatch(
		array $path,
		$data,
		array $branches, // Original branches before narrowing
		array $parentSchema, // The schema containing anyOf/oneOf
		string $keyword, // 'anyOf' or 'oneOf'
		array $childErrors // Errors from validating against candidate branches
	): ValidationError {
		$pointer = $this->convertPathToString( $path );

		// 1. Type mismatch (if data type doesn't match any of the branch types)
		$allowedTypes = array();
		foreach ( $branches as $b ) {
			$s    = isset( $b['$ref'] ) ? $this->resolveReference( $b['$ref'] ) : $b;
			$type = $s['type'] ?? null;
			if ( $type !== null ) {
				if ( is_array( $type ) ) {
					foreach ( $type as $t ) {
						if ( ! in_array( $t, $allowedTypes, true ) ) {
							$allowedTypes[] = $t;
						}
					}
				} elseif ( ! in_array( $type, $allowedTypes, true ) ) {
						$allowedTypes[] = $type;
				}
			}
		}

		if ( ! empty( $allowedTypes ) && ! $this->typeMatchesAny( $data, $allowedTypes ) ) {
			if ( count( $allowedTypes ) === 1 ) {
				$message = sprintf(
					'Expected type "%s" but got type "%s".',
					$allowedTypes[0],
					gettype( $data )
				);
			} else {
				$message = sprintf(
					'Value must be one of the following types: [%s], but it was of type "%s".',
					implode( ', ', $allowedTypes ),
					gettype( $data )
				);
			}

			return new ValidationError(
				$pointer,
				'type-mismatch',
				$message,
				array(
					'expected' => array( 'types' => $allowedTypes ),
					'actual'   => array(
						'type' => gettype( $data ),
						'snippet' => $this->valueSnippet( $data ),
					),
				)
			);
		}

		// 2. Discriminator check (if applicable and discriminator value is invalid)
		$disc = $this->inferDiscriminator( $parentSchema['discriminator'] ?? null, $branches );
		if ( $disc ) {
			[ $prop, $allowedDiscriminatorValues ] = $disc;
			$actualValue                           = $this->MISSING; // Default to missing
			if ( is_array( $data ) && array_key_exists( $prop, $data ) ) {
				$actualValue = $data[ $prop ];
			} elseif ( is_object( $data ) && property_exists( $data, $prop ) ) {
				$actualValue = $data->$prop;
			}

			if ( ! in_array( $actualValue, $allowedDiscriminatorValues, true ) ) {
				$actual_humanized = ( $actualValue === $this->MISSING ) ? 'missing' : $this->valueSnippet( $actualValue );

				return new ValidationError(
					$pointer,
					'discriminator-mismatch',
					sprintf(
						'Property "%s" must be one of [%s], but it was %s.',
						$prop,
						implode( ', ', $allowedDiscriminatorValues ),
						$actual_humanized
					),
					array(
						'expected' => array(
							'property' => $prop,
							'allowedValues' => $allowedDiscriminatorValues,
						),
						'actual'   => array(
							'value'   => ( $actualValue === $this->MISSING ) ? null : $actualValue,
							'snippet' => $this->valueSnippet( $actualValue ),
						),
					)
				);
			}
		}

		// 3. If there's only one child error, return it directly.
		// No need to wrap it in a parent error.
		if ( count( $childErrors ) === 1 ) {
			return $childErrors[0];
		}

		// 4. Fallback: Generic message with children errors
		$labels  = array_unique( array_map( array( $this, 'branchLabel' ), $branches ) );
		$message = 'Value did not match any of the allowed shapes: ' . implode( ', ', $labels ) . '.';
		if ( $keyword === 'oneOf' ) {
			$message = 'Value did not match exactly one of the allowed shapes: ' . implode( ', ', $labels ) . '.';
		}

		return new ValidationError(
			$pointer,
			$keyword . '-mismatch', // e.g., 'anyOf-mismatch'
			$message,
			array( 'allowedShapes' => $labels ),
			$childErrors // Attach all child errors here
		);
	}

	// ─────────────────────────────────────────── primitives / objects / arrays ─┐

	/**
	 * @param  mixed $data
	 */
	private function validateType( array $path, $data, array $schema ): ?ValidationError {
		$type = $schema['type'];
		if ( ! $this->typeMatchesAny( $data, $type ) ) {
			$error = is_array( $type ) ? 'Expected one of the following types: ' . implode( ', ', $type ) . ' but got type "' . gettype( $data ) . '".' : 'Expected type "' . $type . '" but got type "' . gettype( $data ) . '".';
			return new ValidationError(
				$this->convertPathToString( $path ),
				'type-mismatch',
				$error,
				array(
					'expected' => array( 'type' => $type ),
					'actual'   => array(
						'type' => gettype( $data ),
						'snippet' => $this->valueSnippet( $data ),
					),
				)
			);
		}

		// Schema integrity checks (throw exceptions as these are schema definition issues)
		if ( $type === 'string' ) {
			$unsupportedStringKeywords = array( 'pattern', 'minLength', 'maxLength', 'format' );
			foreach ( $unsupportedStringKeywords as $keyword ) {
				if ( isset( $schema[ $keyword ] ) ) {
					throw new UnsupportedSchemaException( "The string constraint \"{$keyword}\" is not supported." );
				}
			}
		}
		if ( $type === 'number' || $type === 'integer' ) {
			$unsupportedNumericKeywords = array( 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf' );
			foreach ( $unsupportedNumericKeywords as $keyword ) {
				if ( isset( $schema[ $keyword ] ) ) {
					throw new UnsupportedSchemaException( "The numeric constraint \"{$keyword}\" is not supported." );
				}
			}
		}
		if ( isset( $schema['enum'] ) ) {
			foreach ( $schema['enum'] as $enumValue ) {
				if ( ! $this->typeMatches( $enumValue, $type ) ) {
					throw new UnsupportedSchemaException(
						'Enum value ' . json_encode( $enumValue ) . " does not match the declared type \"{$type}\"."
					);
				}
			}
		}

		if ( isset( $schema['enum'] ) ) {
			if ( ! in_array( $data, $schema['enum'], true ) ) {
				return new ValidationError(
					$this->convertPathToString( $path ),
					'enum-mismatch',
					sprintf(
						'The provided value (%s) is not allowed here. Please use one of the following: %s.',
						$this->valueSnippet( $data ),
						implode( ', ', $schema['enum'] )
					),
					array(
						'expected' => array( 'enum' => $schema['enum'] ),
						'actual'   => array(
							'value' => $data,
							'snippet' => $this->valueSnippet( $data ),
						),
					)
				);
			}
		}

		if ( isset( $schema['const'] ) ) {
			if ( $data !== $schema['const'] ) {
				return new ValidationError(
					$this->convertPathToString( $path ),
					'const-mismatch',
					sprintf( 'Expected value "%s" but got "%s".', $this->valueSnippet( $schema['const'] ), $this->valueSnippet( $data ) ),
					array(
						'expected' => array( 'const' => $schema['const'] ),
						'actual'   => array(
							'value' => $data,
							'snippet' => $this->valueSnippet( $data ),
						),
					)
				);
			}
		}

		switch ( $type ) {
			case 'object':
				return $this->validateObject( $path, $data, $schema );
			case 'array':
				return $this->validateArray( $path, $data, $schema );
			default:
				return null;
		}
	}

	// ───────────────────────────────────────────────────────────── object ─┐

	/**
	 * @param  mixed[]|object $data
	 */
	private function validateObject( array $path, $data, array $schema ): ?ValidationError {
		$arr            = is_object( $data ) ? (array) $data : $data;
		$childrenErrors = array();

		if ( ! empty( $schema['required'] ) ) {
			$missing = array_diff( $schema['required'], array_keys( $arr ) );
			if ( $missing ) {
				foreach ( $missing as $m ) {
					// For missing fields, the error pointer should be to the parent object,
					// as the field itself doesn't exist yet to point to.
					$childrenErrors[] = new ValidationError(
						$this->convertPathToString( $path ), // Error is about the object at $path
						'required-field-missing',
						'Missing required field: ' . $m . '.',
						array(
							'missingField' => $m,
							'requiredFields' => $schema['required'],
						)
					);
				}
			}
		}

		if ( ! empty( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $name => $propSpec ) {
				if ( array_key_exists( $name, $arr ) ) {
					$error = $this->validateNode( array_merge( $path, array( $name ) ), $arr[ $name ], $propSpec );
					if ( $error ) {
						$childrenErrors[] = $error;
					}
				}
			}
		}

		if ( array_key_exists( 'additionalProperties', $schema ) && $schema['additionalProperties'] !== true ) {
			foreach ( $arr as $name => $v ) {
				if ( isset( $schema['properties'][ $name ] ) ) {
					continue;
				} // Handled by 'properties' validation

				$currentPropPath = array_merge( $path, array( $name ) );
				if ( $schema['additionalProperties'] === false ) {
					$childrenErrors[] = new ValidationError(
						$this->convertPathToString( $currentPropPath ),
						'additional-property-not-allowed',
						sprintf(
							'Property "%s" isn\'t allowed here. Allowed properties are: %s.',
							$name,
							implode( ', ', array_keys( $schema['properties'] ) )
						),
						array( 'propertyName' => $name )
					);
				} elseif ( is_array( $schema['additionalProperties'] ) ) {
					if ( count( $schema['additionalProperties'] ) ) {
						$error = $this->validateNode( $currentPropPath, $v, $schema['additionalProperties'] );
						if ( $error ) {
							$childrenErrors[] = $error;
						}
					}
				} else {
					// This is a schema definition issue, not a data validation issue for this specific property.
					throw new UnsupportedSchemaException( 'Invalid additionalProperties schema. Expected boolean or object for schema at path: ' . $this->convertPathToString( $path ) );
				}
			}
		}

		if ( ! empty( $childrenErrors ) ) {
			if ( count( $childrenErrors ) === 1 ) {
				return $childrenErrors[0];
			}

			return new ValidationError(
				$this->convertPathToString( $path ),
				'object-validation-failed',
				'Object validation failed.',
				array(),
				$childrenErrors
			);
		}

		return null;
	}

	// ───────────────────────────────────────────────────────────── array ─┐

	private function validateArray( array $path, array $data, array $schema ): ?ValidationError {
		$childrenErrors = array();
		if ( isset( $schema['items'] ) ) {
			foreach ( $data as $idx => $item ) {
				$error = $this->validateNode( array_merge( $path, array( $idx ) ), $item, $schema['items'] );
				if ( $error ) {
					$childrenErrors[] = $error;
				}
			}
		}

		$currentPathStr = $this->convertPathToString( $path );
		if ( isset( $schema['minItems'] ) && count( $data ) < $schema['minItems'] ) {
			$childrenErrors[] = new ValidationError(
				$currentPathStr,
				'minItems-not-met',
				'Need at least ' . $schema['minItems'] . ' items, found ' . count( $data ) . '.',
				array(
					'expectedMin' => $schema['minItems'],
					'actualCount' => count( $data ),
				)
			);
		}
		if ( isset( $schema['maxItems'] ) && count( $data ) > $schema['maxItems'] ) {
			$childrenErrors[] = new ValidationError(
				$currentPathStr,
				'maxItems-exceeded',
				'May contain at most ' . $schema['maxItems'] . ' items, found ' . count( $data ) . '.',
				array(
					'expectedMax' => $schema['maxItems'],
					'actualCount' => count( $data ),
				)
			);
		}
		if ( isset( $schema['uniqueItems'] ) ) {
			// This is a schema configuration issue, not a data validation issue.
			throw new UnsupportedSchemaException( 'The array constraint "uniqueItems" is not supported.' );
		}

		if ( count( $childrenErrors ) === 1 ) {
			return $childrenErrors[0];
		}

		if ( ! empty( $childrenErrors ) ) {
			return new ValidationError(
				$currentPathStr,
				'array-validation-failed',
				'Array validation failed.',
				array(),
				$childrenErrors
			);
		}

		return null;
	}

	// ────────────────────────────────────────────────────────── references ─┐

	private function resolveReference( string $ref ): array {
		if ( strncmp( $ref, '#/', strlen( '#/' ) ) !== 0 ) {
			throw new UnsupportedSchemaException( 'Only local #/ refs are supported' );
		}
		$node      = $this->schema;
		$pathParts = explode( '/', substr( $ref, 2 ) );
		foreach ( $pathParts as $p ) {
			// Need to handle cases where $p could be an encoded character like ~0 for ~ or ~1 for /
			$p = str_replace( array( '~1', '~0' ), array( '/', '~' ), $p );
			if ( is_array( $node ) && array_key_exists( $p, $node ) ) {
				$node = $node[ $p ];
			} else {
				throw new UnsupportedSchemaException( "Reference {$ref} not found at segment '{$p}'." );
			}
		}

		return $node;
	}

	// ───────────────────────────────────────────── discriminator inference ─┐

	private function inferDiscriminator( ?array $explicit, array $branches ): ?array {
		// Filter branches to only include those that are objects and have properties defined
		$objs = array_filter(
			$branches,
			function ( $b ) {
				$schema = isset( $b['$ref'] ) ? $this->resolveReference( $b['$ref'] ) : $b;

				return ( $schema['type'] ?? null ) === 'object' && isset( $schema['properties'] );
			}
		);

		if ( count( $objs ) < 2 ) {
			return null;
		} // Discriminator is useful for 2+ object shapes
		// The original code had count($objs) !== count($branches). This might be too restrictive if some branches are non-objects.
		// For now, let's proceed if we have at least two object candidates for discrimination.

		if ( $explicit && isset( $explicit['propertyName'] ) ) {
			$prop = $explicit['propertyName'];
			$vals = array();
			foreach ( $objs as $s_wrapper ) {
				$s = isset( $s_wrapper['$ref'] ) ? $this->resolveReference( $s_wrapper['$ref'] ) : $s_wrapper;
				$d = $s['properties'][ $prop ] ?? null;
				if ( $d && isset( $d['enum'] ) && count( $d['enum'] ) === 1 ) {
					$vals[] = $d['enum'][0];
				} elseif ( $d && isset( $d['const'] ) ) {
					$vals[] = $d['const'];
				} else {
					// If an explicit discriminator property is not a single-value enum in a branch, it can't be used.
					return null;
				}
			}
			// Ensure all discriminator values are unique among the object branches considered
			if ( count( $vals ) === count( $objs ) && count( array_unique( $vals ) ) === count( $vals ) ) {
				return array( $prop, $vals );
			}

			return null; // Explicit discriminator not viable (e.g. not present in all, or not single enum)
		}

		// Auto‑guess single‑value enums and consts
		$candidates = array();
		if ( isset( $objs[0] ) ) {
			$firstObjSchema = isset( $objs[0]['$ref'] ) ? $this->resolveReference( $objs[0]['$ref'] ) : $objs[0];
			if ( ! isset( $firstObjSchema['properties'] ) ) {
				return null;
			} // Should not happen due to filter above

			foreach ( array_keys( $firstObjSchema['properties'] ) as $prop ) {
				$possibleValues          = array();
				$allObjsHaveThisEnumProp = true;
				foreach ( $objs as $s_wrapper ) {
					$s = isset( $s_wrapper['$ref'] ) ? $this->resolveReference( $s_wrapper['$ref'] ) : $s_wrapper;
					if ( isset( $s['properties'][ $prop ]['const'] ) ) {
						$possibleValues[] = $s['properties'][ $prop ]['const'];
						continue;
					}
					if ( isset( $s['properties'][ $prop ]['enum'] ) && count( $s['properties'][ $prop ]['enum'] ) === 1 ) {
						$possibleValues[] = $s['properties'][ $prop ]['enum'][0];
						continue;
					}
					$allObjsHaveThisEnumProp = false;
					break;
				}
				if ( $allObjsHaveThisEnumProp && count( array_unique( $possibleValues ) ) === count( $objs ) ) {
					$candidates[ $prop ] = $possibleValues;
				}
			}
		}
		// print_R($objs);

		if ( count( $candidates ) === 1 ) { // Only one property serves as a unique discriminator
			return array( key( $candidates ), current( $candidates ) );
		}

		return null; // No single property found to act as an implicit discriminator, or multiple found (ambiguous)
	}
}
