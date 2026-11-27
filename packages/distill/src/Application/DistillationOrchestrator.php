<?php

declare(strict_types=1);

namespace KnowKit\Distill\Application;

use KnowKit\Application\Access\{AccessContext, PolicyChecks};
use KnowKit\Distill\Domain\{DistillOutput, DistillRequest};
use KnowKit\Distill\Port\DistillModelTransport;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject};
use KnowKit\Domain\{
    Bbox,
    Decimal,
    DomainFailure,
    IrDocument,
    JobCheckpointManifest,
    JobResult,
    Lease,
    LocalId,
    Outcome,
    ResourceId,
    ResourceRef,
    Scope,
    Sha256,
    TextLocator,
};
use KnowKit\Port\JobQueue;

/** Coordinates gated transport calls, IR block extraction, and fenced JobCheckpointManifest commits. */
final readonly class DistillationOrchestrator
{
    public function __construct(
        private JobQueue $jobs,
    ) {
    }

    /**
     * @param \Closure(Scope, \KnowKit\Domain\EgressRequest): JsonObject $currentBinding
     * @param list<ResourceRef> $collections
     */
    public static function transmitGated(
        PolicyChecks $policy,
        ContractValidator $validator,
        DistillModelTransport $transport,
        AccessContext $context,
        DistillRequest $request,
        \Closure $currentBinding,
        array $collections = [],
    ): DistillOutput {
        if ($transport->isFixture() && $request->profile->production) {
            throw new DomainFailure(Outcome::Incompatible, 'fixture_transport_in_production');
        }

        $egressRequest = $request->toEgressRequest($validator);

        /** @var DistillOutput $output */
        $output = $policy->transmit(
            $context,
            $egressRequest,
            [$request->promptBytes],
            $currentBinding,
            static fn (): DistillOutput => $transport->send($request),
            $collections,
        );

        return $output;
    }

    /**
     * @param callable(): array{inputDigest: Sha256, outputDigest: Sha256, resourceId: ResourceId, byteLength: int, uncertainExternalEffect?: bool} $work
     */
    public function executeFenced(
        Lease $lease,
        string $stage,
        Sha256 $processingDigest,
        callable $work,
    ): JobCheckpointManifest {
        if (!$this->jobs->heartbeat($lease)) {
            throw new DomainFailure(Outcome::Denied, 'stale_lease');
        }

        $outcome = $work();
        $byteLength = $outcome['byteLength'];
        if ($byteLength < 0 || $byteLength > 268435456) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_checkpoint');
        }

        $resources = [
            new JsonObject([
                'resourceId' => $outcome['resourceId']->value,
                'digest' => $outcome['outputDigest']->value,
                'byteLength' => (string) $byteLength,
            ]),
        ];

        $manifest = new JobCheckpointManifest(new JsonObject([
            'schemaVersion' => 'job-checkpoint-1.0',
            'tenantId' => $lease->scope->tenantId->value,
            'kbId' => $lease->scope->kbId->value,
            'sourceId' => $lease->sourceRef->sourceId->value,
            'sourceVersionId' => $lease->sourceRef->sourceVersionId->value,
            'jobId' => $lease->jobId->value,
            'sourceEpoch' => $lease->sourceEpoch->value,
            'processingDigest' => $processingDigest->value,
            'stage' => $stage,
            'inputDigest' => $outcome['inputDigest']->value,
            'outputDigest' => HashDomain::ResourceInventory->digest($resources),
            'uncertainExternalEffect' => $outcome['uncertainExternalEffect'] ?? false,
            'resources' => $resources,
        ]));

        if (!$this->jobs->checkpoint($lease, $manifest->checkpoint())) {
            throw new DomainFailure(Outcome::Denied, 'stale_lease');
        }
        if (!$this->jobs->complete($lease, new JobResult(Outcome::Success, $outcome['outputDigest']))) {
            throw new DomainFailure(Outcome::Denied, 'stale_lease');
        }

        return $manifest;
    }

    /**
     * @return array{blocks: list<array{blockId: string, kind: string, text: string, locators: list<TextLocator>}>, corpusText: string}
     */
    public static function extractIrBlocks(IrDocument $ir): array
    {
        $wire = $ir->toWire();
        /** @var array<string, string> $pageTexts */
        $pageTexts = [];
        $corpusParts = [];
        foreach ($wire->get('pages') as $page) {
            if ($page instanceof JsonObject) {
                $text = (string) $page->get('text');
                $pageTexts[(string) $page->get('pageId')] = $text;
                $corpusParts[] = $text;
            }
        }
        /** @var array<string, string> $segmentTexts */
        $segmentTexts = [];
        foreach ($wire->get('segments') as $segment) {
            if ($segment instanceof JsonObject) {
                $text = (string) $segment->get('text');
                $segmentTexts[(string) $segment->get('segmentId')] = $text;
                $corpusParts[] = $text;
            }
        }

        /** @var list<JsonObject> $rawBlocks */
        $rawBlocks = [];
        foreach ($wire->get('blocks') as $block) {
            if ($block instanceof JsonObject) {
                $rawBlocks[] = $block;
            }
        }
        usort($rawBlocks, static function (JsonObject $a, JsonObject $b): int {
            $cmp = (int) $a->get('order') <=> (int) $b->get('order');
            return $cmp !== 0 ? $cmp : strcmp((string) $a->get('blockId'), (string) $b->get('blockId'));
        });

        $blocks = [];
        foreach ($rawBlocks as $block) {
            $blockId = (string) $block->get('blockId');
            $kind = (string) $block->get('kind');
            $parts = [];
            $locators = [];
            foreach ($block->get('spans') as $span) {
                if (!$span instanceof JsonObject) {
                    continue;
                }
                $spanKind = (string) $span->get('kind');
                $start = (int) $span->get('byteStart');
                $end = (int) $span->get('byteEnd');
                $unitText = $spanKind === 'page'
                    ? ($pageTexts[(string) $span->get('pageId')] ?? '')
                    : ($segmentTexts[(string) $span->get('segmentId')] ?? '');
                if ($unitText !== '' && $end > $start && $end <= strlen($unitText)) {
                    $parts[] = substr($unitText, $start, $end - $start);
                }
                $locators[] = self::toLocator($span);
            }
            $blocks[] = [
                'blockId' => $blockId,
                'kind' => $kind,
                'text' => implode('', $parts),
                'locators' => $locators,
            ];
        }

        return [
            'blocks' => $blocks,
            'corpusText' => implode("\n", $corpusParts),
        ];
    }

    private static function toLocator(JsonObject $span): TextLocator
    {
        if ((string) $span->get('kind') === 'page') {
            $bboxWire = $span->has('bbox') ? $span->get('bbox') : null;
            $bbox = $bboxWire instanceof JsonObject
                ? new Bbox(
                    (int) $bboxWire->get('x0'),
                    (int) $bboxWire->get('y0'),
                    (int) $bboxWire->get('x1'),
                    (int) $bboxWire->get('y1'),
                )
                : null;
            $printed = $span->get('printedLabel');
            return TextLocator::page(
                new LocalId((string) $span->get('pageId')),
                (int) $span->get('physicalNo'),
                is_string($printed) ? $printed : null,
                new LocalId((string) $span->get('blockId')),
                (int) $span->get('byteStart'),
                (int) $span->get('byteEnd'),
                $bbox,
            );
        }

        return TextLocator::segment(
            new LocalId((string) $span->get('segmentId')),
            new LocalId((string) $span->get('blockId')),
            (int) $span->get('byteStart'),
            (int) $span->get('byteEnd'),
        );
    }
}
