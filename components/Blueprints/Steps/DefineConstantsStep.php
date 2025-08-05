<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'defineConstants' step.
 */
class DefineConstantsStep implements StepInterface {
	/**
	 * An associative array of constant names to their values (string, bool, int, float).
	 * @var array<string, scalar>
	 */
	public $constants;

	/**
	 * @param  array<string, scalar>  $constants  Constants to define.
	 */
	public function __construct( array $constants ) {
		$this->constants = $constants;
	}

	/**
	 * Executes the defineConstants step.
	 */
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Defining wp-config constants' );
		// Inline PHP script to avoid reading a static script.php file via
		// file_get_contents() inside the built blueprints.phar file.
		$runtime->evalPhpCodeInSubProcess(
<<<'PHP'
<?php

/**
 * Rewrites the wp-config.php file to ensure specific constants are defined
 * with specific values.
 *
 * Example:
 *
 * ```php
 * <?php
 * define('WP_DEBUG', true);
 * // The third define() argument is also supported:
 * define('SAVEQUERIES', false, true);
 *
 * // Expression
 * define(true ? 'WP_DEBUG_LOG' : 'WP_DEBUG_LOG', 123);
 *
 * // Guarded expressions shouldn't be wrapped twice
 * if(!defined(1 ? 'A' : 'B')) {
 *     define(1 ? 'A' : 'B', 0);
 * }
 *
 * // More advanced expression
 * define((function() use($x) {
 *     return [$x, 'a'];
 * })(), 123);
 * ```
 *
 * Rewritten with
 *
 *     $constants = [
 *        'WP_DEBUG' => false,
 *        'WP_DEBUG_LOG' => true,
 *        'SAVEQUERIES' => true,
 *        'NEW_CONSTANT' => "new constant",
 *     ];
 *
 * ```php
 * <?php
 * define('WP_DEBUG_LOG',true);
 * define('NEW_CONSTANT','new constant');
 * ?><?php
 * define('WP_DEBUG',false);
 * // The third define() argument is also supported:
 * define('SAVEQUERIES',true, true);
 *
 * // Expression
 * if(!defined($const ? 'WP_DEBUG_LOG' : 'WP_DEBUG_LOG')) {
 *      define($const ? 'WP_DEBUG_LOG' : 'WP_DEBUG_LOG', 123);
 * }
 *
 * // Guarded expressions shouldn't be wrapped twice
 * if(!defined(1 ? 'A' : 'B')) {
 *     define(1 ? 'A' : 'B', 0);
 * }
 *
 * // More advanced expression
 * if(!defined((function() use($x) {
 *    return [$x, 'a'];
 * })())) {
 *     define((function() use($x) {
 *         return [$x, 'a'];
 *     })(), 123);
 * }
 * ```
 *
 * @param  mixed  $content
 *
 * @return string
 */
function rewrite_wp_config_to_define_constants( $content, $constants = array() ) {
	$tokens              = array_reverse( token_get_all( $content ) );
	$output              = array();
	$defined_expressions = array();

	// Look through all the tokens and find the define calls
	do {
		$buffer           = array();
		$name_buffer      = array();
		$value_buffer     = array();
		$third_arg_buffer = array();

		// Capture everything until the define call into output.
		// Capturing the define call into a buffer.
		// Example:
		// <?php echo 'a'; define  (
		// ^^^^^^^^^^^^^^^^^^^^^^
		// output   |buffer
		while ( $token = array_pop( $tokens ) ) {
			if ( is_array( $token ) && $token[0] === T_STRING && ( strtolower( $token[1] ) === 'define' || strtolower( $token[1] ) === 'defined' ) ) {
				$buffer[] = $token;
				break;
			}
			$output[] = $token;
		}

		// Maybe we didn't find a define call and reached the end of the file?
		if ( ! count( $tokens ) ) {
			break;
		}

		// Keep track of the "defined" expressions that are already accounted for
		if ( $token[1] === 'defined' ) {
			$output[]           = $token;
			$defined_expression = array();
			$open_parenthesis   = 0;
			// Capture everything up to the opening parenthesis, including the parenthesis
			// e.g. defined  (
			// ^^^^
			while ( $token = array_pop( $tokens ) ) {
				$output[] = $token;
				if ( $token === '(' ) {
					++ $open_parenthesis;
					break;
				}
			}

			// Capture everything up to the closing parenthesis, including the parenthesis
			// e.g. defined  (
			// ^^^^
			while ( $token = array_pop( $tokens ) ) {
				$output[] = $token;
				if ( $token === ')' ) {
					-- $open_parenthesis;
				}
				if ( $open_parenthesis === 0 ) {
					break;
				}
				$defined_expression[] = $token;
			}

			$defined_expressions[] = stringify_tokens( skip_whitespace( $defined_expression ) );
			continue;
		}

		// Capture everything up to the opening parenthesis, including the parenthesis
		// e.g. define  (
		// ^^^^
		while ( $token = array_pop( $tokens ) ) {
			$buffer[] = $token;
			if ( $token === '(' ) {
				break;
			}
		}

		// Capture the first argument – it's the first expression after the opening
		// parenthesis and before the comma:
		// Examples:
		// define("WP_DEBUG", true);
		// ^^^^^^^^^^^
		//
		// define(count([1,2]) > 2 ? 'WP_DEBUG' : 'FOO', true);
		// ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
		$open_parenthesis = 0;
		while ( $token = array_pop( $tokens ) ) {
			$buffer[] = $token;
			if ( $token === '(' || $token === '[' || $token === '{' ) {
				++ $open_parenthesis;
			} elseif ( $token === ')' || $token === ']' || $token === '}' ) {
				-- $open_parenthesis;
			} elseif ( $token === ',' && $open_parenthesis === 0 ) {
				break;
			}

			// Don't capture the comma as a part of the constant name
			$name_buffer[] = $token;
		}

		// Capture everything until the closing parenthesis
		// define("WP_DEBUG", true);
		// ^^^^^^
		$open_parenthesis   = 0;
		$is_second_argument = true;
		while ( $token = array_pop( $tokens ) ) {
			$buffer[] = $token;
			if ( $token === ')' && $open_parenthesis === 0 ) {
				// Final parenthesis of the define call.
				break;
			} elseif ( $token === '(' || $token === '[' || $token === '{' ) {
				++ $open_parenthesis;
			} elseif ( $token === ')' || $token === ']' || $token === '}' ) {
				-- $open_parenthesis;
			} elseif ( $token === ',' && $open_parenthesis === 0 ) {
				// This define call has more than 2 arguments! The third one is the
				// boolean value indicating $is_case_insensitive. Let's continue capturing
				// to $third_arg_buffer.
				$is_second_argument = false;
			}
			if ( $is_second_argument ) {
				$value_buffer[] = $token;
			} else {
				$third_arg_buffer[] = $token;
			}
		}

		// Capture until the semicolon
		// define("WP_DEBUG", true)  ;
		// ^^^
		while ( $token = array_pop( $tokens ) ) {
			$buffer[] = $token;
			if ( $token === ';' ) {
				break;
			}
		}

		// Decide whether $name_buffer is a constant name or an expression
		$name_token       = null;
		$name_token_index = $token;
		$name_is_literal  = true;
		foreach ( $name_buffer as $k => $token ) {
			if ( is_array( $token ) ) {
				if ( $token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT ) {
					continue;
				} elseif ( $token[0] === T_STRING || $token[0] === T_CONSTANT_ENCAPSED_STRING ) {
					$name_token       = $token;
					$name_token_index = $k;
				} else {
					$name_is_literal = false;
					break;
				}
			} elseif ( $token !== '(' && $token !== ')' ) {
				$name_is_literal = false;
				break;
			}
		}

		// We can't handle expressions as constant names. Let's wrap that define
		// call in an if(!defined()) statement, just in case it collides with
		// a constant name.
		if ( ! $name_is_literal ) {
			// Ensure the defined expression is not already accounted for
			foreach ( $defined_expressions as $defined_expression ) {
				if ( $defined_expression === stringify_tokens( skip_whitespace( $name_buffer ) ) ) {
					$output = array_merge( $output, $buffer );
					continue 2;
				}
			}
			$output = array_merge(
				$output,
				array( 'if(!defined(' ),
				$name_buffer,
				array( ")) {\n     " ),
				array( 'define(' ),
				$name_buffer,
				array( ',' ),
				$value_buffer,
				$third_arg_buffer,
				array( ');' ),
				array( "\n}\n" )
			);
			continue;
		}

		// Yay, we have a literal constant name in the buffer now. Let's
		// get its value:
		$name = eval( 'return ' . $name_token[1] . ';' );

		// If the constant name is not in the list of constants we're looking,
		// we can ignore it.
		if ( ! array_key_exists( $name, $constants ) ) {
			$output = array_merge( $output, $buffer );
			continue;
		}

		// We now have a define() call that defines a constant we're looking for.
		// Let's rewrite its value to the one
		$output = array_merge(
			$output,
			array( 'define(' ),
			$name_buffer,
			array( ',' ),
			array( var_export( $constants[ $name ], true ) ),
			$third_arg_buffer,
			array( ');' )
		);

		// Remove the constant from the list so we can process any remaining
		// constants later.
		unset( $constants[ $name ] );
	} while ( count( $tokens ) );

	// Add any constants that weren't found in the file
	if ( count( $constants ) ) {
        // First try to find the "That's all, stop editing!" comment.
		$anchor = find_first_token_index( $output, T_COMMENT, "That's all, stop editing!" );

        // If not found, try the "Absolute path to the WordPress directory." doc comment.
		if ( null === $anchor ) {
			$anchor = find_first_token_index( $output, T_DOC_COMMENT, "Absolute path to the WordPress directory." );
		}

        // If not found, try the "Sets up WordPress vars and included files." doc comment.
		if ( null === $anchor ) {
			$anchor = find_first_token_index( $output, T_DOC_COMMENT, "Sets up WordPress vars and included files." );
		}

        // If not found, try "require_once ABSPATH . 'wp-settings.php';".
        if ( null === $anchor ) {
            $require_anchor = find_first_token_index( $output, T_REQUIRE_ONCE );
            if ( null !== $require_anchor ) {
                $abspath = $output[$require_anchor + 2] ?? null;
                $path    = $output[$require_anchor + 6] ?? null;
                if (
                    ( is_array( $abspath ) && $abspath[1] === 'ABSPATH' )
                    && ( is_array( $path ) && $path[1] === "'wp-settings.php'" )
                ) {
                    $anchor = $require_anchor;
                }
            }
        }

        // If not found, fall back to the PHP opening tag.
		if ( null === $anchor ) {
			$open_tag_anchor = find_first_token_index( $output, T_OPEN_TAG );
			if ( null !== $open_tag_anchor ) {
				$anchor = $open_tag_anchor + 1;
			}
		}

		// If we still don't have an anchor, the file is not a valid PHP file.
        if ( null === $anchor ) {
            error_log( "Blueprint Error: wp-config.php file is not a valid PHP file." );
            exit( 1 );
        }

		// Ensure surrounding newlines when not already present.
        $prev = $output[ $anchor - 1 ] ?? null;
        $prev = is_array( $prev ) ? $prev[1] : $prev;
        $next = $output[ $anchor ] ?? null;
        $next = is_array( $next ) ? $next[1] : $next;

        $no_prefix = $prev && "\n\n" === substr( $prev, -2 );
        $no_suffix = $next && "\n\n" === substr( $next, 0, 2 );

        // Add the new constants.
		$new_constants = array( "\n" );
		foreach ( $constants as $name => $path ) {
			$new_constants[] = 'define( ';
			$new_constants[] = var_export( $name, true );
			$new_constants[] = ', ';
			$new_constants[] = var_export( $path, true );
			$new_constants[] = " );\n";
		}
        $new_constants[] = "\n";

		$output = array_merge(
			array_slice( $output, 0, $anchor ),
			$new_constants,
			array_slice( $output, $anchor )
		);
	}

	// Translate the output tokens back into a string
	return stringify_tokens( $output );
}

function stringify_tokens( $tokens ) {
	$output = '';
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			$output .= $token[1];
		} else {
			$output .= $token;
		}
	}

