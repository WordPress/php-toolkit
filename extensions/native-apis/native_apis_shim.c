#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"

#include <stddef.h>
#include <string.h>

typedef struct {
	char        *source;
	size_t       source_len;
	size_t       cursor;
	char        current_name[64];
	char        current_url[256];
	zend_bool   current_had_protocol;
	zend_object std;
} wp_native_smoke_object;

static zend_class_entry *wp_native_html_tag_processor_ce;
static zend_class_entry *wp_native_html_processor_ce;
static zend_class_entry *wp_native_xml_processor_ce;
static zend_class_entry *wp_native_url_processor_ce;
static zend_object_handlers wp_native_smoke_object_handlers;

static zend_bool
wp_native_ascii_is_space( char c ) {
	return ' ' == c || '\t' == c || '\n' == c || '\r' == c || '\f' == c;
}

static zend_bool
wp_native_ascii_is_alnum( char c ) {
	return ( 'a' <= c && 'z' >= c ) || ( 'A' <= c && 'Z' >= c ) || ( '0' <= c && '9' >= c );
}

static char
wp_native_ascii_lower( char c ) {
	if ( 'A' <= c && 'Z' >= c ) {
		return c + ( 'a' - 'A' );
	}
	return c;
}

static char
wp_native_ascii_upper( char c ) {
	if ( 'a' <= c && 'z' >= c ) {
		return c - ( 'a' - 'A' );
	}
	return c;
}

static const char *
wp_native_find_char( const char *start, size_t length, char needle ) {
	size_t i;

	for ( i = 0; i < length; i++ ) {
		if ( needle == start[ i ] ) {
			return start + i;
		}
	}

	return NULL;
}

static void
wp_native_copy_bytes( char *target, const char *source, size_t length ) {
	size_t i;

	for ( i = 0; i < length; i++ ) {
		target[ i ] = source[ i ];
	}
}

static zend_bool
wp_native_ascii_starts_with( const char *value, size_t value_len, const char *prefix, size_t prefix_len ) {
	size_t i;

	if ( value_len < prefix_len ) {
		return 0;
	}

	for ( i = 0; i < prefix_len; i++ ) {
		if ( wp_native_ascii_lower( value[ i ] ) != wp_native_ascii_lower( prefix[ i ] ) ) {
			return 0;
		}
	}

	return 1;
}

static zend_bool
wp_native_is_trailing_url_punctuation( char c ) {
	return '.' == c || ',' == c || ';' == c || ':' == c || '!' == c || '?' == c || ')' == c || '"' == c || ']' == c || '}' == c;
}

static inline wp_native_smoke_object *
wp_native_smoke_from_object( zend_object *object ) {
	return (wp_native_smoke_object *) ( (char *) object - XtOffsetOf( wp_native_smoke_object, std ) );
}

#define Z_WP_NATIVE_SMOKE_P( zval_p ) wp_native_smoke_from_object( Z_OBJ_P( zval_p ) )

static zend_object *
wp_native_smoke_create_object( zend_class_entry *class_entry ) {
	wp_native_smoke_object *object = zend_object_alloc( sizeof( *object ), class_entry );
	memset( object, 0, XtOffsetOf( wp_native_smoke_object, std ) );

	zend_object_std_init( &object->std, class_entry );
	object_properties_init( &object->std, class_entry );
	object->std.handlers = &wp_native_smoke_object_handlers;

	return &object->std;
}

static void
wp_native_smoke_free_object( zend_object *std ) {
	wp_native_smoke_object *object = wp_native_smoke_from_object( std );

	if ( NULL != object->source ) {
		efree( object->source );
	}

	zend_object_std_dtor( std );
}

static void
wp_native_smoke_set_source( wp_native_smoke_object *object, const char *source, size_t source_len ) {
	if ( NULL != object->source ) {
		efree( object->source );
	}

	object->source               = estrndup( source, source_len );
	object->source_len           = source_len;
	object->cursor               = 0;
	object->current_name[0]      = '\0';
	object->current_url[0]       = '\0';
	object->current_had_protocol = 0;
}

