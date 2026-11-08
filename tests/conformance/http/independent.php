<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/examples/plain-php/bootstrap.php';

use KnowKit\Domain\Contract\Jcs;
use KnowKitExample\Runtime;
use KnowKitExample\Sandbox;
use KnowKitExample\Walkthrough;

$result = Sandbox::run(static fn (Runtime $runtime) => (new Walkthrough($runtime))->run());
foreach (get_included_files() as $file) {
    if (str_contains($file, '/vendor/') || str_contains($file, '/laravel/')
        || str_contains($file, '/agentools/') || str_contains($file, '/python/')) {
        throw new RuntimeException('Independent delivery loaded an optional runtime');
    }
}
fwrite(STDOUT, Jcs::canonicalize($result) . "\n");
