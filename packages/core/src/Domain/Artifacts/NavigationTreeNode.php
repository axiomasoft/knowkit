<?php
declare(strict_types=1);

namespace KnowKit\Domain\Artifacts;

use KnowKit\Domain\{LocalId, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

final readonly class NavigationTreeNode
{
    public const MAX_TITLE_BYTES = 160;
    public const MAX_TERMS = 8;

    /**
     * @param list<string> $unitRange
     * @param list<string> $terms
     */
    public function __construct(
        public LocalId $nodeId,
        public ?LocalId $parentId,
        public string $title,
        public int $depth,
        public array $unitRange,
        public int $formulaCount,
        public int $codeCount,
        public int $tableCount,
        public array $terms,
    ) {
        if (trim($title) === '' || preg_match('//u', $title) !== 1 || strlen($title) > self::MAX_TITLE_BYTES) {
            throw new \InvalidArgumentException('Bounded nonempty UTF-8 title required');
        }
        if ($depth < 0 || $depth > 32) {
            throw new \InvalidArgumentException('Depth must be 0..32');
        }
        ValueGuard::strings($unitRange);
        if (count($unitRange) > 2) {
            throw new \InvalidArgumentException('unitRange may have at most 2 endpoints');
        }
        if ($formulaCount < 0 || $codeCount < 0 || $tableCount < 0) {
            throw new \InvalidArgumentException('Atom counts must be non-negative');
        }
        ValueGuard::strings($terms, unique: true);
        if (count($terms) > self::MAX_TERMS) {
            throw new \InvalidArgumentException('At most 8 terms allowed per node');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'nodeId' => $this->nodeId->value,
            'parentId' => $this->parentId?->value,
            'title' => $this->title,
            'depth' => $this->depth,
            'unitRange' => $this->unitRange,
            'formulaCount' => $this->formulaCount,
            'codeCount' => $this->codeCount,
            'tableCount' => $this->tableCount,
            'terms' => $this->terms,
        ]);
    }
}
