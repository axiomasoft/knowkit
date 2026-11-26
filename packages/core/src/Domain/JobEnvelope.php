<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\Jcs;
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
        $expiry = new \DateTimeImmutable((new UtcInstant($this->toWire()->get('leaseExpiresAt')))->value);
        $seconds = (int) $expiry->format('U');
        $micros = (int) $expiry->format('u');
        // Split the clock before subtracting. Casting "U.u" to float drops sub-second lease precision.
        [$fraction, $nowSeconds] = explode(' ', microtime(false));
        $nowMicros = (int) substr($fraction, 2, 6);

        return ($seconds - (int) $nowSeconds) + ($micros - $nowMicros) / 1_000_000;
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
        $wire = $this->toWire();
        $irWire = $ir->toWire();
        foreach (['tenantId', 'sourceVersionId', 'parserSignature'] as $field) {
            if (Jcs::canonicalize($irWire->get($field)) !== Jcs::canonicalize($wire->get($field))) {
                throw new DomainFailure(Outcome::Denied, 'worker_identity_mismatch');
            }
        }
        // Preserve original-page references beyond the processing limit, but
        // accept only an explicitly unprocessed, empty skipped suffix.
        foreach (array_slice($irWire->get('pages'), $this->limits()->maxPages) as $page) {
            if ($page->get('status') !== 'skipped' || $page->get('text') !== '') {
                throw new DomainFailure(Outcome::Incompatible, 'output_limit');
            }
        }
    }
}
