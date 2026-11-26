<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/LocalIO.php';
require_once dirname(__DIR__, 2) . '/tests/tooling/baseline/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tests/storage/catalog/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tests/ingest/text/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'KnowKit\\Load\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
    if (!is_file($file)) {
        return;
    }
    $resolved = realpath($file);
    if ($resolved === false) {
        throw new \RuntimeException('Cannot resolve load helper');
    }
    if ($class === 'KnowKit\\Load\\Actor') {
        require __DIR__ . '/Actor.php';
    } elseif ($resolved !== realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
        require_once $resolved;
    }
});
