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
    public function digest(): Fingerprint { return new Fingerprint(HashDomain::ProcessingIdentity->digest($this->toWire())); }

    /** @return list<string> IDs to allocate anew; earlier refs remain immutable. */
    public function changedAxes(self $next): array
    {
        if ($this->sourceVersionId->equals($next->sourceVersionId) && !$this->sourceDigest->equals($next->sourceDigest)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        if (!$this->sourceVersionId->equals($next->sourceVersionId)) {
            return ['sourceVersionId', 'documentRevisionId', 'chunkSetId', 'generationId'];
        }
        if ($this->parserSignature != $next->parserSignature || $this->ocrProfileSignature != $next->ocrProfileSignature
            || $this->irSchema !== $next->irSchema) {
            return ['documentRevisionId', 'chunkSetId', 'generationId'];
        }
        if ($this->chunkerSignature != $next->chunkerSignature) { return ['chunkSetId', 'generationId']; }
        if ($this->indexProfileDigest != $next->indexProfileDigest || $this->embeddingSpaceDigest != $next->embeddingSpaceDigest) {
            return ['generationId'];
        }
        return [];
    }
}
