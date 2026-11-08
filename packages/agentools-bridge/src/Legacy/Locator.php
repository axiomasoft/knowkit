<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Legacy;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\ValueGuard;

/** Donor locator units are retained verbatim; these are not canonical IR byte offsets. */
final readonly class Locator
{
    public function __construct(
        public ReleaseIdentity $release,
        public string $material,
        public string $version,
        public string $fragment,
        public int $page,
        public int $start,
        public int $end,
        public Sha256 $contentHash,
    ) {
        foreach ([$material, $version, $fragment] as $value) {
            ValueGuard::text(value: $value, max: 128);
        }
        if ($page < 1 || $start < 0 || $end <= $start) {
            throw new \InvalidArgumentException('Exact nonempty legacy locator required');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'corpus' => $this->release->corpus,
            'release' => $this->release->releaseId,
            'release_hash' => $this->release->legacyHash,
            'material' => $this->material,
            'version' => $this->version,
            'fragment' => $this->fragment,
            'page' => $this->page,
            'start' => $this->start,
            'end' => $this->end,
            'content_hash' => $this->contentHash->value,
        ]);
    }
}
