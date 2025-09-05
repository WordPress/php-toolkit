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
class DisallowShortTernarySniff implements Sniff
{
    public const CODE_SHORT_TERNARY_USED = 'ShortTernaryUsed';

    /**
     * @return array<int, (int|string)>
     */
    public function register(): array
    {
        return [T_INLINE_THEN];
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     * @param int $stackPtr
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // Find the next effective token after '?'.
        $nextEffective = TokenHelper::findNextEffective($phpcsFile, $stackPtr + 1);
        if ($nextEffective === null) {
            return;
        }

        // If the next effective token is ':', this is a short ternary (Elvis) operator.
        if ($tokens[$nextEffective]['code'] !== T_INLINE_ELSE) {
            return;
        }

        $fix = $phpcsFile->addFixableError(
            'Using short ternaries is not allowed as they are rarely used correctly.',
            $stackPtr,
            self::CODE_SHORT_TERNARY_USED
        );

        if ($fix !== true) {
            return;
        }

        // Expand `cond ?: else` into `cond ? cond : else` while preserving original condition text.
        $startPtr = TernaryOperatorHelper::getStartPointer($phpcsFile, $stackPtr);
        $elsePtr = TernaryOperatorHelper::getElsePointer($phpcsFile, $stackPtr);

        $condition = '';
        for ($i = $startPtr; $i < $stackPtr; $i++) {
            $condition .= $tokens[$i]['content'];
        }
        $condition = rtrim($condition);

        $phpcsFile->fixer->beginChangeset();
        // Remove anything between '?' and ':' (whitespace/comments).
        FixerHelper::removeBetween($phpcsFile, $stackPtr, $elsePtr);
        // Insert the duplicated condition with spaces around.
        FixerHelper::add($phpcsFile, $stackPtr, ' ' . $condition . ' ');
        $phpcsFile->fixer->endChangeset();
    }
}