static zend_bool
wp_native_ascii_ieq( const char *left, size_t left_len, const char *right, size_t right_len ) {
	size_t i;

	if ( left_len != right_len ) {
		return 0;
	}

	for ( i = 0; i < left_len; i++ ) {
		if ( wp_native_ascii_lower( left[ i ] ) != wp_native_ascii_lower( right[ i ] ) ) {
			return 0;
		}
	}

	return 1;
}

static void
wp_native_copy_upper_name( char *target, size_t target_len, const char *name, size_t name_len ) {
	size_t i;
	size_t limit = name_len;

	if ( 0 == target_len ) {
		return;
	}

	if ( limit >= target_len ) {
		limit = target_len - 1;
	}

	for ( i = 0; i < limit; i++ ) {
		target[ i ] = wp_native_ascii_upper( name[ i ] );
	}
	target[ limit ] = '\0';
}

static zend_bool
wp_native_tag_has_class( const char *tag_start, const char *tag_end, const char *class_name, size_t class_name_len ) {
	const char *cursor = tag_start;

	while ( cursor + 5 < tag_end ) {
		if (
			( 'c' == cursor[0] || 'C' == cursor[0] ) &&
			( 'l' == cursor[1] || 'L' == cursor[1] ) &&
			( 'a' == cursor[2] || 'A' == cursor[2] ) &&
			( 's' == cursor[3] || 'S' == cursor[3] ) &&
			( 's' == cursor[4] || 'S' == cursor[4] )
		) {
			const char *value;
			const char *value_end;

			cursor += 5;
			while ( cursor < tag_end && wp_native_ascii_is_space( *cursor ) ) {
				cursor++;
			}
			if ( cursor >= tag_end || '=' != *cursor ) {
				continue;
			}
			cursor++;
			while ( cursor < tag_end && wp_native_ascii_is_space( *cursor ) ) {
				cursor++;
			}
			if ( cursor >= tag_end || ( '"' != *cursor && '\'' != *cursor ) ) {
				continue;
			}

			value     = ++cursor;
			value_end = wp_native_find_char( value, tag_end - value, cursor[-1] );
			if ( NULL == value_end ) {
				value_end = tag_end;
			}

			while ( value < value_end ) {
				const char *part = value;
				while ( part < value_end && wp_native_ascii_is_space( *part ) ) {
					part++;
				}
				value = part;
				while ( value < value_end && ! wp_native_ascii_is_space( *value ) ) {
					value++;
				}
				if ( wp_native_ascii_ieq( part, value - part, class_name, class_name_len ) ) {
					return 1;
				}
			}
		}
		cursor++;
	}

	return 0;
}

static zend_bool
wp_native_html_next_tag( wp_native_smoke_object *object, zval *query ) {
	const char *requested_tag       = NULL;
	size_t      requested_tag_len   = 0;
	const char *requested_class     = NULL;
	size_t      requested_class_len = 0;

	if ( NULL != query && IS_ARRAY == Z_TYPE_P( query ) ) {
		zval *tag_name = zend_hash_str_find( Z_ARRVAL_P( query ), "tag_name", sizeof( "tag_name" ) - 1 );
		zval *class_name = zend_hash_str_find( Z_ARRVAL_P( query ), "class_name", sizeof( "class_name" ) - 1 );

		if ( NULL != tag_name && IS_STRING == Z_TYPE_P( tag_name ) ) {
			requested_tag     = Z_STRVAL_P( tag_name );
			requested_tag_len = Z_STRLEN_P( tag_name );
		}
		if ( NULL != class_name && IS_STRING == Z_TYPE_P( class_name ) ) {
			requested_class     = Z_STRVAL_P( class_name );
			requested_class_len = Z_STRLEN_P( class_name );
		}
	}

	while ( object->cursor < object->source_len ) {
		const char *tag_start;
		const char *tag_end;
		const char *name;
		size_t      name_len;

		tag_start = wp_native_find_char( object->source + object->cursor, object->source_len - object->cursor, '<' );
		if ( NULL == tag_start ) {
			object->cursor = object->source_len;
			return 0;
		}

		object->cursor = ( tag_start - object->source ) + 1;
		if ( object->cursor >= object->source_len || '/' == object->source[ object->cursor ] || '!' == object->source[ object->cursor ] || '?' == object->source[ object->cursor ] ) {
			continue;
		}

		name = object->source + object->cursor;
		while ( object->cursor < object->source_len ) {
			char c = object->source[ object->cursor ];
			if ( ! ( wp_native_ascii_is_alnum( c ) || ':' == c || '-' == c ) ) {
				break;
			}
			object->cursor++;
		}

		name_len = object->source + object->cursor - name;
		tag_end  = wp_native_find_char( object->source + object->cursor, object->source_len - object->cursor, '>' );
		if ( NULL == tag_end ) {
			tag_end = object->source + object->source_len;
		}
		object->cursor = ( tag_end - object->source ) + ( tag_end < object->source + object->source_len ? 1 : 0 );

		if ( 0 == name_len ) {
			continue;
		}
		if ( NULL != requested_tag && ! wp_native_ascii_ieq( name, name_len, requested_tag, requested_tag_len ) ) {
			continue;
		}
		if ( NULL != requested_class && ! wp_native_tag_has_class( name + name_len, tag_end, requested_class, requested_class_len ) ) {
			continue;
		}

		wp_native_copy_upper_name( object->current_name, sizeof( object->current_name ), name, name_len );
		return 1;
	}

	return 0;
}

