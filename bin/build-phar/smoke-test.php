<?php

require_once __DIR__ . '/../../dist/wordpress-libraries.phar';

/**
 * None of this will actually try to parse a file or import
 * any data. We're just making sure the importer can
 * be created without throwing an exception.
 */
$c = WordPress\DataLiberation\Importer\StreamImporter::create_for_wxr_file(__DIR__ . '/nosuchfile.xml', [
    'uploads_path' => __DIR__ . '/uploads',
    'new_site_url' => 'https://smoke-test.org'
]);

WordPress\DataLiberation\URL\WPURL::parse('https://example.com');

echo 'Stream importer created!';

