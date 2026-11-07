<?php

declare(strict_types=1);

// No Composer install, framework, Python or model service is needed by this example.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'KnowKit\\' => dirname(__DIR__, 2) . '/packages/core/src/',
        'KnowKitExample\\' => __DIR__ . '/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $path = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
    }
}, true, true);
