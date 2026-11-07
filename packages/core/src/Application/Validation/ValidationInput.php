<?php
declare(strict_types=1);

namespace KnowKit\Application\Validation;

use KnowKit\Domain\{DocumentRevisionRef, LocalId, ProcessingIdentity, SourceBytes};

/** Preloaded outside the catalog transaction. Bytes must never be logged. */
final readonly class ValidationInput
{
    /** @param array<array-key,mixed> $assets Exact local asset ID => original bytes, checked at this boundary. */
    public function __construct(public SourceBytes $source, public DocumentRevisionRef $revision,
        public ProcessingIdentity $processing, public array $assets)
    {
        if (count($assets) > 2000) { throw new \InvalidArgumentException('Bounded asset inventory required'); }
        foreach ($assets as $id => $bytes) {
            new LocalId((string) $id);
            if (!is_string($bytes)) { throw new \InvalidArgumentException('Asset bytes required'); }
        }
    }

    public function __debugInfo(): array { return ['revision' => $this->revision]; }
}
