<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Cli;

/** No user input in messages, including malformed paths and JSON. */
final class UsageError extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('invalid_command');
    }
}
