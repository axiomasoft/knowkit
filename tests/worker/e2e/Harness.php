<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Adapter\ProcessWorker\ProcessWorkerSupervisor;
use KnowKit\Adapter\ProcessWorker\SandboxRuntime;
use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port as P;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;
use KnowKit\Tests\Storage\Blobs\Bytes;
use KnowKit\Tests\Worker\Protocol\Fixture as Protocol;
use PHPUnit\Framework\Assert;

final readonly class Attempt
{
    public function __construct(
        public D\Lease $lease,
        public D\SourceBytes $source,
        public D\ParserProfile $profile,
        public D\ProcessingIdentity $identity,
    ) {
    }
}

final class Harness
{
    public readonly Pipeline $pipeline;
    public readonly string $root;

    public function __construct(public readonly Jobs $jobs)
    {
        $this->root = Protocol::temporary();
        mkdir($this->root . '/blobs', 0700);
        mkdir($this->root . '/stages', 0700);
        $this->pipeline = new Pipeline(new Catalog($jobs->catalog->db), $jobs->scope, $jobs->directory, $this->root . '/blobs');
        $jobs->catalog->db->execute("INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,'{}')",
            [Jobs::id(1), Jobs::id(400), Jobs::id(2), Jobs::id(100)]);
    }

    public static function run(\Closure $test): void
    {
        Jobs::run(function (Jobs $jobs) use ($test): void {
            $harness = new self($jobs);
            try {
                $test($harness);
            } finally {
                WorkerFiles::remove($harness->root);
            }
        });
    }

    public static function path(string $file): string
    {
        return __DIR__ . '/fixtures/v1/' . $file;
    }

    public static function expected(): array
    {
        return json_decode(file_get_contents(self::path('manifest.json')), true, flags: JSON_THROW_ON_ERROR)['expected'];
    }

    public static function profile(string $id): D\ParserProfile
    {
        $wire = StrictJson::decode(file_get_contents(self::path('profiles.json')))->get($id);
        $profile = new D\ParserProfile($id, $wire->get('name'), $wire->get('version'), $wire->get('options'));
        Assert::assertEquals($wire->get('signature'), $profile->signature->toWire());

        return $profile;
    }

    public function attempt(string $profile = 'docling-pdf-ocr-eng-v1', bool $audit = false,
        int $version = 30, int $source = 2, string $key = 'e2e-ingest'): Attempt
    {
        $db = $this->jobs->catalog->db;
        if ($source !== 2) {
            $this->jobs->catalog->source($source);
            $db->execute("INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,'{}')",
                [Jobs::id(1), Jobs::id(400 + $source), Jobs::id($source), Jobs::id(100)]);
        }
        $bytes = file_get_contents($audit ? self::path('ten-pages.pdf')
            : Protocol::root() . '/tests/fixtures/documents/scans/v1/approved-eng.pdf');
        $profile = self::profile($profile);
        $ref = new D\SourceVersionRef($this->jobs->scope->tenantId, new D\SourceId(Jobs::id($source)),
            new D\SourceVersionId(Jobs::id($version)), new D\SourceDigest(hash('sha256', $bytes)));
        if ($db->row('SELECT id FROM source_versions WHERE tenant_id=? AND id=?', [Jobs::id(1), Jobs::id($version)]) === null) {
            $resource = new D\ResourceId(Jobs::id(3000 + $version));
            $reservation = new D\BlobWriteReservation(new D\ResourceRef($ref->tenantId, 'resource', $resource), $ref->sourceId,
                new D\WriterId(Jobs::id(4000 + $version)), new D\Decimal('0'), new D\Decimal('0'), null, null,
                new D\Ulid(Jobs::id(5000 + $version)));
            $blobs = $this->pipeline->blobs;
            $uploaded = $blobs->store->putImmutable($blobs->ledger->reserveWriter($reservation), new Bytes($bytes));
            $blobs->ledger->requireAccepted($uploaded);
            $db->execute("INSERT INTO source_versions(tenant_id,id,source_id,blob_resource_id,source_digest,byte_length,media_type,rights_ref,idempotency_fingerprint,created_at)
                VALUES (?,?,?,?,?,?,'application/pdf','synthetic:e2e',?,clock_timestamp())",
                [$ref->tenantId->value, $ref->sourceVersionId->value, $ref->sourceId->value, $resource->value,
                    $ref->sourceDigest->value, strlen($bytes), hash('sha256', 'version-' . $version)]);
            $db->execute("INSERT INTO source_version_state VALUES (?,?,'usable',NULL,NULL)", [$ref->tenantId->value, $ref->sourceVersionId->value]);
        }
        $base = $this->jobs->processing;
        $identity = new D\ProcessingIdentity($ref->sourceVersionId, $ref->sourceDigest, $profile->signature,
            $profile->signature, $this->pipeline->chunker->signature, 'ir-1.0', $base->indexProfileDigest, null);
        $this->pipeline->queue->enqueue(new D\JobCommand($this->jobs->scope, $ref, null, new D\JobType('ingest'),
            $identity, new D\UtcInstant('2026-01-01T00:00:00Z')), new D\IdempotencyKey($key));
        $lease = $this->pipeline->queue->lease(new D\JobType('ingest'), new D\WorkerId(Jobs::id(901)))
            ?? throw new \RuntimeException('Missing ingest lease');

        return new Attempt($lease, new D\SourceBytes($ref, $bytes, 'application/pdf'), $profile, $identity);
    }

    public function extract(Attempt $attempt, ?P\WorkerRunner $runner = null, int $revision = 701): D\WorkerAcceptance
    {
        return $this->pipeline->extract($attempt->lease, $attempt->identity, $attempt->profile, $attempt->source,
            $runner ?? self::supervisor($this->root . '/stages'), $revision);
    }

