<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class DisclosureOutcome
{
    /** Runtime checks the generic data type at this PHP boundary. */
    public function __construct(public Result $result)
    {
        if ($result->data !== null && !$result->data instanceof EvidenceSet && !$result->data instanceof DisclosureReplay) {
            throw new \InvalidArgumentException('EvidenceSet or metadata-only replay required');
        }
    }
}
