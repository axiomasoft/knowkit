<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain\Guard;
use KnowKit\Answer\Domain\GroundingEvidence;
use KnowKit\ContextCompilers\Domain\CitationAliasMap;
use KnowKit\Domain\CitationRef;
use KnowKit\Domain\Contract\ContractValidator;

/** Exact subset via CitationAliasMap, immutable provenance and bounded spans only. No entailment claim. */
final readonly class CitationValidator
{
    public function __construct(
        private ContractValidator $validator,
    ) {
    }

    /** @return list<CitationRef> */
    public function validate(mixed $wire, GroundingEvidence $evidence, ?CitationAliasMap $aliasMap = null): array
    {
        if (!is_array($wire) || $wire === []) {
            throw new \InvalidArgumentException('Exact citation aliases required');
        }
        Guard::orderedList($wire, 64);
        $map = $aliasMap ?? CitationAliasMap::fromEvidenceSet($evidence->set, $this->validator);
        $allowed = [];
        foreach ($evidence->citations() as $citation) {
            $allowed[$citation->id()->value] = $citation;
        }
        $citations = [];
        foreach ($wire as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Citation alias string required');
            }
            $resolved = $map->resolve($value);
            if ($resolved === null) {
                throw new \InvalidArgumentException('Unknown citation alias: ' . $value);
            }
            $id = $resolved->id()->value;
            if (!isset($allowed[$id]) || isset($citations[$id]) || $resolved->canonical() !== $allowed[$id]->canonical()) {
                throw new \InvalidArgumentException('Citation is outside disclosed provenance/spans');
            }
            $citations[$id] = $allowed[$id];
        }

        return array_values($citations);
    }
}
