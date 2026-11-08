<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\EgressRequest;
use KnowKit\Domain\ResourceRef;

/** Exact whole-content mapping to core. Classification is supplied by the trusted host composer. */
final readonly class EgressContentManifest
{
    /** @param list<EgressContentItem> $items */
    public function __construct(
        public ModelCallIdentity $identity,
        public array $items,
    ) {
        Guard::objects($items, EgressContentItem::class, 1024);
        foreach ($items as $item) {
            foreach ($item->sources as $source) {
                $identity->binding->scope->assertTenant($source->tenantId);
            }
        }
        $this->requireCategory(EgressCategory::Question, $identity->dependencies->questionDigest->value);
        // Even unprotected/empty supplied history is classified rather than omitted.
        if ($identity->dependencies->history === []) {
            $this->requireCategory(EgressCategory::History);
        }
        foreach ($identity->dependencies->history as $history) {
            $this->requireCategory(EgressCategory::History, $history->contentDigest->value);
        }
        foreach ($identity->messages as $message) {
            $this->requireDigest($message->contentDigest->value);
        }
        $this->requireEvidence();
    }

    public function toCore(ContractValidator $validator): EgressRequest
    {
        $binding = $this->identity->binding;
        $wire = new JsonObject([
            'operation' => 'model_egress_context',
            'destination' => new JsonObject([
                'providerId' => $binding->provider,
                'accountId' => $binding->account,
                'region' => $binding->region,
                'modelId' => $binding->model,
            ]),
            'items' => array_map(static fn (EgressContentItem $item) => $item->toWire(), $this->items),
        ]);
        $digest = hash('sha256', "knowkit:answer-egress:v1\0" . $this->identity->digest()->value . Jcs::canonicalize($wire));

        return new EgressRequest($wire->with('manifestDigest', $digest), $validator);
    }

    /** @return list<ResourceRef> */
    public function resources(): array
    {
        $scope = $this->identity->binding->scope;
        $resources = [];
        foreach ($this->items as $item) {
            foreach ($item->sources as $source) {
                $resources[$source->sourceVersionId->value] = new ResourceRef($source->tenantId, 'source_version', $source->sourceVersionId);
            }
        }

        return array_values($resources ?: [new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId)]);
    }

    private function requireCategory(EgressCategory $category, ?string $digest = null): void
    {
        foreach ($this->items as $item) {
            if ($item->category === $category && ($digest === null || $item->contentDigest->value === $digest)) {
                return;
            }
        }
        throw new \InvalidArgumentException('Required egress category or digest missing');
    }

    private function requireDigest(string $digest): void
    {
        foreach ($this->items as $item) {
            if ($item->contentDigest->value === $digest) {
                return;
            }
        }
        throw new \InvalidArgumentException('Outbound message missing from whole-content manifest');
    }

    private function requireEvidence(): void
    {
        foreach ($this->identity->dependencies->evidence as $citation) {
            $wire = $citation->toWire();
            $found = false;
            foreach ($this->items as $item) {
                if ($item->category !== EgressCategory::Evidence || $item->contentDigest->value !== $wire->get('contentDigest')) {
                    continue;
                }
                foreach ($item->sources as $source) {
                    $found = $found || ($source->sourceId->value === $wire->get('sourceId')
                        && $source->sourceVersionId->value === $wire->get('sourceVersionId')
                        && $source->sourceDigest->value === $wire->get('sourceDigest'));
                }
            }
            if (!$found) {
                throw new \InvalidArgumentException('Exact evidence content and source reference required');
            }
        }
    }
}
