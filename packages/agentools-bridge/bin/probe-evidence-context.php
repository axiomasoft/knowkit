<?php

declare(strict_types=1);

use KnowKit\Bridge\AgenTools\Qualification\InstalledSeam;

try {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('unavailable: bridge Composer installation missing');
    }
    require $autoload;
    $result = InstalledSeam::probe(dirname(__DIR__, 3));
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $failure) {
    fwrite(STDERR, json_encode(['fixture' => false, 'upstream_seam' => 'unavailable',
        'owningTask' => 'KAT-EVIDENCE-CONTEXT-1', 'error' => $failure->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(2);
}
