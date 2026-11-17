<?php

declare(strict_types=1);

namespace KnowKit\Application\Ingest\Worker;

use KnowKit\Application\Validation\SourceReadiness;
use KnowKit\Application\Validation\TechnicalValidator;
use KnowKit\Application\Validation\ValidationInput;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\QualityPolicy;
use KnowKit\Port\WorkerJobs;
use KnowKit\Port\WorkerRunner;
use KnowKit\Registry\WorkerProfiles;

/** A valid extraction is never authority to publish. Publication retains its own atomic fence/policy barrier. */
final readonly class WorkerParserClient
{
    /** @param \Closure(D\Lease, D\ParserProfile): void $authorize Fresh owner/lifecycle access, including retries. */
    public function __construct(
        private WorkerRunner $runner,
        private WorkerJobs $jobs,
        private WorkerProfiles $profiles,
        private WorkerArtifactImporter $artifacts,
        private ContractValidator $contracts,
        private QualityPolicy $quality,
        private \Closure $authorize,
    ) {
    }

    /** @param list<D\Capability> $required */
    public function execute(D\Lease $lease, D\SourceBytes $source, D\ParserProfile $profile,
        D\ProcessingIdentity $processing, D\DocumentRevisionId $revisionId, D\WorkerLimits $limits,
        D\CoveragePolicy $policy, D\Ulid $correlationId, array $required): D\WorkerAcceptance
    {
        $lease->sourceRef->assertSame($source->ref);
        $source->ref->assertBytes($source->bytes());
        $context = $this->guard($lease, $profile, $processing, $required);
        if ($context->stage->value !== 'extract' || $context->inputDigest->value !== $source->ref->sourceDigest->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'worker_stage_mismatch');
        }
        $job = $this->envelope($lease, $profile, $limits, $correlationId);
        $reserved = $this->artifacts->reserve($lease);
        try {
            $output = $this->extract($job, $lease, $source, $profile, $processing, $required);
            $revision = $this->validate($source, $profile, $processing, $revisionId, $output);
            $readiness = new SourceReadiness($revision, $output->ir, $policy, $this->quality);
            [$manifest, $uncertain] = $this->import($lease, $profile, $processing, $required, $reserved, $output, $context);
            return new D\WorkerAcceptance($revision, $output, $readiness->quality, $manifest->checkpoint(), $uncertain, $context->completesJob);
        } catch (\Throwable $failure) {
            $this->artifacts->abandon($reserved);
            throw $failure;
        }
    }

    /** @param list<D\Capability> $required */
    private function extract(D\JobEnvelope $job, D\Lease $lease, D\SourceBytes $source, D\ParserProfile $profile,
        D\ProcessingIdentity $processing, array $required): D\WorkerOutput
    {
        $output = $this->runner->run($job, $source->bytes(), function () use ($lease, $profile, $processing, $required): bool {
            $this->guard($lease, $profile, $processing, $required);
            return false;
        });
        $this->guard($lease, $profile, $processing, $required);
        $output->result->assertOutput($job, $output->ir);
        foreach ($required as $capability) {
            ($output->capabilities ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'capabilities_missing'))
                ->require($capability, 'ir-1.0');
        }
        return $output;
    }

    private function validate(D\SourceBytes $source, D\ParserProfile $profile, D\ProcessingIdentity $processing,
        D\DocumentRevisionId $revisionId, D\WorkerOutput $output): D\DocumentRevisionRef
    {
        $revision = new D\DocumentRevisionRef($source->ref, $revisionId,
            new D\IrDigest(hash('sha256', $output->ir->canonical())), $profile->signature);
        $input = new ValidationInput($source, $revision, $processing, $output->assetsForIr());
        $validation = (new TechnicalValidator($input))->validate($output->ir, $source->ref);
        if ($validation->status !== 'valid') {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'worker_ir_invalid');
        }
        return $revision;
    }

    /** @param list<D\Capability> $required */
    private function guard(D\Lease $lease, D\ParserProfile $profile, D\ProcessingIdentity $processing, array $required): D\WorkerJobContext
    {
        ($this->authorize)($lease, $profile);
        $this->profiles->require($profile, $required);
        $context = $this->jobs->workerContext($lease)
            ?? throw new D\DomainFailure(D\Outcome::Denied, 'stale_lease');
        if (
            !$processing->sourceVersionId->equals($lease->sourceRef->sourceVersionId)
            || !$processing->sourceDigest->equals($lease->sourceRef->sourceDigest)
            || !$processing->parserSignature->equals($profile->signature)
            || !$context->processingDigest->equals($processing->digest())
        ) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'processing_identity_mismatch');
        }
        return $context;
    }

    /**
     * @param list<D\Capability> $required
     * @return array{D\JobCheckpointManifest, bool}
     */
    private function import(D\Lease $lease, D\ParserProfile $profile, D\ProcessingIdentity $processing,
        array $required, D\BlobWriteReservation $reserved, D\WorkerOutput $output, D\WorkerJobContext $context): array
    {
        try {
            $manifest = $this->artifacts->import($lease, $reserved, $output, $context);
        } catch (D\DomainFailure $failure) {
            if (in_array($failure->outcome, [D\Outcome::Unknown, D\Outcome::Unavailable], true)) {
                $this->jobs->complete($lease, new D\JobResult(D\Outcome::Unknown, $output->result->resultDigest()));
            }
            throw $failure;
        }
        $this->guard($lease, $profile, $processing, $required);
        if (!$this->jobs->checkpoint($lease, $manifest->checkpoint())) {
            throw new D\DomainFailure(D\Outcome::Denied, 'stale_lease');
        }
        $current = $this->guard($lease, $profile, $processing, $required);
        $result = new D\JobResult(D\Outcome::Success, $output->result->resultDigest(), $output->result->coverage());
        if ($context->completesJob && !$this->jobs->complete($lease, $result)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'stale_lease');
        }
        return [$manifest, $context->uncertainExternalEffect || $current->uncertainExternalEffect];
    }

    private function envelope(D\Lease $lease, D\ParserProfile $profile, D\WorkerLimits $limits, D\Ulid $correlationId): D\JobEnvelope
    {
        return new D\JobEnvelope(new JsonObject([
            'schemaVersion' => 'job-envelope-1.0',
            'jobId' => $lease->jobId->value,
            'attemptId' => $lease->attemptId->value,
            'tenantId' => $lease->scope->tenantId->value,
            'kbId' => $lease->scope->kbId->value,
            'sourceId' => $lease->sourceRef->sourceId->value,
            'sourceVersionId' => $lease->sourceRef->sourceVersionId->value,
            'fence' => $lease->fence->value,
            'sourceEpoch' => $lease->sourceEpoch->value,
            'inputDigest' => $lease->sourceRef->sourceDigest->value,
            'inputPath' => '/input/source.bin',
            'outDir' => '/output',
            'parserSignature' => $profile->signature->toWire(),
            'parserProfile' => $profile->id,
            'profileFingerprint' => $profile->signature->profileDigest->value,
            'irSchema' => 'ir-1.0',
            'leaseExpiresAt' => $lease->leaseUntil->value,
            'correlationId' => $correlationId->value,
            'limits' => $limits->toWire(126),
        ]), $this->contracts);
    }
}
