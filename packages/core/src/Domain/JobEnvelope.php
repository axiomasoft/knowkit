<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class JobEnvelope extends SchemaValue
{
    protected const SCHEMA = 'job-envelope';

    public function tenantId(): TenantId
    {
        return new TenantId($this->toWire()->get('tenantId'));
    }

    public function inputDigest(): string
    {
        return $this->toWire()->get('inputDigest');
    }

    public function limits(): WorkerLimits
    {
        return WorkerLimits::fromWire($this->toWire()->get('limits'));
    }

    public function secondsRemaining(): float
    {
        $expiry = new UtcInstant($this->toWire()->get('leaseExpiresAt'));
        return (float) (new \DateTimeImmutable($expiry->value))->format('U.u') - microtime(true);
    }

    public function assertStagingPaths(): void
    {
        $input = $this->inputRelativePath();
        if ($input === 'job.json' || str_starts_with($input, 'job.json/')) {
            throw new DomainFailure(Outcome::Denied, 'path_escape');
        }
    }

    public function inputRelativePath(): string
    {
        return substr($this->toWire()->get('inputPath'), strlen('/input/'));
    }

    public function outputRelativePath(): string
    {
        return ltrim(substr($this->toWire()->get('outDir'), strlen('/output')), '/');
    }

    public function assertResult(JsonObject $result): void
    {
        $wire = $this->toWire();
        foreach (['jobId', 'attemptId', 'tenantId', 'sourceId', 'sourceVersionId',
            'fence', 'sourceEpoch', 'profileFingerprint'] as $field) {
            if ($result->get($field) !== $wire->get($field)) {
                throw new DomainFailure(Outcome::Denied, 'worker_identity_mismatch');
            }
        }
        if ($this->secondsRemaining() <= 0) {
            throw new DomainFailure(Outcome::Denied, 'stale_lease');
        }
    }

    public function assertIr(IrDocument $ir): void
    {
        foreach (['tenantId', 'sourceVersionId', 'parserSignature'] as $field) {
            if ($ir->toWire()->get($field) != $this->toWire()->get($field)) {
                throw new DomainFailure(Outcome::Denied, 'worker_identity_mismatch');
            }
        }
        // Preserve original-page references beyond the processing limit, but
        // accept only an explicitly unprocessed, empty skipped suffix.
        foreach (array_slice($ir->pages(), $this->limits()->maxPages) as $page) {
            if ($page->get('status') !== 'skipped' || $page->get('text') !== '') {
                throw new DomainFailure(Outcome::Incompatible, 'output_limit');
            }
        }
    }
}
