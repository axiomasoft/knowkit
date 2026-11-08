<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';
require_once dirname(__DIR__, 2) . '/jobs/leases/Fixture.php';

// Optional packages are exercised from this candidate, independent of installed Composer copies.
spl_autoload_register(static function (string $class): void {
    foreach (['KnowKit\\Http\\' => 'http', 'KnowKit\\Answer\\' => 'answer'] as $prefix => $package) {
        if (str_starts_with($class, $prefix)) {
            $path = dirname(__DIR__, 3) . '/packages/' . $package . '/src/'
                . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
    }
}, true, true);

require_once __DIR__ . '/HardeningCase.php';
