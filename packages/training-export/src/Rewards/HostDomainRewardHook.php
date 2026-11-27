<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Rewards;

use KnowKit\TrainingExport\Domain\TrainingTraceRecord;

interface HostDomainRewardHook
{
    public function id(): string;

    /** Returns an integer reward/penalty in permille (-10000..10000). */
    public function evaluate(TrainingTraceRecord $trace, string $candidateResponse): int;
}
