<?php

declare(strict_types=1);

namespace KnowKit\Application\SourceNavigation;

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Domain as D;
use KnowKit\Domain\Navigation as N;

/** @internal Typed navigation over a digest-verified, exact revision. No backend snippets. */
final readonly class CanonicalDocument
{
    /** @var list<N\CanonicalUnit> */
    public array $units;
    /** @var list<N\CanonicalBlock> */
    public array $blocks;
    /** @var array<string, N\CanonicalBlock> */
    private array $blocksById;
    /** @var array<string, list<N\CanonicalUnit>> */
    private array $unitsByKey;
    private CanonicalMap $map;
    public D\FrozenJson $coverage;

    public function __construct(D\IrDocument $ir, D\DocumentRevisionRef $revision, D\DocumentId $documentId)
    {
        if (strlen($ir->canonical()) > 8388608) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_preload_limit');
        }
        $this->map = new CanonicalMap($ir, $revision, $documentId);
        $this->coverage = new D\FrozenJson($ir->toWire()->get('coverage'));
        $units = [];
        foreach (['page' => $ir->pages(), 'segment' => $ir->segments()] as $kind => $items) {
            foreach ($items as $unit) {
                $units[] = N\CanonicalUnit::fromWire($unit, $kind);
            }
        }
        if (count($units) > 1024) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_unit_limit');
        }
        $this->units = $units;
        $blocks = [];
        foreach ($this->map->blocks as $block) {
            $parent = $block->get('parentId');
            $provenance = $block->get('provenance');
            $blocks[] = new N\CanonicalBlock(new D\LocalId($block->get('blockId')), $block->get('kind'),
                $parent === null ? null : new D\LocalId($parent), $block->get('order'), $this->map->spans($block->get('spans')),
                $block->get('origin'), $provenance->get('kind'), $provenance->get('supportingRefs'));
        }
        usort($blocks, static fn (N\CanonicalBlock $a, N\CanonicalBlock $b): int =>
            [$a->order, $a->id->value] <=> [$b->order, $b->id->value]);
        $this->blocks = $blocks;
        $byId = [];
        foreach ($blocks as $block) {
            $byId[$block->id->value] ??= $block;
        }
        $this->blocksById = $byId;
        $byUnit = [];
        foreach ($units as $unit) {
            $byUnit[$unit->ref->kind . ':' . $unit->ref->id->value][] = $unit;
        }
        $this->unitsByKey = $byUnit;
    }

    /** @return list<N\SourceUnitRef> */
    public function sourceUnits(): array
    {
        return array_map(static fn (N\CanonicalUnit $unit): N\SourceUnitRef => $unit->ref, $this->units);
    }

    public function outline(D\DocumentRevisionRef $revision, int $depth, int $limit, N\Limits $limits): N\StructureOutline
    {
        $nodes = [];
        $bytes = 0;
        $truncated = false;
        foreach ($this->blocks as $block) {
            $level = count($this->ancestors($block)) + 1;
            if ($level > $depth || count($nodes) >= $limit) {
                $truncated = true;
                continue;
            }
            $title = $block->kind === 'heading' && $block->spans !== [] ? $this->text($block) : null;
            $bytes += strlen($title ?? '');
            if ($bytes > $limits->maxTextBytes) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
            $nodes[] = new N\StructureNode($block->id, $block->kind, $block->parentId, $level, $title, $block->spans,
                $block->origin, $block->provenanceKind, $block->supportingRefs);
        }

        return new N\StructureOutline(D\Outcome::Success, $revision, $nodes, $truncated, coverage: $this->coverage);
    }

    /** @return list<N\Unit> */
    public function read(N\UnitRange $range, int $maxBytes, N\Limits $limits): array
    {
        $selected = $this->select($range, $limits);
        $result = [];
        $bytes = 0;
        foreach ($selected as $unit) {
            $text = $unit instanceof N\CanonicalUnit
                ? $unit->text()
                : ($unit->spans === [] ? '' : $this->text($unit));
            // Unit refuses text above its hard cap before this budget check can run.
            if ($bytes + strlen($text) > $maxBytes) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
            $result[] = $unit instanceof N\CanonicalUnit ? $this->wholeUnit($unit) : $this->blockUnit($unit, $text);
            $bytes += strlen($text);
        }

        return $result;
    }

    /** @return list<N\CanonicalUnit>|list<N\CanonicalBlock> */
    private function select(N\UnitRange $range, N\Limits $limits): array
    {
        $range->requireLimits($limits);
        if ($range->kind === 'section') {
            $root = $range->root ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_locator');
            $selected = $this->section($root, $limits->maxUnits);
        } elseif ($range->kind === 'block') {
            $selected = array_slice($this->blocks, $range->first - 1, $range->last - $range->first + 1);
        } elseif ($range->kind === 'segment') {
            $segments = array_values(array_filter($this->units, static fn (N\CanonicalUnit $u): bool => $u->ref->kind === 'segment'));
            $selected = array_slice($segments, $range->first - 1, $range->last - $range->first + 1);
        } else {
            $selected = array_values(array_filter($this->units, static fn (N\CanonicalUnit $u): bool =>
                $u->ref->kind === 'page' && $u->physicalNo >= $range->first && $u->physicalNo <= $range->last));
            usort($selected, static fn (N\CanonicalUnit $a, N\CanonicalUnit $b): int => $a->physicalNo <=> $b->physicalNo);
            $expected = $range->first;
            foreach ($selected as $unit) {
                if ($unit->physicalNo !== $expected) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_locator');
                }
                ++$expected;
            }
        }
        if (count($selected) > $limits->maxUnits) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        if ($selected === [] || ($range->kind !== 'section' && count($selected) !== $range->last - $range->first + 1)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_locator');
        }

        return $selected;
    }

    /** @return list<N\CanonicalBlock> */
    private function section(D\LocalId $root, int $maxUnits): array
    {
        $selected = [];
        $heading = false;
        foreach ($this->blocks as $block) {
            if (count($selected) >= $maxUnits
                && ($block->id->equals($root) || in_array($root->value, $this->ancestors($block), true))) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
            if ($block->id->equals($root)) {
                $heading = $block->kind === 'heading';
                $selected[] = $block;
                continue;
            }
            if (in_array($root->value, $this->ancestors($block), true)) {
                $selected[] = $block;
            }
        }
        if (!$heading) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_locator');
        }

        return $selected;
    }

    /** @return list<string> */
    private function ancestors(N\CanonicalBlock $block): array
    {
        $parents = [];
        while ($block->parentId !== null) {
            if (in_array($block->parentId->value, $parents, true) || count($parents) >= 1024) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_structure');
            }
            $parentId = $block->parentId->value;
            $parents[] = $parentId;
            $block = $this->blocksById[$parentId]
                ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_structure');
        }

        return $parents;
    }

    private function wholeUnit(N\CanonicalUnit $unit): N\Unit
    {
        return new N\Unit($unit->ref->kind, $unit->ref->id, $unit->physicalNo, $unit->printedLabel,
            $unit->status, $unit->text(), [], [$unit->ref]);
    }

    private function blockUnit(N\CanonicalBlock $block, string $text): N\Unit
    {
        $refs = [];
        foreach ($block->spans as $span) {
            $wire = $span->locator->toWire();
            $kind = $wire->get('kind');
            $id = $wire->get($kind === 'page' ? 'pageId' : 'segmentId');
            $key = $kind . ':' . $id;
            foreach ($this->unitsByKey[$key] ?? [] as $unit) {
                $unit->text();
                $refs[$key] = $unit->ref;
            }
        }

        return new N\Unit('block', $block->id, null, null, $text === '' ? 'empty' : 'ok', $text,
            $block->spans, array_values($refs), count($block->spans) > 1 ? "\n" : null,
            $block->origin, $block->provenanceKind, $block->supportingRefs);
    }

    private function text(N\CanonicalBlock $block): string
    {
        return $this->map->assembler->assemble($block->spans, count($block->spans) > 1 ? "\n" : null);
    }
}
