<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Sources;

use KnowKit\Domain\CrawlItem;
use KnowKit\Domain\Sources\VerifiedDownload;

/** Synthetic SDK transport body; debug output excludes bytes. */
final readonly class RecordedDownload
{
    public function __construct(
        public CrawlItem $item,
        public string $readRevision,
        private string $bytes,
        public int $maxBytes,
    ) {}

    public function verify(): VerifiedDownload
    {
        return VerifiedDownload::verify($this->item, $this->readRevision, $this->bytes, $this->maxBytes);
    }

    public function __debugInfo(): array
    {
        return ['originKey' => $this->item->originKey, 'readRevision' => $this->readRevision];
    }
}
