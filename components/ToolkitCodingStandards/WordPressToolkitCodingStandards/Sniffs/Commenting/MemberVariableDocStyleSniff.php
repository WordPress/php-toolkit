<?php declare(strict_types = 1);

namespace WordPressToolkitCodingStandards\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\DocCommentHelper;
use SlevomatCodingStandard\Helpers\PropertyHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;

/**
 * Enforce that member variable comments use DocBlock style starting with '/**'.
 *
 * Auto-fix converts the leading '/*' to '/**' when the non-Doc comment directly
 * precedes a property.
 */
class MemberVariableDocStyleSniff implements Sniff
{
    public const CODE_WRONG_STYLE = 'MemberVariableCommentWrongStyle';

    /**
     * @return array<int, (int|string)>
     */
    public function register(): array
    {
        return [T_VARIABLE];
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     * @param int $stackPtr
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // Only apply to class/trait/interface properties.
        if (!PropertyHelper::isProperty($phpcsFile, $stackPtr)) {
            return;
        }

        // Already has a DocBlock comment; nothing to do.
        if (DocCommentHelper::hasDocComment($phpcsFile, $stackPtr)) {
            return;
        }

        // 1) Handle a block comment directly above the property (/* ... */ -> /** ... */).
        $prevPtr = TokenHelper::findPreviousExcluding($phpcsFile, [T_WHITESPACE], $stackPtr - 1);
        if ($prevPtr !== null && $tokens[$prevPtr]['code'] === T_COMMENT) {
            $comment = $tokens[$prevPtr]['content'];
            $trimmed = ltrim($comment);

            if (strncmp($trimmed, '/*', 2) === 0 && strncmp($trimmed, '/**', 3) !== 0) {
                // Ensure the comment is immediately adjacent (no blank line) to the property.
                if ($tokens[$stackPtr]['line'] - $tokens[$prevPtr]['line'] <= 1) {
                    $fix = $phpcsFile->addFixableError(
                        'You must use "/**" style comments for a member variable comment',
                        $prevPtr,
                        self::CODE_WRONG_STYLE
                    );

                    if ($fix === true) {
                        $replacePos = strpos($comment, '/*');
                        if ($replacePos !== false) {
                            $fixed = substr($comment, 0, $replacePos) . '/**' . substr($comment, $replacePos + 2);
                            $phpcsFile->fixer->beginChangeset();
                            $phpcsFile->fixer->replaceToken($prevPtr, $fixed);
                            $phpcsFile->fixer->endChangeset();
                        }
                    }
                    return;
                }
            }
        }

        // 2) Handle an end-of-line comment after the property on the same line.
        $propertyLine = $tokens[$stackPtr]['line'];
        $startPtr = PropertyHelper::getStartPointer($phpcsFile, $stackPtr);
        $commentPtr = null;
        for ($i = $stackPtr + 1, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i]['line'] !== $propertyLine) {
                break;
            }
            if ($tokens[$i]['code'] === T_COMMENT) {
                $commentPtr = $i;
                break;
            }
        }

        if ($commentPtr === null) {
            return;
        }

        $commentContent = $tokens[$commentPtr]['content'];
        $trimmed = ltrim($commentContent);

        // Extract single-line comment text.
        if (strncmp($trimmed, '//', 2) === 0) {
            $text = ltrim(substr($trimmed, 2));
        } elseif (strncmp($trimmed, '#', 1) === 0) {
            $text = ltrim(substr($trimmed, 1));
        } elseif (strncmp($trimmed, '/*', 2) === 0 && substr(rtrim($trimmed), -2) === '*/') {
            $inner = trim($trimmed);
            $inner = substr($inner, 2, -2);
            $text = trim($inner);
        } else {
            // Not a single-line comment we can transform safely.
            return;
        }

        // Compute indentation from the beginning of the line where the property starts.
        $lineStartPtr = $startPtr;
        for ($i = $startPtr; $i >= 0; $i--) {
            if ($tokens[$i]['line'] < $propertyLine) {
                $lineStartPtr = $i + 1;
                break;
            }
            if ($i === 0) {
                $lineStartPtr = 0;
                break;
            }
        }

        // Build a multi-line DocBlock.
        $doc  = "/**\n";
        $doc .= " * " . $text . "\n";
        $doc .= " */\n";

        $fix = $phpcsFile->addFixableError(
            'You must use "/**" style comments for a member variable comment',
            $commentPtr,
            self::CODE_WRONG_STYLE
        );

        if ($fix !== true) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        // Insert docblock before the property declaration start.
        $phpcsFile->fixer->addContentBefore($startPtr, $doc);
        $phpcsFile->fixer->addContentBefore($startPtr-1, "\n");
        // Remove whitespace before the inline comment on the same line.
        for ($i = $commentPtr - 1; $i > 0 && $tokens[$i]['line'] === $propertyLine && $tokens[$i]['code'] === T_WHITESPACE; $i--) {
            $phpcsFile->fixer->replaceToken($i, '');
        }
        // Remove the original inline comment token.
        $phpcsFile->fixer->replaceToken($commentPtr, '');
        $phpcsFile->fixer->endChangeset();
    }
}
