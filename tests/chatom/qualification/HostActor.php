<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Agents\Contracts\Agent;

final readonly class HostActor implements Agent
{
    public function __construct(
        public string $id,
        public string $type,
    ) {}

    public function getChatomId(): string
    {
        return $this->id;
    }

    public function getChatomName(): string
    {
        return $this->id;
    }

    public function getChatomAvatar(): ?string
    {
        return null;
    }

    public function handlerKey(): string
    {
        return 'knowkit.lesson';
    }
}
