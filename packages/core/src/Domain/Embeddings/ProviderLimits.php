<?php

declare(strict_types=1);

namespace KnowKit\Domain\Embeddings;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final readonly class ProviderLimits
{
    public function __construct(
        public int $maxBatchSize,
        public int $maxInputBytes,
        public int $maxBatchBytes,
    ) {
        if ($maxBatchSize < 1 || $maxBatchSize > 1024 || $maxInputBytes < 1 || $maxBatchBytes < $maxInputBytes) {
            throw new \InvalidArgumentException('Positive bounded provider limits required');
        }
    }

    /** @param array<array-key, string> $texts */
    public function assertInputs(array $texts): void
    {
        if (!array_is_list($texts) || $texts === [] || count($texts) > $this->maxBatchSize) {
            throw new DomainFailure(Outcome::Incompatible, 'embedding_batch_limit');
        }
        $bytes = 0;
        foreach ($texts as $text) {
            if (trim($text) === '' || strlen($text) > $this->maxInputBytes) {
                throw new DomainFailure(Outcome::Incompatible, 'embedding_input_limit');
            }
            $bytes += strlen($text);
        }
        if ($bytes > $this->maxBatchBytes) {
            throw new DomainFailure(Outcome::Incompatible, 'embedding_batch_limit');
        }
    }
}
