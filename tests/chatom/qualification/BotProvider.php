<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Actors\Enums\ActorKind;

final class BotProvider extends UserProvider
{
    public function type(): string
    {
        return 'bot';
    }

    public function kind(): ActorKind
    {
        return ActorKind::Agent;
    }
}
