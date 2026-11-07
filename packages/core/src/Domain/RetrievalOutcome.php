<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class RetrievalOutcome
{
    /** Runtime checks the generic data type at this PHP boundary. */
    /** @param list<ChunkRef> $readRequirements Exact separate-read refs, never permission to disclose. */
    public function __construct(
        public Result $result,
        public ?SelectionToken $token = null,
        public array $readRequirements = [],
    ) {
        if ($result->data !== null && !$result->data instanceof Selection) {
            throw new \InvalidArgumentException('Selection outcome required');
        }
        ValueGuard::objects($readRequirements, ChunkRef::class, 512);
        if (($token !== null && $result->outcome !== Outcome::Success)
            || ($readRequirements !== [] && !in_array($result->outcome, [Outcome::Success, Outcome::Empty], true))) {
            throw new \InvalidArgumentException('No partial selection payload on failure');
        }
    }
}
