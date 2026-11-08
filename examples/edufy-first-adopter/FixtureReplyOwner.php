<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\InvocationLedger;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;

/** In-memory synthetic Chatom semantic-slot receipt. This does not implement Chatom persistence. */
final class FixtureReplyOwner
{
    /** @var array<string,array<string,mixed>> */
    private array $receipts = [];
    public ?\Closure $beforeMutation = null;

    public function __construct(
        private readonly HostAuthority $authority,
        private readonly LessonBindings $bindings,
        private readonly InvocationLedger $ledger,
    ) {
    }

    /**
     * @return array<string,mixed> Metadata only on denial.
     * @phpstan-impure
     */
    public function publish(A\AnswerRequest $request, A\AnswerOutcome $outcome): array
    {
        $lesson = $this->authority->inputs->lesson ?? 'workspace:1';
        $binding = $this->bindings->conversationFor($lesson);
        try {
            $record = $this->ledger->find($request->invocationRef());
        } catch (\Throwable) {
            return ['fixture' => true, 'status' => 'denied'];
        }
        $digest = hash('sha256', Jcs::canonicalize($outcome->toWire()));
        if ($outcome->answeredContent() === null || !$outcome->fixture || $record === null
            || $record->state !== A\InvocationState::Completed || $record->responsePayloadHash?->value !== $digest
            || $outcome->invocationRef != $request->invocationRef()) {
            return ['fixture' => true, 'status' => 'denied'];
        }
        if ($this->beforeMutation !== null) {
            ($this->beforeMutation)();
        }
        try {
            $record->requireOriginal($request);
            $this->authority->requireCurrent($request->scope);
            if ($binding != $this->bindings->conversationFor($lesson)
                || !$this->authority->publicationAllowed
                || $this->authority->beforeReplay($request->scope, $record) !== null) {
                return ['fixture' => true, 'status' => 'denied'];
            }
        } catch (\Throwable) {
            return ['fixture' => true, 'status' => 'denied'];
        }
        $key = $request->scope->tenantId->value . ':' . $binding->channel . ':' . $request->invocationKey->value . ':final.v1';
        $receipt = ['fixture' => true, 'status' => 'published', 'slot' => 'final.v1', 'key' => $key,
            'contentDigest' => $digest, 'channel' => $binding->channel, 'bindingRevision' => $binding->revision];
        if (isset($this->receipts[$key]) && $this->receipts[$key] !== $receipt) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'reply_slot_conflict');
        }
        $this->receipts[$key] = $receipt;

        return $receipt;
    }
}
