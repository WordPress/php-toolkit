<?php

namespace WordPress\Blueprints\Validator;

use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\VersionStrings\PHPVersion;
use WordPress\Blueprints\VersionStrings\VersionConstraint;
use WordPress\Blueprints\VersionStrings\WordPressVersion;
use WordPress\Filesystem\Filesystem;

/**
 * Validates Blueprint configuration and the bundled `wp-content` directory structure.
 * Also provides summary helpers for explain-style commands.
 *
 * Bundle structure rules come from the WEP-1 Blueprint v2 proposal (Bundle directory structure):
 * https://github.com/Automattic/WordPress-extension-proposals/blob/trunk/wep-1-blueprint-v2-schema/proposal.md#bundle-directory-structure
 */
class BlueprintValidator {
    /** @var array */
    private $blueprintArray;

    /** @var Filesystem */
    private $fs;

    /** @var VersionConstraint|null */
    private $phpVersionConstraint;

    /** @var VersionConstraint|null */
    private $wpVersionConstraint;

    /** @var string */
    private $recommendedWpVersion = 'latest';


    public function __construct( array $blueprint, Filesystem $fs ) {
        $this->blueprintArray = $blueprint;
        $this->fs = $fs;
    }

    public function validate(): array {
        $errors = [];

        // Validate blueprint JSON and collect errors instead of throwing
        $this->validateBlueprintSchemaAndVersions($errors);

        // Validate wp-content structure
        $bundleError = $this->validateWpContent($this->fs);
        if ($bundleError) {
            $errors[] = $bundleError;
        }

        return [
            'blueprint' => self::summarizeBlueprintArray($this->blueprintArray),
            'bundle'    => $this->summarizeWpContent($this->fs),
            'errors'    => $errors,
        ];
    }

