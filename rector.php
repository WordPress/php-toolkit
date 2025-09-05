<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

require_once __DIR__ . '/tools/RectorRules/CamelCaseToSnakeCaseVariableAndPropertyRector.php';

return static function (RectorConfig $rectorConfig): void {
    // Autoload the custom Rector rule class
    $rectorConfig->autoloadPaths([
        __DIR__ . '/tools/Rector',
    ]);

    // Skip certain paths from processing
    $rectorConfig->skip([
        __DIR__ . '/vendor/*',
        __DIR__ . '/*/vendor-patched/*',
        __DIR__ . '/*/Tests/*',
    ]);

    // Register our custom rule
    $rectorConfig->rule(\Tools\RectorRules\CamelCaseToSnakeCaseVariableAndPropertyRector::class);

    $rectorConfig->paths([
        __DIR__ . '/components/*',
    ]);
};

