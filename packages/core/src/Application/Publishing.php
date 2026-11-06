<?php
declare(strict_types=1);

namespace KnowKit\Application;

use KnowKit\Domain\{Scope, BuildGeneration, IdempotencyKey, GenerationReceipt, GenerationId, Decimal, PublishOutcome};

/** Public use-case contract; delivery adapters and persistence implementations belong to their owning items. */
interface Publishing
{
    public function buildGeneration(Scope $scope, BuildGeneration $command, IdempotencyKey $key): GenerationReceipt;
    public function publish(Scope $scope, GenerationId $generationId, Decimal $expectedPointerVersion): PublishOutcome;
}
