<?php declare(strict_types = 1);

namespace WordPressToolkitCodingStandards\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\FixerHelper;
use SlevomatCodingStandard\Helpers\TernaryOperatorHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;

/**
 * Disallow the short ternary (Elvis) operator `?:`.
 *
 * Using short ternaries is discouraged in the WordPress coding standards,
 * as they are often used incorrectly and can reduce readability.
 *
 * This sniff reports when a ternary `?` is immediately followed (ignoring
 * whitespace/comments) by `:`, indicating a short ternary.
 */
class DisallowShortTernarySniff implements Sniff {

	public const CODE_SHORT_TERNARY_USED = 'ShortTernaryUsed';

	/**
	 * @return array<int, (int|string)>
	 */
	public function register(): array {
		return array( T_INLINE_THEN );
	}

	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 * @param int $stackPtr
	 */
	public function process( File $phpcs_file, $stack_ptr ): void {
		$tokens = $phpcs_file->getTokens();

		// Find the next effective token after '?'.
		$next_effective = TokenHelper::findNextEffective( $phpcs_file, $stack_ptr + 1 );
		if ( null === $next_effective ) {
			return;
		}

		// If the next effective token is ':', this is a short ternary (Elvis) operator.
		if ( T_INLINE_ELSE !== $tokens[ $next_effective ]['code'] ) {
			return;
		}

		$fix = $phpcs_file->addFixableError(
			'Using short ternaries is not allowed as they are rarely used correctly.',
			$stack_ptr,
			self::CODE_SHORT_TERNARY_USED
		);

		if ( true !== $fix ) {
			return;
		}

		// Expand `cond ?: else` into `cond ? cond : else` while preserving original condition text.
		$start_ptr = TernaryOperatorHelper::getStartPointer( $phpcs_file, $stack_ptr );
		$else_ptr  = TernaryOperatorHelper::getElsePointer( $phpcs_file, $stack_ptr );

		$condition = '';
		for ( $i = $start_ptr; $i < $stack_ptr; $i++ ) {
			$condition .= $tokens[ $i ]['content'];
		}
		$condition = rtrim( $condition );

		$phpcs_file->fixer->beginChangeset();
		// Remove anything between '?' and ':' (whitespace/comments).
		FixerHelper::removeBetween( $phpcs_file, $stack_ptr, $else_ptr );
		// Insert the duplicated condition with spaces around.
		FixerHelper::add( $phpcs_file, $stack_ptr, ' ' . $condition . ' ' );
		$phpcs_file->fixer->endChangeset();
	}
}
