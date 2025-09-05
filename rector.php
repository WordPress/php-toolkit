<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    // Autoload the custom Rector rule class
    $rectorConfig->autoloadPaths([
        __DIR__ . '/tools/Rector',
    ]);

    // Register our custom rule
    $rectorConfig->rule(\Tools\Rector\CamelCaseToSnakeCaseVariableAndPropertyRector::class);
};

