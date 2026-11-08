<?php

declare(strict_types=1);

// Fresh core-only Composer install; no host application's autoloader is available.
$root = dirname(__DIR__, 5);
require $argv[1];
$manifest = json_decode(file_get_contents($root . '/packages/core/composer.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (array_keys($manifest['require']) as $dependency) {
    if ($dependency !== 'php' && !str_starts_with($dependency, 'ext-')) {
        exit(11);
    }
}
$registry = new KnowKit\Registry\AdapterRegistry();
$registry->boot();
KnowKit\Domain\Result::empty();
$kernel = new KnowKit\Adapter\Cli\Kernel(static fn () => KnowKit\Adapter\Cli\Response::result(KnowKit\Domain\Result::empty()));
if ($kernel->run(['capabilities'])->exit !== 0 || class_exists(Illuminate\Support\ServiceProvider::class)) {
    exit(12);
}
echo 'core-only';
