<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/packages/answer/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/tooling/baseline/CandidateAutoload.php';
\KnowKit\Tests\Tooling\CandidateAutoload::register(dirname(__DIR__, 3));
require_once dirname(__DIR__, 3) . '/tools/qa.php';
require_once __DIR__ . '/Fixture.php';
