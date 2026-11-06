<?php
declare(strict_types=1);

// Core is a Composer copy; tests explicitly prefer the candidate workspace, without changing production autoload.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KnowKit\\') && !str_starts_with($class, 'KnowKit\\Tests\\')) {
        $path = dirname(__DIR__, 3) . '/packages/core/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
}, true, true);
require_once dirname(__DIR__) . '/schemas/ContractHarness.php';
require_once __DIR__ . '/PortFixture.php';
