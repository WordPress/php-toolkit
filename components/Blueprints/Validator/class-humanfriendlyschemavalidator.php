<?php

namespace WordPress\Blueprints\Validator;

require_once __DIR__ . '/class-symbol.php';

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
	private $array_is_valid_object;

	private $missing;

	public function __construct(
		array $schema,
		array $options = array()
	) {
		$this->schema                = $schema;
		$this->array_is_valid_object = $options['array_is_valid_object'] ?? true;
		$this->missing               = new Symbol( 'missing' );
	}

	/**
	 * @param  mixed $data
	 */
	public function validate( $data ): ?ValidationError {
		return $this->validate_node( array( 'root' ), $data, $this->schema );
	}

	private function convert_path_to_string( array $path ): string {
		if ( empty( $path ) || 'root' !== $path[0] ) {
			array_unshift( $path, '#' ); // JSON pointers start with # or are relative.
		} else {
			$path[0] = '#'; // Replace 'root' with '#'.
		}
		$imploded = implode( '/', $path );
		if ( '#' === $imploded ) {
			return '#/';
		}

		return $imploded;
	}

	// ─────────────────────────────────────────────────────── helpers ─┐.

	/**
	 * @param  mixed $v
	 */
	private function value_snippet( $v ): string {
		return substr( json_encode( $v, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES ), 0, 80 );
	}

	private function branch_label( array $s ): string {
		if ( isset( $s['$ref'] ) ) {
			return substr( $s['$ref'], strrpos( $s['$ref'], '/' ) + 1 );
		}

		return $s['title'] ?? ( $s['type'] ?? '<schema>' );
	}

	/**
	 * @param  mixed $data
	 */
	private function type_matches( $data, ?string $type ): bool {
		$array_is_list_function = function ( array $array ): bool {
			if ( function_exists( 'array_is_list' ) ) {
				return array_is_list( $array );
			}
			if ( array() === $array ) {
				return true;
			}
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
				return is_object( $data ) || ( $this->array_is_valid_object && is_array( $data ) && ( ! $array_is_list_function( $data ) || 0 === count( $data ) ) );
			case 'array':
				$array_is_list_function = function ( array $array ): bool {
					if ( function_exists( 'array_is_list' ) ) {
						return array_is_list( $array );
					}
					if ( array() === $array ) {
						return true;
					}
					$current_key = 0;
					foreach ( $array as $key => $noop ) {
						if ( $key !== $current_key ) {
							return false;
						}
						++$current_key;
					}

					return true;
				};

				return is_array( $data ) && $array_is_list_function( $data );
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
	private function type_matches_any( $data, $type_or_types ): bool {
		if ( ! is_array( $type_or_types ) ) {
			return $this->type_matches( $data, $type_or_types );
		}
		foreach ( $type_or_types as $t ) {
			if ( is_array( $t ) ) {
				$t = $t['type'] ?? null;
			}
			if ( $this->type_matches( $data, $t ) ) {
				return true;
			}
		}

		return false;
	}

	// ───────────────────────────────────────────────────────── validation ─┐.

	/**
	 * @param  array $path
	 * @param  mixed $data
	 * @param  array $schema
	 */
	private function validate_node( array $path, $data, array $schema ): ?ValidationError {
		if ( isset( $schema['$ref'] ) ) {
			$schema = $this->resolve_reference( $schema['$ref'] );
		}

		// Check for unsupported keywords.
		$unsupported_keywords = array(
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
		foreach ( $unsupported_keywords as $keyword ) {
			if ( isset( $schema[ $keyword ] ) ) {
				// This should remain an exception as it's a schema configuration issue, not a data validation issue.
				throw new UnsupportedSchemaException( "The schema keyword \"{$keyword}\" is not supported." );
			}
		}

		switch ( true ) {
			case isset( $schema['allOf'] ):
				return $this->validate_all_of( $path, $data, $schema );
			case isset( $schema['anyOf'] ):
				return $this->validate_any_of( $path, $data, $schema );
			case isset( $schema['oneOf'] ):
				return $this->validate_one_of( $path, $data, $schema );
			case isset( $schema['type'] ):
				return $this->validate_type( $path, $data, $schema );
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

	// ───────────────────────────────────────────── anyOf / oneOf ─┐.

	/**
	 * @param  mixed $data
	 */
	private function narrow_branches( $data, array $branches, array $schema ): array {
		// 1. filter by declared top‑level type.
		$candidates = array_filter(
			$branches,
			function ( $spec ) use ( $data ) {
				while ( isset( $spec['$ref'] ) ) {
					$spec = $this->resolve_reference( $spec['$ref'] );
				}

				return $this->type_matches_any( $data, $spec['type'] ?? null );
			}
		);

		// 2. filter by discriminator (explicit or inferred).
		$disc = $this->infer_discriminator( $schema['discriminator'] ?? null, $branches );
		if ( $disc && ( is_array( $data ) || is_object( $data ) ) ) { // Discriminator implies object/array data.
			$data_arr           = (array) $data; // Cast to array for consistent access.
			[ $prop, $allowed ] = $disc;
			if ( array_key_exists( $prop, $data_arr ) ) {
				$wanted     = $data_arr[ $prop ];
				$candidates = array_values(
					array_filter(
						$candidates,
						function ( $b ) use ( $prop, $wanted ) {
							$r = isset( $b['$ref'] ) ? $this->resolve_reference( $b['$ref'] ) : $b;
							// Ensure properties exist before accessing.
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

		return $candidates ? $candidates : $branches; // never empty.
	}

	private function validate_all_of( array $path, $data, array $schema ): ?ValidationError {
		$branches = $schema['allOf'];
		foreach ( $branches as $b ) {
			$error = $this->validate_node( $path, $data, isset( $b['$ref'] ) ? $this->resolve_reference( $b['$ref'] ) : $b );
			if ( null !== $error ) {
				return $error;
			}
		}
		return null;
	}

	/**
	 * @param  array $path
	 * @param  mixed $data
	 * @param  array $schema
	 */
	private function validate_any_of( array $path, $data, array $schema ): ?ValidationError {
		$branches = $schema['anyOf'];
		$cands    = $this->narrow_branches( $data, $branches, $schema );
		// $narrowed = count($cands) < count($branches); // This logic changes.
		$child_errors = array();

		foreach ( $cands as $b ) {
			// $label = $this->branchLabel($b); // branchLabel might be used in error message context.
			$error = $this->validate_node( $path, $data, isset( $b['$ref'] ) ? $this->resolve_reference( $b['$ref'] ) : $b );
			if ( null === $error ) {
				return null;
			} // Success, one branch matched.
			// $this->tagBranch($label, $r); // tagBranch is removed.
			$child_errors[] = $error;
		}

		// If we are here, no candidate branch validated successfully.
		// The old logic for $narrowed seems less relevant. We always create a parent error with children.
		// The explanation for aggregate mismatch needs to be adapted.
		return $this->explain_aggregate_mismatch( $path, $data, $branches, $schema, 'anyOf', $child_errors );
	}

	/**
	 * @param  array $path
	 * @param  mixed $data
	 * @param  array $schema
	 */
	private function validate_one_of( array $path, $data, array $schema ): ?ValidationError {
		$branches = $schema['oneOf'];
		$cands    = $this->narrow_branches( $data, $branches, $schema );
		// $narrowed = count($cands) < count($branches);.

		$valid_results = array();
		$child_errors  = array();
		foreach ( $cands as $b ) {
			// $label=$this->branchLabel($b);.
			$error = $this->validate_node( $path, $data, isset( $b['$ref'] ) ? $this->resolve_reference( $b['$ref'] ) : $b );
			if ( null === $error ) {
				$valid_results[] = $b; // Store the schema of the valid branch.
			} else {
				// $this->tagBranch($label,$r); // tagBranch removed.
				$child_errors[] = $error;
			}
		}

		if ( 1 === count( $valid_results ) ) {
			return null;
		} // Exactly one schema matched.

		if ( count( $valid_results ) > 1 ) {
			$matched_shapes = array_map(
				function ( $b ) {
					if ( isset( $b['$ref'] ) ) {
							$resolved = $this->resolve_reference( $b['$ref'] );

							return $resolved['title'] ?? $b['$ref'];
					}

					return $this->branch_label( $b );
				},
				$valid_results
			);

			return new ValidationError(
				$this->convert_path_to_string( $path ),
				'oneOf-multiple-matches',
				'Data matches more than one allowed shape - you need to make it unambiguous. Matched shapes: ' . implode(
					', ',
					$matched_shapes
				) . '.',
				array( 'matchedShapes' => $matched_shapes )
			);
		}

		// No schema matched, or narrowing didn't help / wasn't conclusive.
		// The old logic for $narrowed seems less relevant. We always create a parent error with children.
		return $this->explain_aggregate_mismatch( $path, $data, $branches, $schema, 'oneOf', $child_errors );
	}

	/**
	 * Create a parent error for anyOf/oneOf mismatches.
	 *
	 * @param  array  $path
	 * @param  mixed  $data
	 * @param  array  $branches
	 * @param  array  $parent_schema
	 * @param  string $keyword
	 * @param  array  $child_errors
	 */
	private function explain_aggregate_mismatch(
		array $path,
		$data,
		array $branches, // Original branches before narrowing.
		array $parent_schema, // The schema containing anyOf/oneOf.
		string $keyword, // 'anyOf' or 'oneOf'.
		array $child_errors // Errors from validating against candidate branches.
	): ValidationError {
		$pointer = $this->convert_path_to_string( $path );

		// 1. Type mismatch (if data type doesn't match any of the branch types).
		$allowed_types = array();
		foreach ( $branches as $b ) {
			$s    = isset( $b['$ref'] ) ? $this->resolve_reference( $b['$ref'] ) : $b;
			$type = $s['type'] ?? null;
			if ( null !== $type ) {
				if ( is_array( $type ) ) {
					foreach ( $type as $t ) {
						if ( ! in_array( $t, $allowed_types, true ) ) {
							$allowed_types[] = $t;
						}
					}
				} elseif ( ! in_array( $type, $allowed_types, true ) ) {
						$allowed_types[] = $type;
				}
			}
		}

		if ( ! empty( $allowed_types ) && ! $this->type_matches_any( $data, $allowed_types ) ) {
			if ( 1 === count( $allowed_types ) ) {
				$message = sprintf(
					'Expected type "%s" but got type "%s".',
					$allowed_types[0],
					gettype( $data )
				);
			} else {
				$message = sprintf(
					'Value must be one of the following types: [%s], but it was of type "%s".',
					implode( ', ', $allowed_types ),
					gettype( $data )
				);
			}

			return new ValidationError(
				$pointer,
				'type-mismatch',
				$message,
				array(
					'expected' => array( 'types' => $allowed_types ),
					'actual'   => array(
						'type' => gettype( $data ),
						'snippet' => $this->value_snippet( $data ),
					),
				)
			);
		}

		// 2. Discriminator check (if applicable and discriminator value is invalid).
		$disc = $this->infer_discriminator( $parent_schema['discriminator'] ?? null, $branches );
		if ( $disc ) {
			[ $prop, $allowed_discriminator_values ] = $disc;
			$actual_value                            = $this->missing; // Default to missing.
			if ( is_array( $data ) && array_key_exists( $prop, $data ) ) {
				$actual_value = $data[ $prop ];
			} elseif ( is_object( $data ) && property_exists( $data, $prop ) ) {
				$actual_value = $data->$prop;
			}

			if ( ! in_array( $actual_value, $allowed_discriminator_values, true ) ) {
				$actual_humanized = ( $actual_value === $this->missing ) ? 'missing' : $this->value_snippet( $actual_value );

				return new ValidationError(
					$pointer,
					'discriminator-mismatch',
					sprintf(
						'Property "%s" must be one of [%s], but it was %s.',
						$prop,
						implode( ', ', $allowed_discriminator_values ),
						$actual_humanized
					),
					array(
						'expected' => array(
							'property' => $prop,
							'allowedValues' => $allowed_discriminator_values,
						),
						'actual'   => array(
							'value'   => ( $actual_value === $this->missing ) ? null : $actual_value,
							'snippet' => $this->value_snippet( $actual_value ),
						),
					)
				);
			}
		}

		// 3. If there's only one child error, return it directly.
		// No need to wrap it in a parent error.
		if ( 1 === count( $child_errors ) ) {
			return $child_errors[0];
		}

		// 4. Fallback: Generic message with children errors.
		$labels  = array_unique( array_map( array( $this, 'branch_label' ), $branches ) );
		$message = 'Value did not match any of the allowed shapes: ' . implode( ', ', $labels ) . '.';
		if ( 'oneOf' === $keyword ) {
			$message = 'Value did not match exactly one of the allowed shapes: ' . implode( ', ', $labels ) . '.';
		}

		return new ValidationError(
			$pointer,
			$keyword . '-mismatch', // e.g., 'anyOf-mismatch'.
			$message,
			array( 'allowedShapes' => $labels ),
			$child_errors // Attach all child errors here.
		);
	}

	// ─────────────────────────────────────────── primitives / objects / arrays ─┐.

	/**
	 * @param  array $path
	 * @param  mixed $data
	 * @param  array $schema
	 */
	private function validate_type( array $path, $data, array $schema ): ?ValidationError {
		$type = $schema['type'];
		if ( ! $this->type_matches_any( $data, $type ) ) {
			$error = is_array( $type ) ? 'Expected one of the following types: ' . implode( ', ', $type ) . ' but got type "' . gettype( $data ) . '".' : 'Expected type "' . $type . '" but got type "' . gettype( $data ) . '".';
			return new ValidationError(
				$this->convert_path_to_string( $path ),
				'type-mismatch',
				$error,
				array(
					'expected' => array( 'type' => $type ),
					'actual'   => array(
						'type' => gettype( $data ),
						'snippet' => $this->value_snippet( $data ),
					),
				)
			);
		}

		// Schema integrity checks (throw exceptions as these are schema definition issues).
		if ( 'string' === $type ) {
			$unsupported_string_keywords = array( 'pattern', 'minLength', 'maxLength', 'format' );
			foreach ( $unsupported_string_keywords as $keyword ) {
				if ( isset( $schema[ $keyword ] ) ) {
					throw new UnsupportedSchemaException( "The string constraint \"{$keyword}\" is not supported." );
				}
			}
		}
		if ( 'number' === $type || 'integer' === $type ) {
			$unsupported_numeric_keywords = array( 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf' );
			foreach ( $unsupported_numeric_keywords as $keyword ) {
				if ( isset( $schema[ $keyword ] ) ) {
					throw new UnsupportedSchemaException( "The numeric constraint \"{$keyword}\" is not supported." );
				}
			}
		}
		if ( isset( $schema['enum'] ) ) {
			foreach ( $schema['enum'] as $enum_value ) {
				if ( ! $this->type_matches( $enum_value, $type ) ) {
					throw new UnsupportedSchemaException(
						'Enum value ' . json_encode( $enum_value ) . " does not match the declared type \"{$type}\"."
					);
				}
			}
		}

		if ( isset( $schema['enum'] ) ) {
			if ( ! in_array( $data, $schema['enum'], true ) ) {
				return new ValidationError(
					$this->convert_path_to_string( $path ),
					'enum-mismatch',
					sprintf(
						'The provided value (%s) is not allowed here. Please use one of the following: %s.',
						$this->value_snippet( $data ),
						implode( ', ', $schema['enum'] )
					),
					array(
						'expected' => array( 'enum' => $schema['enum'] ),
						'actual'   => array(
							'value' => $data,
							'snippet' => $this->value_snippet( $data ),
						),
					)
				);
			}
		}

		if ( isset( $schema['const'] ) ) {
			if ( $data !== $schema['const'] ) {
				return new ValidationError(
					$this->convert_path_to_string( $path ),
					'const-mismatch',
					sprintf( 'Expected value "%s" but got "%s".', $this->value_snippet( $schema['const'] ), $this->value_snippet( $data ) ),
					array(
						'expected' => array( 'const' => $schema['const'] ),
						'actual'   => array(
							'value' => $data,
							'snippet' => $this->value_snippet( $data ),
						),
					)
				);
			}
		}

		switch ( $type ) {
			case 'object':
				return $this->validate_object( $path, $data, $schema );
			case 'array':
				return $this->validate_array( $path, $data, $schema );
			default:
				return null;
		}
	}

	// ───────────────────────────────────────────────────────────── object ─┐.

	/**
	 * @param  mixed[]|object $path
	 */
	private function validate_object( array $path, $data, array $schema ): ?ValidationError {
		$arr             = is_object( $data ) ? (array) $data : $data;
		$children_errors = array();

		if ( ! empty( $schema['required'] ) ) {
			$missing = array_diff( $schema['required'], array_keys( $arr ) );
			if ( $missing ) {
				foreach ( $missing as $m ) {
					// For missing fields, the error pointer should be to the parent object,.
					// as the field itself doesn't exist yet to point to.
					$children_errors[] = new ValidationError(
						$this->convert_path_to_string( $path ), // Error is about the object at $path.
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
			foreach ( $schema['properties'] as $name => $prop_spec ) {
				if ( array_key_exists( $name, $arr ) ) {
					$error = $this->validate_node( array_merge( $path, array( $name ) ), $arr[ $name ], $prop_spec );
					if ( $error ) {
						$children_errors[] = $error;
					}
				}
			}
		}

		if ( array_key_exists( 'additionalProperties', $schema ) && true !== $schema['additionalProperties'] ) {
			foreach ( $arr as $name => $v ) {
				if ( isset( $schema['properties'][ $name ] ) ) {
					continue;
				} // Handled by 'properties' validation.

				$current_prop_path = array_merge( $path, array( $name ) );
				if ( false === $schema['additionalProperties'] ) {
					$children_errors[] = new ValidationError(
						$this->convert_path_to_string( $current_prop_path ),
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
						$error = $this->validate_node( $current_prop_path, $v, $schema['additionalProperties'] );
						if ( $error ) {
							$children_errors[] = $error;
						}
					}
				} else {
					// This is a schema definition issue, not a data validation issue for this specific property.
					throw new UnsupportedSchemaException( 'Invalid additionalProperties schema. Expected boolean or object for schema at path: ' . $this->convert_path_to_string( $path ) );
				}
			}
		}

		if ( ! empty( $children_errors ) ) {
			if ( 1 === count( $children_errors ) ) {
				return $children_errors[0];
			}

			return new ValidationError(
				$this->convert_path_to_string( $path ),
				'object-validation-failed',
				'Object validation failed.',
				array(),
				$children_errors
			);
		}

		return null;
	}

	// ───────────────────────────────────────────────────────────── array ─┐.

	private function validate_array( array $path, array $data, array $schema ): ?ValidationError {
		$children_errors = array();
		if ( isset( $schema['items'] ) ) {
			foreach ( $data as $idx => $item ) {
				$error = $this->validate_node( array_merge( $path, array( $idx ) ), $item, $schema['items'] );
				if ( $error ) {
					$children_errors[] = $error;
				}
			}
		}

		$current_path_str = $this->convert_path_to_string( $path );
		if ( isset( $schema['minItems'] ) && count( $data ) < $schema['minItems'] ) {
			$children_errors[] = new ValidationError(
				$current_path_str,
				'minItems-not-met',
				'Need at least ' . $schema['minItems'] . ' items, found ' . count( $data ) . '.',
				array(
					'expectedMin' => $schema['minItems'],
					'actualCount' => count( $data ),
				)
			);
		}
		if ( isset( $schema['maxItems'] ) && count( $data ) > $schema['maxItems'] ) {
			$children_errors[] = new ValidationError(
				$current_path_str,
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

		if ( 1 === count( $children_errors ) ) {
			return $children_errors[0];
		}

		if ( ! empty( $children_errors ) ) {
			return new ValidationError(
				$current_path_str,
				'array-validation-failed',
				'Array validation failed.',
				array(),
				$children_errors
			);
		}

		return null;
	}

	// ────────────────────────────────────────────────────────── references ─┐.

	private function resolve_reference( string $ref ): array {
		if ( 0 !== strncmp( $ref, '#/', strlen( '#/' ) ) ) {
			throw new UnsupportedSchemaException( 'Only local #/ refs are supported' );
		}
		$node       = $this->schema;
		$path_parts = explode( '/', substr( $ref, 2 ) );
		foreach ( $path_parts as $p ) {
			// Need to handle cases where $p could be an encoded character like ~0 for ~ or ~1 for /.
			$p = str_replace( array( '~1', '~0' ), array( '/', '~' ), $p );
			if ( is_array( $node ) && array_key_exists( $p, $node ) ) {
				$node = $node[ $p ];
			} else {
				throw new UnsupportedSchemaException( "Reference {$ref} not found at segment '{$p}'." );
			}
		}

		return $node;
	}

	// ───────────────────────────────────────────── discriminator inference ─┐.

	private function infer_discriminator( ?array $explicit, array $branches ): ?array {
		// Filter branches to only include those that are objects and have properties defined.
		$objs = array_filter(
			$branches,
			function ( $b ) {
				$schema = isset( $b['$ref'] ) ? $this->resolve_reference( $b['$ref'] ) : $b;

				return 'object' === ( $schema['type'] ?? null ) && isset( $schema['properties'] );
			}
		);

		if ( count( $objs ) < 2 ) {
			return null;
		} // Discriminator is useful for 2+ object shapes.
		// The original code had count($objs) !== count($branches). This might be too restrictive if some branches are non-objects.
		// For now, let's proceed if we have at least two object candidates for discrimination.

		if ( $explicit && isset( $explicit['propertyName'] ) ) {
			$prop = $explicit['propertyName'];
			$vals = array();
			foreach ( $objs as $s_wrapper ) {
				$s = isset( $s_wrapper['$ref'] ) ? $this->resolve_reference( $s_wrapper['$ref'] ) : $s_wrapper;
				$d = $s['properties'][ $prop ] ?? null;
				if ( $d && isset( $d['enum'] ) && 1 === count( $d['enum'] ) ) {
					$vals[] = $d['enum'][0];
				} elseif ( $d && isset( $d['const'] ) ) {
					$vals[] = $d['const'];
				} else {
					// If an explicit discriminator property is not a single-value enum in a branch, it can't be used.
					return null;
				}
			}
			// Ensure all discriminator values are unique among the object branches considered.
			if ( count( $vals ) === count( $objs ) && count( array_unique( $vals ) ) === count( $vals ) ) {
				return array( $prop, $vals );
			}

			return null; // Explicit discriminator not viable (e.g. not present in all, or not single enum).
		}

		// Auto‑guess single‑value enums and consts.
		$candidates = array();
		if ( isset( $objs[0] ) ) {
			$first_obj_schema = isset( $objs[0]['$ref'] ) ? $this->resolve_reference( $objs[0]['$ref'] ) : $objs[0];
			if ( ! isset( $first_obj_schema['properties'] ) ) {
				return null;
			} // Should not happen due to filter above.

			foreach ( array_keys( $first_obj_schema['properties'] ) as $prop ) {
				$possible_values              = array();
				$all_objs_have_this_enum_prop = true;
				foreach ( $objs as $s_wrapper ) {
					$s = isset( $s_wrapper['$ref'] ) ? $this->resolve_reference( $s_wrapper['$ref'] ) : $s_wrapper;
					if ( isset( $s['properties'][ $prop ]['const'] ) ) {
						$possible_values[] = $s['properties'][ $prop ]['const'];
						continue;
					}
					if ( isset( $s['properties'][ $prop ]['enum'] ) && 1 === count( $s['properties'][ $prop ]['enum'] ) ) {
						$possible_values[] = $s['properties'][ $prop ]['enum'][0];
						continue;
					}
					$all_objs_have_this_enum_prop = false;
					break;
				}
				if ( $all_objs_have_this_enum_prop && count( array_unique( $possible_values ) ) === count( $objs ) ) {
					$candidates[ $prop ] = $possible_values;
				}
			}
		}
		// print_R($objs);.

		if ( 1 === count( $candidates ) ) { // Only one property serves as a unique discriminator.
			return array( key( $candidates ), current( $candidates ) );
		}

		return null; // No single property found to act as an implicit discriminator, or multiple found (ambiguous).
	}
}
