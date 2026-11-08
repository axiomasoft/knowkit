<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Actors\Contracts\Actor;
use Chatom\Actors\Contracts\ActorProvider;
use Chatom\Actors\Enums\ActorKind;
use Illuminate\Http\Request;

class UserProvider implements ActorProvider
{
    public function type(): string
    {
        return 'user';
    }

    public function kind(): ActorKind
    {
        return ActorKind::Human;
    }

    public function resolveMany(array $ids): array
    {
        $actors = [];
        foreach ($ids as $id) {
            $actors[$id] = new HostActor(id: $id, type: $this->type());
        }

        return $actors;
    }

    public function recognizes(Actor $actor): bool
    {
        return $actor instanceof HostActor && $actor->type === $this->type();
    }

    public function fromRequest(Request $request): ?Actor
    {
        return null;
    }
}
