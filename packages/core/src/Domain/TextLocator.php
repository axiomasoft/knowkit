<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{JsonObject, LocatorConversion};

final readonly class TextLocator
{
    private function __construct(private FrozenJson $value) {}

    public static function page(LocalId $pageId, int $physicalNo, ?string $printedLabel, LocalId $blockId,
        int $byteStart, int $byteEnd, ?Bbox $bbox = null): self
    {
        self::offsets($byteStart, $byteEnd);
        if ($physicalNo < 1 || $physicalNo > 4294967295) { throw new \InvalidArgumentException('Physical page must be 1-based'); }
        if ($printedLabel !== null) { ValueGuard::text($printedLabel, 64); }
        $wire = new JsonObject(['kind' => 'page', 'pageId' => $pageId->value, 'physicalNo' => $physicalNo,
            'printedLabel' => $printedLabel, 'blockId' => $blockId->value, 'byteStart' => $byteStart, 'byteEnd' => $byteEnd]);
        if ($bbox !== null) { $wire = $wire->with('bbox', $bbox->toWire()); }
        return new self(new FrozenJson($wire));
    }
    public static function segment(LocalId $segmentId, LocalId $blockId, int $byteStart, int $byteEnd): self
    {
        self::offsets($byteStart, $byteEnd);
        return new self(new FrozenJson(new JsonObject(['kind' => 'segment', 'segmentId' => $segmentId->value,
            'blockId' => $blockId->value, 'byteStart' => $byteStart, 'byteEnd' => $byteEnd])));
    }
    public function toWire(): JsonObject { return $this->value->toWire(); }
    public function physicalNo(): ?int { return $this->toWire()->get('physicalNo'); }
    public function slice(string $text): string
    {
        if (preg_match('//u', $text) !== 1) { throw new \InvalidArgumentException('UTF8 required'); }
        return LocatorConversion::resolveSpan($this->toWire()->with('text', $text))->get('text');
    }
    private static function offsets(int $start, int $end): void
    {
        if ($start < 0 || $end <= $start || $end > 4294967295) { throw new \InvalidArgumentException('Nonempty uint32 span required'); }
    }
}
