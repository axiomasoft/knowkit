<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Selection extends SchemaValue
{
    protected const SCHEMA = 'selection';

    public function id(): SelectionId
    {
        return new SelectionId($this->toWire()->get('selectionId'));
    }

    public function expiresAt(): UtcInstant
    {
        return new UtcInstant($this->toWire()->get('expiresAt'));
    }

    public function generationId(): GenerationId
    {
        return new GenerationId($this->toWire()->get('generationId'));
    }

    public function runRef(): ?string
    {
        return $this->toWire()->get('runRef');
    }

    public function scopeBindingDigest(): Fingerprint
    {
        return new Fingerprint($this->toWire()->get('scopeBindingDigest'));
    }

    /** @return list<ChunkRef> */
    public function candidateRefs(): array
    {
        $refs = [];
        foreach ($this->toWire()->get('candidateRefs') as $ref) {
            $signature = $ref->get('parserSignature');
            $source = new SourceVersionRef(new TenantId($ref->get('tenantId')), new SourceId($ref->get('sourceId')),
                new SourceVersionId($ref->get('sourceVersionId')), new SourceDigest($ref->get('sourceDigest')));
            $revision = new DocumentRevisionRef($source, new DocumentRevisionId($ref->get('documentRevisionId')),
                new IrDigest($ref->get('irDigest')), new ParserSignature($signature->get('name'), $signature->get('version'),
                    new Fingerprint($signature->get('profileDigest'))));
            $refs[] = new ChunkRef($revision, new ChunkSetId($ref->get('chunkSetId')),
                new ChunkId($ref->get('chunkId')), new Sha256($ref->get('chunkDigest')));
        }

        return $refs;
    }
}
