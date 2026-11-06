<?php
declare(strict_types=1);

namespace KnowKit\Conformance;

/** SDK assertions, never host-supplied verdicts. Failure messages contain only static codes. */
abstract class AdapterContract
{
    protected static function check(bool $condition, string $code): void
    {
        if (!$condition) { throw new \RuntimeException($code); }
    }

    protected static function failure(callable $call, \KnowKit\Domain\Outcome $outcome, ?string $code = null): void
    {
        try { $call(); }
        catch (\KnowKit\Domain\DomainFailure $failure) {
            self::check($failure->outcome === $outcome && ($code === null || $failure->errorCode === $code), 'wrong_failure');
            return;
        }
        throw new \RuntimeException('expected_failure_missing');
    }

    abstract public function execute(string $caseId, AdapterHost $host, \KnowKit\Registry\HostProfile $profile, Fixture $fixture): void;
}
