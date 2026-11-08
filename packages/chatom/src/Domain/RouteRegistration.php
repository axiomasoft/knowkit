<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

use KnowKit\Domain\ConversationRoute;

final readonly class RouteRegistration
{
    public function __construct(
        public ConversationRef $conversation,
        public ConversationRoute $route,
        public string $owner,
    ) {
        ConversationRef::assertReference($owner);
    }
}
