<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Pruning;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\EgressRequest;
use KnowKit\Domain\Operation;
use KnowKit\Domain\Pruning\PrunableUnit;
use KnowKit\Domain\Pruning\PrunedSelection;
use KnowKit\Domain\Pruning\PruningPolicy;
use KnowKit\Domain\QueryBinding;
use KnowKit\Domain\ResourceRef;
use KnowKit\Port\ContextPruner;

/** Enforces PolicyChecks::transmit with a complete QUERY + EVIDENCE manifest before any external pruner call. */
final readonly class PolicyCheckedContextPruner
{
    public function __construct(
        private ContextPruner $inner,
        private PolicyChecks $policy,
        private ContractValidator $validator,
        private JsonObject $destination,
        private Operation $operation = Operation::Retrieve,
    ) {}

    /**
     * @param list<PrunableUnit> $units
     * @param list<ResourceRef> $collections
     * @return list<PrunedSelection>
     */
    public function prune(
        AccessContext $context,
        QueryBinding $query,
        array $units,
        PruningPolicy $policy,
        \Closure $currentBinding,
        array $collections = [],
    ): array {
        if ($units === []) {
            return [];
        }

        $queryText = $query->original->text();
        $items = [
            new JsonObject([
                'category' => 'QUERY',
                'contentDigest' => hash('sha256', $queryText),
                'sourceVersionRefs' => [],
            ]),
        ];
        $contents = [$queryText];
        $seen = [hash('sha256', "QUERY\0" . hash('sha256', $queryText) . "\0[]") => true];

        foreach ($units as $unit) {
            $item = new JsonObject([
                'category' => 'EVIDENCE',
                'contentDigest' => $unit->chunk->ref->chunkDigest->value,
                'sourceVersionRefs' => [$unit->chunk->ref->revisionRef->sourceRef->toWire()],
            ]);
            $key = hash('sha256', 'EVIDENCE' . "\0" . $unit->chunk->ref->chunkDigest->value . "\0" . Jcs::canonicalize($item));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $item;
            $contents[] = $unit->text();
        }

        $wire = new JsonObject([
            'operation' => $this->operation->value,
            'destination' => $this->destination,
            'items' => $items,
        ]);
        $request = new EgressRequest(
            $wire->with('manifestDigest', hash('sha256', Jcs::canonicalize($wire))),
            $this->validator,
        );

        /** @var list<PrunedSelection> $result */
        $result = $this->policy->transmit(
            $context,
            $request,
            $contents,
            $currentBinding,
            fn (): array => $this->inner->prune($query, $units, $policy),
            $collections,
        );

        return $result;
    }
}
