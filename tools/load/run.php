<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Load as L;

$case = $argv[1] ?? '';
if ($case === 'lexical') {
    // Bulk fixture construction has a separate budget; TXT ingest keeps its measured ceiling.
    ini_set('memory_limit', '1024M');
}
$report = match ($case) {
    'catalog' => L\Catalog::run(),
    'limits' => L\Limits::run(),
    'lexical' => L\Search::run(),
    'hybrid' => L\Hybrid::run(),
    'disclosure' => L\Disclosure::run(),
    'reindex' => L\Reindex::run(),
    'hot-tenant' => L\Graph::run(hot: true),
    'many-tenants' => L\Graph::run(hot: false),
    'outbox' => L\Operations::outbox(),
    'purge' => L\Operations::purge(),
    'ocr' => L\Ocr::run(),
    default => throw new InvalidArgumentException('Unknown workload case'),
};
echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
