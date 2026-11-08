<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\CitationRef;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;

/** Only validated final content. Evidence membership/fresh access remain the service's obligation. */
final readonly class AnsweredContent
{
    /** @param list<CitationRef> $citations */
    public function __construct(
        public string $text,
        public array $citations,
        public Sha256 $modelBindingDigest,
        public Sha256 $evidenceSetId,
    ) {
        Guard::utf8($text, 65536, false);
        Guard::objects($citations, CitationRef::class, 1024);
        if ($citations === []) {
            throw new \InvalidArgumentException('Bounded exact citations required');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'text' => $this->text,
            'citations' => array_map(static fn (CitationRef $citation) => $citation->toWire(), $this->citations),
            'modelBindingDigest' => $this->modelBindingDigest->value,
            'evidenceSetId' => $this->evidenceSetId->value,
        ]);
    }

    public function __debugInfo(): array
    {
        return ['bytes' => strlen($this->text), 'citations' => count($this->citations)];
    }
}
