<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, JsonObject};

/** The four axes are invalidated downstream; original bytes never become parser identity. */
final readonly class ProcessingIdentity
{
    public function __construct(
        public SourceVersionId $sourceVersionId, public SourceDigest $sourceDigest,
        public ParserSignature $parserSignature, public ParserSignature $ocrProfileSignature,
        public ParserSignature $chunkerSignature, public string $irSchema,
        public Fingerprint $indexProfileDigest, public ?Fingerprint $embeddingSpaceDigest,
    ) {
        if ($irSchema !== 'ir-1.0') { throw new DomainFailure(Outcome::Incompatible, 'unsupported_schema_version'); }
    }
    public function toWire(): JsonObject
    {
        return new JsonObject(['sourceVersionId' => $this->sourceVersionId->value, 'sourceDigest' => $this->sourceDigest->value,
            'parserSignature' => $this->parserSignature->toWire(), 'ocrProfileSignature' => $this->ocrProfileSignature->toWire(),
            'chunkerSignature' => $this->chunkerSignature->toWire(), 'irSchema' => $this->irSchema,
            'indexProfileDigest' => $this->indexProfileDigest->value, 'embeddingSpaceDigest' => $this->embeddingSpaceDigest?->value]);
    }
    public function digest(): Fingerprint
    {
        return new Fingerprint(HashDomain::ProcessingIdentity->digest($this->toWire()));
    }

    public function equals(self $other): bool
    {
        return $this->sourceVersionId->equals($other->sourceVersionId)
            && $this->sourceDigest->equals($other->sourceDigest)
            && $this->parserSignature->equals($other->parserSignature)
            && $this->ocrProfileSignature->equals($other->ocrProfileSignature)
            && $this->chunkerSignature->equals($other->chunkerSignature)
            && $this->irSchema === $other->irSchema
            && $this->indexProfileDigest->equals($other->indexProfileDigest)
            && ($this->embeddingSpaceDigest === null
                ? $other->embeddingSpaceDigest === null
                : ($other->embeddingSpaceDigest !== null && $this->embeddingSpaceDigest->equals($other->embeddingSpaceDigest)));
    }

    /** @return list<string> IDs to allocate anew; earlier refs remain immutable. */
    public function changedAxes(self $next): array
    {
        if ($this->sourceVersionId->equals($next->sourceVersionId) && !$this->sourceDigest->equals($next->sourceDigest)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        if (!$this->sourceVersionId->equals($next->sourceVersionId)) {
            return ['sourceVersionId', 'documentRevisionId', 'chunkSetId', 'generationId'];
        }
        if (!$this->parserSignature->equals($next->parserSignature)
            || !$this->ocrProfileSignature->equals($next->ocrProfileSignature)
            || $this->irSchema !== $next->irSchema) {
            return ['documentRevisionId', 'chunkSetId', 'generationId'];
        }
        if (!$this->chunkerSignature->equals($next->chunkerSignature)) {
            return ['chunkSetId', 'generationId'];
        }
        $sameSpace = $this->embeddingSpaceDigest === null
            ? $next->embeddingSpaceDigest === null
            : ($next->embeddingSpaceDigest !== null && $this->embeddingSpaceDigest->equals($next->embeddingSpaceDigest));
        if (!$this->indexProfileDigest->equals($next->indexProfileDigest) || !$sameSpace) {
            return ['generationId'];
        }
        return [];
    }
}
