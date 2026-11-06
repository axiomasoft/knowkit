<?php
declare(strict_types=1);

// Explicit dev/test opt-in. No Composer production mapping and no package scanning.
spl_autoload_register(static function (string $class): void {
    $prefix = 'KnowKit\\Conformance\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    } elseif (str_starts_with($class, 'KnowKit\\') && !str_starts_with($class, 'KnowKit\\Tests\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    } else { return; }
    if (is_file($path)) { require_once $path; }
}, true, true);
