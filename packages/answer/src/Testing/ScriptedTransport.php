<?php

declare(strict_types=1);

namespace KnowKit\Answer\Testing;

use KnowKit\Answer\Domain\CancellationToken;
use KnowKit\Answer\Domain\DispatchCertainty;
use KnowKit\Answer\Domain\Guard;
use KnowKit\Answer\Domain\TransportDescriptor;
use KnowKit\Answer\Domain\TransportRequest;
use KnowKit\Answer\Domain\TransportResult;
use KnowKit\Answer\Domain\UsageObservation;
use KnowKit\Answer\Port\ModelTransport;
use KnowKit\Answer\Profile\EffectiveProfile;

/** Deterministic fixture exchange. Not a provider qualification or production fallback. */
final class ScriptedTransport implements ModelTransport
{
    private int $sendCount = 0;
    private int $outboundBytes = 0;

    /** @param list<TransportResult> $script */
    public function __construct(
        EffectiveProfile $profile,
        private array $script,
    ) {
        $profile->requireTest();
        if ($profile->transport != self::testDescriptor()) {
            throw new \InvalidArgumentException('Explicit scripted test binding required');
        }
        Guard::objects($script, TransportResult::class, 1024);
    }

    public static function testDescriptor(): TransportDescriptor
    {
        return new TransportDescriptor('scripted', '1', false, false);
    }

    public function descriptor(): TransportDescriptor
    {
        return self::testDescriptor();
    }

    public function send(TransportRequest $request, CancellationToken $cancellation): TransportResult
    {
        if ($request->identity->binding->transport != $this->descriptor()) {
            throw new \InvalidArgumentException('Fixture transport cannot substitute a pinned deployment');
        }
        if ($cancellation->isCancelled()) {
            return new TransportResult(DispatchCertainty::KnownNotSent, null, false, new UsageObservation(), fixture: true);
        }
        $result = array_shift($this->script);
        if ($result === null) {
            throw new \UnderflowException('No scripted exchange remains');
        }
        $this->sendCount++;
        $this->outboundBytes += strlen($request->bytes);

        return new TransportResult(
            $result->certainty,
            $result->text,
            $result->complete,
            $result->usage,
            $result->observedModelRef,
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
}
