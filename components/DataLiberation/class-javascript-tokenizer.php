<?php

namespace WordPress\DataLiberation;

/**
 * First stab at tokenizing JavaScript according to the language specification at:
 *
 * https://tc39.es/ecma262/#sec-unicode-format-control-characters
 * 
 * It parses the source into subatomic particles, that is tokens that are more fine-grained
 * than you might expect. Consider this fragment:
 * 
 *     const \u{0061}mazing = 'yes';
 * 
 * A more advanced tokenizer could yield the following non-whitespace tokens:
 * 
 *     `const`, `amazing`, `=`, `'yes'`
 * 
 * This tokenizer, however, will yield the following non-whitespace tokens:
 * 
 *     `const`, `\u`, `{`, `0061`, `}`, `mazing`, `=`, `'yes'`
 * 
 * There are a few shortcomings as well:
 * 
 * * Line separator and paragraph separator are not recognized as line terminators.
 * * ... more to discover :-)...
 */
class JavaScriptTokenizer {

	const T_SINGLE_LINE_COMMENT = 'SingleLineComment';
	const T_MULTI_LINE_COMMENT = 'MultiLineComment';
	const T_REGEX_LITERAL = 'RegularExpressionLiteral';
	const T_STRING_LITERAL = 'StringLiteral';
	const T_NO_SUBST_TEMPLATE = 'NoSubstitutionTemplate';
	const T_TEMPLATE_HEAD = 'TemplateHead';
	const T_TEMPLATE_MIDDLE = 'TemplateMiddle';
	const T_TEMPLATE_TAIL = 'TemplateTail';
	const T_NUMERIC_LITERAL = 'NumericLiteral';
	const T_PUNCTUATOR = 'Punctuator';
	const T_IDENTIFIER_NAME = 'IdentifierName';
	const T_PRIVATE_IDENTIFIER = 'PrivateIdentifier';

	// Whitespace chars: space, tab, LF, CR.
	const WS = " \t\n\r";

	// Characters that start "punctuators" or terminate identifiers.
	const NON_IDENT = " \t\r\n!%^&*()-=+[]{}|;:~,./<>?`\"'";

	private $keywords = array(
		'await' => true,
		'case' => true,
		'delete' => true,
		'in' => true,
		'instanceof' => true,
		'return' => true,
		'throw' => true,
		'typeof' => true,
		'void' => true,
		'yield' => true,
		'!' => true,
		'!==' => true,
		'%' => true,
		'%=' => true,
		'^' => true,
		'^=' => true,
		'&' => true,
		'&=' => true,
		'&&' => true,
		'&&=' => true,
		'*' => true,
		'*=' => true,
		'**' => true,
		'**=' => true,
		'(' => true,
		'-' => true,
		'-=' => true,
		'=' => true,
		'==' => true,
		'===' => true,
		'=>' => true,
		'+' => true,
		'+=' => true,
		'[' => true,
		'{' => true,
		'}' => true,
		'|' => true,
		'|=' => true,
		'||' => true,
		'||=' => true,
		';' => true,
		':' => true,
		'~' => true,
		',' => true,
		'...' => true,
		'/' => true,
		'/=' => true,
		'<' => true,
		'<=' => true,
		'<<' => true,
		'<<=' => true,
		'>' => true,
		'>=' => true,
		'>>' => true,
		'>>=' => true,
		'>>>' => true,
		'>>>=' => true,
		'?' => true,
		'??' => true,
		'??=' => true,
		self::T_TEMPLATE_HEAD => true,
		self::T_TEMPLATE_MIDDLE => true
	);

	/** @var string */
	private $js = '';

	/** @var int */
	private $bytes_parsed_so_far = 0;

	/** @var int */
	private $level = 1;

	/** @var int */
	private $levels = 0;

	/** @var string */
	private $last_token = ';';

	/** @var string|null */
	private $token_type = null;

	/** @var int|null */
	private $token_start = null;

	/** @var int|null */
	private $token_end = null;

	public function __construct( string $code ) {
		$this->js = $code;
	}