PHP_METHOD( WP_HTML_Native_Tag_Processor, __construct ) {
	char *html;
	size_t html_len;

	ZEND_PARSE_PARAMETERS_START( 1, 1 )
		Z_PARAM_STRING( html, html_len )
	ZEND_PARSE_PARAMETERS_END();

	wp_native_smoke_set_source( Z_WP_NATIVE_SMOKE_P( getThis() ), html, html_len );
}

PHP_METHOD( WP_HTML_Native_Tag_Processor, next_tag ) {
	zval *query = NULL;

	ZEND_PARSE_PARAMETERS_START( 0, 1 )
		Z_PARAM_OPTIONAL
		Z_PARAM_ZVAL( query )
	ZEND_PARSE_PARAMETERS_END();

	RETURN_BOOL( wp_native_html_next_tag( Z_WP_NATIVE_SMOKE_P( getThis() ), query ) );
}

PHP_METHOD( WP_HTML_Native_Tag_Processor, get_tag ) {
	wp_native_smoke_object *object = Z_WP_NATIVE_SMOKE_P( getThis() );

	ZEND_PARSE_PARAMETERS_NONE();

	if ( '\0' == object->current_name[0] ) {
		RETURN_NULL();
	}
	RETURN_STRING( object->current_name );
}

PHP_METHOD( WP_HTML_Native_Processor, create_fragment ) {
	char *html;
	size_t html_len;
	wp_native_smoke_object *object;

	ZEND_PARSE_PARAMETERS_START( 1, 1 )
		Z_PARAM_STRING( html, html_len )
	ZEND_PARSE_PARAMETERS_END();

	object_init_ex( return_value, wp_native_html_processor_ce );
	object = Z_WP_NATIVE_SMOKE_P( return_value );
	wp_native_smoke_set_source( object, html, html_len );
}

PHP_METHOD( WP_HTML_Native_Processor, next_tag ) {
	zval *query = NULL;

	ZEND_PARSE_PARAMETERS_START( 0, 1 )
		Z_PARAM_OPTIONAL
		Z_PARAM_ZVAL( query )
	ZEND_PARSE_PARAMETERS_END();

	RETURN_BOOL( wp_native_html_next_tag( Z_WP_NATIVE_SMOKE_P( getThis() ), query ) );
}

PHP_METHOD( WP_HTML_Native_Processor, get_tag ) {
	wp_native_smoke_object *object = Z_WP_NATIVE_SMOKE_P( getThis() );

	ZEND_PARSE_PARAMETERS_NONE();

	if ( '\0' == object->current_name[0] ) {
		RETURN_NULL();
	}
	RETURN_STRING( object->current_name );
}

