<?php

declare(strict_types=1);

// Fresh optional Composer graphs: no optional test classes enter Core discovery.
$root = dirname(__DIR__, 3);
require $root . '/packages/laravel/vendor/autoload.php';
require $root . '/packages/http/vendor/autoload.php';
require_once $root . '/tests/tooling/baseline/CandidateAutoload.php';
\KnowKit\Tests\Tooling\CandidateAutoload::register($root);
require $root . '/examples/plain-php/bootstrap.php';
require $root . '/tests/delivery/laravel/Fixture.php';
require $root . '/tests/delivery/http/Fixture.php';

use KnowKit\Domain as D;
use KnowKit\Http\Server as H;
use KnowKit\Laravel\Jobs\Jobs;
use KnowKit\Tests\Delivery\Http\Fixture as Http;
use KnowKit\Tests\Delivery\Laravel\Fixture as Laravel;
use KnowKitExample\Host;
use KnowKitExample\Runtime;
use KnowKitExample\Sandbox;
use KnowKitExample\Walkthrough;

$app = Laravel::app(sys_get_temp_dir());
Laravel::boot($app);
if (count($app['router']->getRoutes()) !== 0) {
    throw new RuntimeException('Routes enabled during installation');
}
try {
    $app->make(Jobs::class);
    throw new RuntimeException('Jobs enabled during installation');
} catch (D\DomainFailure $failure) {
    if ($failure->errorCode !== 'jobs_disabled') {
        throw $failure;
    }
}
Sandbox::run(function (Runtime $runtime): void {
    (new Walkthrough($runtime))->prepare();
    $http = new Http();
    $http->host = $runtime->host;
    $application = new H\CoreCommands(
        remaining: $http, catalog: $runtime->catalogCommands, sources: $runtime->sources, lifecycle: $runtime->lifecycle,
    );
    $server = $http->server(application: $application, clock: $runtime->host, ids: $runtime->host);
    $request = Http::request(path: '/v1/lifecycle/revocations',
        body: ['sourceId' => Host::id('demo-source'), 'reason' => 'source_revoked'],
        headers: ['Idempotency-Key' => 'service-revoke', 'Expected-Epoch' => '0']);
    $http->authenticated = false;
    if ($server->handle($request)->status !== 401) {
        throw new RuntimeException('Unauthenticated service accepted');
    }
    $http->authenticated = true;
    if ($server->handle($request)->status !== 200) {
        throw new RuntimeException('Authenticated service failed');
    }
    $walkthrough = new Walkthrough($runtime);
    if ($walkthrough->call(command: 'search', input: ['query' => 'support'])->envelope->get('outcome') !== 'empty') {
        throw new RuntimeException('Revoked source remained searchable');
    }
});
echo json_encode(['profile' => 'service-no-worker', 'outcome' => 'success',
    'worker' => 'NOT_SELECTED', 'auth' => 'host-fixture', 'bridge' => 'NOT_SELECTED/PENDING'], JSON_THROW_ON_ERROR) . "\n";
