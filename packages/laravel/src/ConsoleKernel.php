<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Kernel;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;

final readonly class ConsoleKernel
{
    public function __construct(
        private HostIdentity $identity,
        private HostApplication $application,
    ) {}

    /** @param list<string> $arguments */
    public function run(array $arguments, string $stdin = ''): Response
    {
        return (new Kernel(function (Command $command): Response {
            $context = AccessContext::fromHost($this->identity);
            $context->assertCurrent();

            return $this->application->execute(command: $command, context: $context);
        }))->run(arguments: $arguments, stdin: $stdin);
    }
}