	/** Returns true if a token was read, false on EOF. */
	public function next_token() {
		// Skip whitespace.
		$this->bytes_parsed_so_far += strspn( $this->js, self::WS, $this->bytes_parsed_so_far );

		if ( $this->bytes_parsed_so_far >= strlen( $this->js ) ) {
			$this->token_type = '';

			return false;
		}

		$byte = $this->js[ $this->bytes_parsed_so_far ];

		// Comments and possible regex literal start with '/'.
		if ( '/' === $byte ) {
			$next = ( $this->bytes_parsed_so_far + 1 < strlen( $this->js ) ) ? $this->js[ $this->bytes_parsed_so_far + 1 ] : '';

			if ( '/' === $next ) {
				$this->token_start = $this->bytes_parsed_so_far;
				// Consume the next line of text
				$this->bytes_parsed_so_far += strcspn( $this->js, "\r\n", $this->bytes_parsed_so_far );
				// Line separator and paragraph separator are not supported as line terminators at the moment.
				
				$this->token_type  = self::T_SINGLE_LINE_COMMENT;
				$this->token_end   = $this->bytes_parsed_so_far;

				// Comments don't affect $last_token.
				return true;
			}

			if ( '*' === $next ) {
				$this->token_start         = $this->bytes_parsed_so_far;
				$end                       = strpos( $this->js, '*/', $this->bytes_parsed_so_far + 2 );
				$this->bytes_parsed_so_far = ( false === $end ) ? strlen( $this->js ) : $end + 2;
				$this->token_type          = self::T_MULTI_LINE_COMMENT;
				$this->token_end           = $this->bytes_parsed_so_far;

				return true;
			}

			if ( isset( $this->keywords[ $this->last_token ] ) ) {
				$this->token_start = $this->bytes_parsed_so_far;
				++ $this->bytes_parsed_so_far; // past initial '/'
				// Consume the regular expression
				while ( $this->bytes_parsed_so_far < strlen( $this->js ) ) {
					$next = strpos( $this->js, '/', $this->bytes_parsed_so_far );
					if ( false === $next ) {
						$this->bytes_parsed_so_far = strlen( $this->js );
						break;
					}
					if ( $this->is_escaped( $next ) ) {
						$this->bytes_parsed_so_far = $next + 1;
						continue;
					}
					$this->bytes_parsed_so_far = $next + 1; // past closing '/'; now flags
					break;
				}
				// @TODO: Why dgimsuy?
				$this->bytes_parsed_so_far += strspn( $this->js, 'dgimsuy', $this->bytes_parsed_so_far );
				$this->token_type = self::T_REGEX_LITERAL;
				$this->token_end  = $this->bytes_parsed_so_far;

				// Intentionally do not change $last_token (matches source behavior).
				return true;
			}
		}

		// String literals.
		if ( '\'' === $byte || '"' === $byte ) {
			$this->token_start = $this->bytes_parsed_so_far;
			++ $this->bytes_parsed_so_far; // skip opening quote
			
			// Consume the string literal
			$quote = $byte;
			while ( $this->bytes_parsed_so_far < strlen( $this->js ) ) {
				$next = strpos( $this->js, $quote, $this->bytes_parsed_so_far );
				if ( false === $next ) {
					$this->bytes_parsed_so_far = strlen( $this->js );

					break;
				}
				// If the quote is escaped, skip it and continue.
				if ( $this->is_escaped( $next ) ) {
					$this->bytes_parsed_so_far = $next + 1;
					continue;
				}
				$this->bytes_parsed_so_far = $next + 1;

				break;
			}
			
			$this->token_type  = self::T_STRING_LITERAL;
			$this->token_end   = $this->bytes_parsed_so_far;
			$this->last_token  = self::T_STRING_LITERAL;

			return true;
		}

		// Template literals.
		if ( '`' === $byte ) {
			$this->token_start = $this->bytes_parsed_so_far;
			++ $this->bytes_parsed_so_far; // skip `
			while ( $this->bytes_parsed_so_far < strlen( $this->js ) ) {
				// Fast skip to next potential stop: ` $ or \.
				$this->bytes_parsed_so_far += strcspn( $this->js, '`$\\', $this->bytes_parsed_so_far );

				if ( $this->bytes_parsed_so_far >= strlen( $this->js ) ) {
					break;
				}

				$byte = $this->js[ $this->bytes_parsed_so_far ];

				// Handle backslash escapes.
				if ( '\\' === $byte ) {
					// Skip escaped character (skip \ and next char).
					$this->bytes_parsed_so_far += ( $this->bytes_parsed_so_far + 1 < strlen( $this->js ) ) ? 2 : 1;
					continue;
				}

				// Check for backtick.
				if ( '`' === $byte ) {
					++ $this->bytes_parsed_so_far;
					$this->token_type = self::T_NO_SUBST_TEMPLATE;
					$this->token_end  = $this->bytes_parsed_so_far;
					$this->last_token = self::T_NO_SUBST_TEMPLATE;

					return true;
				}

				// Check for ${.
				if ( '$' === $byte && $this->bytes_parsed_so_far + 1 < strlen( $this->js ) && '{' === $this->js[ $this->bytes_parsed_so_far + 1 ] ) {
					++ $this->level;
					++ $this->levels;
					$this->bytes_parsed_so_far += 2; // past ${
					$this->token_type          = self::T_TEMPLATE_HEAD;
					$this->token_end           = $this->bytes_parsed_so_far;
					$this->last_token          = self::T_TEMPLATE_HEAD;

					return true;
				}

				// Just a plain $, keep scanning.
				++ $this->bytes_parsed_so_far;
			}
			// Unterminated: treat what we saw as a no-subst template anyway.
			$this->token_type = self::T_NO_SUBST_TEMPLATE;
			$this->token_end  = $this->bytes_parsed_so_far;
			$this->last_token = self::T_NO_SUBST_TEMPLATE;

			return true;
		}

		// Numbers.
		$before = $this->bytes_parsed_so_far;
		$this->consume_number();
		if ( $this->bytes_parsed_so_far !== $before ) {
			$this->token_type  = self::T_NUMERIC_LITERAL;
			$this->token_start = $before;
			$this->token_end   = $this->bytes_parsed_so_far;
			$this->last_token  = self::T_NUMERIC_LITERAL;

			return true;
		}

		// Punctuators.
		$punct = $this->parse_punctuator();
		if ( '' !== $punct ) {
			$at                     = $this->bytes_parsed_so_far;
			$this->bytes_parsed_so_far += strlen( $punct );
			$this->last_token          = $punct;

			// Template expression boundary handling after '}'.
			if ( 0 !== $this->levels ) {
				if ( '{' === $punct ) {
					++ $this->level;
				} elseif ( '}' === $punct ) {
					-- $this->level;
					if ( $this->level === $this->levels ) {
						-- $this->levels;
						$this->token_start = $this->bytes_parsed_so_far - 1;

						while ( $this->bytes_parsed_so_far < strlen( $this->js ) ) {
							// Fast skip to next potential stop: ` $ or \.
							$this->bytes_parsed_so_far += strcspn( $this->js, '`$\\', $this->bytes_parsed_so_far );

							if ( $this->bytes_parsed_so_far >= strlen( $this->js ) ) {
								break;
							}

							$ch = $this->js[ $this->bytes_parsed_so_far ];

							// Handle backslash escapes.
							if ( '\\' === $ch ) {
								// Skip escaped character (skip \ and next char).
								$this->bytes_parsed_so_far += ( $this->bytes_parsed_so_far + 1 < strlen( $this->js ) ) ? 2 : 1;
								continue;
							}

							// Check for backtick.
							if ( '`' === $ch ) {
								++ $this->bytes_parsed_so_far;
								$this->token_type = self::T_TEMPLATE_TAIL;
								$this->token_end  = $this->bytes_parsed_so_far;
								$this->last_token = self::T_TEMPLATE_TAIL;

								return true;
							}

							// Check for ${.
							if ( '$' === $ch && $this->bytes_parsed_so_far + 1 < strlen( $this->js ) && '{' === $this->js[ $this->bytes_parsed_so_far + 1 ] ) {
								++ $this->level;
								$this->bytes_parsed_so_far += 2; // ${
								$this->token_type          = self::T_TEMPLATE_MIDDLE;
								$this->token_end           = $this->bytes_parsed_so_far;
								$this->last_token          = self::T_TEMPLATE_MIDDLE;

								return true;
							}

							// Just a plain $, keep scanning.
							++ $this->bytes_parsed_so_far;
						}

						// If unterminated, emit tail from '}' to EOF.
						$this->token_type = self::T_TEMPLATE_TAIL;
						$this->token_end  = $this->bytes_parsed_so_far;
						$this->last_token = self::T_TEMPLATE_TAIL;

						return true;
					}
				}
			}

			$this->token_type  = self::T_PUNCTUATOR;
			$this->token_start = $at;
			$this->token_end   = $this->bytes_parsed_so_far;

			return true;
		}

		// Identifier / private identifier / unknown word-like chunk.
		$this->token_start = $this->bytes_parsed_so_far;
		// Fast skip until any whitespace or any punctuator-start char.
		$this->bytes_parsed_so_far += strcspn( $this->js, self::NON_IDENT, $this->bytes_parsed_so_far );
		$this->token_type = ( $this->token_start < strlen( $this->js ) && '#' === $this->js[ $this->token_start ] ) ? self::T_PRIVATE_IDENTIFIER : self::T_IDENTIFIER_NAME;
		$this->token_end  = $this->bytes_parsed_so_far;
		// Note: $last_token becomes the literal identifier text (affects regex detection).
		$this->last_token = substr( $this->js, $this->token_start, $this->token_end - $this->token_start );

		return true;
	}

