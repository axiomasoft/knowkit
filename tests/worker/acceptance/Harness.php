<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Acceptance;

use KnowKit\Adapter\Postgres\Jobs\PdoJobQueue;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Application\Ingest\Worker\WorkerArtifactImporter;
use KnowKit\Application\Ingest\Worker\WorkerParserClient;
use KnowKit\Application\Jobs\VerifiedCheckpoints;
use KnowKit\Application\Validation\DefaultQualityPolicy;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Digests;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\WorkerRunner;
use KnowKit\Registry\WorkerProfiles;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use KnowKit\Tests\Storage\Catalog\TestIds;
use KnowKit\Tests\Worker\Protocol\Fixture;

/** Real PG/ledger/LocalBlob; only this runner is a contract double. No PDF qualification. */
final class FixtureRunner implements WorkerRunner
{
    public int $runs = 0;
    public ?D\JobEnvelope $lastJob = null;

    public function __construct(
        private ?\Closure $mutate = null,
        private ?\Closure $after = null,
        private ?\Closure $afterWrite = null,
    )
    {
    }

    public function run(D\JobEnvelope $job, string $source, ?\Closure $cancel = null): D\WorkerOutput
    {
        $this->runs++;
        $this->lastJob = $job;
        $root = Fixture::temporary();
        try {
            self::write($root, $job, $source, $this->mutate);
            if ($this->afterWrite !== null) {
                ($this->afterWrite)($root);
            }
            $bundle = (new WorkerIngressBundleValidator(Fixture::validator()))->verify($job, $root);
            if ($this->after !== null) {
                ($this->after)();
            }
            return new D\WorkerOutput($bundle->result, $bundle->ir, $bundle->assets, self::capabilities());
        } finally {
            WorkerFiles::remove($root);
        }
    }

    public static function capabilities(): D\ParserCapabilities
    {
        return new D\ParserCapabilities([D\Capability::ParserText], ['ir-1.0']);
    }

    public static function write(string $root, D\JobEnvelope $job, string $source, ?\Closure $mutate = null): void
    {
        $expected = $job->toWire();
        $ir = Fixture::wire('ir')->with('tenantId', $expected->get('tenantId'))
            ->with('sourceVersionId', $expected->get('sourceVersionId'))->with('parserSignature', $expected->get('parserSignature'))
            ->with('segments', [new JsonObject([
                'segmentId' => 's1', 'text' => $source, 'textDigest' => hash('sha256', $source), 'status' => 'ok',
            ])]);
        $result = Fixture::wire('result');
        foreach (['jobId', 'attemptId', 'tenantId', 'sourceId', 'sourceVersionId', 'fence', 'sourceEpoch', 'profileFingerprint'] as $key) {
            $result = $result->with($key, $expected->get($key));
        }
        if ($mutate !== null) {
            [$ir, $result] = $mutate($ir, $result, $root);
        }
        $bytes = Jcs::canonicalize($ir);
        $result = Fixture::sign($result->with('irDigest', Digests::ir($ir))->with('irByteLength', (string) strlen($bytes)));
        file_put_contents($root . '/ir.json', $bytes);
        file_put_contents($root . '/result.json', Jcs::canonicalize($result));
    }
}

final class Harness
{
    public readonly WorkerProfiles $profiles;
    public readonly D\ParserProfile $profile;
    public readonly D\ProcessingIdentity $processing;
    public readonly D\Lease $lease;
    public readonly D\SourceBytes $source;
    public readonly PdoJobQueue $queue;
    public readonly BlobFixture $blobs;
    private readonly TestIds $resourceIds;
    public int $authorizations = 0;
    public bool $accessAllowed = true;
    public ?\Closure $onAuthorize = null;
    public ?\Closure $onSave = null;
    public ?\Closure $onAccepted = null;
    /** @var array<string, D\BlobRef> */
    public array $refs = [];

