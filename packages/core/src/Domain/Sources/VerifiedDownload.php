<?php
declare(strict_types=1);

namespace KnowKit\Domain\Sources;

use KnowKit\Domain\CrawlItem;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** A byte verification receipt, never a prefix or a mutable URL. Holds no plaintext. */
final readonly class VerifiedDownload
{
    private function __construct(
        public CrawlItem $item,
        public string $readRevision,
    ) {}

    public static function verify(CrawlItem $item, string $readRevision, string $bytes, int $maxBytes): self
    {
        if ($readRevision !== $item->immutableRevision) {
            throw new DomainFailure(Outcome::Incompatible, 'download_revision_mismatch');
        }
        if ($maxBytes < 0 || strlen($bytes) > $maxBytes) {
            throw new DomainFailure(Outcome::Incompatible, 'download_limit_exceeded');
        }
        if ($item->status !== 'verified' || (string) strlen($bytes) !== $item->byteLength->value
            || !hash_equals($item->contentDigest->value, hash('sha256', $bytes))) {
            throw new DomainFailure(Outcome::Incompatible, 'download_integrity_mismatch');
        }

        return new self($item, $readRevision);
    }
}
