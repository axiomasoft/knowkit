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
            if (!in_array([
                'ref' => $block->sourceRef,
                'version' => $block->sourceVersion,
                'hash' => $block->sourceHash,
            ], $dependencies, true)) {
                throw new RuntimeException('Selected block requires exact source/version/hash dependency');
            }
        }
    }

    private static function requireBlock(mixed $block): ContextBlock
    {
        if (!$block instanceof ContextBlock) {
            throw new RuntimeException('Typed ContextBlock required');
        }

        return $block;
    }
}
