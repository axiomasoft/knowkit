<?php
declare(strict_types=1);

// An optional suite selects its own Composer install; never load host test files here.
if (PHP_SAPI === 'cli' && ini_get('memory_limit') === '128M') {
    @ini_set('memory_limit', '1G');
}
$project = getenv('KNOWKIT_QA_COMPOSER_PROJECT') ?: '.';
require dirname(__DIR__, 3) . '/' . $project . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/tools/qa.php';