	private function is_escaped( $pos ) {
		// True if an odd number of backslashes precede position $pos.
		$run = 0;
		$i   = $pos - 1;
		while ( $i >= 0 && '\\' === $this->js[ $i ] ) {
			++ $run;
			-- $i;
		}

		return ( $run & 1 ) === 1;
	}

	private function consume_number() {
		$at   = $this->bytes_parsed_so_far;
		$byte = ( $at < strlen( $this->js ) ) ? $this->js[ $at ] : '';

		if ( '0' === $byte ) {
			$n1 = ( $at + 1 < strlen( $this->js ) ) ? $this->js[ $at + 1 ] : '';
			if ( 'b' === $n1 || 'B' === $n1 ) {
				$at += 2;
				$at += strspn( $this->js, '01', $at );

				$this->bytes_parsed_so_far = $at;

				return;
			}
			if ( 'x' === $n1 || 'X' === $n1 ) {
				$at += 2;
				$at += strspn( $this->js, '0123456789abcdefABCDEF', $at );

				$this->bytes_parsed_so_far = $at;

				return;
			}
		} elseif ( '.' === $byte ) {
			++ $at;
			if ( 1 !== strspn( $this->js, '0123456789', $at, 1 ) ) {
				// Not a number; restore.
				$this->bytes_parsed_so_far = $this->bytes_parsed_so_far; // no-op, for readability

				return;
			}
		} elseif ( 1 !== strspn( $this->js, '0123456789', $at, 1 ) ) {
			return;
		}

		++ $at; // consumed first digit or leading '.'
		while ( true ) {
			$at   += strspn( $this->js, '0123456789', $at );
			$byte = ( $at < strlen( $this->js ) ) ? $this->js[ $at ] : "\0";

			if ( '_' === $byte ) {
				++ $at;
				continue;
			}

			if ( 'e' === $byte || 'E' === $byte ) {
				++ $at;
				$sign = ( $at < strlen( $this->js ) ) ? $this->js[ $at ] : "\0";
				if ( '+' === $sign || '-' === $sign ) {
					++ $at;
				}
				$at                        += strspn( $this->js, '0123456789', $at );
				$this->bytes_parsed_so_far = $at;

				return;
			}

			if ( 'n' === $byte ) { // BigInt
				++ $at;
				$this->bytes_parsed_so_far = $at;

				return;
			}

			if ( '.' === $byte ) {
				++ $at;
				continue;
			}

			$this->bytes_parsed_so_far = $at;

			return;
		}
	}

