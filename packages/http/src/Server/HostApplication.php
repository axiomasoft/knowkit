<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Application\Access\AccessContext;

/** Explicit host composition, analogous to the Laravel HostApplication boundary.
 * Implementations delegate to core services, retaining policy, durable idempotency and epoch locks.
 * No route is installed, no migration or worker is started by loading this package.
 */
interface HostApplication
{
    public function execute(Command $command, AccessContext $context): Reply;
}
