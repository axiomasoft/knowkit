<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\Access\PolicyContext;

/** Implemented by the authenticated host; request/model JSON must never implement this port. */
interface HostIdentity
{
    /** Request-local current identity/eligibility, or null when authentication is absent. No ambient fallback. */
    public function current(): ?PolicyContext;
}
