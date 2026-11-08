<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

use AgenToolsPlatform\Core\Security\ExecutionContext;

interface CurrentSourceAuthority
{
    public function id(): string;

    public function authorizeBlock(
        ExecutionContext $context,
        ContextBlock $block,
        ContributionReceipt $receipt,
        BoundaryStage $stage,
    ): void;

    public function authorizeSnapshot(
        ExecutionContext $context,
        ContextSnapshot $snapshot,
        ContributionReceipt $receipt,
        BoundaryStage $stage,
    ): void;
}
