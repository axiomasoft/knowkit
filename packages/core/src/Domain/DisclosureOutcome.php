<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Navigation\UnitDisclosure;

final readonly class DisclosureOutcome
{
    /** Runtime checks the generic data type at this PHP boundary. */
    public function __construct(public Result $result)
    {
        if ($result->data !== null && !$result->data instanceof EvidenceSet
            && !$result->data instanceof DisclosureReplay && !$result->data instanceof UnitDisclosure) {
            throw new \InvalidArgumentException('Committed evidence, unit disclosure or metadata-only replay required');
        }
    }
}
