<?php

declare(strict_types=1);

// Executed from a copied artifact after its original core source was removed.
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/examples/plain-php/bootstrap.php';

use KnowKit\Domain\Contract\Jcs;
use KnowKitExample\Runtime;
use KnowKitExample\Sandbox;
use KnowKitExample\Walkthrough;

foreach (['Illuminate\\Support\\ServiceProvider', 'AgenTools\\Core', 'KnowKit\\Http\\Server\\Server'] as $optional) {
    if (class_exists($optional)) {
        throw new RuntimeException('Optional runtime loaded in core-only install');
    }
}
$report = Sandbox::run(static fn (Runtime $runtime) => (new Walkthrough($runtime))->run());
fwrite(STDOUT, Jcs::canonicalize($report) . "\n");
