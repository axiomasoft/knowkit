#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
require __DIR__ . '/bootstrap.php';

try {
    $report = KnowKitExample\Sandbox::run(static fn (KnowKitExample\Runtime $runtime) =>
        (new KnowKitExample\Walkthrough($runtime))->run());
    fwrite(STDOUT, KnowKit\Domain\Contract\Jcs::canonicalize($report) . "\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "{\"outcome\":\"unavailable\",\"error\":{\"code\":\"walkthrough_failed\",\"retryable\":false}}\n");
    fwrite(STDERR, "knowkit: standalone walkthrough failed\n");
    exit(4);
}
