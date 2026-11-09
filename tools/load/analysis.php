<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Resolve the same development-only fixture symbols without executing a workload.
foreach (['Catalog', 'Limits', 'Dataset', 'Search', 'Hybrid', 'Disclosure', 'Reindex', 'Graph', 'Operations', 'Ocr'] as $name) {
    class_exists('KnowKit\\Load\\' . $name);
}
