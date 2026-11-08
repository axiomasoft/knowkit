<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

use InvalidArgumentException;
use KnowKit\Domain\TenantId;

final readonly class ConversationRef
{
    public function __construct(
        public TenantId $tenantId,
        public string $spaceId,
        public string $channelId,
        public string $botId,
        public string $bindingId,
    ) {
        foreach ([$spaceId, $channelId, $botId, $bindingId] as $part) {
            self::assertReference($part);
        }
    }

    public static function assertReference(string $value): void
    {
        if ($value === '' || strlen($value) > 512 || preg_match('/[\x00-\x20\x7f]/', $value)
            || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Bounded opaque reference required');
        }
    }

    /** Tuple encoding prevents separator collisions between opaque host identifiers. */
    public function registrationKey(): string
    {
        return json_encode([
            $this->tenantId->value,
            $this->spaceId,
            $this->channelId,
            $this->botId,
            $this->bindingId,
        ], JSON_THROW_ON_ERROR);
    }

    public function equals(self $other): bool
    {
        return $this->registrationKey() === $other->registrationKey();
    }
}
