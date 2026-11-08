<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter\Psr18;

use Psr\Http\Client\ClientInterface;

/** PSR-18 has no standard socket/timeout/options API. This is a trusted host boundary.
 * Configure THIS client for the supplied limits before returning true. Enforce endpoint,
 * provider/account/region allowlists, public DNS and actual pinned peer/TLS validation,
 * proxy safety and bounded response buffering. Disable redirects, cookies and ALL retries.
 * Never probe or send here. Unsupported enforcement MUST return false.
 */
interface HostHttpPolicy
{
    public function prepare(ClientInterface $client, Deployment $deployment, HttpLimits $limits): bool;
}
