<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Application\Validation\ExtractionCacheEntry;
use KnowKit\Application\Validation\ValidationInput;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\WorkerRunner;
use KnowKit\Tests\Worker\Protocol\Fixture as Protocol;

/** Run-local fixture reuse for host crash tests; a cold miss must execute the actual producer. */
final readonly class ExtractionFixtureCache implements WorkerRunner
{
    public function __construct(
        private string $root,
        private WorkerRunner $producer,
        private D\SourceBytes $source,
        private D\ProcessingIdentity $processing,
        private D\DocumentRevisionId $revisionId,
    ) {
    }

    public function run(D\JobEnvelope $job, string $source, ?\Closure $cancel = null): D\WorkerOutput
    {
        $this->source->ref->assertBytes($source);
        $wire = $job->toWire();
        $this->source->ref->assertSame(new D\SourceVersionRef(
            new D\TenantId($wire->get('tenantId')),
            new D\SourceId($wire->get('sourceId')),
            new D\SourceVersionId($wire->get('sourceVersionId')),
            new D\SourceDigest($job->inputDigest()),
        ));
        $directory = $this->directory($job);
        if (!is_dir($directory)) {
            $this->save($directory, $job, $this->producer->run($job, $source, $cancel));
        }
        if ($cancel !== null && $cancel()) {
            throw new D\DomainFailure(D\Outcome::Denied, 'cancelled');
        }

        return $this->load($directory, $job);
    }

    public function directory(D\JobEnvelope $job): string
    {
        $wire = $job->toWire();
        $key = new JsonObject([
            'stage' => 'extract',
            'tenantId' => $wire->get('tenantId'),
            'kbId' => $wire->get('kbId'),
            'sourceRef' => $this->source->ref->toWire(),
            'sourceEpoch' => $wire->get('sourceEpoch'),
            'processingIdentity' => $this->processing->toWire(),
            'revisionId' => $this->revisionId->value,
            'parserProfile' => $wire->get('parserProfile'),
            'limits' => $wire->get('limits'),
        ]);

        return $this->root . '/' . hash('sha256', Jcs::canonicalize($key));
    }

    private function save(string $directory, D\JobEnvelope $job, D\WorkerOutput $output): void
    {
        $output->result->assertOutput($job, $output->ir);
        $entry = ExtractionCacheEntry::accept($this->input($output), $output->ir);
        mkdir($directory, 0700);
        mkdir($directory . '/bundle', 0700);
        file_put_contents($directory . '/bundle/' . $output->result->irPath(), $output->ir->canonical());
        file_put_contents($directory . '/bundle/result.json', $output->result->canonical());
        foreach ($output->assets as $path => $bytes) {
            $target = $directory . '/bundle/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            file_put_contents($target, $bytes);
        }
        file_put_contents($directory . '/job.json', $job->canonical());
        // Trusted PHP fixture metadata only, never worker transport or a tracked artifact.
        file_put_contents($directory . '/entry.bin', serialize($entry));
        $capabilities = $output->capabilities ?? throw new \RuntimeException('Producer negotiation missing');
        file_put_contents($directory . '/capabilities.json', Jcs::canonicalize(new JsonObject([
            'capabilities' => array_map(static fn (D\Capability $value): string => $value->value, $capabilities->capabilities),
            'schemas' => $capabilities->schemaVersions,
        ])));
    }

    private function load(string $directory, D\JobEnvelope $job): D\WorkerOutput
    {
        $contracts = Protocol::validator();
        $origin = new D\JobEnvelope(StrictJson::decode(WorkerFiles::read($directory, 'job.json', 65536)), $contracts);
        $validator = new WorkerIngressBundleValidator($contracts);
        $bundle = $validator->verify($origin, $directory . '/bundle');
        $entry = unserialize(WorkerFiles::read($directory, 'entry.bin', 65536));
        if (!$entry instanceof ExtractionCacheEntry || $entry->reuse($this->input($bundle), $bundle->ir)->status !== 'valid') {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'cache_identity_mismatch');
        }
        $wire = $bundle->result->toWire();
        foreach (['jobId', 'attemptId', 'tenantId', 'sourceId', 'sourceVersionId', 'fence', 'sourceEpoch', 'profileFingerprint'] as $key) {
            $wire = $wire->with($key, $job->toWire()->get($key));
        }
        $result = new D\WorkerResult(Protocol::sign($wire), $contracts);
        $result->assertOutput($job, $bundle->ir);
        $caps = StrictJson::decode(WorkerFiles::read($directory, 'capabilities.json', 65536));

        return new D\WorkerOutput($result, $bundle->ir, $bundle->assets, new D\ParserCapabilities(
            array_map(static fn (string $value): D\Capability => D\Capability::from($value), $caps->get('capabilities')),
            $caps->get('schemas'),
        ));
    }

    private function input(D\WorkerOutput $output): ValidationInput
    {
        $revision = new D\DocumentRevisionRef($this->source->ref, $this->revisionId,
            new D\IrDigest(hash('sha256', $output->ir->canonical())), $this->processing->parserSignature);

        return new ValidationInput($this->source, $revision, $this->processing, $output->assetsForIr());
    }
}
