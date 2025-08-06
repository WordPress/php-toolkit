<?php
/**
 * Polyfills WordPress core functions for running in non-WordPress environments
 */
namespace WordPress\Polyfill;

// This may be loaded twice if Polyfills are included before WordPress, but that's fine.
// There are no function declarations in that file, only a global variable.
if (
	! isset( $html5_named_character_references ) &&
	file_exists( __DIR__ . '/../HTML/html5-named-character-references.php' )
) {
	require_once __DIR__ . '/../HTML/html5-named-character-references.php';
}

// @TODO: Wrap in namespaces before merging:
if ( ! class_exists( 'WP_Block_Parser' ) ) {
	require_once __DIR__ . '/../BlockParser/class-wp-block-parser-block.php';
	require_once __DIR__ . '/../BlockParser/class-wp-block-parser-frame.php';
	require_once __DIR__ . '/../BlockParser/class-wp-block-parser.php';
}

$GLOBALS['_doing_it_wrong_messages'] = array();
function _doing_it_wrong( $method, $message, $version ) {
	if ( function_exists( '\\_doing_it_wrong' ) ) {
		return call_user_func_array( '\\_doing_it_wrong', func_get_args() );
	}
	$GLOBALS['_doing_it_wrong_messages'][] = $message;
}

function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {
	if ( function_exists( '\\wp_trigger_error' ) ) {
		return call_user_func_array( '\\wp_trigger_error', func_get_args() );
	}

	if ( ! empty( $function_name ) ) {
		$message = sprintf( '%s(): %s', $function_name, $message );
	}

	if ( E_USER_ERROR === $error_level ) {
		if ( ! class_exists( '\WP_Exception' ) ) {
			class WP_Exception extends \Exception {
			}
		}
		throw new WP_Exception( $message );
	}

	trigger_error( $message, $error_level );
}

function wp_kses_uri_attributes() {
	if ( function_exists( '\\wp_kses_uri_attributes' ) ) {
		return call_user_func_array( '\\wp_kses_uri_attributes', func_get_args() );
	}
	return array();
}

function __( $input ) {
	if ( function_exists( '\\__' ) ) {
		return call_user_func_array( '\\__', func_get_args() );
	}
	return $input;
}

function esc_attr( $input ) {
	if ( function_exists( '\\esc_attr' ) ) {
		return call_user_func_array( '\\esc_attr', func_get_args() );
	}
	return htmlspecialchars( $input );
}

function esc_html( $input ) {
	if ( function_exists( '\\esc_html' ) ) {
		return call_user_func_array( '\\esc_html', func_get_args() );
	}
	return htmlspecialchars( $input );
}

function esc_url( $url ) {
	if ( function_exists( '\\esc_url' ) ) {
		return call_user_func_array( '\\esc_url', func_get_args() );
	}
	return htmlspecialchars( $url );
}

