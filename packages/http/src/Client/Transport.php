<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

use KnowKit\Http\Server\Request;
use KnowKit\Http\Server\Response;

/** A transport must bound the entire exchange and must never follow redirects. */
interface Transport
{
    public function send(Request $request, int $timeoutMs): Response;
}