    public static function supervisor(string $stages): ProcessWorkerSupervisor
    {
        $path = self::cgroupParent();
        $runtimeRoot = getenv('KNOWKIT_OCR_RUNTIME') ?: Protocol::root() . '/python/knowkit-worker/.local-checks/ocr-runtime';
        \KnowKit\Tests\Worker\Protocol\RuntimeCandidate::requireCurrent($runtimeRoot, Protocol::root());
        $runtime = new SandboxRuntime($runtimeRoot,
            $stages, $path, Protocol::root() . '/python/knowkit-worker/.venv/bin/python',
            Protocol::root() . '/deploy/worker/cgroup_exec.py');

        return new ProcessWorkerSupervisor($runtime, new WorkerIngressBundleValidator(Protocol::validator()));
    }

    public static function cgroupParent(): string
    {
        $path = getenv('KNOWKIT_TEST_CGROUP_PARENT');
        if (!is_string($path) || $path === '') {
            $path = '/sys/fs/cgroup' . trim(explode('::', file_get_contents('/proc/self/cgroup'))[1]);
            while (str_starts_with($path, '/sys/fs/cgroup/')) {
                $enabled = explode(' ', trim(file_get_contents($path . '/cgroup.subtree_control')));
                if (is_writable($path) && array_diff(['cpu', 'memory', 'pids'], $enabled) === []) {
                    break;
                }
                $path = dirname($path);
            }
        }
        return $path;
    }

    public function takeover(Attempt $old): D\Lease
    {
        $this->jobs->expire($old->lease);

        return $this->pipeline->queue->lease(new D\JobType('ingest'), new D\WorkerId(Jobs::id(902)))
            ?? throw new \RuntimeException('Missing retry lease');
    }

    public function pointer(): ?string
    {
        return $this->jobs->catalog->db->row('SELECT active_snapshot_id FROM knowledge_bases WHERE tenant_id=? AND id=?',
            [Jobs::id(1), Jobs::id(100)])['active_snapshot_id'];
    }
}

final class MeasuredRunner implements P\WorkerRunner
{
    public int $sampledMemoryPeakBytes = 0;
    public int $sampledCpuUsec = 0;
    public int $peakStagedBytes = 0;

    public function __construct(
        private string $stages,
    )
    {
    }

    public function run(D\JobEnvelope $job, string $source, ?\Closure $cancel = null): D\WorkerOutput
    {
        $parent = Harness::cgroupParent();

        return Harness::supervisor($this->stages)->run($job, $source, function () use ($parent, $cancel): bool {
            $pid = getmypid();
            $children = trim(file_get_contents("/proc/{$pid}/task/{$pid}/children"));
            foreach ($children === '' ? [] : explode(' ', $children) as $child) {
                $path = @file_get_contents("/proc/{$child}/cgroup") ?: '';
                $group = '/sys/fs/cgroup' . trim(explode('::', $path)[1] ?? '');
                if (!str_starts_with($group, $parent . '/knowkit-') || !is_file($group . '/memory.peak')) {
                    continue;
                }
                $this->sampledMemoryPeakBytes = max($this->sampledMemoryPeakBytes, (int) file_get_contents($group . '/memory.peak'));
                if (preg_match('/^usage_usec ([0-9]+)$/m', file_get_contents($group . '/cpu.stat'), $match)) {
                    $this->sampledCpuUsec = max($this->sampledCpuUsec, (int) $match[1]);
                }
            }
            $bytes = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->stages, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
            $this->peakStagedBytes = max($this->peakStagedBytes, $bytes);

            return $cancel !== null && $cancel();
        });
    }
}

/** Deterministic untrusted parser-output fault. Production independent ingress still validates it. */
final readonly class OracleRunner implements P\WorkerRunner
{
    public function __construct(
        private bool $corrupt = false,
    )
    {
    }

    public function run(D\JobEnvelope $job, string $source, ?\Closure $cancel = null): D\WorkerOutput
    {
        $root = Protocol::temporary();
        try {
            $wire = $job->toWire();
            $ir = StrictJson::decode(file_get_contents(Harness::path('nine-of-ten.ir.json')))
                ->with('tenantId', $wire->get('tenantId'))->with('sourceVersionId', $wire->get('sourceVersionId'))
                ->with('parserSignature', $wire->get('parserSignature'));
            $result = Protocol::wire('result');
            foreach (['jobId', 'attemptId', 'tenantId', 'sourceId', 'sourceVersionId', 'fence', 'sourceEpoch', 'profileFingerprint'] as $key) {
                $result = $result->with($key, $wire->get($key));
            }
            $bytes = Jcs::canonicalize($ir);
            $result = Protocol::sign($result->with('status', 'partial')->with('coverage', $ir->get('coverage'))
                ->with('diagnostics', $ir->get('coverage')->get('diagnostics'))->with('irDigest', hash('sha256', $bytes))
                ->with('irByteLength', (string) strlen($bytes)));
            file_put_contents($root . '/ir.json', $bytes);
            file_put_contents($root . '/result.json', Jcs::canonicalize($this->corrupt
                ? Protocol::sign($result->with('irDigest', str_repeat('0', 64))) : $result));
            $bundle = (new WorkerIngressBundleValidator(Protocol::validator()))->verify($job, $root);

            return new D\WorkerOutput($bundle->result, $bundle->ir, [],
                new D\ParserCapabilities([D\Capability::ParserText], ['ir-1.0']));
        } finally {
            WorkerFiles::remove($root);
        }
    }
}