    private function validateBlueprintSchemaAndVersions(array &$errors): void {
        $this->blueprintArray = apply_filters( 'blueprint.resolved', $this->blueprintArray );

        // Schema
        $v     = new HumanFriendlySchemaValidator(
            json_decode( file_get_contents( __DIR__ . '/../Versions/Version2/json-schema/schema-v2.json' ), true )
        );
        $schemaError = $v->validate( $this->blueprintArray );
        if ( $schemaError ) {
            $errors[] = $schemaError;
            // If schema fails, follow-up validations may be noisy; still proceed best-effort
        }

        // PHP Version Constraint
        if ( isset( $this->blueprintArray['phpVersion'] ) ) {
            $min = $max = $recommended = null;

            $php_version = $this->blueprintArray['phpVersion'];
            if ( is_string( $php_version ) ) {
                $parsed_version = PHPVersion::fromString( $php_version );
                if ( ! $parsed_version ) {
                    $errors[] = new ValidationError('/phpVersion', 'invalid-php-version', 'Invalid PHP version string in phpVersion.', [ 'value' => $php_version ]);
                } else {
                    $recommended = $parsed_version;
                }
            } else {
                if ( isset( $php_version['min'] ) ) {
                    $min = PHPVersion::fromString( $php_version['min'] );
                    if ( ! $min ) {
                        $errors[] = new ValidationError('/phpVersion/min', 'invalid-php-version', 'Invalid PHP version string in phpVersion.min.', [ 'value' => $php_version['min'] ]);
                    }
                }
                if ( isset( $php_version['max'] ) ) {
                    $max = PHPVersion::fromString( $php_version['max'] );
                    if ( ! $max ) {
                        $errors[] = new ValidationError('/phpVersion/max', 'invalid-php-version', 'Invalid PHP version string in phpVersion.max.', [ 'value' => $php_version['max'] ]);
                    }
                }
                if ( isset( $php_version['recommended'] ) ) {
                    $recommended = PHPVersion::fromString( $php_version['recommended'] );
                    if ( ! $recommended ) {
                        $errors[] = new ValidationError('/phpVersion/recommended', 'invalid-php-version', 'Invalid PHP version string in phpVersion.recommended.', [ 'value' => $php_version['recommended'] ]);
                    }
                }
            }
            if ($min || $max || $recommended) {
                $this->phpVersionConstraint = new VersionConstraint( $min, $max, $recommended );
                $phpConstraintErrors        = $this->phpVersionConstraint->validate();
                if ( ! empty( $phpConstraintErrors ) ) {
                    $errors[] = new ValidationError('/phpVersion', 'invalid-php-version-constraint', 'Invalid PHP version constraint.', [ 'errors' => $phpConstraintErrors ]);
                }

                // Confirm the environment satisfies the PHP version constraint.
                $currentPhpVersion = PHPVersion::fromString( PHP_VERSION );
                if ( $currentPhpVersion && ! $this->phpVersionConstraint->satisfiedBy( $currentPhpVersion ) ) {
                    $errors[] = new ValidationError('/phpVersion', 'php-version-unsatisfied', 'PHP version requirement not satisfied for the current environment.', [ 'required' => (string)$this->phpVersionConstraint, 'current' => (string)$currentPhpVersion ]);
                }
            }
        }

        // WordPress Version Constraint
        if ( isset( $this->blueprintArray['wordpressVersion'] ) ) {
            $wp_version = $this->blueprintArray['wordpressVersion'];
            $min = $max = $recommended = null;
            if ( is_string( $wp_version ) ) {
                $this->recommendedWpVersion = $wp_version;
                $recommended = WordPressVersion::fromString( $wp_version );
                if ( false === $recommended ) {
                    $errors[] = new ValidationError('/wordpressVersion', 'invalid-wp-version', 'Invalid WordPress version string in wordpressVersion.', [ 'value' => $wp_version ]);
                }
            } else {
                if ( isset( $wp_version['min'] ) ) {
                    if ( $wp_version['min'] === 'latest' ) {
                        $errors[] = new ValidationError('/wordpressVersion/min', 'invalid-wp-version', 'wordpressVersion.min cannot be "latest". Use a specific version.', [ 'value' => $wp_version['min'] ]);
                    } else {
                        $min = WordPressVersion::fromString( $wp_version['min'] );
                        if ( ! $min ) {
                            $errors[] = new ValidationError('/wordpressVersion/min', 'invalid-wp-version', 'Invalid WordPress version string in wordpressVersion.min.', [ 'value' => $wp_version['min'] ]);
                        }
                    }
                }
                if ( isset( $wp_version['max'] ) && $wp_version['max'] !== 'latest' ) {
                    $this->recommendedWpVersion = $wp_version['max'];
                    $max = WordPressVersion::fromString( $wp_version['max'] );
                    if ( ! $max ) {
                        $errors[] = new ValidationError('/wordpressVersion/max', 'invalid-wp-version', 'Invalid WordPress version string in wordpressVersion.max.', [ 'value' => $wp_version['max'] ]);
                    }
                }
                if ( isset( $wp_version['recommended'] ) && $wp_version['recommended'] !== 'latest' ) {
                    $this->recommendedWpVersion = $wp_version['recommended'];
                    $recommended = WordPressVersion::fromString( $wp_version['recommended'] );
                    if ( false === $recommended ) {
                        $errors[] = new ValidationError('/wordpressVersion/recommended', 'invalid-wp-version', 'Invalid WordPress version string in wordpressVersion.recommended.', [ 'value' => $wp_version['recommended'] ]);
                    }
                }
            }

            if ($min || $max || $recommended) {
                $this->wpVersionConstraint = new VersionConstraint( $min, $max, $recommended );
                $wpConstraintErrors        = $this->wpVersionConstraint->validate();
                if ( ! empty( $wpConstraintErrors ) ) {
                    $errors[] = new ValidationError('/wordpressVersion', 'invalid-wp-version-constraint', 'Invalid WordPress version constraint.', [ 'errors' => $wpConstraintErrors ]);
                }
            }
        }

        // If override constraint were set (not via this class), we would validate here.
    }