static zend_bool
wp_native_xml_next_tag( wp_native_smoke_object *object, const char *requested, size_t requested_len ) {
	while ( object->cursor < object->source_len ) {
		const char *tag_start;
		const char *name;
		const char *local;
		size_t      name_len;
		size_t      local_len;

		tag_start = wp_native_find_char( object->source + object->cursor, object->source_len - object->cursor, '<' );
		if ( NULL == tag_start ) {
			object->cursor = object->source_len;
			return 0;
		}

		object->cursor = ( tag_start - object->source ) + 1;
		if ( object->cursor >= object->source_len || '/' == object->source[ object->cursor ] || '!' == object->source[ object->cursor ] || '?' == object->source[ object->cursor ] ) {
			continue;
		}

		name = object->source + object->cursor;
		while ( object->cursor < object->source_len ) {
			char c = object->source[ object->cursor ];
			if ( ! ( wp_native_ascii_is_alnum( c ) || ':' == c || '-' == c || '_' == c ) ) {
				break;
			}
			object->cursor++;
		}

		name_len = object->source + object->cursor - name;
		local = wp_native_find_char( name, name_len, ':' );
		if ( NULL == local ) {
			local = name;
			local_len = name_len;
		} else {
			local++;
			local_len = name + name_len - local;
		}

		if ( NULL != requested && ! wp_native_ascii_ieq( local, local_len, requested, requested_len ) ) {
			continue;
		}

		if ( local_len >= sizeof( object->current_name ) ) {
			local_len = sizeof( object->current_name ) - 1;
		}
		wp_native_copy_bytes( object->current_name, local, local_len );
		object->current_name[ local_len ] = '\0';
		return 1;
	}

	return 0;
}

PHP_METHOD( NativeXMLProcessor, create_from_string ) {
	char *xml;
	size_t xml_len;
	wp_native_smoke_object *object;

	ZEND_PARSE_PARAMETERS_START( 1, 1 )
		Z_PARAM_STRING( xml, xml_len )
	ZEND_PARSE_PARAMETERS_END();

	object_init_ex( return_value, wp_native_xml_processor_ce );
	object = Z_WP_NATIVE_SMOKE_P( return_value );
	wp_native_smoke_set_source( object, xml, xml_len );
}

PHP_METHOD( NativeXMLProcessor, next_tag ) {
	char *tag_name = NULL;
	size_t tag_name_len = 0;

	ZEND_PARSE_PARAMETERS_START( 0, 1 )
		Z_PARAM_OPTIONAL
		Z_PARAM_STRING( tag_name, tag_name_len )
	ZEND_PARSE_PARAMETERS_END();

	RETURN_BOOL( wp_native_xml_next_tag( Z_WP_NATIVE_SMOKE_P( getThis() ), tag_name, tag_name_len ) );
}

PHP_METHOD( NativeXMLProcessor, get_tag_local_name ) {
	wp_native_smoke_object *object = Z_WP_NATIVE_SMOKE_P( getThis() );

	ZEND_PARSE_PARAMETERS_NONE();

	if ( '\0' == object->current_name[0] ) {
		RETURN_NULL();
	}
	RETURN_STRING( object->current_name );
}

static zend_bool
wp_native_url_next( wp_native_smoke_object *object ) {
	while ( object->cursor < object->source_len ) {
		size_t start = object->cursor;
		size_t end;
		zend_bool had_protocol = 0;

		while ( start < object->source_len && wp_native_ascii_is_space( object->source[ start ] ) ) {
			start++;
		}

		end = start;
		while ( end < object->source_len && ! wp_native_ascii_is_space( object->source[ end ] ) ) {
			end++;
		}
		object->cursor = end + ( end < object->source_len ? 1 : 0 );

		if ( end <= start ) {
			continue;
		}

		while ( end > start && wp_native_is_trailing_url_punctuation( object->source[ end - 1 ] ) ) {
			end--;
		}

		if ( wp_native_ascii_starts_with( object->source + start, end - start, "http://", 7 ) ) {
			had_protocol = 1;
		} else if ( wp_native_ascii_starts_with( object->source + start, end - start, "https://", 8 ) ) {
			had_protocol = 1;
		} else if ( NULL == wp_native_find_char( object->source + start, end - start, '.' ) ) {
			continue;
		}

		if ( end - start >= sizeof( object->current_url ) ) {
			end = start + sizeof( object->current_url ) - 1;
		}
		wp_native_copy_bytes( object->current_url, object->source + start, end - start );
		object->current_url[ end - start ] = '\0';
		object->current_had_protocol = had_protocol;
		return 1;
	}

	return 0;
}

