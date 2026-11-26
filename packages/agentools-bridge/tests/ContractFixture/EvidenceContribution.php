<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

use RuntimeException;

final readonly class EvidenceContribution
{
    /** @param list<ContextBlock> $blocks
     * @param list<array{ref: string, version: string, hash: string}> $dependencies */
    public function __construct(
        public ContributionPin $pin,
        public array $blocks,
        public array $dependencies,
        public ContributionReceipt $receipt,
    ) {
        Contract::assertDto(name: 'EvidenceContribution', value: get_object_vars($this));
        if ($receipt->id !== $pin->id || $receipt->pinDigest !== $pin->digest()) {
            throw new RuntimeException('Receipt must bind exact contributor id+pinDigest');
        }
        foreach ($blocks as $block) {
            $block = self::requireBlock($block);
            if (!self::hasDependency($block, $dependencies)) {
                throw new RuntimeException('Selected block requires exact source/version/hash dependency');
            }
        }
    }

    /** Exact {ref, version, hash}; PHP key order is not part of the identity. */
    private static function hasDependency(ContextBlock $block, array $dependencies): bool
    {
        foreach ($dependencies as $dependency) {
            if (is_array($dependency) && count($dependency) === 3
                && ($dependency['ref'] ?? null) === $block->sourceRef
                && ($dependency['version'] ?? null) === $block->sourceVersion
                && ($dependency['hash'] ?? null) === $block->sourceHash) {
                return true;
            }
        }

        return false;
    }

    private static function requireBlock(mixed $block): ContextBlock
    {
        if (!$block instanceof ContextBlock) {
            throw new RuntimeException('Typed ContextBlock required');
        }

        return $block;
    }
}
