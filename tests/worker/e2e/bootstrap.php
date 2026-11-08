<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/protocol/bootstrap.php';
require_once dirname(__DIR__, 3) . '/tools/qa.php';
require_once dirname(__DIR__, 2) . '/storage/blobs/bootstrap.php';
require_once dirname(__DIR__, 2) . '/jobs/leases/Fixture.php';
require_once dirname(__DIR__, 2) . '/retrieval/disclosure/bootstrap.php';
require_once dirname(__DIR__, 2) . '/publication/activate/Fixture.php';
require_once __DIR__ . '/LeaseHeartbeat.php';
require_once __DIR__ . '/Pipeline.php';
require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/ExtractionFixtureCache.php';
require_once __DIR__ . '/Reader.php';
