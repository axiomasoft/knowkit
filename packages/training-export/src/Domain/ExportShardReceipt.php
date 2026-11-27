<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;

final readonly class ExportShardReceipt
{
    public function __construct(
        public string $path,
        public int $recordCount,
        public Sha256 $sha256,
        public int $byteLength,
    ) {
        if (preg_match('/^[A-Za-z0-9._\/-]{1,256}$/D', $path) !== 1) {
            throw new \InvalidArgumentException('Invalid shard path');
        }
        if ($recordCount < 0 || $recordCount > 10000000) {
            throw new \InvalidArgumentException('Invalid recordCount');
        }
        if ($byteLength < 0 || $byteLength > 1073741824) {
            throw new \InvalidArgumentException('Invalid byteLength');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'path' => $this->path,
            'recordCount' => $this->recordCount,
            'sha256' => $this->sha256->value,
            'byteLength' => $this->byteLength,
        ]);
    }
}