typedef struct {
	char  *value;
	size_t length;
	size_t capacity;
} wp_native_string_buffer;

static zend_bool
wp_native_buffer_append( wp_native_string_buffer *buffer, const char *value, size_t length ) {
	size_t required;
	size_t capacity;

	if ( 0 == length ) {
		return 1;
	}

	if ( length > ( (size_t) -1 ) - buffer->length - 1 ) {
		return 0;
	}

	required = buffer->length + length + 1;
	if ( required > buffer->capacity ) {
		capacity = buffer->capacity ? buffer->capacity : 256;
		while ( capacity < required ) {
			if ( capacity > ( (size_t) -1 ) / 2 ) {
				capacity = required;
				break;
			}
			capacity *= 2;
		}

		buffer->value    = buffer->value ? erealloc( buffer->value, capacity ) : emalloc( capacity );
		buffer->capacity = capacity;
	}

	memcpy( buffer->value + buffer->length, value, length );
	buffer->length += length;
	buffer->value[ buffer->length ] = '\0';

	return 1;
}

static size_t
wp_native_http_scheme_len( const char *value, size_t value_len ) {
	if ( wp_native_ascii_starts_with( value, value_len, "http://", 7 ) ) {
		return 7;
	}

	if ( wp_native_ascii_starts_with( value, value_len, "https://", 8 ) ) {
		return 8;
	}

	return 0;
}

static zend_bool
wp_native_base_boundary_allows_match( const char *candidate, size_t candidate_len, size_t from_len ) {
	char next;

	if ( candidate_len == from_len ) {
		return 1;
	}

	if ( from_len > 0 && '/' == candidate[ from_len - 1 ] ) {
		return 1;
	}

	next = candidate[ from_len ];
	return '/' == next || '?' == next || '#' == next;
}

static zend_bool
wp_native_find_url_rewrite(
	const char *candidate,
	size_t candidate_len,
	const char *compact_mapping,
	size_t compact_mapping_len,
	const char **replacement,
	size_t *replacement_len,
	size_t *replacement_prefix_len,
	size_t *replace_len
) {
	size_t row_start = 0;
	size_t candidate_scheme_len = wp_native_http_scheme_len( candidate, candidate_len );
	size_t candidate_compare_offset = 0;
	zend_bool protocol_relative = 0;

	if (
		0 == candidate_scheme_len &&
		candidate_len >= 2 &&
		'/' == candidate[0] &&
		'/' == candidate[1]
	) {
		candidate_compare_offset = 2;
		protocol_relative        = 1;
	}

	while ( row_start < compact_mapping_len ) {
		size_t row_end = row_start;
		size_t separator;
		const char *from;
		const char *to;
		size_t from_len;
		size_t to_len;
		size_t from_scheme_len;
		size_t to_scheme_len;
		size_t from_compare_offset = 0;
		size_t from_compare_len;
		size_t to_offset = 0;

		while ( row_end < compact_mapping_len && '\x1e' != compact_mapping[ row_end ] ) {
			row_end++;
		}

		separator = row_start;
		while ( separator < row_end && '\x1f' != compact_mapping[ separator ] ) {
			separator++;
		}

		if ( separator == row_end ) {
			row_start = row_end + ( row_end < compact_mapping_len ? 1 : 0 );
			continue;
		}

		from     = compact_mapping + row_start;
		from_len = separator - row_start;
		to       = compact_mapping + separator + 1;
		to_len   = row_end - separator - 1;

		from_scheme_len = wp_native_http_scheme_len( from, from_len );
		to_scheme_len   = wp_native_http_scheme_len( to, to_len );

		if ( 0 == from_len ) {
			row_start = row_end + ( row_end < compact_mapping_len ? 1 : 0 );
			continue;
		}

		if ( 0 == candidate_scheme_len && from_scheme_len > 0 ) {
			from_compare_offset = from_scheme_len;
		}

		from_compare_len = from_len - from_compare_offset;
		if ( 0 == from_compare_len ) {
			row_start = row_end + ( row_end < compact_mapping_len ? 1 : 0 );
			continue;
		}

		if (
			candidate_len >= candidate_compare_offset + from_compare_len &&
			wp_native_ascii_starts_with(
				candidate + candidate_compare_offset,
				candidate_len - candidate_compare_offset,
				from + from_compare_offset,
				from_compare_len
			) &&
			wp_native_base_boundary_allows_match(
				candidate + candidate_compare_offset,
				candidate_len - candidate_compare_offset,
				from_compare_len
			)
		) {
			if ( 0 == candidate_scheme_len && to_scheme_len > 0 ) {
				to_offset = to_scheme_len;
			}

			*replacement            = to + to_offset;
			*replacement_len        = to_len - to_offset;
			*replacement_prefix_len = protocol_relative ? 2 : 0;
			*replace_len            = candidate_compare_offset + from_compare_len;
			return 1;
		}

		row_start = row_end + ( row_end < compact_mapping_len ? 1 : 0 );
	}

	return 0;
}

