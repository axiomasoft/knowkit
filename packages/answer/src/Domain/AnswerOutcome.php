<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\CitationRef;
use KnowKit\Domain\Sha256;

/** Closed tagged union: protected payload is representable only in Answered. */
final readonly class AnswerOutcome
{
    public function __construct(
        public OutcomeCode $code,
        public InvocationRef $invocationRef,
        public DispatchCertainty $certainty,
        public ?string $observedModelRef = null,
        public ?string $usageRef = null,
        public bool $fixture = false,
        private ?AnsweredContent $content = null,
        public RefusalReason|DenialReason|null $reason = null,
        public ?UnavailableComponent $component = null,
        public ?bool $sent = null,
    ) {
        foreach ([$observedModelRef, $usageRef] as $ref) {
            if ($ref !== null) {
                Guard::reference($ref);
            }
        }
        $this->assertVariant();
    }

    public function answeredContent(): ?AnsweredContent
    {
        return $this->content;
    }

    public function toWire(): JsonObject
    {
        $value = new JsonObject([
            'type' => $this->code->value,
            'invocationRef' => $this->invocationRef->toWire(),
            'certainty' => $this->certainty->value,
            'observedModelRef' => $this->observedModelRef,
            'usageRef' => $this->usageRef,
            'fixture' => $this->fixture,
        ]);
        foreach ($this->content?->toWire() ?? [] as $key => $field) {
            $value = $value->with($key, $field);
        }

        return match ($this->code) {
            OutcomeCode::Refused, OutcomeCode::Denied => $value->with('reason', $this->reason?->value),
            OutcomeCode::Unavailable => $value->with('component', $this->component?->value),
            OutcomeCode::Cancelled => $value->with('sent', $this->sent),
            default => $value,
        };
    }

    private function assertVariant(): void
    {
        $valid = ($this->content !== null) === ($this->code === OutcomeCode::Answered)
            && ($this->reason instanceof RefusalReason) === ($this->code === OutcomeCode::Refused)
            && ($this->reason instanceof DenialReason) === ($this->code === OutcomeCode::Denied)
            && ($this->component !== null) === ($this->code === OutcomeCode::Unavailable)
            && ($this->sent !== null) === ($this->code === OutcomeCode::Cancelled);
        if (!$valid) {
            throw new \InvalidArgumentException('Outcome fields must match the exact variant');
        }
        if ($this->code === OutcomeCode::Answered && ($this->certainty !== DispatchCertainty::Sent || $this->usageRef === null)) {
            throw new \InvalidArgumentException('Answered requires known dispatch and captured usage reference');
        }
        foreach ($this->content->citations ?? [] as $citation) {
            if (!$this->invocationRef->tenantId->equals(new \KnowKit\Domain\TenantId($citation->toWire()->get('tenantId')))) {
                throw new \InvalidArgumentException('Cross-tenant answer citation');
            }
        }
        if ($this->code === OutcomeCode::TransportUnknown && $this->certainty !== DispatchCertainty::Unknown) {
            throw new \InvalidArgumentException('Unknown transport cannot claim dispatch certainty');
        }
        if ($this->sent !== null && $this->certainty !== ($this->sent ? DispatchCertainty::Sent : DispatchCertainty::KnownNotSent)) {
            throw new \InvalidArgumentException('Ambiguous cancellation is TransportUnknown');
        }
    }

    public static function fromWire(JsonObject $wire, ContractValidator $validator): self
    {
        $ref = $wire->get('invocationRef');
        $code = OutcomeCode::from($wire->get('type'));

        return new self(
            $code,
            new InvocationRef(new \KnowKit\Domain\TenantId($ref->get('tenantId')), new InvocationKey($ref->get('invocationKey'))),
            DispatchCertainty::from($wire->get('certainty')),
            $wire->get('observedModelRef'),
            $wire->get('usageRef'),
            $wire->get('fixture'),
            $code !== OutcomeCode::Answered ? null : new AnsweredContent(
                $wire->get('text'),
                array_map(static fn (JsonObject $citation) => new CitationRef($citation, $validator), $wire->get('citations')),
                new Sha256($wire->get('modelBindingDigest')),
                new Sha256($wire->get('evidenceSetId')),
            ),
            match ($code) {
                OutcomeCode::Denied => DenialReason::from($wire->get('reason')),
                OutcomeCode::Refused => RefusalReason::from($wire->get('reason')),
                default => null,
            },
            $code === OutcomeCode::Unavailable ? UnavailableComponent::from($wire->get('component')) : null,
            $wire->get('sent'),
        );
    }
}
