<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

final class VersionMismatch extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Incompatible KnowKit schema version');
    }
}
