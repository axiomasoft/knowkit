<?php

declare(strict_types=1);

use KnowKit\Http\Server\Sapi;
use KnowKit\Tests\Delivery\Http\Fixture;

require_once dirname(__DIR__, 3) . '/delivery/http/Fixture.php';

$host = new Fixture();
$host->authenticated = ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer synthetic-http-test';
Sapi::serve($host->server());
