<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/protocol/bootstrap.php';
require_once dirname(__DIR__, 2) . '/ingest/chunking/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tooling/baseline/DatabaseSandbox.php';
require_once dirname(__DIR__, 3) . '/tools/qa.php';
require_once __DIR__ . '/StructureFixture.php';
