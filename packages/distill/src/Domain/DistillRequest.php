<?php

declare(strict_types=1);

namespace KnowKit\Distill\Domain;

use KnowKit\Domain\Contract\{ContractValidator, Jcs, JsonObject};
use KnowKit\Domain\{DocumentRevisionRef, EgressRequest, JobType, Operation, Sha256};

/** Immutable outbound distillation request bound to a specific document revision and egress operation. */
final readonly class DistillRequest
{
    public Sha256 $promptDigest;

    public function __construct(
        public JobType $jobType,
        public DistillProfile $profile,
        public DocumentRevisionRef $documentRevisionRef,
        public string $promptBytes,
        public Operation $operation = Operation::Ingest,
    ) {
        if (!DistillJobKinds::isDistillJob($jobType)) {
            throw new \InvalidArgumentException('Unsupported distillation jobType');
        }
        if (trim($promptBytes) === '' || preg_match('//u', $promptBytes) !== 1) {
            throw new \InvalidArgumentException('Nonempty UTF-8 promptBytes required');
        }
        if (strlen($promptBytes) > $profile->maxInputBytes) {
            throw new \InvalidArgumentException('promptBytes exceeds profile maxInputBytes');
        }
        $this->promptDigest = new Sha256(hash('sha256', $promptBytes));
    }

    public function toEgressRequest(ContractValidator $validator): EgressRequest
    {
        $wire = new JsonObject([
            'operation' => $this->operation->value,
            'destination' => $this->profile->destination(),
            'items' => [
                new JsonObject([
                    'category' => 'DOCUMENT_CONTENT',
                    'contentDigest' => $this->promptDigest->value,
                    'sourceVersionRefs' => [
                        $this->documentRevisionRef->sourceRef->toWire(),
                    ],
                ]),
            ],
        ]);

        return new EgressRequest(
            $wire->with('manifestDigest', hash('sha256', Jcs::canonicalize($wire))),
            $validator,
        );
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'jobType' => $this->jobType->value,
            'profile' => $this->profile->toWire(),
            'documentRevisionRef' => $this->documentRevisionRef->toWire(),
            'promptDigest' => $this->promptDigest->value,
            'promptByteLength' => strlen($this->promptBytes),
            'operation' => $this->operation->value,
        ]);
    }

    public function digest(): Sha256
    {
        return new Sha256(hash('sha256', Jcs::canonicalize($this->toWire())));
    }
}
