<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class RetrievalOutcome
{
    /** Runtime checks the generic data type at this PHP boundary. */
    public function __construct(public Result $result)
    {
        if ($result->data !== null && !$result->data instanceof Selection) { throw new \InvalidArgumentException('Selection outcome required'); }
    }
}
