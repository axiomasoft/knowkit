<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/handler/bootstrap.php';
require_once dirname(__DIR__) . '/contracts/Fixture.php';

$qualificationLoader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$qualificationLoader->addPsr4('KnowKit\\Tests\\Chatom\\Qualification\\', __DIR__);
\KnowKit\Tests\Tooling\CandidateAutoload::register(dirname(__DIR__, 3));
