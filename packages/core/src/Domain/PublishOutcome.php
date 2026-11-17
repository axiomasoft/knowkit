<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class PublishOutcome
{
    /**
     * Runtime checks the generic data type at this PHP boundary.
     *
     * @param Result<PublishReceipt> $result
     */
    public function __construct(public Result $result)
    {
        /** @var object|null $data */
        $data = $result->data;
        if ($data !== null && !$data instanceof PublishReceipt) {
            throw new \InvalidArgumentException('PublishReceipt outcome required');
        }
    }
}
