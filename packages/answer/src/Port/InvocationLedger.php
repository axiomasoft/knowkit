<?php

declare(strict_types=1);

namespace KnowKit\Answer\Port;

use KnowKit\Answer\Domain\AnswerOutcome;
use KnowKit\Answer\Domain\InvocationPins;
use KnowKit\Answer\Domain\InvocationRecord;
use KnowKit\Answer\Domain\InvocationRef;
use KnowKit\Answer\Domain\LedgerClaim;
use KnowKit\Answer\Domain\ModelCallIdentity;
use KnowKit\Domain\Sha256;
use KnowKit\Answer\Domain\BudgetReservation;
use KnowKit\Answer\Domain\TransportRequest;

/** Adapted fencing boundary from AgenTools InvocationJournal; see P4.7-reuse.json.
 * Each mutation commits a row-version/live-lease/fence CAS. No transaction surrounds external IO.
 * UNIQUE(tenant,key): loser reloads winner and compares original request, without dispatch.
 */
interface InvocationLedger
{
    /** Always called before resolving mutable model settings. */
    public function find(InvocationRef $key): ?InvocationRecord;

    public function begin(
        InvocationRef $key,
        Sha256 $fingerprint,
        InvocationPins $pins,
    ): InvocationRecord;

    /** Store exact managed request/dependencies and confirmed budget reservation before intent. */
    public function reserve(LedgerClaim $claim, TransportRequest $request, BudgetReservation $budget): InvocationRecord;

    /** Expired pre-send work gets a higher fence; expired sending becomes terminal unknown. */
    public function resume(LedgerClaim $claim): InvocationRecord;

    /** Must follow the fresh external guard immediately before dispatch. */
    public function assertLive(LedgerClaim $claim): void;

    /** Durably commits exact request hash before one send; crash afterward cannot be retried. */
    public function markSending(LedgerClaim $claim, ModelCallIdentity $identity): InvocationRecord;

    /** Persists immutable payload refs/usage; replay still requires fresh disclosure authority. */
    public function complete(LedgerClaim $claim, AnswerOutcome $outcome): InvocationRecord;

    /** Terminal non-sendable state. Never returns unknown to reserved/sending. */
    public function markUnknown(LedgerClaim $claim): InvocationRecord;
}
