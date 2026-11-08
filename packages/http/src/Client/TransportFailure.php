<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

/** Deliberately excludes URL, credentials, response content and underlying exception. */
final class TransportFailure extends \RuntimeException
{
    public function __construct(
        public readonly bool $timedOut = false,
    ) {
        parent::__construct($timedOut ? 'HTTP deadline exceeded; commit state is unknown' : 'HTTP exchange unavailable');
    }
}
