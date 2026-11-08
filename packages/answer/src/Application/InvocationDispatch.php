<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\InvocationAuthorization;
use KnowKit\Answer\Port\InvocationLedger;
use KnowKit\Answer\Port\ModelTransport;
use KnowKit\Domain as D;
use KnowKit\Port\Clock;

/** One local dispatch, no loop, fallback, probe or automatic retry. Caller validates final observations. */
final readonly class InvocationDispatch
{
    public function __construct(
        private InvocationLedger $ledger,
        private InvocationAuthorization $authorization,
        private ModelTransport $transport,
        private Clock $clock,
    ) {
    }

    public function send(A\LedgerClaim $claim, A\TransportRequest $request, A\CancellationToken $cancellation): A\TransportResult|A\AnswerOutcome
    {
        if ($request->identity->binding->transport != $this->transport->descriptor()) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'transport_mismatch');
        }
        $sending = $this->ledger->markSending($claim, $request->identity);
        // If this process dies anywhere after markSending, recovery is unknown, even before send.
        $denial = $this->authorization->beforeSend($sending->pins->binding->scope, $request->identity);
        if ($denial !== null) {
            A\InvocationDecision::requireMetadata($denial, $sending->invocationRef);
            if ($denial->certainty !== A\DispatchCertainty::KnownNotSent) {
                throw new \InvalidArgumentException('Local denial must prove no dispatch');
            }
            $this->ledger->complete($sending->claim, $denial);

            return $denial;
        }
        $this->requireCurrent($request);
        $this->ledger->assertLive($sending->claim);
        if ($cancellation->isCancelled()) {
            $cancelled = new A\AnswerOutcome(A\OutcomeCode::Cancelled, $sending->invocationRef, A\DispatchCertainty::KnownNotSent, sent: false);
            $this->ledger->complete($sending->claim, $cancelled);

            return $cancelled;
        }

        return $this->dispatch($sending, $request, $cancellation);
    }

    private function requireCurrent(A\TransportRequest $request): void
    {
        $now = new \DateTimeImmutable($this->clock->now()->value);
        $request->identity->binding->requireCurrent($now);
        if ($request->identity->deadline <= $now || $request->identity->egressDecision->expiresAt <= $now) {
            throw new D\DomainFailure(D\Outcome::Denied, 'stale_egress');
        }
    }

    private function dispatch(A\InvocationRecord $sending, A\TransportRequest $request, A\CancellationToken $cancellation): A\TransportResult|A\AnswerOutcome
    {
        try {
            $result = $this->transport->send($request, $cancellation);
            if ($result->certainty !== A\DispatchCertainty::Unknown) {
                return $result;
            }
        } catch (A\TransportUnavailable $unavailable) {
            $outcome = $unavailable->outcome($sending->invocationRef);
            $this->ledger->complete($sending->claim, $outcome);

            return $outcome;
        } catch (\Throwable) {
            // A thrown transport exception says nothing about provider acceptance. Never resend.
        }
        try {
            $this->ledger->markUnknown($sending->claim);
        } catch (D\DomainFailure) {
            // Lease loss or uncertain SQL commit leaves sending/unknown for fenced recovery.
        }

        return new A\AnswerOutcome(A\OutcomeCode::TransportUnknown, $sending->invocationRef, A\DispatchCertainty::Unknown);
    }
}
