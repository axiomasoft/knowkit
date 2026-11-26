<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class CitationRef extends SchemaValue
{
    protected const SCHEMA = 'citation-ref';

    public function id(): Ulid
    {
        return new Ulid($this->toWire()->get('citationId'));
    }

    public function documentId(): DocumentId
    {
        return new DocumentId($this->toWire()->get('documentId'));
    }

    public function contentDigest(): Sha256
    {
        return new Sha256($this->toWire()->get('contentDigest'));
    }

    /** @return list<Span> */
    public function spans(): array
    {
        $spans = [];
        foreach ($this->toWire()->get('spans') as $wire) {
            $block = new LocalId($wire->get('blockId'));
            $box = $wire->get('bbox');
            $bbox = $box === null ? null : new Bbox($box->get('x0'), $box->get('y0'), $box->get('x1'), $box->get('y1'));
            $locator = $wire->get('kind') === 'page'
                ? TextLocator::page(new LocalId($wire->get('pageId')), $wire->get('physicalNo'), $wire->get('printedLabel'),
                    $block, $wire->get('byteStart'), $wire->get('byteEnd'), $bbox)
                : TextLocator::segment(new LocalId($wire->get('segmentId')), $block, $wire->get('byteStart'), $wire->get('byteEnd'));
            $spans[] = new Span($locator, new Sha256($wire->get('textDigest')));
        }

        return $spans;
    }

    public function requireChunk(ChunkRef $chunk): void
    {
        $wire = $this->toWire();
        foreach ($chunk->revisionRef->sourceRef->toWire() as $key => $value) {
            if ($wire->get($key) !== $value) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
        }
        if ($wire->get('documentRevisionId') !== $chunk->revisionRef->documentRevisionId->value
            || $wire->get('irDigest') !== $chunk->revisionRef->irDigest->value
            || !$this->contentDigest()->equals($chunk->chunkDigest)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }
}