	return $output;
}

function skip_whitespace( $tokens ) {
	$output = array();
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) && ( $token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT ) ) {
			continue;
		}
		$output[] = $token;
	}

	return $output;
}

function find_first_token_index( $tokens, $type, $search = null ) {
	foreach ( $tokens as $i => $token ) {
		if ( ! is_array( $token ) ) {
			continue;
		}

		if ( $type !== $token[0] ) {
			continue;
		}

		if ( null === $search || false !== strpos( $token[1], $search ) ) {
			return $i;
		}
	}
	return null;
}

$wp_config_path = getenv( "DOCROOT" ) . "/wp-config.php";

if ( ! file_exists( $wp_config_path ) ) {
	error_log( "Blueprint Error: wp-config.php file not found at " . $wp_config_path );
	exit( 1 );
}

if ( ! is_readable( $wp_config_path ) || ! is_writable( $wp_config_path ) ) {
	error_log( "Blueprint Error: wp-config.php is not readable or writable at " . $wp_config_path );
	exit( 1 );
}

$consts        = json_decode( getenv( "CONSTS" ), true );
$wp_config     = file_get_contents( $wp_config_path );
$new_wp_config = rewrite_wp_config_to_define_constants( $wp_config, $consts );
file_put_contents( $wp_config_path, $new_wp_config );

PHP
			,
			array( 'CONSTS' => json_encode( $this->constants ) )
		);
	}
}
