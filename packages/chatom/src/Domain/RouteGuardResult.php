<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

/** NotHandled carries metadata only; no protected history or answer bytes. */
final readonly class RouteGuardResult
{
    private function __construct(
        public ?ConversationBinding $binding,
        public ?NotHandledReason $reason,
    ) {}

    public static function matched(ConversationBinding $binding): self
    {
        return new self(binding: $binding, reason: null);
    }

    public static function notHandled(NotHandledReason $reason): self
    {
        return new self(binding: null, reason: $reason);
    }

    public function isHandled(): bool
    {
        return $this->binding !== null;
    }
}