    /**
     * Summarize the blueprint.json content.
     */
    public static function summarizeBlueprintArray(array $blueprint): array {
        $summ = [
            'version' => $blueprint['version'] ?? null,
            'constantsCount' => isset($blueprint['constants']) && is_array($blueprint['constants']) ? count($blueprint['constants']) : 0,
            'siteOptionsCount' => isset($blueprint['siteOptions']) && is_array($blueprint['siteOptions']) ? count($blueprint['siteOptions']) : 0,
            'muPluginsCount' => isset($blueprint['muPlugins']) && is_array($blueprint['muPlugins']) ? count($blueprint['muPlugins']) : 0,
            'themes' => [],
            'activeTheme' => $blueprint['activeTheme'] ?? null,
            'plugins' => [],
            'mediaCount' => isset($blueprint['media']) && is_array($blueprint['media']) ? count($blueprint['media']) : 0,
            'siteLanguage' => $blueprint['siteLanguage'] ?? null,
            'rolesCount' => isset($blueprint['roles']) && is_array($blueprint['roles']) ? count($blueprint['roles']) : 0,
            'usersCount' => isset($blueprint['users']) && is_array($blueprint['users']) ? count($blueprint['users']) : 0,
            'postTypesCount' => isset($blueprint['postTypes']) && is_array($blueprint['postTypes']) ? count($blueprint['postTypes']) : 0,
            'contentCount' => isset($blueprint['content']) && is_array($blueprint['content']) ? count($blueprint['content']) : 0,
        ];

        if (!empty($blueprint['themes']) && is_array($blueprint['themes'])) {
            foreach ($blueprint['themes'] as $themeRef) {
                if (is_string($themeRef)) {
                    $summ['themes'][] = $themeRef;
                } elseif (is_array($themeRef) && isset($themeRef['source'])) {
                    $summ['themes'][] = $themeRef['source'];
                }
            }
        }
        if (!empty($blueprint['plugins']) && is_array($blueprint['plugins'])) {
            foreach ($blueprint['plugins'] as $pluginRef) {
                if (is_string($pluginRef)) {
                    $summ['plugins'][] = $pluginRef;
                } elseif (is_array($pluginRef) && isset($pluginRef['source'])) {
                    $summ['plugins'][] = $pluginRef['source'];
                }
            }
        }

        return $summ;
    }

