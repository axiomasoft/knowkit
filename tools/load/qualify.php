<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Load\Qualification;
use KnowKit\Load\Report;

$reports = [];
foreach (Qualification::CASES as $case) {
    $file = Report::root() . '/.phpunit.cache/P9.4/' . $case . '.json';
    if (is_file($file)) {
        $reports[$case] = json_decode(\KnowKit\Tooling\LocalIO::read($file), true, 64, JSON_THROW_ON_ERROR);
    }
}
$failures = Qualification::failures($reports);
echo json_encode(['schemaVersion' => 'capacity-qualification:1', 'qualified' => $failures === [], 'failures' => $failures], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit($failures === [] ? 0 : 1);
