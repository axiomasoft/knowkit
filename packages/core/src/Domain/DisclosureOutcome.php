<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Navigation\UnitDisclosure;

final readonly class DisclosureOutcome
{
    /**
     * Runtime checks the generic data type at this PHP boundary.
     *
     * @param Result<EvidenceSet|DisclosureReplay|UnitDisclosure> $result
     */
    public function __construct(public Result $result)
    {
        /** @var object|null $data */
        $data = $result->data;
        if ($data !== null && !$data instanceof EvidenceSet
            && !$data instanceof DisclosureReplay && !$data instanceof UnitDisclosure) {
            throw new \InvalidArgumentException('Committed evidence, unit disclosure or metadata-only replay required');
        }
    }
}
