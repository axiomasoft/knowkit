<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain\Guard;
use KnowKit\Answer\Domain\GroundingEvidence;
use KnowKit\Domain\CitationRef;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;

/** Exact subset, immutable provenance and bounded spans only. No entailment claim. */
final readonly class CitationValidator
{
    public function __construct(
        private ContractValidator $validator,
    ) {
    }

    /** @return list<CitationRef> */
    public function validate(mixed $wire, GroundingEvidence $evidence): array
    {
        if (!is_array($wire) || $wire === []) {
            throw new \InvalidArgumentException('Exact citations required');
        }
        Guard::orderedList($wire, 64);
        $allowed = [];
        foreach ($evidence->citations() as $citation) {
            $allowed[$citation->id()->value] = $citation;
        }
        $citations = [];
        foreach ($wire as $value) {
            if (!$value instanceof JsonObject) {
                throw new \InvalidArgumentException('Typed citation required');
            }
            $candidate = new CitationRef($value, $this->validator);
            $id = $candidate->id()->value;
            if (!isset($allowed[$id]) || isset($citations[$id]) || $candidate->canonical() !== $allowed[$id]->canonical()) {
                throw new \InvalidArgumentException('Citation is outside disclosed provenance/spans');
            }
            $citations[$id] = $allowed[$id];
        }

        return array_values($citations);
    }
}