	private function parse_punctuator() {
		$at  = $this->bytes_parsed_so_far;
		$len = strlen( $this->js );
		
		if ( $at >= $len ) {
			return '';
		}

		switch ( $this->js[ $at ] ) {
			case '(':
			case ')':
			case '[':
			case ']':
			case '{':
			case '}':
			case ';':
			case ':':
			case '~':
			case ',':
				return $this->js[ $at ];
		}

		if($at + 1 >= $len) {
			return false;
		}

		switch ( $this->js[ $at ] ) {
			case '/':
				return ( '=' === $this->js[ $at + 1 ] ) ? '/=' : '/';
			case '%':
				return ( '=' === $this->js[ $at + 1 ] ) ? '%=' : '%';
			case '^':
				return ( '=' === $this->js[ $at + 1 ] ) ? '^=' : '^';
			case '!':
				if ( '=' === $this->js[ $at + 1 ] ) {
					return ( $at + 2 < $len && '=' === $this->js[ $at + 2 ] ) ? '!==' : '!=';
				}
				return '!';
			case '&':
				if ( '&' === $this->js[ $at + 1 ] ) {
					return ( $at + 2 < $len && '=' === $this->js[ $at + 2 ] ) ? '&&=' : '&&';
				}
				return ( '=' === $this->js[ $at + 1 ] ) ? '&=' : '&';
			case '*':
				if ( '*' === $this->js[ $at + 1 ] ) {
					return ( $at + 2 < $len && '=' === $this->js[ $at + 2 ] ) ? '**=' : '**';
				}
				return ( '=' === $this->js[ $at + 1 ] ) ? '*=' : '*';
			case '-':
				if ( '-' === $this->js[ $at + 1 ] ) {
					return '--';
				}
				return ( '=' === $this->js[ $at + 1 ] ) ? '-=' : '-';
			case '=':
				if ( '=' === $this->js[ $at + 1 ] ) {
					return ( $at + 2 < $len && '=' === $this->js[ $at + 2 ] ) ? '===' : '==';
				}
				return ( '>' === $this->js[ $at + 1 ] ) ? '=>' : '=';
			case '+':
				if ( '+' === $this->js[ $at + 1 ] ) {
					return '++';
				}
				return ( '=' === $this->js[ $at + 1 ] ) ? '+=' : '+';
			case '|':
				if ( '|' === $this->js[ $at + 1 ] ) {
					return ( $at + 2 < $len && '=' === $this->js[ $at + 2 ] ) ? '||=' : '||';
				}
				return ( '=' === $this->js[ $at + 1 ] ) ? '|=' : '|';
			case '.':
				if ( '.' === $this->js[ $at + 1 ] && $at + 2 < $len && '.' === $this->js[ $at + 2 ] ) {
					return '...';
				}
				return '.';
			case '<':
				if ( '<' === $this->js[ $at + 1 ] ) {
					return ( $at + 2 < $len && '=' === $this->js[ $at + 2 ] ) ? '<<=' : '<<';
				}
				return ( '=' === $this->js[ $at + 1 ] ) ? '<=' : '<';
			case '>':
				if ( '>' === $this->js[ $at + 1 ] ) {
					if ( $at + 2 < $len && '>' === $this->js[ $at + 2 ] ) {
						return ( $at + 3 < $len && '=' === $this->js[ $at + 3 ] ) ? '>>>=' : '>>>';
					}
					return ( $at + 2 < $len && '=' === $this->js[ $at + 2 ] ) ? '>>=' : '>>';
				}
				return ( '=' === $this->js[ $at + 1 ] ) ? '>=' : '>';
			case '?':
				if ( '?' === $this->js[ $at + 1 ] ) {
					return ( $at + 2 < $len && '=' === $this->js[ $at + 2 ] ) ? '??=' : '??';
				}
				if ( '.' === $this->js[ $at + 1 ] ) {
					return '?.';
				}
				return '?';
			default:
				return '';
		}
	}

	public function get_type() {
		return $this->token_type;
	}

	public function get_token_start() {
		return $this->token_start;
	}

	public function get_token_end() {
		return $this->token_end;
	}

	public function get_text() {
		return substr( $this->js, $this->token_start, $this->token_end - $this->token_start );
	}

}
