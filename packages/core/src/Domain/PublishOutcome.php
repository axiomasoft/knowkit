<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class PublishOutcome
{
    /** Runtime checks the generic data type at this PHP boundary. */
    public function __construct(public Result $result)
    {
        if ($result->data !== null && !$result->data instanceof PublishReceipt) { throw new \InvalidArgumentException('PublishReceipt outcome required'); }
    }
}