    private function validateWpContent(Filesystem $fs): ?ValidationError {
        if (!$fs->exists('/wp-content')) {
            return null;
        }

        $errors = [];
        $rootPointer = '/bundle/wp-content';

        // Top-level allowed directories within wp-content
        $allowedRootDirs = [ 'plugins', 'mu-plugins', 'themes', 'languages', 'uploads', 'content' ];
        foreach ($fs->ls('/wp-content') as $entry) {
            $path = '/wp-content/' . $entry;
            $isDir = $fs->is_dir($path);
            if ($isDir) {
                if (!in_array($entry, $allowedRootDirs, true)) {
                    $errors[] = new ValidationError(
                        $rootPointer . '/' . $entry,
                        'unexpected-entry',
                        sprintf('Unexpected directory in wp-content: %s. Allowed: %s', $entry, implode(', ', $allowedRootDirs))
                    );
                }
            } else {
                $errors[] = new ValidationError(
                    $rootPointer . '/' . $entry,
                    'unexpected-file',
                    sprintf('Unexpected file at wp-content root: %s. Only specific subdirectories are allowed.', $entry)
                );
            }
        }

        // Validate plugins
        if ($fs->exists('/wp-content/plugins') && $fs->is_dir('/wp-content/plugins')) {
            self::validate_plugins_like_dir($fs, '/wp-content/plugins', $rootPointer . '/plugins', $errors);
        }

        // Validate mu-plugins
        if ($fs->exists('/wp-content/mu-plugins') && $fs->is_dir('/wp-content/mu-plugins')) {
            self::validate_plugins_like_dir($fs, '/wp-content/mu-plugins', $rootPointer . '/mu-plugins', $errors);
        }

        // Validate themes
        if ($fs->exists('/wp-content/themes') && $fs->is_dir('/wp-content/themes')) {
            foreach ($fs->ls('/wp-content/themes') as $entry) {
                $entryPath = '/wp-content/themes/' . $entry;
                $pointer = $rootPointer . '/themes/' . $entry;
                if ($fs->is_dir($entryPath)) {
                    if (! $fs->exists($entryPath . '/style.css')) {
                        $errors[] = new ValidationError(
                            $pointer,
                            'theme-missing-style-css',
                            sprintf('Theme directory "%s" is missing required file: style.css', $entry)
                        );
                    }
                } else {
                    if (!self::hasExtension($entry, 'zip')) {
                        $errors[] = new ValidationError(
                            $pointer,
                            'unsupported-theme-file',
                            sprintf('Unsupported theme file: %s. Allowed: .zip archive or a theme directory.', $entry)
                        );
                    }
                }
            }
        }

        // Validate languages
        if ($fs->exists('/wp-content/languages') && $fs->is_dir('/wp-content/languages')) {
            foreach ($fs->ls('/wp-content/languages') as $entry) {
                $entryPath = '/wp-content/languages/' . $entry;
                $pointer = $rootPointer . '/languages/' . $entry;
                if ($fs->is_dir($entryPath)) {
                    if (!in_array($entry, ['plugins', 'themes'], true)) {
                        $errors[] = new ValidationError(
                            $pointer,
                            'unexpected-language-subdir',
                            sprintf('Unexpected directory under languages: %s. Allowed: plugins/, themes/, or .po/.mo files at root.', $entry)
                        );
                        continue;
                    }
                    foreach ($fs->ls($entryPath) as $slug) {
                        $slugPath = $entryPath . '/' . $slug;
                        $slugPtr = $pointer . '/' . $slug;
                        if (! $fs->is_dir($slugPath)) {
                            $errors[] = new ValidationError(
                                $slugPtr,
                                'expected-directory',
                                sprintf('Expected a directory for %s locale files: %s', $entry, $slug)
                            );
                            continue;
                        }
                        foreach ($fs->ls($slugPath) as $file) {
                            $filePtr = $slugPtr . '/' . $file;
                            if ($fs->is_dir($slugPath . '/' . $file)) {
                                $errors[] = new ValidationError(
                                    $filePtr,
                                    'unexpected-directory',
                                    'Unexpected nested directory inside languages. Only .po/.mo files are allowed.'
                                );
                                continue;
                            }
                            if (! self::hasAnyExtension($file, ['po', 'mo', 'zip'])) {
                                $errors[] = new ValidationError(
                                    $filePtr,
                                    'unexpected-language-file',
                                    'Only .po, .mo, or a .zip archive of translations are allowed.'
                                );
                            }
                        }
                    }
                } else {
                    if (! self::hasAnyExtension($entry, ['po', 'mo', 'zip'])) {
                        $errors[] = new ValidationError(
                            $pointer,
                            'unexpected-language-file',
                            'Only .po, .mo, or a .zip archive of translations are allowed at languages/ root.'
                        );
                    }
                }
            }
        }

        // Validate uploads/fonts specifically. Other uploads subdirs are allowed.
        if ($fs->exists('/wp-content/uploads') && $fs->is_dir('/wp-content/uploads')) {
            if ($fs->exists('/wp-content/uploads/fonts') && $fs->is_dir('/wp-content/uploads/fonts')) {
                foreach ($fs->ls('/wp-content/uploads/fonts') as $font) {
                    $fontPtr = $rootPointer . '/uploads/fonts/' . $font;
                    $fontPath = '/wp-content/uploads/fonts/' . $font;
                    if ($fs->is_dir($fontPath)) {
                        $errors[] = new ValidationError(
                            $fontPtr,
                            'unexpected-directory',
                            'Unexpected directory inside uploads/fonts. Only font files are allowed.'
                        );
                        continue;
                    }
                    if (! self::hasAnyExtension($font, ['woff2', 'woff', 'ttf', 'otf'])) {
                        $errors[] = new ValidationError(
                            $fontPtr,
                            'unsupported-font-file',
                            'Unsupported font file type. Allowed: .woff2, .woff, .ttf, .otf.'
                        );
                    }
                }
            }
        }

        // Validate content directory
        if ($fs->exists('/wp-content/content') && $fs->is_dir('/wp-content/content')) {
            foreach ($fs->ls('/wp-content/content') as $entry) {
                $entryPath = '/wp-content/content/' . $entry;
                $pointer = $rootPointer . '/content/' . $entry;
                if ($fs->is_dir($entryPath)) {
                    if ($entry !== 'posts') {
                        $errors[] = new ValidationError(
                            $pointer,
                            'unexpected-content-subdir',
                            'Unexpected directory under content/. Allowed: posts/ and root .sql/.xml dumps.'
                        );
                        continue;
                    }
                    foreach ($fs->ls($entryPath) as $postType) {
                        $postTypePath = $entryPath . '/' . $postType;
                        $postTypePtr = $pointer . '/' . $postType;
                        if (! $fs->is_dir($postTypePath)) {
                            $errors[] = new ValidationError(
                                $postTypePtr,
                                'expected-directory',
                                sprintf('Expected a directory for post type: %s', $postType)
                            );
                            continue;
                        }
                        self::validate_posts_tree($fs, $postTypePath, $postTypePtr, $errors);
                    }
                } else {
                    if (! self::hasAnyExtension($entry, ['sql', 'xml'])) {
                        $errors[] = new ValidationError(
                            $pointer,
                            'unexpected-content-file',
                            'Only .sql and .xml files are allowed at content/ root.'
                        );
                    }
                }
            }
        }

        if (empty($errors)) {
            return null;
        }

        return new ValidationError(
            $rootPointer,
            'bundle-structure-invalid',
            'The wp-content bundle directory contains files or directories outside the allowed structure.',
            [],
            $errors
        );
    }

