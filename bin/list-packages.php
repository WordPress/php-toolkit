#!/usr/bin/env php
<?php
declare(strict_types=1);

$roots = [__DIR__ . '/../packages', __DIR__ . '/../components'];
$org   = getenv('SPLIT_ORG') ?: getenv('GITHUB_REPOSITORY_OWNER') ?: 'your-org';

$rows = [];
foreach ($roots as $root) {
    if (!is_dir($root)) { continue; }
    foreach (glob($root.'/*', GLOB_ONLYDIR) as $dir) {
        $cj = $dir.'/composer.json';
        if (!is_file($cj)) { continue; }
        $meta = json_decode(file_get_contents($cj), true);
        if (!isset($meta['name'])) { continue; }
        [, $pkg] = explode('/', $meta['name'], 2);
        $rows[] = [
            'path' => ltrim(str_replace(realpath(__DIR__.'/..').'/', '', realpath($dir)), './'),
            'repo' => $pkg,
            'org'  => $org,
        ];
    }
}
echo json_encode(['include' => $rows], JSON_UNESCAPED_SLASHES).PHP_EOL;
