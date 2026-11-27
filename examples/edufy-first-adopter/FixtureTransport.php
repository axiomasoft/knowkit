<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\ModelTransport;
use KnowKit\Answer\Profile\EffectiveProfile;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Answer\Testing\ScriptedTransport;
use KnowKit\Domain\CitationRef;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;

/** Fixture response echoes exact disclosed citation identities. No network or production fallback. */
final class FixtureTransport implements ModelTransport
{
    public int $sends = 0;
    public int $outboundBytes = 0;
    public bool $partial = false;
    public ?\Closure $afterSend = null;
    public ?A\TransportRequest $lastRequest = null;

    public function __construct(
        private readonly string $answer,
    ) {
    }

    public function descriptor(): A\TransportDescriptor
    {
        return ScriptedTransport::testDescriptor();
    }

    public function send(A\TransportRequest $request, A\CancellationToken $cancellation): A\TransportResult
    {
        $this->lastRequest = $request;
        $bytes = Jcs::canonicalize(new JsonObject([
            'text' => $this->answer,
            'citations' => array_map(
                static fn (int $index): string => '#' . ($index + 1),
                array_keys($request->identity->dependencies->evidence),
            ),
        ]));
        $transport = new ScriptedTransport(new EffectiveProfile(ProfileMode::Test, $this->descriptor()), [
            new A\TransportResult(A\DispatchCertainty::Sent, $this->partial ? null : $bytes, !$this->partial,
                new A\UsageObservation(123, 17, 'fixture:usage'), $request->identity->binding->model, true),
        ]);
        $result = $transport->send($request, $cancellation);
        $this->sends += $transport->sendCount();
        $this->outboundBytes += $transport->outboundBytes();
        if ($this->afterSend !== null) {
            ($this->afterSend)();
        }

        return $result;
    }
}
