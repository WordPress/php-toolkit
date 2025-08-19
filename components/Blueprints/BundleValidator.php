<?php

namespace WordPress\Blueprints;

use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Zip\ZipFilesystem;

class BundleValidator {
    /**
     * @var RunnerConfiguration
     */
    private $configuration;

    public function __construct( RunnerConfiguration $configuration ) {
        $this->configuration = $configuration;
    }

    public function validate_mu_plugin_source( string $source ): ?string {
        // TODO: The runner implementation of the MU plugins seems to only serve
        //       inline plugin definition, while the specs also allow for the use
        //       of ./wp-content/mu-plugins/* paths. We need to solve that first.
        return null;
    }

    public function validate_plugin_source( string $source ): ?string {
        if ( ! $this->is_bundle_source( $source ) ) {
            return null;
        }

        $error = $this->validate_source_prefix( $source, 'wp-content/plugins/' );
        if ( null !== $error ) {
            return sprintf( 'Invalid plugin path. %s', $error );
        }

        $extension = $this->get_extension( $source );
        if ( 'php' === $extension ) {
            $error = $this->validate_plugin_file( $source );
        } elseif ( 'zip' === $extension ) {
            $error = $this->validate_plugin_zip( $source );
        } elseif ( '' === $extension ) {
            $error = $this->validate_plugin_dir( $source );
        } else {
            $error = sprintf(
                'Invalid plugin path. Expected a ".php" file, a ".zip" archive, or a directory, got "%s": %s',
                $extension,
                $source
            );
        }
        return $error;
    }

    public function validate_theme_source( string $source ): ?string {
        if ( ! $this->is_bundle_source( $source ) ) {
            return null;
        }

        $error = $this->validate_source_prefix( $source, 'wp-content/themes/' );
        if ( null !== $error ) {
            return sprintf( 'Invalid theme path. %s', $error );
        }

        $extension = $this->get_extension( $source );
        if ( 'zip' === $extension ) {
            $error = $this->validate_theme_zip( $source );
        } elseif ( '' === $extension ) {
            $error = $this->validate_theme_dir( $source );
        } else {
            $error = sprintf(
                'Invalid theme path. Expected a ".zip" archive or a directory, got "%s": %s',
                $extension,
                $source
            );
        }
        return $error;
    }

    public function validate_language_source( string $source ): ?string {
        // TODO: Language support in Blueprints is not yet implemented.
        return null;
    }

    public function validate_font_source( string $source ): ?string {
        // TODO: Font support in Blueprints is not yet implemented.
        return null;
    }

    public function validate_content_source( string $source ): ?string {
        if ( ! $this->is_bundle_source( $source ) ) {
            return null;
        }

        $error = $this->validate_source_prefix( $source, 'wp-content/content/' );
        if ( null !== $error ) {
            return sprintf( 'Invalid content path. %s', $error );
        }

        $extension = $this->get_extension( $source );
        if ( 'sql' !== $extension && 'xml' !== $extension ) {
            $error = sprintf(
                'Invalid content path. Expected a ".sql" or ".xml" file, got "%s": %s',
                $extension,
                $source
            );
        }
        return $error;
    }

    public function validate_post_type_source( string $source ): ?string {
        // TODO: Implement this.
        return null;
    }

    public function validate_media_source( string $source ): ?string {
        if ( ! $this->is_bundle_source( $source ) ) {
            return null;
        }

        // TODO: Do we want to check for allowed file formats?
        $error = $this->validate_source_prefix( $source, 'wp-content/uploads/' );
        if ( null !== $error ) {
            return sprintf( 'Invalid media path. %s', $error );
        }
        return null;
    }

    /**
     * Validate a plugin file as per WordPress plugin requirements.
     *
     * The implementation mirrors "get_file_data()" from WordPress core.
     *
     * @param string $source The path to the plugin file.
     * @return string|null   An error message if the plugin file is invalid, or null if it is valid.
     */
    private function validate_plugin_file( string $source ): ?string {
        $fs    = $this->get_blueprint_filesystem();
        $error = $this->validate_file( $fs, $source );
        if ( null !== $error ) {
            return sprintf( 'Invalid plugin file. %s', $error );
        }
        return $this->validate_plugin_header( $fs, $source );
    }

    private function validate_plugin_dir( string $source ): ?string {
        $fs    = $this->get_blueprint_filesystem();
        $error = $this->validate_dir( $fs, $source );
        if ( null !== $error ) {
            return sprintf( 'Invalid plugin directory. %s', $error );
        }

        $plugin_files = [];
        foreach ( $fs->ls( $source ) as $path ) {
            if ( $fs->is_file( "$source/$path" ) && str_ends_with( $path, '.php' ) ) {
                if ( null === $this->validate_plugin_header( $fs, "$source/$path" ) ) {
                    $plugin_files[] = $path;
                }
            }
        }

        if ( count( $plugin_files ) === 0 ) {
            return 'Invalid plugin directory. Contains no plugin file.';
        } elseif ( count( $plugin_files ) > 1 ) {
            return 'Invalid plugin directory. Contains multiple plugin files.';
        }
        return null;
    }

