<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

final class ProtocolFailure extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid KnowKit HTTP response');
    }
}
