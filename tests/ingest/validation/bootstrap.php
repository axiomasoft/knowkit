<?php
declare(strict_types=1);

// Execute the workspace candidate before Composer's copied core package.
require_once dirname(__DIR__, 2) . '/contract/ports/bootstrap.php';
require_once __DIR__ . '/ValidationFixture.php';
