<?php

declare(strict_types=1);

namespace KnowKit\Tests\Security\Hardening;

use KnowKit\Adapter\LocalQuota\TenantQuota;
use KnowKit\Domain as D;
use KnowKit\Http\Server as H;
use KnowKit\Tests\Security\Hardening\HttpHost as Fixture;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/HttpHost.php';

#[Group('security:hardening')]
final class QuotaTest extends HardeningCase
{
    public function testConcurrentTenantLimitsAreSharedAcrossProcessesWithoutSideEffects(): void
    {
        $store = new TenantQuota($this->temporary);
        $tenant = new D\TenantId(Fixture::id(1));
        $first = $store->acquire(tenant: $tenant, lane: 'worker', concurrent: 2, perMinute: 100);
        $second = (new TenantQuota($this->temporary))->acquire(tenant: $tenant, lane: 'worker', concurrent: 2, perMinute: 100);
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            require 'tests/security/hardening/bootstrap.php';
            try {
                (new KnowKit\Adapter\LocalQuota\TenantQuota($argv[1]))->acquire(
                    tenant: new KnowKit\Domain\TenantId($argv[2]), lane: 'worker', concurrent: 2, perMinute: 100);
                exit(9);
            } catch (KnowKit\Domain\DomainFailure $e) {
                exit($e->errorCode === 'quota_exceeded' ? 0 : 8);
            }
            PHP;
        $child = proc_open([PHP_BINARY, '-r', $script, '--', $this->temporary, $tenant->value],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root());
        fclose($pipes[0]);
        self::assertSame('', stream_get_contents($pipes[1]));
        self::assertSame('', stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($child));
        $otherTenant = $store->acquire(tenant: new D\TenantId(Fixture::id(2)), lane: 'worker', concurrent: 2, perMinute: 100);
        $first->release();
        $replacement = $store->acquire(tenant: $tenant, lane: 'worker', concurrent: 2, perMinute: 100);
        $second->release();
        $replacement->release();
        $otherTenant->release();
    }

    public function testRateQuotaAndConcurrentOverflowGiveRetryAfterBeforeApplication(): void
    {
        $host = new Fixture();
        $server = $this->server(host: $host, concurrent: 1, rate: 3);
        $nested = null;
        $host->handler = function () use ($server, &$nested): H\Reply {
            $nested = $server->handle(Fixture::request(path: '/v1/search', body: ['query' => 'safe']));

            return H\Reply::success(new D\Contract\JsonObject());
        };
        self::assertSame(200, $server->handle(Fixture::request(path: '/v1/search', body: ['query' => 'safe']))->status);
        self::assertSame(429, $nested->status);
        self::assertSame('60', $nested->headers['Retry-After']);
        self::assertCount(1, $host->seen);
        $host->handler = null;
        foreach ([200, 200, 429] as $expected) {
            self::assertSame($expected, $server->handle(Fixture::request(path: '/v1/search', body: ['query' => 'safe']))->status);
        }
        self::assertCount(3, $host->seen);
    }

    public function testIngestRoutesAndFailureReleaseUseFiniteIndependentLanes(): void
    {
        $host = new Fixture();
        $store = new TenantQuota($this->temporary);
        $quotas = new H\Limits\RequestQuotas(store: $store);
        foreach (['addVersion', 'startIngestJob', 'reprocess'] as $operation) {
            $held = [];
            for ($i = 0; $i < 4; $i++) {
                $held[] = $quotas->acquire(tenant: $host->identity->scope->tenantId, operation: $operation);
            }
            $this->failure(fn () => $quotas->acquire(tenant: $host->identity->scope->tenantId, operation: $operation),
                code: 'quota_exceeded', outcome: D\Outcome::Unavailable);
            foreach ($held as $permit) {
                $permit->release();
            }
            // Each operation shares the same ingest lane and rate. Use a new tenant for the next case.
            $scope = $host->identity->scope;
            $host->identity = new D\Access\PolicyContext(new D\Scope(
                tenantId: new D\TenantId(Fixture::id(++$host->sequence)), kbId: $scope->kbId, actorId: $scope->actorId,
                purpose: $scope->purpose, policyEpoch: $scope->policyEpoch, hostScopeHash: $scope->hostScopeHash,
            ), 'quota-host');
        }
        $server = $this->server(host: $host, concurrent: 1, rate: 10);
        $host->handler = static fn () => throw new D\DomainFailure(D\Outcome::Unavailable, 'synthetic_failure');
        self::assertSame(503, $server->handle(Fixture::request(path: '/v1/search', body: ['query' => 'safe']))->status);
        $host->handler = null;
        self::assertSame(200, $server->handle(Fixture::request(path: '/v1/search', body: ['query' => 'safe']))->status);
    }

    public function testHostLimitsCannotBeDisabledAndQuotaSymlinkIsRefused(): void
    {
        foreach (['searchConcurrent' => 1024, 'ingestConcurrent' => 1024,
            'searchPerMinute' => 1000000, 'ingestPerMinute' => 1000000] as $name => $maximum) {
            foreach ([0, $maximum + 1] as $limit) {
                try {
                    new H\Limits\RequestQuotas(...[$name => $limit]);
                    self::fail('Unsupported host quota accepted: ' . $name);
                } catch (\InvalidArgumentException $failure) {
                    self::assertSame('Finite host quotas required', $failure->getMessage());
                }
            }
        }
        mkdir($this->temporary . '/owned', 0700);
        symlink($this->temporary . '/owned', $this->temporary . '/linked');
        $this->failure(fn () => (new TenantQuota($this->temporary . '/linked'))->acquire(
            tenant: new D\TenantId(Fixture::id(1)), lane: 'search', concurrent: 16, perMinute: 60,
        ), 'unsafe_blob_path');
    }

    private function server(Fixture $host, int $concurrent, int $rate): H\Server
    {
        return new H\Server(
            authenticator: $host, application: $host, contract: new H\Contract(),
            commands: new H\CommandFactory(validator: Fixture::validator(), clock: $host), ids: $host,
            quotas: new H\Limits\RequestQuotas(store: new TenantQuota($this->temporary), searchConcurrent: $concurrent, searchPerMinute: $rate),
        );
    }
}
