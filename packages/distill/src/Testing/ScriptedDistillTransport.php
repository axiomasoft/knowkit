<?php

declare(strict_types=1);

namespace KnowKit\Distill\Testing;

use KnowKit\Distill\Domain\{DistillOutput, DistillProfile, DistillRequest};
use KnowKit\Distill\Port\DistillModelTransport;
use KnowKit\Domain\ValueGuard;

/** Deterministic fixture transport for tests (fixture=true, production=false). */
final class ScriptedDistillTransport implements DistillModelTransport
{
    private int $sendCount = 0;
    private int $outboundBytes = 0;
    /** @var list<DistillRequest> */
    private array $recordedRequests = [];

    /** @param list<DistillOutput> $script */
    public function __construct(
        DistillProfile $profile,
        private array $script,
    ) {
        if ($profile->production) {
            throw new \InvalidArgumentException('ScriptedDistillTransport requires production=false profile');
        }
        ValueGuard::objects($script, DistillOutput::class, 1024);
    }

    public function isFixture(): bool
    {
        return true;
    }

    public function send(DistillRequest $request): DistillOutput
    {
        if ($request->profile->production) {
            throw new \InvalidArgumentException('Fixture transport cannot serve production profile');
        }
        $next = array_shift($this->script);
        if ($next === null) {
            throw new \UnderflowException('No scripted distillation exchange remains');
        }
        $this->sendCount++;
        $this->outboundBytes += strlen($request->promptBytes);
        $this->recordedRequests[] = $request;

        return new DistillOutput(
            complete: $next->complete,
            text: $next->text,
            inputTokens: $next->inputTokens,
            outputTokens: $next->outputTokens,
            fixture: true,
        );
    }

    public function sendCount(): int
    {
        return $this->sendCount;
    }

    public function outboundBytes(): int
    {
        return $this->outboundBytes;
    }

    /** @return list<DistillRequest> */
    public function recordedRequests(): array
    {
        return $this->recordedRequests;
    }
}
