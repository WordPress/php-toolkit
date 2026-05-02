<?php

if ( ! class_exists( 'Composer\\Autoload\\ClassLoader' ) ) {
	require_once __DIR__ . '/../../vendor/composer/ClassLoader.php';
}

$wp_origin_loader = new Composer\Autoload\ClassLoader();

$wp_origin_classmap = require __DIR__ . '/../../vendor/composer/autoload_classmap.php';
$wp_origin_loader->addClassMap( $wp_origin_classmap );

$wp_origin_psr4 = require __DIR__ . '/../../vendor/composer/autoload_psr4.php';
foreach ( $wp_origin_psr4 as $prefix => $paths ) {
	$wp_origin_loader->setPsr4( $prefix, $paths );
}

$wp_origin_namespaces = require __DIR__ . '/../../vendor/composer/autoload_namespaces.php';
foreach ( $wp_origin_namespaces as $prefix => $paths ) {
	$wp_origin_loader->set( $prefix, $paths );
}

$wp_origin_loader->register( true );

$wp_origin_files = array(
	__DIR__ . '/../../components/DataLiberation/URL/functions.php',
	__DIR__ . '/../../components/Encoding/utf8.php',
	__DIR__ . '/../../components/Encoding/compat-utf8.php',
	__DIR__ . '/../../components/Encoding/utf8-encoder.php',
	__DIR__ . '/../../components/Filesystem/functions.php',
	__DIR__ . '/../../components/Zip/functions.php',
	__DIR__ . '/../../components/Polyfill/mbstring.php',
	__DIR__ . '/../../components/Polyfill/php-functions.php',
	__DIR__ . '/../../components/Git/functions.php',
);

foreach ( $wp_origin_files as $wp_origin_file ) {
	require_once $wp_origin_file;
}
