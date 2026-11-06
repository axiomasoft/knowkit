<?php
declare(strict_types=1);

// Load the workspace KnowKit\Domain\Contract sources ahead of any stale vendor copy (core is
// installed with symlink=false), then the test harness. No other autoload side effects.
spl_autoload_register(static function (string $class): void {
    $prefix = 'KnowKit\\Domain\\Contract\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 3) . '/packages/core/src/Domain/Contract/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, true, true);

require_once __DIR__ . '/ContractHarness.php';
