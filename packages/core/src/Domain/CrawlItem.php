<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class CrawlItem
{
    public function __construct(public string $originKey, public string $logicalPath, public string $immutableRevision,
        public Sha256 $contentDigest, public Uint64 $byteLength, public string $status)
    {
        foreach ([$originKey,$logicalPath,$immutableRevision] as $text) { ValueGuard::text($text, 1024); }
        if (!in_array($status, ['verified', 'failed', 'unavailable'], true)) {
            throw new \InvalidArgumentException('Crawl status required');
        }
    }

    public function equals(self $other): bool
    {
        return $this->originKey === $other->originKey
            && $this->logicalPath === $other->logicalPath
            && $this->immutableRevision === $other->immutableRevision
            && $this->contentDigest->equals($other->contentDigest)
            && $this->byteLength->equals($other->byteLength)
            && $this->status === $other->status;
    }
}
