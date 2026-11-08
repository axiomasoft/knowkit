<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Sha256;
use KnowKit\Domain\Contract\JsonObject;

final readonly class InvocationPins
{
    public function __construct(
        public ResolvedModelBinding $binding,
        public ProfileRef $retrievalProfile,
        public Sha256 $originalRequestDigest,
        public ?Sha256 $questionDigest = null,
    ) {
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'schemaVersion' => 'answer-pins:1',
            'binding' => $this->binding->toWire(),
            'retrievalProfile' => $this->retrievalProfile->toWire(),
            'originalRequestDigest' => $this->originalRequestDigest->value,
            'questionDigest' => $this->questionDigest?->value,
        ]);
    }

    public static function fromWire(JsonObject $wire): self
    {
        if ($wire->get('schemaVersion') !== 'answer-pins:1') {
            throw new \InvalidArgumentException('Unsupported invocation pins');
        }

        return new self(
            ResolvedModelBinding::fromWire($wire->get('binding')),
            ProfileRef::fromWire($wire->get('retrievalProfile')),
            new Sha256($wire->get('originalRequestDigest')),
            $wire->get('questionDigest') === null ? null : new Sha256($wire->get('questionDigest')),
        );
    }
}
