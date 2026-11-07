<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Cli;

use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Result;

/** The host closure maps validated transport data to application DTOs and use cases. */
final readonly class Kernel
{
    /** @param \Closure(Command): Response $dispatch */
    public function __construct(
        private \Closure $dispatch,
        private Grammar $grammar = new Grammar(),
    ) {}

    /** @param list<string> $arguments */
    public function run(array $arguments, string $stdin = ''): Response
    {
        try {
            $command = $this->grammar->parse($arguments, $stdin);
        } catch (\InvalidArgumentException | ContractViolation | DomainFailure) {
            return Response::error('invalid_command', 2);
        }
        // Host output is never a diagnostic channel. Do not allow accidental debug prints.
        ob_start();
        try {
            return ($this->dispatch)($command);
        } catch (UsageError | ContractViolation | \InvalidArgumentException) {
            return Response::error('invalid_command', 2);
        } catch (DomainFailure $failure) {
            return Response::result(Result::failure($failure,
                in_array($failure->outcome, [Outcome::Unavailable, Outcome::Unknown], true)));
        } catch (\Throwable) {
            return Response::error('internal_error', 70);
        } finally {
            ob_end_clean();
        }
    }
}
