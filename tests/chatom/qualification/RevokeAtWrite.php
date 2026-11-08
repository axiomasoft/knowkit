<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Operations\Pipeline\OperationContext;
use Chatom\Operations\Pipeline\Stage;

/** Fault injection after owner work, at the real native mutation boundary. */
final readonly class RevokeAtWrite implements Stage
{
    public function __construct(
        private InstalledHandler $handler,
    ) {}

    public function handle(OperationContext $context): void
    {
        if ($context->actor()->type === 'bot' && $this->handler->revokeAtWrite !== null) {
            ($this->handler->revokeAtWrite)();
        }
    }
}
