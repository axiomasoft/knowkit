<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Origins\FetchLimits;
use KnowKit\Domain\Origins\RemoteResponse;
use KnowKit\Domain\Origins\ResolvedOrigin;

interface OriginTransport
{
    /** Pin origin.address, verify TLS origin.host and actual peer BEFORE sending any HTTP bytes.
     * No proxy, automatic redirect, caller headers, cookie jar or credential forwarding.
     * $deadline is a monotonic hrtime nanosecond deadline shared by all redirects.
     */
    public function get(ResolvedOrigin $origin, FetchLimits $limits, int $deadline): RemoteResponse;
}
