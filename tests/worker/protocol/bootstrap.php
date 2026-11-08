<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if ($class === 'KnowKit\\Tests\\Jobs\\Leases\\Fixture') {
        require_once dirname(__DIR__, 2) . '/jobs/leases/Fixture.php';
        return;
    }
    if (!str_starts_with($class, 'KnowKit\\') || str_starts_with($class, 'KnowKit\\Tests\\')) {
        return;
    }
    $path = dirname(__DIR__, 3) . '/packages/core/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
}, true, true);

require_once __DIR__ . '/Fixture.php';