    private function validate_plugin_zip( string $source ): ?string {
        $fs    = $this->get_blueprint_filesystem();
        $error = $this->validate_file( $fs, $source );
        if ( null !== $error ) {
            return sprintf( 'Invalid plugin zip. %s', $error );
        }

        $stream = $fs->open_read_stream( $source );
        $zip    = ZipFilesystem::create( $stream );
        $paths  = $zip->ls();

        // Plugin files in the root of the ZIP archive.
        $plugin_files = [];
        foreach ( $paths as $path ) {
            if ( $zip->is_file( $path ) && str_ends_with( $path, '.php' ) ) {
                if ( null === $this->validate_plugin_header( $zip, $path ) ) {
                    $plugin_files[] = $path;
                }
            }
        }

        if ( count( $plugin_files ) > 1 ) {
            return 'Invalid plugin zip. Contains multiple plugin files.';
        } elseif ( count( $plugin_files ) === 1 ) {
            return null;
        }

        // Plugin directories in the root of the ZIP archive.
        $plugin_dirs = [];
        foreach ( $paths as $path ) {
            if ( ! $zip->is_dir( $path ) || $path === '__MACOSX' ) {
                continue;
            }
            foreach ( $zip->ls( $path ) as $subpath ) {
                $full_path = "$path/$subpath";
                if ( $zip->is_file( $full_path ) && str_ends_with( $subpath, '.php' ) ) {
                    if ( null === $this->validate_plugin_header( $zip, $full_path ) ) {
                        $plugin_dirs[] = $path;
                    }
                }
            }
        }

        if ( count( $plugin_dirs ) > 1 ) {
            return 'Invalid plugin zip. Contains multiple plugin directories.';
        } elseif ( count( $plugin_dirs ) === 1 ) {
            return null;
        }

        return 'Invalid plugin zip. Contains no plugin file or directory.';
    }

    private function validate_plugin_header( Filesystem $fs, string $path ): ?string {
    	// Pull only the first 8 KB of the file in.
        $stream    = $fs->open_read_stream( $path );
        $bytes     = $stream->pull( 8 * 1024 );
        $file_data = $stream->consume( $bytes );
        $stream->close_reading();

        // Make sure we catch CR-only line endings.
        $file_data = str_replace( "\r", "\n", $file_data );

        // We only need to check for the plugin name header.
        $all_headers = array( 'Name' => 'Plugin Name' );
        foreach ( $all_headers as $field => $regex ) {
            if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
                $all_headers[ $field ] = $match[1];
            } else {
                $all_headers[ $field ] = '';
            }
        }

        if ( empty( $all_headers['Name'] ) ) {
            return 'Invalid plugin file. Missing "Plugin Name" header.';
        }
        return null;
    }

    private function validate_theme_dir( string $source ): ?string {
        $fs    = $this->get_blueprint_filesystem();
        $error = $this->validate_dir( $fs, $source );
        if ( null !== $error ) {
            return sprintf( 'Invalid theme directory. %s', $error );
        }
        return $this->validate_theme_path( $fs, $source );
    }

    private function validate_theme_zip( string $source ): ?string {
        $fs    = $this->get_blueprint_filesystem();
        $error = $this->validate_file( $fs, $source );
        if ( null !== $error ) {
            return sprintf( 'Invalid theme zip. %s', $error );
        }
        $stream = $fs->open_read_stream( $source );
        $zip    = ZipFilesystem::create( $stream );
        $paths  = $zip->ls();

        // Theme directories in the root of the ZIP archive.
        $theme_dirs = [];
        foreach ( $paths as $path ) {
            if ( ! $zip->is_dir( $path ) || $path === '__MACOSX' ) {
                continue;
            }
            if ( null === $this->validate_theme_path( $zip, $path ) ) {
                $theme_dirs[] = $path;
            }
        }

        if ( count( $theme_dirs ) === 0 ) {
            return sprintf( 'Invalid theme ZIP. No theme directories found: %s', $source );
        } elseif ( count( $theme_dirs ) > 1 ) {
            return sprintf( 'Invalid theme ZIP. Contains multiple theme directories: %s', $source );
        }
        return null;
    }

    private function validate_theme_path( Filesystem $fs, string $path ): ?string {
        if ( ! $fs->exists( $path ) ) {
            return sprintf( 'Invalid theme directory. Directory does not exist: %s', $path );
        }
        if ( ! $fs->is_dir( $path ) ) {
            return sprintf( 'Invalid theme directory. Not a directory: %s', $path );
        }
        if ( ! $fs->exists( "$path/style.css" ) ) {
            return sprintf( 'Invalid theme directory. Missing "style.css" file: %s', $path );
        }
        if ( ! $fs->is_file( "$path/style.css" ) ) {
            return sprintf( 'Invalid theme directory. "style.css" is not a file: %s', $path );
        }
        return null;
    }

    private function validate_file( Filesystem $fs, string $path ): ?string {
        if ( ! $fs->exists( $path ) ) {
            return sprintf( 'File does not exist: %s', $path );
        }
        if ( ! $fs->is_file( $path ) ) {
            return sprintf( 'Not a file: %s', $path );
        }
        return null;
    }

    private function validate_dir( Filesystem $fs, string $path ): ?string {
        if ( ! $fs->exists( $path ) ) {
            return sprintf( 'Directory does not exist: %s', $path );
        }
        if ( ! $fs->is_dir( $path ) ) {
            return sprintf( 'Not a directory: %s', $path );
        }
        return null;
    }

    private function validate_source_prefix( string $source, string $prefix ): ?string {
        $byte_1 = $source[0];
        $byte_2 = $source[1] ?? null;

        if ( '/' === $byte_1 ) {
            $path = substr( $source, 1 );
        } elseif ( '.' === $byte_1 && '/' === $byte_2 ) {
            $path = substr( $source, 2 );
        } else {
            $path = $source;
        }

        if ( ! str_starts_with( $path, $prefix ) ) {
            return sprintf( 'The path must start with "%s": %s', $prefix, $source );
        }
        return null;
    }

    private function get_blueprint_filesystem(): Filesystem {
        $root = dirname( $this->configuration->getBlueprint()->get_path() );
        return LocalFilesystem::create( $root );
    }

    private function get_extension( string $source ): string {
        return strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
    }

    private function is_bundle_source( string $source ): bool {
        return ! str_contains( $source, '://' ) && str_contains( $source, '/' );
    }
}
