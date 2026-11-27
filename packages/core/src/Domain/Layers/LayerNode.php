<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

use KnowKit\Domain\{Fingerprint, LocalId, ValueGuard};
use KnowKit\Domain\Contract\{ContractViolation, HashDomain, JsonObject};

final readonly class LayerNode
{
    private const RULE_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){0,3}$/D';

    /**
     * @param list<LayerId> $dependsOnLayerIds
     * @param list<string> $lockedKeys
     * @param list<LocalId> $denySelectors
     */
    public function __construct(
        public LayerId $layerId,
        public array $dependsOnLayerIds,
        public TrustTier $trustTier,
        public DeliveryMode $deliveryMode,
        public MergeStrategy $mergeStrategy,
        public DisclosureMode $disclosureMode,
        public array $lockedKeys,
        public JsonObject $ruleValues,
        public array $denySelectors,
        public LayerBudgetPolicy $budget,
        public LayerBacking $backing,
    ) {
        ValueGuard::objects($dependsOnLayerIds, LayerId::class, 32);
        $depValues = array_map(static fn(LayerId $id): string => $id->value, $dependsOnLayerIds);
        if (count(array_unique($depValues)) !== count($depValues)) {
            throw new \InvalidArgumentException('Duplicate dependsOnLayerIds');
        }

        if (count($lockedKeys) > 64 || count(array_unique($lockedKeys)) !== count($lockedKeys)) {
            throw new \InvalidArgumentException('Unique list of up to 64 lockedKeys required');
        }
        foreach ($lockedKeys as $key) {
            if (preg_match(self::RULE_KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException('Invalid lockedKeys entry');
            }
        }

        if ($ruleValues->count() > 64) {
            throw new \InvalidArgumentException('ruleValues exceeds 64 properties');
        }
        foreach ($ruleValues as $key => $value) {
            if (preg_match(self::RULE_KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException('Invalid ruleValues key');
            }
            if (is_float($value)) {
                throw new ContractViolation('non_integer_number');
            }
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new \InvalidArgumentException('ruleValues values must be string, int, or bool');
            }
            if (is_string($value) && mb_strlen($value, 'UTF-8') > 256) {
                throw new \InvalidArgumentException('ruleValues string exceeds 256 chars');
            }
        }

        ValueGuard::objects($denySelectors, LocalId::class, 256);
        $denyValues = array_map(static fn(LocalId $id): string => $id->value, $denySelectors);
        if (count(array_unique($denyValues)) !== count($denyValues)) {
            throw new \InvalidArgumentException('Duplicate denySelectors');
        }
    }

    public function toWireWithoutDigest(): JsonObject
    {
        return new JsonObject([
            'layerId' => $this->layerId->value,
            'dependsOnLayerIds' => array_map(
                static fn(LayerId $id): string => $id->value,
                $this->dependsOnLayerIds,
            ),
            'trustTier' => $this->trustTier->value,
            'deliveryMode' => $this->deliveryMode->value,
            'mergeStrategy' => $this->mergeStrategy->value,
            'disclosureMode' => $this->disclosureMode->value,
            'lockedKeys' => $this->lockedKeys,
            'ruleValues' => $this->ruleValues,
            'denySelectors' => array_map(
                static fn(LocalId $id): string => $id->value,
                $this->denySelectors,
            ),
            'budget' => $this->budget->toWire(),
            'backing' => $this->backing->toWire(),
        ]);
    }

    public function nodeDigest(): Fingerprint
    {
        return new Fingerprint(HashDomain::LayerNode->digest($this->toWireWithoutDigest()));
    }

    public function toWire(): JsonObject
    {
        return $this->toWireWithoutDigest()->with('nodeDigest', $this->nodeDigest()->value);
    }
}
