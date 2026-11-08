<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain\AnswerRequest;
use KnowKit\Answer\Domain\InvocationPins;
use KnowKit\Answer\Domain\InvocationRecord;
use KnowKit\Answer\Port\InvocationLedger;
use KnowKit\Answer\Port\ModelBindingResolver;
use KnowKit\Domain\Sha256;

final readonly class InvocationAdmission
{
    public function __construct(
        private InvocationLedger $ledger,
        private ModelBindingResolver $bindings,
    ) {
    }

    public function admit(AnswerRequest $request): InvocationRecord
    {
        $existing = $this->ledger->find($request->invocationRef());
        if ($existing !== null) {
            $existing->requireOriginal($request);

            return $existing;
        }

        // Host resolution may perform I/O. begin owns only the subsequent insert-CAS.
        $binding = $this->bindings->resolve($request->scope, $request->modelBindingRef);
        $fingerprint = $request->fingerprint($binding);
        $pins = new InvocationPins(
            $binding,
            $request->retrievalProfileRef,
            $request->originalRequestDigest(),
            new Sha256(hash('sha256', $request->question)),
        );
        $winner = $this->ledger->begin($request->invocationRef(), $fingerprint, $pins);
        $winner->requireOriginal($request);

        return $winner;
    }
}
