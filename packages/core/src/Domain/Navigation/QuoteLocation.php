<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;

/** Locations are not persisted citations. Normalized candidates never prove a quotation. */
final readonly class QuoteLocation
{
    /** @param list<QuoteCandidate> $candidates */
    public function __construct(
        public string $status,
        public D\DocumentRevisionRef $revision,
        public array $candidates = [],
        public bool $truncated = false,
        public ?string $errorCode = null,
        public ?D\Fingerprint $normalizerFingerprint = null,
        public ?D\FrozenJson $coverage = null,
    ) {
        D\ValueGuard::objects($candidates, QuoteCandidate::class);
        $matches = ['unique_exact', 'ambiguous', 'candidate_normalized'];
        $failures = ['not_found', 'denied', 'unknown', 'unavailable', 'incompatible'];
        if (!in_array($status, [...$matches, ...$failures], true) || count($candidates) > 16
            || (in_array($status, $matches, true) && $candidates === [])
            || (in_array($status, $failures, true) && ($candidates !== [] || $truncated))) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_location');
        }
        if ($candidates === []) {
            return;
        }
        $total = $candidates[0]->candidateCount;
        foreach ($candidates as $candidate) {
            if ($candidate->candidateCount !== $total) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_location');
            }
        }
        if (($status === 'ambiguous') !== ($total > 1) || $total < count($candidates)
            || $truncated !== ($total > count($candidates))
            || ($status === 'unique_exact' && $candidates[0]->matchKind !== 'exact')
            || ($status === 'candidate_normalized' && $candidates[0]->matchKind === 'exact')) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_location');
        }
    }
}
