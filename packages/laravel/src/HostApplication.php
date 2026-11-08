<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Application\Access\AccessContext;

/** Host composition maps validated commands to the existing application use cases and DTOs. */
interface HostApplication
{
    public function execute(Command $command, AccessContext $context): Response;
}
