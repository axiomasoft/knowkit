<?php
declare(strict_types=1);

namespace KnowKit\Application\Artifacts;

use KnowKit\Domain\{DocumentRevisionRef, IrDocument, LocalId};
use KnowKit\Domain\Artifacts\{NavigationTreeArtifact, NavigationTreeNode};
use KnowKit\Domain\Contract\JsonObject;

final readonly class DeterministicOutlineBuilder
{
    private const STOPWORDS = [
        'the' => true, 'and' => true, 'for' => true, 'that' => true, 'with' => true,
        'this' => true, 'from' => true, 'are' => true, 'was' => true, 'were' => true,
        'have' => true, 'has' => true, 'had' => true, 'not' => true, 'but' => true,
        'can' => true, 'will' => true, 'into' => true, 'than' => true, 'then' => true,
        'when' => true, 'where' => true, 'which' => true, 'while' => true, 'about' => true,
        'если' => true, 'что' => true, 'это' => true, 'как' => true, 'для' => true,
        'при' => true, 'или' => true, 'все' => true, 'всех' => true, 'его' => true,
        'она' => true, 'они' => true, 'быть' => true, 'так' => true, 'уже' => true,
        'под' => true, 'над' => true, 'между' => true, 'через' => true, 'после' => true,
    ];

    public function build(IrDocument $ir, DocumentRevisionRef $revision): NavigationTreeArtifact
    {
        $wire = $ir->toWire();
        $pageTexts = [];
        $pageLabels = [];
        $sourceBytes = 0;

        foreach ($wire->get('pages') as $page) {
            if (!$page instanceof JsonObject) {
                continue;
            }
            $pageId = (string) $page->get('pageId');
            $text = (string) $page->get('text');
            $pageTexts[$pageId] = $text;
            $printed = $page->get('printedLabel');
            $pageLabels[$pageId] = is_string($printed) && $printed !== ''
                ? $printed
                : ('p.' . (string) $page->get('physicalNo'));
            $sourceBytes += strlen($text);
        }

        $segmentTexts = [];
        foreach ($wire->get('segments') as $segment) {
            if (!$segment instanceof JsonObject) {
                continue;
            }
            $segmentId = (string) $segment->get('segmentId');
            $text = (string) $segment->get('text');
            $segmentTexts[$segmentId] = $text;
            $sourceBytes += strlen($text);
        }

        /** @var list<JsonObject> $blocks */
        $blocks = [];
        foreach ($wire->get('blocks') as $block) {
            if ($block instanceof JsonObject) {
                $blocks[] = $block;
            }
        }
        usort($blocks, static function (JsonObject $a, JsonObject $b): int {
            $cmp = (int) $a->get('order') <=> (int) $b->get('order');
            return $cmp !== 0 ? $cmp : strcmp((string) $a->get('blockId'), (string) $b->get('blockId'));
        });

        /** @var array<string, JsonObject> $blockMap */
        $blockMap = [];
        /** @var array<string, bool> $headingIds */
        $headingIds = [];
        foreach ($blocks as $block) {
            $id = (string) $block->get('blockId');
            $blockMap[$id] = $block;
            if ((string) $block->get('kind') === 'heading') {
                $headingIds[$id] = true;
            }
        }

        if ($headingIds === []) {
            return $this->buildFallbackOutline($blocks, $pageTexts, $segmentTexts, $pageLabels, $revision, $sourceBytes);
        }

        /** @var array<string, array{parentId: ?string, title: string, depth: int, firstUnit: ?string, lastUnit: ?string, formulaCount: int, codeCount: int, tableCount: int, terms: array<string, int>}> $sections */
        $sections = [];
        /** @var list<string> $sectionOrder */
        $sectionOrder = [];
        $currentHeadingId = null;

        foreach ($blocks as $block) {
            $blockId = (string) $block->get('blockId');
            $kind = (string) $block->get('kind');
            $text = $this->extractBlockText($block, $pageTexts, $segmentTexts);
            [$firstUnit, $lastUnit] = $this->extractUnitEndpoints($block, $pageLabels);

            if ($kind === 'heading') {
                $parentHeadingId = $this->resolveParentHeading($block, $blockMap, $headingIds);
                $depth = $parentHeadingId !== null && isset($sections[$parentHeadingId])
                    ? min(32, $sections[$parentHeadingId]['depth'] + 1)
                    : 0;
                $title = $this->truncateTitle($text !== '' ? $text : $blockId);

                $sections[$blockId] = [
                    'parentId' => $parentHeadingId,
                    'title' => $title,
                    'depth' => $depth,
                    'firstUnit' => $firstUnit,
                    'lastUnit' => $lastUnit,
                    'formulaCount' => 0,
                    'codeCount' => 0,
                    'tableCount' => 0,
                    'terms' => [],
                ];
                $this->accumulateTerms($sections[$blockId]['terms'], $text);
                $sectionOrder[] = $blockId;
                $currentHeadingId = $blockId;
                continue;
            }

            $ownerId = $this->resolveParentHeading($block, $blockMap, $headingIds) ?? $currentHeadingId;
            if ($ownerId === null || !isset($sections[$ownerId])) {
                continue;
            }

            if ($kind === 'formula') {
                $sections[$ownerId]['formulaCount']++;
            } elseif ($kind === 'code') {
                $sections[$ownerId]['codeCount']++;
            } elseif ($kind === 'table') {
                $sections[$ownerId]['tableCount']++;
            }

            if ($firstUnit !== null) {
                if ($sections[$ownerId]['firstUnit'] === null) {
                    $sections[$ownerId]['firstUnit'] = $firstUnit;
                }
                $sections[$ownerId]['lastUnit'] = $lastUnit ?? $firstUnit;
            }
            $this->accumulateTerms($sections[$ownerId]['terms'], $text);
        }

        $nodes = [];
        foreach ($sectionOrder as $id) {
            $sec = $sections[$id];
            $unitRange = [];
            if ($sec['firstUnit'] !== null) {
                $unitRange[] = $sec['firstUnit'];
                if ($sec['lastUnit'] !== null && $sec['lastUnit'] !== $sec['firstUnit']) {
                    $unitRange[] = $sec['lastUnit'];
                }
            }
            $nodes[] = new NavigationTreeNode(
                nodeId: new LocalId($id),
                parentId: $sec['parentId'] !== null ? new LocalId($sec['parentId']) : null,
                title: $sec['title'],
                depth: $sec['depth'],
                unitRange: $unitRange,
                formulaCount: $sec['formulaCount'],
                codeCount: $sec['codeCount'],
                tableCount: $sec['tableCount'],
                terms: $this->topTerms($sec['terms']),
            );
        }

        return new NavigationTreeArtifact($revision, $nodes, $sourceBytes);
    }

    /**
     * @param list<JsonObject> $blocks
     * @param array<string, string> $pageTexts
     * @param array<string, string> $segmentTexts
     * @param array<string, string> $pageLabels
     */
    private function buildFallbackOutline(
        array $blocks,
        array $pageTexts,
        array $segmentTexts,
        array $pageLabels,
        DocumentRevisionRef $revision,
        int $sourceBytes,
    ): NavigationTreeArtifact {
        if ($blocks === []) {
            return new NavigationTreeArtifact($revision, [], $sourceBytes);
        }

        $firstUnit = null;
        $lastUnit = null;
        $formulaCount = 0;
        $codeCount = 0;
        $tableCount = 0;
        /** @var array<string, int> $terms */
        $terms = [];

        foreach ($blocks as $block) {
            $kind = (string) $block->get('kind');
            if ($kind === 'formula') {
                $formulaCount++;
            } elseif ($kind === 'code') {
                $codeCount++;
            } elseif ($kind === 'table') {
                $tableCount++;
            }
            [$u1, $u2] = $this->extractUnitEndpoints($block, $pageLabels);
            if ($u1 !== null) {
                $firstUnit ??= $u1;
                $lastUnit = $u2 ?? $u1;
            }
            $this->accumulateTerms($terms, $this->extractBlockText($block, $pageTexts, $segmentTexts));
        }

        $unitRange = [];
        if ($firstUnit !== null) {
            $unitRange[] = $firstUnit;
            if ($lastUnit !== null && $lastUnit !== $firstUnit) {
                $unitRange[] = $lastUnit;
            }
        }

        $node = new NavigationTreeNode(
            nodeId: new LocalId('doc-root'),
            parentId: null,
            title: 'Document',
            depth: 0,
            unitRange: $unitRange,
            formulaCount: $formulaCount,
            codeCount: $codeCount,
            tableCount: $tableCount,
            terms: $this->topTerms($terms),
        );

        return new NavigationTreeArtifact($revision, [$node], $sourceBytes);
    }

    /**
     * @param array<string, string> $pageTexts
     * @param array<string, string> $segmentTexts
     */
    private function extractBlockText(JsonObject $block, array $pageTexts, array $segmentTexts): string
    {
        $parts = [];
        foreach ($block->get('spans') as $span) {
            if (!$span instanceof JsonObject) {
                continue;
            }
            $kind = (string) $span->get('kind');
            $start = (int) $span->get('byteStart');
            $end = (int) $span->get('byteEnd');
            $unitText = $kind === 'page'
                ? ($pageTexts[(string) $span->get('pageId')] ?? '')
                : ($segmentTexts[(string) $span->get('segmentId')] ?? '');
            if ($unitText !== '' && $end > $start && $end <= strlen($unitText)) {
                $parts[] = substr($unitText, $start, $end - $start);
            }
        }
        return trim(implode(' ', $parts));
    }

    /**
     * @param array<string, string> $pageLabels
     * @return array{0: ?string, 1: ?string}
     */
    private function extractUnitEndpoints(JsonObject $block, array $pageLabels): array
    {
        $first = null;
        $last = null;
        foreach ($block->get('spans') as $span) {
            if (!$span instanceof JsonObject) {
                continue;
            }
            $unit = (string) $span->get('kind') === 'page'
                ? ($pageLabels[(string) $span->get('pageId')] ?? (string) $span->get('pageId'))
                : (string) $span->get('segmentId');
            $first ??= $unit;
            $last = $unit;
        }
        return [$first, $last];
    }

    /**
     * @param array<string, JsonObject> $blockMap
     * @param array<string, bool> $headingIds
     */
    private function resolveParentHeading(JsonObject $block, array $blockMap, array $headingIds): ?string
    {
        $visited = [];
        $parentId = $block->get('parentId');
        while (is_string($parentId) && $parentId !== '' && !isset($visited[$parentId])) {
            $visited[$parentId] = true;
            if (isset($headingIds[$parentId])) {
                return $parentId;
            }
            $parentBlock = $blockMap[$parentId] ?? null;
            $parentId = $parentBlock?->get('parentId');
        }
        return null;
    }

    private function truncateTitle(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if ($normalized === '') {
            return 'Untitled';
        }
        if (strlen($normalized) <= 96) {
            return $normalized;
        }
        $cut = mb_strcut($normalized, 0, 93, 'UTF-8');
        return rtrim($cut) . '...';
    }

    /**
     * @param array<string, int> $counts
     */
    private function accumulateTerms(array &$counts, string $text): void
    {
        if ($text === '') {
            return;
        }
        if (preg_match_all('/\p{L}[\p{L}\p{N}_-]{2,31}/u', $text, $matches) !== false) {
            foreach ($matches[0] as $raw) {
                $term = mb_strtolower($raw, 'UTF-8');
                if (isset(self::STOPWORDS[$term])) {
                    continue;
                }
                $counts[$term] = ($counts[$term] ?? 0) + 1;
            }
        }
    }

    /**
     * @param array<string, int> $counts
     * @return list<string>
     */
    private function topTerms(array $counts): array
    {
        if ($counts === []) {
            return [];
        }
        $terms = array_keys($counts);
        usort($terms, static function (string $a, string $b) use ($counts): int {
            $cmp = $counts[$b] <=> $counts[$a];
            return $cmp !== 0 ? $cmp : strcmp($a, $b);
        });
        return array_slice($terms, 0, NavigationTreeNode::MAX_TERMS);
    }
}
