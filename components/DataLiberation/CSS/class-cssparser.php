<?php

namespace WordPress\DataLiberation\CSS;

/**
 * Parses CSS into a structured data tree according to CSS Syntax Level 3.
 *
 * This parser builds on top of CSSProcessor's tokenizer to construct a complete
 * parse tree representing stylesheets, rules, declarations, and component values.
 *
 * ## Parse Tree Structure
 *
 * The parser produces a tree of nodes:
 * - **Stylesheet**: Contains a list of rules (at-rules and qualified rules)
 * - **At-rule**: Has a name (e.g., "media"), prelude, and optional block
 * - **Qualified rule**: Has a prelude (selector) and a block (declarations)
 * - **Declaration**: Has a name (property), value, and important flag
 * - **Simple block**: A block enclosed by {}, [], or () containing component values
 * - **Function**: A function call with name and arguments
 * - **Component value**: A preserved token, function, or simple block
 *
 * ## Usage
 *
 * Basic parsing:
 *
 * ```php
 * $css = 'body { color: red; } @media (max-width: 600px) { .item { display: none; } }';
 * $parser = CSSParser::create( $css );
 * $stylesheet = $parser->parse_stylesheet();
 *
 * foreach ( $stylesheet->rules as $rule ) {
 *     if ( $rule instanceof CSS_At_Rule ) {
 *         echo "At-rule: @" . $rule->name . "\n";
 *     } elseif ( $rule instanceof CSS_Qualified_Rule ) {
 *         echo "Qualified rule with " . count( $rule->block->values ) . " declarations\n";
 *     }
 * }
 * ```
 *
 * @see https://www.w3.org/TR/css-syntax-3/#parsing
 */
class CSSParser {
	/**
	 * The CSS processor (tokenizer) instance.
	 *
	 * @var CSSProcessor
	 */
	private $processor;

	/**
	 * Buffered token data for lookahead.
	 *
	 * @var array|null
	 */
	private $current_token = null;

	/**
	 * Whether we've reached the end of the token stream.
	 *
	 * @var bool
	 */
	private $at_eof = false;

	/**
	 * Constructor for the CSS parser.
	 *
	 * Do not instantiate directly. Use CSSParser::create() instead.
	 *
	 * @param CSSProcessor $processor CSS processor instance.
	 */
	private function __construct( CSSProcessor $processor ) {
		$this->processor = $processor;
		$this->consume_next_token();
	}

	/**
	 * Creates a CSS parser for the given CSS string.
	 *
	 * @param string $css      CSS source to parse.
	 * @param string $encoding Text encoding of the document; must be 'UTF-8'.
	 * @return static|null The created parser if successful, otherwise null.
	 */
	public static function create( string $css, string $encoding = 'UTF-8' ) {
		$processor = CSSProcessor::create( $css, $encoding );
		if ( null === $processor ) {
			return null;
		}
		return new static( $processor );
	}

	/**
	 * Parse a stylesheet.
	 *
	 * This is the main entry point for parsing a complete CSS stylesheet.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#parse-stylesheet
	 * @return CSS_Stylesheet
	 */
	public function parse_stylesheet(): CSS_Stylesheet {
		$stylesheet       = new CSS_Stylesheet();
		$stylesheet->rules = $this->consume_list_of_rules( true );
		return $stylesheet;
	}