    public function __construct(public readonly Jobs $jobs, string $source = 'source', string $jobType = 'extract')
    {
        $this->blobs = new BlobFixture($jobs->catalog);
        $this->resourceIds = new TestIds(900000);
        $jobs->catalog->db->execute('INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,\'{}\')',
            [Jobs::id(1), Jobs::id(400), Jobs::id(2), Jobs::id(100)]);
        $this->profile = new D\ParserProfile('protocol-fixture-v1', 'synthetic', '1.0.0',
            new JsonObject(['fixture' => 'worker-protocol-1.0.0', 'ocr' => false]));
        $this->profiles = new WorkerProfiles();
        $this->profiles->register($this->profile, FixtureRunner::capabilities(), true);
        $version = $source === 'source' ? Jobs::id(3) : Jobs::id(30);
        if ($source !== 'source') {
            $jobs->resource(303, $source);
            $jobs->catalog->db->execute('INSERT INTO source_versions(tenant_id,id,source_id,blob_resource_id,source_digest,byte_length,media_type,rights_ref,idempotency_fingerprint,created_at)
                VALUES (?,?,?,?,?,?,\'text/plain\',\'synthetic:test\',?,clock_timestamp())',
                [Jobs::id(1), $version, Jobs::id(2), Jobs::id(303), hash('sha256', $source), strlen($source), str_repeat('c', 64)]);
            $jobs->catalog->db->execute('INSERT INTO source_version_state VALUES (?,?,\'usable\',NULL,NULL)', [Jobs::id(1), $version]);
        }
        $this->processing = new D\ProcessingIdentity(new D\SourceVersionId($version), new D\SourceDigest(hash('sha256', $source)),
            $this->profile->signature, $jobs->processing->ocrProfileSignature, $jobs->processing->chunkerSignature,
            'ir-1.0', $jobs->processing->indexProfileDigest, null);
        $ref = new D\SourceVersionRef($jobs->scope->tenantId, new D\SourceId(Jobs::id(2)),
            new D\SourceVersionId($version), new D\SourceDigest(hash('sha256', $source)));
        $jobs->queue->enqueue(new D\JobCommand($jobs->scope, $ref, null, new D\JobType($jobType),
            $this->processing, new D\UtcInstant('2026-01-01T00:00:00Z')), new D\IdempotencyKey('worker-acceptance'));
        $this->lease = $jobs->lease($jobType);
        $this->source = new D\SourceBytes($ref, $source, 'text/plain');
        $verifier = new VerifiedCheckpoints(
            fn (D\Sha256 $digest): string => file_get_contents($jobs->directory . '/manifest-' . $digest->value),
            fn (D\TenantId $tenant, D\ResourceId $id) => $this->open($tenant, $id),
        );
        $this->queue = new PdoJobQueue($jobs->catalog->db, new PdoCatalogTransaction($jobs->catalog->db), $jobs->scope,
            new TestIds(800000), ['extract' => ['extract'], 'ingest' => ['extract', 'chunk', 'index']], $verifier->load(...));
    }

    public static function run(\Closure $test, string $source = 'source', string $jobType = 'extract'): void
    {
        Jobs::run(function (Jobs $jobs) use ($test, $source, $jobType): void {
            $h = new self($jobs, $source, $jobType);
            try {
                $test($h);
            } finally {
                WorkerFiles::remove($h->blobs->root);
            }
        });
    }

    public function client(WorkerRunner $runner): WorkerParserClient
    {
        $ledger = $this->blobs->ledger;
        $importer = new WorkerArtifactImporter($this->blobs->store, $this->resourceIds, $ledger->reserveWriter(...),
            function (D\BlobRef $ref) use ($ledger): void {
                $ledger->requireAccepted($ref);
                $this->refs[$ref->objectVersion] = $ref;
                if ($this->onAccepted !== null) {
                    ($this->onAccepted)($ref);
                }
            }, $ledger->markUncertain(...), function (D\JobCheckpointManifest $manifest): void {
                Jobs::durableFile($this->jobs->directory . '/manifest-' . $manifest->digest()->value, Jcs::canonicalize($manifest->toWire()));
                if ($this->onSave !== null) {
                    ($this->onSave)();
                }
            });
        return new WorkerParserClient($runner, $this->queue, $this->profiles, $importer, Fixture::validator(),
            new DefaultQualityPolicy(), function (D\Lease $lease, D\ParserProfile $profile): void {
                $this->authorizations++;
                if ($this->onAuthorize !== null) {
                    ($this->onAuthorize)($lease, $profile);
                }
            });
    }

    public function execute(WorkerRunner $runner, ?D\ParserProfile $profile = null, ?D\Lease $lease = null,
        ?D\WorkerLimits $limits = null): D\WorkerAcceptance
    {
        return $this->client($runner)->execute($lease ?? $this->lease, $this->source, $profile ?? $this->profile, $this->processing,
            new D\DocumentRevisionId(Jobs::id(701)), $limits ?? Fixture::job()->limits(), new D\CoveragePolicy(),
            new D\Ulid(Jobs::id(702)), [D\Capability::ParserText]);
    }

    private function open(D\TenantId $tenant, D\ResourceId $id): \KnowKit\Port\ReadableStream
    {
        $this->jobs->scope->assertTenant($tenant);
        // Receipt validation here exercises the real ledger and fresh source access.
        return $this->blobs->store->open($this->refs[$id->value]);
    }
}