PHP_FUNCTION( wp_native_apis_rewrite_text_url_bases ) {
	char *text;
	size_t text_len;
	char *base_url = NULL;
	size_t base_url_len = 0;
	char *compact_mapping;
	size_t compact_mapping_len;
	size_t cursor = 0;
	size_t copied_until = 0;
	wp_native_string_buffer buffer = { NULL, 0, 0 };

	ZEND_PARSE_PARAMETERS_START( 3, 3 )
		Z_PARAM_STRING( text, text_len )
		Z_PARAM_STRING_OR_NULL( base_url, base_url_len )
		Z_PARAM_STRING( compact_mapping, compact_mapping_len )
	ZEND_PARSE_PARAMETERS_END();

	(void) base_url;
	(void) base_url_len;

	while ( cursor < text_len ) {
		size_t start = cursor;
		size_t end;
		const char *replacement;
		size_t replacement_len;
		size_t replacement_prefix_len;
		size_t replace_len;
		zend_bool is_url_candidate = 0;

		while ( start < text_len && wp_native_ascii_is_space( text[ start ] ) ) {
			start++;
		}

		end = start;
		while ( end < text_len && ! wp_native_ascii_is_space( text[ end ] ) ) {
			end++;
		}
		cursor = end + ( end < text_len ? 1 : 0 );

		if ( end <= start ) {
			continue;
		}

		while ( end > start && wp_native_is_trailing_url_punctuation( text[ end - 1 ] ) ) {
			end--;
		}

		if ( wp_native_http_scheme_len( text + start, end - start ) > 0 ) {
			is_url_candidate = 1;
		} else if ( NULL != wp_native_find_char( text + start, end - start, '.' ) ) {
			is_url_candidate = 1;
		}

		if (
			! is_url_candidate ||
			! wp_native_find_url_rewrite(
				text + start,
				end - start,
				compact_mapping,
				compact_mapping_len,
				&replacement,
				&replacement_len,
				&replacement_prefix_len,
				&replace_len
			)
		) {
			continue;
		}

		if (
			! wp_native_buffer_append( &buffer, text + copied_until, start - copied_until ) ||
			! wp_native_buffer_append( &buffer, text + start, replacement_prefix_len ) ||
			! wp_native_buffer_append(
				&buffer,
				replacement,
				replacement_len > 0 &&
					replace_len < end - start &&
					'/' == replacement[ replacement_len - 1 ] &&
					'/' == text[ start + replace_len ]
						? replacement_len - 1
						: replacement_len
			) ||
			! wp_native_buffer_append( &buffer, text + start + replace_len, end - start - replace_len )
		) {
			if ( NULL != buffer.value ) {
				efree( buffer.value );
			}
			php_error_docref( NULL, E_WARNING, "Unable to allocate rewritten text" );
			RETURN_FALSE;
		}

		copied_until = end;
	}

	if ( NULL == buffer.value ) {
		RETURN_STRINGL( text, text_len );
	}

	if ( ! wp_native_buffer_append( &buffer, text + copied_until, text_len - copied_until ) ) {
		efree( buffer.value );
		php_error_docref( NULL, E_WARNING, "Unable to allocate rewritten text" );
		RETURN_FALSE;
	}

	RETVAL_STRINGL( buffer.value, buffer.length );
	efree( buffer.value );
}

