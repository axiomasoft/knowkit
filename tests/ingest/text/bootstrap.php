<?php
declare(strict_types=1);

// Composer installs core as a copy; all checks here must execute the workspace candidate.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KnowKit\\') && !str_starts_with($class, 'KnowKit\\Tests\\')) {
        $path = dirname(__DIR__, 3) . '/packages/core/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
}, true, true);
require_once dirname(__DIR__, 2) . '/contract/schemas/ContractHarness.php';
require_once __DIR__ . '/TextFixture.php';
