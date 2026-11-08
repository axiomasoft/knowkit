<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Legacy;

use KnowKit\Domain\CitationRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final readonly class CitationBinding
{
    public function __construct(
        public Locator $legacy,
        public CitationRef $canonical,
    ) {
        if ($legacy->release->tenantId->value !== $canonical->toWire()->get('tenantId')) {
            throw new DomainFailure(outcome: Outcome::Denied, errorCode: 'scope_mismatch');
        }
        if ($legacy->contentHash->value !== $canonical->contentDigest()->value) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'immutable_conflict');
        }
    }
}
