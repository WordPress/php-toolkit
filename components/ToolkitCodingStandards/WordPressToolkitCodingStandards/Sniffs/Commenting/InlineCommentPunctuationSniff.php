<?php declare(strict_types = 1);

namespace WordPressToolkitCodingStandards\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Ensures inline comments end with a full-stop, exclamation mark, or question mark.
 *
 * Inline comments include single-line comments using // or # and single-line block comments (/* ... *\/).
 * DocBlocks are excluded by only registering T_COMMENT tokens.
 */
class InlineCommentPunctuationSniff implements Sniff
{
    public const CODE_MISSING_PUNCTUATION = 'InlineCommentMissingPunctuation';

    /**
     * @return array<int, (int|string)>
     */
    public function register(): array
    {
        return [T_COMMENT];
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     * @param int $stackPtr
     */
    public function process(File $phpcs_file, $stack_ptr): void
    {
        $tokens = $phpcs_file->getTokens();
        $content = $tokens[$stack_ptr]['content'];

        // Ignore multi-line block comments; focus on inline comments only.
        if (substr_count($content, "\n") > 1) {
            return;
        }

        $trimmed = ltrim($content);

        // Only consider //, #, or single-line /* ... */ block comments.
        $is_double_slash = strncmp($trimmed, '//', 2) === 0;
        $is_hash = !$is_double_slash && strncmp($trimmed, '#', 1) === 0;
        $is_single_line_block = !$is_double_slash && !$is_hash && strncmp($trimmed, '/*', 2) === 0 && substr(rtrim($trimmed), -2) === '*/';
        if (!$is_double_slash && !$is_hash && !$is_single_line_block) {
            return;
        }

        // Skip PHPCS directives and common tool directives.
        $lower = strtolower($content);
        if (strpos($lower, 'phpcs:') !== false || strpos($lower, 'codingstandards') !== false) {
            return;
        }

        // Skip comments that are just the markers with no text.
        $comment_text = $this->extractCommentText($content);
        if ($comment_text === '') {
            return;
        }

        // Skip obviously URL-only comments.
        if (stripos($comment_text, 'http://') !== false || stripos($comment_text, 'https://') !== false) {
            return;
        }

        // Determine the last non-whitespace character which should be punctuation.
        $last_char = $this->getLastNonWhitespaceChar($comment_text);
        if ($last_char === null) {
            return; // Only whitespace; ignore.
        }

        if ($last_char === '.' || $last_char === '!' || $last_char === '?') {
            return; // Already properly punctuated.
        }

        $fix = $phpcs_file->addFixableError(
            'Inline comments must end in full-stops, exclamation marks, or question marks.',
            $stack_ptr,
            self::CODE_MISSING_PUNCTUATION
        );

        if ($fix === true) {
            $phpcs_file->fixer->beginChangeset();
            $phpcs_file->fixer->replaceToken($stack_ptr, $this->appendPunctuation($content));
            $phpcs_file->fixer->endChangeset();
        }
    }

    private function extractCommentText(string $content): string
    {
        $trimmed = ltrim($content);
        if (strncmp($trimmed, '//', 2) === 0) {
            return ltrim(substr($trimmed, 2));
        }
        if (strncmp($trimmed, '#', 1) === 0) {
            return ltrim(substr($trimmed, 1));
        }
        if (strncmp($trimmed, '/*', 2) === 0) {
            $inner = trim($trimmed);
            if (substr($inner, -2) === '*/') {
                $inner = substr($inner, 2, -2);
            }
            return trim($inner);
        }
        return '';
    }

    private function getLastNonWhitespaceChar(string $text): ?string
    {
        $len = strlen($text);
        for ($i = $len - 1; $i >= 0; $i--) {
            $ch = $text[$i];
            if ($ch !== ' ' && $ch !== "\t" && $ch !== "\n" && $ch !== "\r") {
                return $ch;
            }
        }
        return null;
    }

    private function appendPunctuation(string $content): string
    {
        $trim_right = rtrim($content);

        // Handle single-line block comment: place punctuation before closing */
        if (substr($trim_right, -2) === '*/') {
            $before = rtrim(substr($trim_right, 0, -2));
            // If nothing to punctuate, return as-is.
            if ($before === '' || $this->endsWithPunctuation($before)) {
                return $content;
            }
            $new = $before . '.' . ' */';
            // Preserve original trailing whitespace (if any).
            return $new . substr($content, strlen($trim_right));
        }

        // For // or # comments: insert punctuation before trailing whitespace/newline.
        if ($this->endsWithPunctuation($trim_right)) {
            return $content;
        }
        return $trim_right . '.' . substr($content, strlen($trim_right));
    }

    private function endsWithPunctuation(string $text): bool
    {
        $last = $this->getLastNonWhitespaceChar($text);
        return $last === '.' || $last === '!' || $last === '?';
    }
}