    private function summarizeWpContent(Filesystem $fs): array {
        $summary = [
            'hasWpContent' => $fs->exists('/wp-content') && $fs->is_dir('/wp-content'),
            'plugins' => [],
            'muPlugins' => [],
            'themes' => [],
            'languages' => [
                'root' => [],
                'plugins' => [],
                'themes' => [],
            ],
            'uploads' => [
                'fonts' => [],
                'otherCount' => 0,
            ],
            'content' => [
                'sql' => [],
                'wxr' => [],
                'posts' => [],
            ],
        ];

        if (! $summary['hasWpContent']) {
            return $summary;
        }

        if ($fs->exists('/wp-content/plugins') && $fs->is_dir('/wp-content/plugins')) {
            foreach ($fs->ls('/wp-content/plugins') as $entry) {
                $summary['plugins'][] = $entry;
            }
        }

        if ($fs->exists('/wp-content/mu-plugins') && $fs->is_dir('/wp-content/mu-plugins')) {
            foreach ($fs->ls('/wp-content/mu-plugins') as $entry) {
                $summary['muPlugins'][] = $entry;
            }
        }

        if ($fs->exists('/wp-content/themes') && $fs->is_dir('/wp-content/themes')) {
            foreach ($fs->ls('/wp-content/themes') as $entry) {
                $summary['themes'][] = $entry;
            }
        }

        if ($fs->exists('/wp-content/languages') && $fs->is_dir('/wp-content/languages')) {
            foreach ($fs->ls('/wp-content/languages') as $entry) {
                $path = '/wp-content/languages/' . $entry;
                if ($fs->is_dir($path)) {
                    if (in_array($entry, ['plugins', 'themes'], true)) {
                        foreach ($fs->ls($path) as $slug) {
                            $summary['languages'][$entry][$slug] = $fs->ls($path . '/' . $slug);
                        }
                    }
                } else {
                    $summary['languages']['root'][] = $entry;
                }
            }
        }

        if ($fs->exists('/wp-content/uploads') && $fs->is_dir('/wp-content/uploads')) {
            foreach ($fs->ls('/wp-content/uploads') as $entry) {
                $path = '/wp-content/uploads/' . $entry;
                if ($entry === 'fonts' && $fs->is_dir($path)) {
                    $summary['uploads']['fonts'] = $fs->ls($path);
                } else {
                    $summary['uploads']['otherCount']++;
                }
            }
        }

        if ($fs->exists('/wp-content/content') && $fs->is_dir('/wp-content/content')) {
            foreach ($fs->ls('/wp-content/content') as $entry) {
                $path = '/wp-content/content/' . $entry;
                if ($fs->is_dir($path)) {
                    if ($entry === 'posts') {
                        $posts = [];
                        foreach ($fs->ls($path) as $postType) {
                            $posts[$postType] = self::summarize_posts_tree($fs, $path . '/' . $postType);
                        }
                        $summary['content']['posts'] = $posts;
                    }
                } else {
                    if (self::hasExtension($entry, 'sql')) {
                        $summary['content']['sql'][] = $entry;
                    } elseif (self::hasExtension($entry, 'xml')) {
                        $summary['content']['wxr'][] = $entry;
                    }
                }
            }
        }

        return $summary;
    }

