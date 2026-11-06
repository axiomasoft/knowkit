<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class DocumentRevisionRef
{
    public function __construct(public ExactSourceRef $sourceRef, public DocumentRevisionId $documentRevisionId,
        public IrDigest $irDigest, public ParserSignature $parserSignature) {}
    public function toWire(): JsonObject
    {
        return $this->sourceRef->toWire()->with('documentRevisionId', $this->documentRevisionId->value)
            ->with('irDigest', $this->irDigest->value)->with('parserSignature', $this->parserSignature->toWire());
    }
}