	/**
	 * Consume a list of rules.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-list-of-rules
	 * @param bool $top_level Whether this is a top-level rule list.
	 * @return array List of CSS_At_Rule and CSS_Qualified_Rule objects.
	 */
	private function consume_list_of_rules( bool $top_level ): array {
		$rules = array();

		while ( ! $this->at_eof ) {
			$token_type = $this->current_token['type'];

			// Whitespace - do nothing.
			if ( CSSProcessor::TOKEN_WHITESPACE === $token_type ) {
				$this->consume_next_token();
				continue;
			}

			// EOF - done.
			if ( null === $token_type ) {
				break;
			}

			// Right brace - stop if we're in a block context (not top level).
			if ( ! $top_level && CSSProcessor::TOKEN_RIGHT_BRACE === $token_type ) {
				break;
			}

			// At-keyword token.
			if ( CSSProcessor::TOKEN_AT_KEYWORD === $token_type ) {
				$rule = $this->consume_at_rule();
				if ( null !== $rule ) {
					$rules[] = $rule;
				}
				continue;
			}

			// CDO (<!--) and CDC (-->) tokens - only valid at top level.
			if ( $top_level && ( CSSProcessor::TOKEN_CDO === $token_type || CSSProcessor::TOKEN_CDC === $token_type ) ) {
				$this->consume_next_token();
				continue;
			}

			// Qualified rule.
			$rule = $this->consume_qualified_rule();
			if ( null !== $rule ) {
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	/**
	 * Consume an at-rule.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-at-rule
	 * @return CSS_At_Rule|null
	 */
	private function consume_at_rule(): ?CSS_At_Rule {
		// Current token must be an at-keyword token.
		if ( CSSProcessor::TOKEN_AT_KEYWORD !== $this->current_token['type'] ) {
			return null;
		}

		$at_rule          = new CSS_At_Rule();
		$at_rule->name    = $this->current_token['value'];
		$at_rule->prelude = array();

		$this->consume_next_token();

		// Consume prelude until semicolon, {, or EOF.
		while ( ! $this->at_eof ) {
			$token_type = $this->current_token['type'];

			if ( CSSProcessor::TOKEN_SEMICOLON === $token_type ) {
				$this->consume_next_token();
				return $at_rule;
			}

			if ( CSSProcessor::TOKEN_LEFT_BRACE === $token_type ) {
				// Determine if this at-rule's block contains rules or declarations.
				// Standard CSS: @media, @supports, @container, @layer → rules
				// Standard CSS: @font-face, @keyframes, @page → declarations
				// Custom theme.json: @elements, @blocks → rules
				// Custom theme.json: @color, @font-size, @font-family → declarations
				$rules_at_rules = array( 'media', 'supports', 'container', 'layer', 'elements', 'blocks' );

				if ( in_array( strtolower( $at_rule->name ), $rules_at_rules, true ) ) {
					$at_rule->block = $this->consume_block_with_rules();
				} else {
					$at_rule->block = $this->consume_block_with_declarations();
				}
				return $at_rule;
			}

			$component_value = $this->consume_component_value();
			if ( null !== $component_value ) {
				$at_rule->prelude[] = $component_value;
			}
		}

		// Reached EOF.
		return $at_rule;
	}

	/**
	 * Consume a qualified rule.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-qualified-rule
	 * @return CSS_Qualified_Rule|null
	 */
	private function consume_qualified_rule(): ?CSS_Qualified_Rule {
		$rule          = new CSS_Qualified_Rule();
		$rule->prelude = array();

		// Consume prelude until { or EOF.
		while ( ! $this->at_eof ) {
			$token_type = $this->current_token['type'];

			if ( CSSProcessor::TOKEN_LEFT_BRACE === $token_type ) {
				$rule->block = $this->consume_block_with_declarations();
				return $rule;
			}

			$component_value = $this->consume_component_value();
			if ( null !== $component_value ) {
				$rule->prelude[] = $component_value;
			}
		}

		// Parse error: EOF without block.
		return null;
	}

	/**
	 * Consume a simple block.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-simple-block
	 * @return CSS_Simple_Block|null
	 */
	private function consume_simple_block(): ?CSS_Simple_Block {
		$opening_token = $this->current_token['type'];

		// Determine the ending token.
		$ending_token_map = array(
			CSSProcessor::TOKEN_LEFT_BRACE   => CSSProcessor::TOKEN_RIGHT_BRACE,
			CSSProcessor::TOKEN_LEFT_BRACKET => CSSProcessor::TOKEN_RIGHT_BRACKET,
			CSSProcessor::TOKEN_LEFT_PAREN   => CSSProcessor::TOKEN_RIGHT_PAREN,
		);

		if ( ! isset( $ending_token_map[ $opening_token ] ) ) {
			return null;
		}

		$ending_token = $ending_token_map[ $opening_token ];

		$block                   = new CSS_Simple_Block();
		$block->associated_token = $opening_token;
		$block->values           = array();

		$this->consume_next_token();

		// Consume component values until ending token or EOF.
		while ( ! $this->at_eof ) {
			$token_type = $this->current_token['type'];

			if ( $ending_token === $token_type ) {
				$this->consume_next_token();
				return $block;
			}

			$component_value = $this->consume_component_value();
			if ( null !== $component_value ) {
				$block->values[] = $component_value;
			}
		}

		// Parse error: EOF without closing token.
		return $block;
	}

	/**
	 * Consume a block that contains rules (for at-rules like @media, @supports).
	 *
	 * @return CSS_Simple_Block|null
	 */
	private function consume_block_with_rules(): ?CSS_Simple_Block {
		$opening_token = $this->current_token['type'];

		if ( CSSProcessor::TOKEN_LEFT_BRACE !== $opening_token ) {
			return null;
		}

		$block                   = new CSS_Simple_Block();
		$block->associated_token = $opening_token;
		$block->rules            = array();

		$this->consume_next_token();

		// Consume rules until closing brace or EOF.
		$block->rules = $this->consume_list_of_rules( false );

		// Consume the closing brace if present.
		if ( CSSProcessor::TOKEN_RIGHT_BRACE === $this->current_token['type'] ) {
			$this->consume_next_token();
		}

		return $block;
	}

	/**
	 * Consume a block that contains declarations (for qualified rules).
	 *
	 * @return CSS_Simple_Block|null
	 */
	private function consume_block_with_declarations(): ?CSS_Simple_Block {
		$opening_token = $this->current_token['type'];

		if ( CSSProcessor::TOKEN_LEFT_BRACE !== $opening_token ) {
			return null;
		}

		$block                   = new CSS_Simple_Block();
		$block->associated_token = $opening_token;
		$block->declarations     = array();

		$this->consume_next_token();

		// Consume declarations until closing brace or EOF.
		$block->declarations = $this->consume_list_of_declarations();

		// Consume the closing brace if present.
		if ( CSSProcessor::TOKEN_RIGHT_BRACE === $this->current_token['type'] ) {
			$this->consume_next_token();
		}

		return $block;
	}

	/**
	 * Consume a function.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-function
	 * @return CSS_Function
	 */
	private function consume_function(): CSS_Function {
		$function         = new CSS_Function();
		$function->name   = $this->current_token['value'];
		$function->values = array();

		$this->consume_next_token();

		// Consume component values until ) or EOF.
		while ( ! $this->at_eof ) {
			$token_type = $this->current_token['type'];

			if ( CSSProcessor::TOKEN_RIGHT_PAREN === $token_type ) {
				$this->consume_next_token();
				return $function;
			}

			$component_value = $this->consume_component_value();
			if ( null !== $component_value ) {
				$function->values[] = $component_value;
			}
		}

		// Parse error: EOF without closing parenthesis.
		return $function;
	}

	/**
	 * Consume a component value.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-component-value
	 * @return CSS_Component_Value|null
	 */
	private function consume_component_value(): ?CSS_Component_Value {
		$token_type = $this->current_token['type'];

		// {, [, ( start a simple block.
		if (
			CSSProcessor::TOKEN_LEFT_BRACE === $token_type ||
			CSSProcessor::TOKEN_LEFT_BRACKET === $token_type ||
			CSSProcessor::TOKEN_LEFT_PAREN === $token_type
		) {
			return new CSS_Component_Value( $this->consume_simple_block() );
		}

		// Function token.
		if ( CSSProcessor::TOKEN_FUNCTION === $token_type ) {
			return new CSS_Component_Value( $this->consume_function() );
		}

		// Anything else - preserved token.
		$component = new CSS_Component_Value( $this->current_token );
		$this->consume_next_token();
		return $component;
	}

	/**
	 * Consume a declaration.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-declaration
	 * @return CSS_Declaration|null
	 */
	private function consume_declaration(): ?CSS_Declaration {
		// Current token must be an ident token.
		if ( CSSProcessor::TOKEN_IDENT !== $this->current_token['type'] ) {
			return null;
		}

		$declaration             = new CSS_Declaration();
		$declaration->name       = $this->current_token['value'];
		$declaration->value      = array();
		$declaration->important  = false;

		$this->consume_next_token();

		// Skip whitespace.
		while ( CSSProcessor::TOKEN_WHITESPACE === $this->current_token['type'] ) {
			$this->consume_next_token();
		}

		// Next token must be a colon.
		if ( CSSProcessor::TOKEN_COLON !== $this->current_token['type'] ) {
			// Parse error.
			return null;
		}

		$this->consume_next_token();

		// Skip whitespace.
		while ( CSSProcessor::TOKEN_WHITESPACE === $this->current_token['type'] ) {
			$this->consume_next_token();
		}

		// Consume component values until ; or EOF.
		while ( ! $this->at_eof ) {
			$token_type = $this->current_token['type'];

			// Stop at semicolon or end of block.
			if (
				CSSProcessor::TOKEN_SEMICOLON === $token_type ||
				CSSProcessor::TOKEN_RIGHT_BRACE === $token_type
			) {
				break;
			}

			$component_value = $this->consume_component_value();
			if ( null !== $component_value ) {
				$declaration->value[] = $component_value;
			}
		}

		// Check for !important flag.
		$this->check_and_set_important_flag( $declaration );

		return $declaration;
	}

	/**
	 * Check if the last two component values are "!" and "important" and set the flag.
	 *
	 * @param CSS_Declaration $declaration The declaration to check.
	 */
	private function check_and_set_important_flag( CSS_Declaration $declaration ): void {
		$value_count = count( $declaration->value );

		// Need at least 2 values to have !important.
		if ( $value_count < 2 ) {
			return;
		}

		// Remove trailing whitespace.
		while ( $value_count > 0 ) {
			$last_value = $declaration->value[ $value_count - 1 ];
			if ( is_array( $last_value->value ) &&
				CSSProcessor::TOKEN_WHITESPACE === $last_value->value['type'] ) {
				array_pop( $declaration->value );
				$value_count--;
			} else {
				break;
			}
		}

		// Need at least 2 values after removing whitespace.
		if ( $value_count < 2 ) {
			return;
		}

		$last_value       = $declaration->value[ $value_count - 1 ];
		$second_last_value = $declaration->value[ $value_count - 2 ];

		// Check if last value is "important" ident and second last is "!" delim.
		if (
			is_array( $last_value->value ) &&
			CSSProcessor::TOKEN_IDENT === $last_value->value['type'] &&
			0 === strcasecmp( $last_value->value['value'], 'important' ) &&
			is_array( $second_last_value->value ) &&
			CSSProcessor::TOKEN_DELIM === $second_last_value->value['type'] &&
			'!' === $second_last_value->value['raw']
		) {
			$declaration->important = true;
			// Remove the !important tokens from the value.
			array_pop( $declaration->value );
			array_pop( $declaration->value );
		}
	}

	/**
	 * Consume a list of declarations.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-list-of-declarations
	 * @return array List of CSS_Declaration objects.
	 */
	public function consume_list_of_declarations(): array {
		$declarations = array();

		while ( ! $this->at_eof ) {
			$token_type = $this->current_token['type'];

			// Whitespace and semicolons - do nothing.
			if (
				CSSProcessor::TOKEN_WHITESPACE === $token_type ||
				CSSProcessor::TOKEN_SEMICOLON === $token_type
			) {
				$this->consume_next_token();
				continue;
			}

			// EOF or end of block.
			if (
				null === $token_type ||
				CSSProcessor::TOKEN_RIGHT_BRACE === $token_type
			) {
				break;
			}

			// At-keyword - parse error, consume until semicolon.
			if ( CSSProcessor::TOKEN_AT_KEYWORD === $token_type ) {
				$this->skip_until_semicolon_or_block_end();
				continue;
			}

			// Ident - try to consume declaration.
			if ( CSSProcessor::TOKEN_IDENT === $token_type ) {
				$declaration = $this->consume_declaration();
				if ( null !== $declaration ) {
					$declarations[] = $declaration;
				} else {
					// Parse error - skip until semicolon.
					$this->skip_until_semicolon_or_block_end();
				}
				continue;
			}

			// Anything else - parse error, skip until semicolon.
			$this->skip_until_semicolon_or_block_end();
		}

		return $declarations;
	}

	/**
	 * Skip tokens until semicolon or end of block.
	 */
	private function skip_until_semicolon_or_block_end(): void {
		while ( ! $this->at_eof ) {
			$token_type = $this->current_token['type'];

			if (
				CSSProcessor::TOKEN_SEMICOLON === $token_type ||
				CSSProcessor::TOKEN_RIGHT_BRACE === $token_type ||
				null === $token_type
			) {
				if ( CSSProcessor::TOKEN_SEMICOLON === $token_type ) {
					$this->consume_next_token();
				}
				return;
			}

			$this->consume_next_token();
		}
	}

	/**
	 * Consume the next token from the processor.
	 */
	private function consume_next_token(): void {
		if ( $this->processor->next_token() ) {
			$this->current_token = array(
				'type'  => $this->processor->get_token_type(),
				'value' => $this->processor->get_token_value(),
				'raw'   => $this->processor->get_unnormalized_token(),
			);
			$this->at_eof        = false;
		} else {
			$this->current_token = array( 'type' => null, 'value' => null, 'raw' => null );
			$this->at_eof        = true;
		}
	}
}

/**
 * Represents a CSS stylesheet.
 */
class CSS_Stylesheet {
	/**
	 * List of rules in the stylesheet.
	 *
	 * @var array
	 */
	public $rules = array();
}

/**
 * Represents a CSS at-rule (e.g., @media, @import).
 */
class CSS_At_Rule {
	/**
	 * The at-rule name (e.g., "media", "import").
	 *
	 * @var string
	 */
	public $name;

	/**
	 * The prelude (component values before the block or semicolon).
	 *
	 * @var array
	 */
	public $prelude = array();

	/**
	 * The block (if present), or null for simple at-rules.
	 *
	 * @var CSS_Simple_Block|null
	 */
	public $block = null;
}

/**
 * Represents a CSS qualified rule (selector + declaration block).
 */
class CSS_Qualified_Rule {
	/**
	 * The prelude (typically selectors).
	 *
	 * @var array
	 */
	public $prelude = array();

	/**
	 * The declaration block.
	 *
	 * @var CSS_Simple_Block
	 */
	public $block;
}

/**
 * Represents a CSS declaration (property: value pair).
 */
class CSS_Declaration {
	/**
	 * The property name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * The property value (list of component values).
	 *
	 * @var array
	 */
	public $value = array();

	/**
	 * Whether the declaration has the !important flag.
	 *
	 * @var bool
	 */
	public $important = false;
}

/**
 * Represents a simple block enclosed by {}, [], or ().
 *
 * A block can contain:
 * - Component values (for generic blocks like [] or ())
 * - Rules (for at-rule blocks like @media, @supports)
 * - Declarations (for qualified rule blocks)
 */
class CSS_Simple_Block {
	/**
	 * The token that opened this block (e.g., TOKEN_LEFT_BRACE).
	 *
	 * @var string
	 */
	public $associated_token;

	/**
	 * The component values inside the block (for generic blocks).
	 *
	 * @var array
	 */
	public $values = array();

	/**
	 * The rules inside the block (for at-rule blocks).
	 *
	 * @var array
	 */
	public $rules = array();

	/**
	 * The declarations inside the block (for qualified rule blocks).
	 *
	 * @var array
	 */
	public $declarations = array();
}

/**
 * Represents a CSS function (e.g., url(), rgb()).
 */
class CSS_Function {
	/**
	 * The function name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * The function arguments (list of component values).
	 *
	 * @var array
	 */
	public $values = array();
}

/**
 * Represents a component value (preserved token, function, or simple block).
 */
class CSS_Component_Value {
	/**
	 * The value (either a token array, CSS_Function, or CSS_Simple_Block).
	 *
	 * @var array|CSS_Function|CSS_Simple_Block
	 */
	public $value;

	/**
	 * Constructor.
	 *
	 * @param array|CSS_Function|CSS_Simple_Block $value The value.
	 */
	public function __construct( $value ) {
		$this->value = $value;
	}
}

/**
 * Type alias for preserved tokens.
 */
class CSS_Token extends CSS_Component_Value {}
