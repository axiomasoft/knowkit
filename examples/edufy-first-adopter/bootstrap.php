<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/plain-php/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'KnowKitExample\\Edufy\\' => __DIR__ . '/',
        'KnowKit\\Answer\\' => dirname(__DIR__, 2) . '/packages/answer/src/',
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

require_once __DIR__ . '/Contracts.php';
require_once __DIR__ . '/HostScope.php';
require_once __DIR__ . '/Bindings.php';
require_once __DIR__ . '/MaterialAdapter.php';