PHP_METHOD( NativeURLInTextProcessor, __construct ) {
	char *text;
	size_t text_len;
	char *base_url = NULL;
	size_t base_url_len = 0;

	ZEND_PARSE_PARAMETERS_START( 1, 2 )
		Z_PARAM_STRING( text, text_len )
		Z_PARAM_OPTIONAL
		Z_PARAM_STRING( base_url, base_url_len )
	ZEND_PARSE_PARAMETERS_END();

	(void) base_url;
	(void) base_url_len;

	wp_native_smoke_set_source( Z_WP_NATIVE_SMOKE_P( getThis() ), text, text_len );
}

PHP_METHOD( NativeURLInTextProcessor, next_url ) {
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_BOOL( wp_native_url_next( Z_WP_NATIVE_SMOKE_P( getThis() ) ) );
}

PHP_METHOD( NativeURLInTextProcessor, get_raw_url ) {
	wp_native_smoke_object *object = Z_WP_NATIVE_SMOKE_P( getThis() );

	ZEND_PARSE_PARAMETERS_NONE();

	if ( '\0' == object->current_url[0] ) {
		RETURN_NULL();
	}
	RETURN_STRING( object->current_url );
}

PHP_METHOD( NativeURLInTextProcessor, had_protocol ) {
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_BOOL( Z_WP_NATIVE_SMOKE_P( getThis() )->current_had_protocol );
}

PHP_FUNCTION( wp_native_apis_extension_version ) {
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_STRING( PHP_WP_NATIVE_APIS_VERSION );
}

ZEND_BEGIN_ARG_INFO_EX( arginfo_wp_native_string_ctor, 0, 0, 1 )
	ZEND_ARG_TYPE_INFO( 0, input, IS_STRING, 0 )
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX( arginfo_wp_native_url_ctor, 0, 0, 1 )
	ZEND_ARG_TYPE_INFO( 0, text, IS_STRING, 0 )
	ZEND_ARG_TYPE_INFO( 0, base_url, IS_STRING, 1 )
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX( arginfo_wp_native_next_tag, 0, 0, 0 )
	ZEND_ARG_TYPE_INFO( 0, query, IS_ARRAY, 1 )
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX( arginfo_wp_native_next_xml_tag, 0, 0, 0 )
	ZEND_ARG_TYPE_INFO( 0, tag_name, IS_STRING, 1 )
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX( arginfo_wp_native_void, 0, 0, 0 )
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX( arginfo_wp_native_create_fragment, 0, 0, 1 )
	ZEND_ARG_TYPE_INFO( 0, html, IS_STRING, 0 )
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX( arginfo_wp_native_create_from_string, 0, 0, 1 )
	ZEND_ARG_TYPE_INFO( 0, xml, IS_STRING, 0 )
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX( arginfo_wp_native_rewrite_text_url_bases, 0, 0, 3 )
	ZEND_ARG_TYPE_INFO( 0, text, IS_STRING, 0 )
	ZEND_ARG_TYPE_INFO( 0, base_url, IS_STRING, 1 )
	ZEND_ARG_TYPE_INFO( 0, compact_mapping, IS_STRING, 0 )
ZEND_END_ARG_INFO()

static const zend_function_entry wp_native_html_tag_processor_methods[] = {
	PHP_ME( WP_HTML_Native_Tag_Processor, __construct, arginfo_wp_native_string_ctor, ZEND_ACC_PUBLIC )
	PHP_ME( WP_HTML_Native_Tag_Processor, next_tag, arginfo_wp_native_next_tag, ZEND_ACC_PUBLIC )
	PHP_ME( WP_HTML_Native_Tag_Processor, get_tag, arginfo_wp_native_void, ZEND_ACC_PUBLIC )
	PHP_FE_END
};

static const zend_function_entry wp_native_html_processor_methods[] = {
	PHP_ME( WP_HTML_Native_Processor, create_fragment, arginfo_wp_native_create_fragment, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC )
	PHP_ME( WP_HTML_Native_Processor, next_tag, arginfo_wp_native_next_tag, ZEND_ACC_PUBLIC )
	PHP_ME( WP_HTML_Native_Processor, get_tag, arginfo_wp_native_void, ZEND_ACC_PUBLIC )
	PHP_FE_END
};