    private function validate_plugins_like_dir(Filesystem $fs, string $dir, string $pointerBase, array &$errors): void {
        foreach ($fs->ls($dir) as $entry) {
            $entryPath = $dir . '/' . $entry;
            $pointer = $pointerBase . '/' . $entry;
            if ($fs->is_dir($entryPath)) {
                continue;
            }
            if (! self::hasAnyExtension($entry, ['php', 'zip'])) {
                $errors[] = new ValidationError(
                    $pointer,
                    'unsupported-plugin-file',
                    'Unsupported plugin file type. Allowed: a plugin directory, a single .php file, or a .zip archive.'
                );
            }
        }
    }

    private function validate_posts_tree(Filesystem $fs, string $dir, string $pointerBase, array &$errors): void {
        foreach ($fs->ls($dir) as $entry) {
            $entryPath = $dir . '/' . $entry;
            $pointer = $pointerBase . '/' . $entry;
            if ($fs->is_dir($entryPath)) {
                self::validate_posts_tree($fs, $entryPath, $pointer, $errors);
                continue;
            }
            if ($entry === 'post-type.json') {
                continue;
            }
            if (! self::hasAnyExtension($entry, ['html', 'md', 'xhtml'])) {
                $errors[] = new ValidationError(
                    $pointer,
                    'unsupported-post-file',
                    'Unsupported post content file. Allowed: .html, .md, .xhtml, or post-type.json.'
                );
            }
        }
    }

    private function summarize_posts_tree(Filesystem $fs, string $dir): array {
        $result = [];
        foreach ($fs->ls($dir) as $entry) {
            $entryPath = $dir . '/' . $entry;
            if ($fs->is_dir($entryPath)) {
                $result[$entry] = self::summarize_posts_tree($fs, $entryPath);
            } else {
                $result[] = $entry;
            }
        }
        return $result;
    }

    private function hasExtension(string $filename, string $ext): bool {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === strtolower($ext);
    }

    private function hasAnyExtension(string $filename, array $exts): bool {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        foreach ($exts as $allowed) {
            if ($ext === strtolower($allowed)) {
                return true;
            }
        }
        return false;
    }

    // Expose parsed constraints and recommendations for Runner usage
    public function getPhpVersionConstraint(): ?VersionConstraint { return $this->phpVersionConstraint; }
    public function getWpVersionConstraint(): ?VersionConstraint { return $this->wpVersionConstraint; }
    public function getRecommendedWpVersion(): string { return $this->recommendedWpVersion; }
}


