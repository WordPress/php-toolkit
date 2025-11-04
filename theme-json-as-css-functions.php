<?php
use WordPress\DataLiberation\CSS\CSSParser;
use WordPress\DataLiberation\CSS\CSS_At_Rule;
use WordPress\DataLiberation\CSS\CSS_Qualified_Rule;
use WordPress\DataLiberation\CSS\CSS_Declaration;
use WordPress\DataLiberation\CSS\CSS_Simple_Block;
use WordPress\DataLiberation\CSS\CSS_Function;
use WordPress\DataLiberation\CSS\CSS_Component_Value;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Get the string value from a declaration's value tokens.
 */
function get_declaration_value_string( $value_tokens ) {
	$parts = array();
	foreach ( $value_tokens as $token ) {
		if ( $token instanceof CSS_Component_Value ) {
			if ( is_array( $token->value ) ) {
				// Simple token - skip whitespace
				if ( $token->value['type'] !== 'whitespace-token' ) {
					$parts[] = $token->value['raw'];
				}
			} elseif ( $token->value instanceof CSS_Function ) {
				// Function
				$parts[] = get_function_string( $token->value );
			}
		}
	}
	return trim( implode( '', $parts ) );
}

/**
 * Get the string representation of a function.
 */
function get_function_string( $function ) {
	$args = array();
	foreach ( $function->values as $value ) {
		if ( $value instanceof CSS_Component_Value && is_array( $value->value ) ) {
			if ( $value->value['type'] !== 'whitespace-token' ) {
				$args[] = $value->value['raw'];
			}
		}
	}
	return $function->name . '(' . implode( '', $args ) . ')';
}

/**
 * Get a simple string from prelude tokens (for selectors, etc).
 */
function get_prelude_string( $prelude ) {
	$parts = array();
	foreach ( $prelude as $token ) {
		if ( $token instanceof CSS_Component_Value && is_array( $token->value ) ) {
			if ( $token->value['type'] !== 'whitespace-token' ) {
				$parts[] = $token->value['raw'];
			}
		}
	}
	return trim( implode( '', $parts ) );
}

/**
 * Parse declarations in a block into a key-value array.
 */
function parse_declarations( $block ) {
	$result = array();
	if ( empty( $block->declarations ) ) {
		return $result;
	}

	foreach ( $block->declarations as $decl ) {
		$value = get_declaration_value_string( $decl->value );
		// Remove quotes from string values
		if ( ( $value[0] === '"' && substr( $value, -1 ) === '"' ) ||
		     ( $value[0] === "'" && substr( $value, -1 ) === "'" ) ) {
			$value = substr( $value, 1, -1 );
		}
		$result[ $decl->name ] = $value;
	}
	return $result;
}

/**
 * Convert CSS declarations to theme.json style format.
 */
function declarations_to_theme_json_styles( $declarations ) {
	$styles = array();

	foreach ( $declarations as $property => $value ) {
		// Map CSS properties to theme.json structure
		switch ( $property ) {
			case 'color':
				if ( ! isset( $styles['color'] ) ) {
					$styles['color'] = array();
				}
				$styles['color']['text'] = $value;
				break;
			case 'background-color':
				if ( ! isset( $styles['color'] ) ) {
					$styles['color'] = array();
				}
				$styles['color']['background'] = $value;
				break;
			case 'font-family':
				if ( ! isset( $styles['typography'] ) ) {
					$styles['typography'] = array();
				}
				$styles['typography']['fontFamily'] = $value;
				break;
			case 'font-size':
				if ( ! isset( $styles['typography'] ) ) {
					$styles['typography'] = array();
				}
				$styles['typography']['fontSize'] = $value;
				break;
			case 'line-height':
				if ( ! isset( $styles['typography'] ) ) {
					$styles['typography'] = array();
				}
				$styles['typography']['lineHeight'] = $value;
				break;
			default:
				// Keep other properties as-is
				$styles[ $property ] = $value;
				break;
		}
	}

	return $styles;
}

/**
 * Convert parsed CSS stylesheet to theme.json format.
 */
function css_to_theme_json( $stylesheet ) {
	$theme_json = array(
		'$schema' => 'https://schemas.wp.org/trunk/theme.json',
		'version' => 3,
	);

	foreach ( $stylesheet->rules as $rule ) {
		// Handle at-rules
		if ( ! $rule instanceof CSS_At_Rule ) {
			continue;
		}

		$at_rule_name = $rule->name;
		$block = $rule->block;

		if ( null === $block ) {
			continue;
		}

		switch ( $at_rule_name ) {
			case 'version':
				// @version { version: 3; }
				$version_data = parse_declarations( $block );
				if ( isset( $version_data['version'] ) ) {
					$theme_json['version'] = (int) $version_data['version'];
				}
				break;

			case 'color':
				// @color { slug: base; color: #111; name: Base; }
				$color_data = parse_declarations( $block );
				if ( ! isset( $theme_json['settings']['color']['palette'] ) ) {
					$theme_json['settings']['color']['palette'] = array();
				}
				$theme_json['settings']['color']['palette'][] = $color_data;
				break;

			case 'font-family':
				// @font-family { fontFamily: ...; slug: system-fonts; name: System Fonts; }
				$font_family_data = parse_declarations( $block );
				if ( ! isset( $theme_json['settings']['typography']['fontFamilies'] ) ) {
					$theme_json['settings']['typography']['fontFamilies'] = array();
				}
				$theme_json['settings']['typography']['fontFamilies'][] = $font_family_data;
				break;

			case 'font-size':
				// @font-size { slug: extra-large; size: 42px; name: Extra Large; }
				$font_size_data = parse_declarations( $block );
				if ( ! isset( $theme_json['settings']['typography']['fontSizes'] ) ) {
					$theme_json['settings']['typography']['fontSizes'] = array();
				}
				$theme_json['settings']['typography']['fontSizes'][] = $font_size_data;
				break;

			case 'font-face':
				// Standard @font-face - could be added to settings if needed
				break;

			case 'elements':
				// @elements { link { color: red; } }
				if ( ! empty( $block->rules ) ) {
					foreach ( $block->rules as $element_rule ) {
						if ( $element_rule instanceof CSS_Qualified_Rule ) {
							$element_name = get_prelude_string( $element_rule->prelude );
							$declarations = parse_declarations( $element_rule->block );
							$styles = declarations_to_theme_json_styles( $declarations );
							if ( ! isset( $theme_json['styles']['elements'] ) ) {
								$theme_json['styles']['elements'] = array();
							}
							$theme_json['styles']['elements'][ $element_name ] = $styles;
						}
					}
				}
				break;

			case 'blocks':
				// @blocks { core/paragraph { font-size: 16px; } }
				if ( ! empty( $block->rules ) ) {
					foreach ( $block->rules as $block_rule ) {
						if ( $block_rule instanceof CSS_Qualified_Rule ) {
							$block_name = get_prelude_string( $block_rule->prelude );
							$declarations = parse_declarations( $block_rule->block );
							$styles = declarations_to_theme_json_styles( $declarations );
							if ( ! isset( $theme_json['styles']['blocks'] ) ) {
								$theme_json['styles']['blocks'] = array();
							}
							$theme_json['styles']['blocks'][ $block_name ] = $styles;
						}
					}
				}
				break;
		}
	}

	return $theme_json;
}
