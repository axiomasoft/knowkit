<?php

declare(strict_types=1);

namespace KnowKit\Chatom;

use InvalidArgumentException;
use KnowKit\Chatom\Domain\ConversationRef;
use KnowKit\Chatom\Domain\RouteRegistration;

/** One complete host composition, frozen before any listeners or relays are installed. */
final readonly class RouteRegistrationAssertion
{
    /** @var array<string, RouteRegistration> */
    private array $registrations;

    /** @param iterable<RouteRegistration> $registrations Include ALL caller/direct/Agentools owners. */
    public function __construct(iterable $registrations)
    {
        $owners = [];

        foreach ($registrations as $registration) {
            $key = $registration->conversation->registrationKey();

            if (isset($owners[$key])) {
                throw new InvalidArgumentException('Multiple route owners for one conversation binding');
            }

            $owners[$key] = $registration;
        }

        $this->registrations = $owners;
    }

    public function ownerFor(ConversationRef $conversation): ?RouteRegistration
    {
        return $this->registrations[$conversation->registrationKey()] ?? null;
    }
}
