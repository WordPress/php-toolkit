<?php

$file = $argv[1];
$phar = new Phar( $file );
$phar->startBuffering();


/**
 * Box, includes an autoloader with a fixed name in every build.
 * However, we want to load two .phar files built with Box, not
 * one. Unfortunately this yields an error:
 *
 *     Cannot declare class ComposerAutoloaderInitHumbugBox451
 *
 * Therefore, we're giving all the HumbugBox classes a unique suffix.
 */
$autoload_suffix = substr( md5( __FILE__ ), 0, 8 );
foreach ( new RecursiveIteratorIterator( $phar ) as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$relative_path     = $file->getPathname();
	$relative_path     = str_replace( 'phar://', '', $relative_path );
	$relative_path     = str_replace( $phar->getPath() . '/', '', $relative_path );
	$contents         = $file->getContent();
	$updated_contents = $contents;
	foreach ( array(
		'InitHumbugBox',
	) as $class ) {
		$updated_contents = str_replace( $class, $class . $autoload_suffix, $updated_contents );
	}
	if ( $updated_contents !== $contents ) {
		$phar[ $relative_path ] = $updated_contents;
	}
}

/**
 * Box, very annoyingly, force-adds a platform_check.php file
 * into the final built .phar archive. The vendor libraries
 * do work with a PHP version lower than 8.1 enforced by that
 * platform_check.php file, so let's just truncate it.
 */
$phar['vendor/composer/platform_check.php'] = '';
$phar['.box/bin/check-requirements.php']    = '';
$phar->stopBuffering();