function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	global $wp_filter;
	if ( function_exists( '\\add_filter' ) ) {
		return call_user_func_array( '\\add_filter', func_get_args() );
	}

	if ( ! isset( $wp_filter ) ) {
		$wp_filter = array();
	}
	if ( ! isset( $wp_filter[ $hook_name ] ) ) {
		$wp_filter[ $hook_name ] = array();
	}
	if ( ! isset( $wp_filter[ $hook_name ][ $priority ] ) ) {
		$wp_filter[ $hook_name ][ $priority ] = array();
	}
	$wp_filter[ $hook_name ][ $priority ][] = array(
		'function'      => $callback,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	if ( function_exists( '\\add_action' ) ) {
		return call_user_func_array( '\\add_action', func_get_args() );
	}
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

function apply_filters( $hook_name, $value ) {
	global $wp_filter;
	if ( function_exists( '\\apply_filters' ) ) {
		return call_user_func_array( '\\apply_filters', func_get_args() );
	}

	if ( ! isset( $wp_filter[ $hook_name ] ) ) {
		return $value;
	}
	$args = func_get_args();
	array_shift( $args ); // Remove hook name

	ksort( $wp_filter[ $hook_name ] );
	foreach ( $wp_filter[ $hook_name ] as $priority => $functions ) {
		foreach ( $functions as $function ) {
			$args[0] = $value;
			$value   = call_user_func_array( $function['function'], array_slice( $args, 0, $function['accepted_args'] ) );
		}
	}

	return $value;
}

function do_action( $hook_name ) {
	global $wp_filter;
	if ( function_exists( '\\do_action' ) ) {
		return call_user_func_array( '\\do_action', func_get_args() );
	}

	if ( ! isset( $wp_filter[ $hook_name ] ) ) {
		return;
	}
	$args = func_get_args();
	array_shift( $args ); // Remove hook name

	ksort( $wp_filter[ $hook_name ] );
	foreach ( $wp_filter[ $hook_name ] as $priority => $functions ) {
		foreach ( $functions as $function ) {
			call_user_func_array( $function['function'], array_slice( $args, 0, $function['accepted_args'] ) );
		}
	}
}

function parse_blocks( $input ) {
	if ( function_exists( '\\parse_blocks' ) ) {
		return call_user_func_array( '\\parse_blocks', func_get_args() );
	}
	$parser = new \WordPress\BlockParser\WP_Block_Parser();

	return $parser->parse( $input );
}

function serialize_blocks( $blocks ) {
	if ( function_exists( '\\serialize_blocks' ) ) {
		return call_user_func_array( '\\serialize_blocks', func_get_args() );
	}
	return implode( '', array_map( 'serialize_block', $blocks ) );
}

function serialize_block( $block ) {
	if ( function_exists( '\\serialize_block' ) ) {
		return call_user_func_array( '\\serialize_block', func_get_args() );
	}
	$block_content = '';

	$index = 0;
	foreach ( $block['innerContent'] as $chunk ) {
		$block_content .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $index ++ ] );
	}

	if ( ! is_array( $block['attrs'] ) ) {
		$block['attrs'] = array();
	}

	return get_comment_delimited_block_content(
		$block['blockName'],
		$block['attrs'],
		$block_content
	);
}

function get_comment_delimited_block_content( $block_name, $block_attributes, $block_content ) {
	if ( function_exists( '\\get_comment_delimited_block_content' ) ) {
		return call_user_func_array( '\\get_comment_delimited_block_content', func_get_args() );
	}
	if ( is_null( $block_name ) ) {
		return $block_content;
	}

	$serialized_block_name = strip_core_block_namespace( $block_name );
	$serialized_attributes = empty( $block_attributes ) ? '' : serialize_block_attributes( $block_attributes ) . ' ';

	if ( empty( $block_content ) ) {
		return sprintf( '<!-- wp:%s %s/-->', $serialized_block_name, $serialized_attributes );
	}

	return sprintf(
		'<!-- wp:%s %s-->%s<!-- /wp:%s -->',
		$serialized_block_name,
		$serialized_attributes,
		$block_content,
		$serialized_block_name
	);
}

function strip_core_block_namespace( $block_name = null ) {
	if ( function_exists( '\\strip_core_block_namespace' ) ) {
		return call_user_func_array( '\\strip_core_block_namespace', func_get_args() );
	}
	if ( is_string( $block_name ) && strncmp( $block_name, 'core/', strlen( 'core/' ) ) === 0 ) {
		return substr( $block_name, 5 );
	}

	return $block_name;
}

function serialize_block_attributes( $block_attributes ) {
	if ( function_exists( '\\serialize_block_attributes' ) ) {
		return call_user_func_array( '\\serialize_block_attributes', func_get_args() );
	}
	$encoded_attributes = json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$encoded_attributes = preg_replace( '/--/', '\\u002d\\u002d', $encoded_attributes );
	$encoded_attributes = preg_replace( '/</', '\\u003c', $encoded_attributes );
	$encoded_attributes = preg_replace( '/>/', '\\u003e', $encoded_attributes );
	$encoded_attributes = preg_replace( '/&/', '\\u0026', $encoded_attributes );
	// Regex: /\\"/
	$encoded_attributes = preg_replace( '/\\\\"/', '\\u0022', $encoded_attributes );

	return $encoded_attributes;
}

if(!function_exists('wp_read_audio_metadata')) {
	function wp_read_audio_metadata($file) {
		return array();
	}
}