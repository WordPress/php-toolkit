<?php declare(strict_types = 1);

namespace WordPressToolkitCodingStandards\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\TokenHelper;
use SlevomatCodingStandard\Helpers\YodaHelper;
use function array_keys;
use function count;
use const T_EQUAL;
use const T_IS_EQUAL;
use const T_IS_IDENTICAL;
use const T_IS_NOT_EQUAL;
use const T_IS_NOT_IDENTICAL;

/**
 * Bigger value must be on the left side:
 *
 * ($variable, Foo::$class, Foo::bar(), foo())
 *  > (Foo::BAR, BAR)
 *  > (true, false, null, 1, 1.0, arrays, 'foo')
 */
class EnforceYodaComparisonSniff implements Sniff {


	public const CODE_DISALLOWED_YODA_COMPARISON = 'DisallowedYodaComparison';

	/**
	 * @return array<int, (int|string)>
	 */
	public function register(): array {
		return array(
			T_IS_IDENTICAL,
			T_IS_NOT_IDENTICAL,
			T_IS_EQUAL,
			T_IS_NOT_EQUAL,
		);
	}

	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 * @param File $phpcs_file The file being processed.
	 * @param int  $comparison_token_pointer The position of the comparison token.
	 */
	public function process( File $phpcs_file, $comparison_token_pointer ): void {
		$tokens            = $phpcs_file->getTokens();
		$left_side_tokens  = YodaHelper::getRightSideTokens( $tokens, $comparison_token_pointer );
		$right_side_tokens = YodaHelper::getLeftSideTokens( $tokens, $comparison_token_pointer );
		$left_dynamism     = YodaHelper::getDynamismForTokens( $tokens, $left_side_tokens );
		$right_dynamism    = YodaHelper::getDynamismForTokens( $tokens, $right_side_tokens );

		if ( null === $left_dynamism || null === $right_dynamism ) {
			return;
		}

		if ( $left_dynamism >= $right_dynamism ) {
			return;
		}

		if ( $left_dynamism >= 900 && $right_dynamism >= 900 ) {
			return;
		}

		$error_parameters = array(
			'Yoda comparisons are required.',
			$comparison_token_pointer,
			self::CODE_DISALLOWED_YODA_COMPARISON,
		);

		$last_right_side_token_pointer = array_keys( $right_side_tokens )[ count( $right_side_tokens ) - 1 ];

		$next_pointer = TokenHelper::findNextEffective( $phpcs_file, $last_right_side_token_pointer + 1 );
		if ( T_EQUAL === $tokens[ $next_pointer ]['code'] ) {
			$phpcs_file->addError( ...$error_parameters );
			return;
		}

		$fix = $phpcs_file->addFixableError( ...$error_parameters );
		if ( ! $fix ) {
			return;
		}

		YodaHelper::fix( $phpcs_file, $left_side_tokens, $right_side_tokens );
	}
}
