<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/contract/ports/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tooling/baseline/DatabaseSandbox.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'KnowKit\\PgVector\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__, 3) . '/packages/pgvector/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
}, true, true);
require_once __DIR__ . '/Fixture.php';
