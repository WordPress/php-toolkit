<?php

namespace WordPress\CLI;

use InvalidArgumentException;

class CLI {
	/**
	 * Parses command-line arguments and options in a POSIX-like style.
	 *
	 * This method processes an array of CLI arguments and an option definition array,
	 * returning a tuple of positional arguments and an associative array of options.
	 * It supports long options (e.g., --foo or --foo=bar), short options (e.g., -f or -f=bar),
	 * and bundled short options (e.g., -abc).
	 *
	 * Option definitions should be in the form:
	 *   [
	 *     'longname' => [ 'short', hasValue, defaultValue, description ],
	 *     // ...
	 *   ]
	 *
	 * Example:
	 *   $optionDefs = [
	 *     'site-url'  => [ 'u', true, null, 'Public site URL' ],
	 *     'site-path' => [ null, true, null, 'Target directory' ],
	 *     'help'      => [ 'h', false, false, 'Show help' ],
	 *   ];
	 *   $argv = ['--site-url=https://mysite.test', '--site-path', '/var/www', '-h', 'blueprint.json'];
	 *   [$positionals, $options] = CLI::parseCommandArgsAndOptions($argv, $optionDefs);
	 *   // $positionals = ['blueprint.json']
	 *   // $options = [
	 *   //   'site-url'  => 'https://mysite.test',
	 *   //   'site-path' => '/var/www',
	 *   //   'help'      => true,
	 *   // ]
	 *
	 * This is used in the Blueprint Runner CLI to parse command-line input, e.g.:
	 *   php blueprint.php exec my-blueprint.json --site-url https://mysite.test --site-path ./mysite --help
	 *
	 * @param array $argv       The CLI arguments (excluding the script name and command).
	 * @param array $optionDefs Option definitions as described above.
	 * @return array            [ $positionals, $options ]
	 * @throws InvalidArgumentException for unknown options or missing required values.
	 */
	public static function parseCommandArgsAndOptions( array $argv, array $optionDefs ): array {
		$positionals = array();
		$options     = array();
		$short2long  = array();

		// Initialise defaults & maps
		foreach ( $optionDefs as $long => $def ) {
			array( $short, , $default ) = $def;
			$options[ $long ]           = $default;
			if ( $short ) {
				$short2long[ $short ] = $long;
			}
		}

		$i = 0; // Start from the first command argument
		while ( $i < count( $argv ) ) {
			$token = $argv[ $i ];

			// Long option --foo or --foo=bar
			if ( preg_match( '/^--([^=]+)(=(.*))?$/', $token, $m ) ) {
				$long = $m[1];
				if ( ! isset( $optionDefs[ $long ] ) ) {
					throw new InvalidArgumentException( "Unknown option --$long" );
				}
				array( $short, $hasVal ) = $optionDefs[ $long ];
				if ( $hasVal ) {
					$val = $m[3] ?? ( $argv[ ++ $i ] ?? null );
					if ( $val === null ) {
						throw new InvalidArgumentException( "Option --$long requires a value" );
					}
					$options[ $long ] = $val;
				} else {
					$options[ $long ] = true;
				}
				$i ++;
				continue;
			}

			// Short option(s): -abc or -e mysql or -e=mysql
			if ( preg_match( '/^-([A-Za-z]{1,})(=(.*))?$/', $token, $m ) ) {
				$bundle    = str_split( $m[1] );
				$inlineVal = $m[3] ?? null;
				foreach ( $bundle as $idx => $short ) {
					if ( ! isset( $short2long[ $short ] ) ) {
						throw new InvalidArgumentException( "Unknown option -$short" );
					}
					$long   = $short2long[ $short ];
					$hasVal = $optionDefs[ $long ][1];
					if ( $hasVal ) {
						if ( $inlineVal !== null && $idx === 0 ) {
							$options[ $long ] = $inlineVal;
						} else {
							$val = ( $idx === count( $bundle ) - 1 ) ? ( $argv[ ++ $i ] ?? null ) : null;
							if ( $val === null ) {
								throw new InvalidArgumentException( "Option -$short requires a value" );
							}
							$options[ $long ] = $val;
						}
						break; // value‑bearing short stops bundle processing
					} else {
						$options[ $long ] = true;
					}
				}
				$i ++;
				continue;
			}

			// Positional argument
			$positionals[] = $token;
			$i ++;
		}

		return array( $positionals, $options );
	}
}
