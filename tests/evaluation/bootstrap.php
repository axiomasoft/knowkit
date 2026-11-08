<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/retrieval/disclosure/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tools/qa.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'KnowKit\\Tests\\Evaluation\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