static const zend_function_entry wp_native_xml_processor_methods[] = {
	PHP_ME( NativeXMLProcessor, create_from_string, arginfo_wp_native_create_from_string, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC )
	PHP_ME( NativeXMLProcessor, next_tag, arginfo_wp_native_next_xml_tag, ZEND_ACC_PUBLIC )
	PHP_ME( NativeXMLProcessor, get_tag_local_name, arginfo_wp_native_void, ZEND_ACC_PUBLIC )
	PHP_FE_END
};

static const zend_function_entry wp_native_url_processor_methods[] = {
	PHP_ME( NativeURLInTextProcessor, __construct, arginfo_wp_native_url_ctor, ZEND_ACC_PUBLIC )
	PHP_ME( NativeURLInTextProcessor, next_url, arginfo_wp_native_void, ZEND_ACC_PUBLIC )
	PHP_ME( NativeURLInTextProcessor, get_raw_url, arginfo_wp_native_void, ZEND_ACC_PUBLIC )
	PHP_ME( NativeURLInTextProcessor, had_protocol, arginfo_wp_native_void, ZEND_ACC_PUBLIC )
	PHP_FE_END
};

static const zend_function_entry wp_native_apis_functions[] = {
	PHP_FE( wp_native_apis_extension_version, arginfo_wp_native_void )
	PHP_FE( wp_native_apis_rewrite_text_url_bases, arginfo_wp_native_rewrite_text_url_bases )
	PHP_FE_END
};

PHP_MINIT_FUNCTION( wp_native_apis ) {
	zend_class_entry class_entry;

	memcpy( &wp_native_smoke_object_handlers, &std_object_handlers, sizeof( zend_object_handlers ) );
	wp_native_smoke_object_handlers.offset    = XtOffsetOf( wp_native_smoke_object, std );
	wp_native_smoke_object_handlers.free_obj  = wp_native_smoke_free_object;
	wp_native_smoke_object_handlers.clone_obj = NULL;

	INIT_CLASS_ENTRY( class_entry, "WP_HTML_Native_Tag_Processor", wp_native_html_tag_processor_methods );
	wp_native_html_tag_processor_ce                = zend_register_internal_class_with_flags( &class_entry, NULL, 0 );
	wp_native_html_tag_processor_ce->create_object = wp_native_smoke_create_object;

	INIT_CLASS_ENTRY( class_entry, "WP_HTML_Native_Processor", wp_native_html_processor_methods );
	wp_native_html_processor_ce                = zend_register_internal_class_with_flags( &class_entry, NULL, 0 );
	wp_native_html_processor_ce->create_object = wp_native_smoke_create_object;

	INIT_NS_CLASS_ENTRY( class_entry, "WordPress\\XML", "NativeXMLProcessor", wp_native_xml_processor_methods );
	wp_native_xml_processor_ce                = zend_register_internal_class_with_flags( &class_entry, NULL, 0 );
	wp_native_xml_processor_ce->create_object = wp_native_smoke_create_object;

	INIT_NS_CLASS_ENTRY( class_entry, "WordPress\\DataLiberation\\URL", "NativeURLInTextProcessor", wp_native_url_processor_methods );
	wp_native_url_processor_ce                = zend_register_internal_class_with_flags( &class_entry, NULL, 0 );
	wp_native_url_processor_ce->create_object = wp_native_smoke_create_object;

	return SUCCESS;
}

PHP_MINFO_FUNCTION( wp_native_apis ) {
	php_info_print_table_start();
	php_info_print_table_row( 2, "wp_native_apis", "enabled" );
	php_info_print_table_row( 2, "playground", "enabled" );
	php_info_print_table_end();
}

zend_module_entry wp_native_apis_module_entry = {
	STANDARD_MODULE_HEADER,
	"wp_native_apis",
	wp_native_apis_functions,
	PHP_MINIT( wp_native_apis ),
	NULL,
	NULL,
	NULL,
	PHP_MINFO( wp_native_apis ),
	PHP_WP_NATIVE_APIS_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_WP_NATIVE_APIS
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE( wp_native_apis )
#endif
