<?php

declare(strict_types=1);

namespace KnowKit\Domain\Reading;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Exact selectors only. A label with several matches produces alternatives. */
final readonly class ReadTarget
{
    private function __construct(
        public string $kind,
        public ?D\LocalId $id = null,
        public ?int $physicalNo = null,
        public ?string $printedLabel = null,
        public ?D\Bbox $region = null,
    ) {}

    public static function quote(): self
    {
        return new self('quote');
    }

    public static function source(): self
    {
        return new self('source');
    }

    public static function page(?D\LocalId $id = null, ?int $physicalNo = null, ?string $printedLabel = null): self
    {
        if (($id === null && $physicalNo === null && $printedLabel === null)
            || ($physicalNo !== null && ($physicalNo < 1 || $physicalNo > 4294967295))) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_page_selector');
        }
        if ($printedLabel !== null) {
            D\ValueGuard::text($printedLabel, 64);
        }

        return new self('page', $id, $physicalNo, $printedLabel);
    }

    public static function asset(D\LocalId $id): self
    {
        return new self('asset', $id);
    }

    public static function table(D\LocalId $id): self
    {
        return new self('table', $id);
    }

    public static function region(D\LocalId $pageId, D\Bbox $region): self
    {
        return new self('region', $pageId, region: $region);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['kind' => $this->kind, 'id' => $this->id?->value,
            'physicalNo' => $this->physicalNo, 'printedLabel' => $this->printedLabel,
            'region' => $this->region?->toWire()]);
    }
}
