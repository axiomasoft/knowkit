<?php
declare(strict_types=1);

namespace KnowKit\Conformance;

use KnowKit\Registry\AdapterRegistry;

/** Implement in the host test suite. Every prepare creates an isolated, reset backend. */
interface AdapterHost
{
    public function registry(): AdapterRegistry;
    public function prepare(Fixture $fixture, string $caseId): AdapterRig;
}
