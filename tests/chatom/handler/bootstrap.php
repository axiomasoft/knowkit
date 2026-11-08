<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/packages/chatom/vendor/autoload.php';
// Optional Composer path installs are copies. Always exercise this candidate's sources.
$chatomLoader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$chatomLoader->addPsr4('KnowKit\\Chatom\\', dirname(__DIR__, 3) . '/packages/chatom/src', true);
$chatomLoader->addPsr4('KnowKit\\Answer\\', dirname(__DIR__, 3) . '/packages/answer/src', true);
require_once dirname(__DIR__, 2) . '/answer/grounding/bootstrap.php';
require_once __DIR__ . '/HandlerFixture.php';
