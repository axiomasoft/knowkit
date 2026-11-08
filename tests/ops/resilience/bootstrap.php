<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lifecycle/commands/Fixture.php';
require_once dirname(__DIR__, 2) . '/jobs/leases/Fixture.php';
require_once dirname(__DIR__, 2) . '/lifecycle/restore/Fixture.php';
require_once dirname(__DIR__, 2) . '/retrieval/cache/Fixture.php';
require_once dirname(__DIR__, 2) . '/retrieval/disclosure/bootstrap.php';
require_once dirname(__DIR__, 3) . '/tools/fault-injection/Process.php';
require_once dirname(__DIR__, 3) . '/tools/fault-injection/Transaction.php';
require_once __DIR__ . '/Fixture.php';
require_once __DIR__ . '/Runtime.php';
require_once __DIR__ . '/Jobs.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Recovery.php';
