<?php
declare(strict_types=1);

require_once __DIR__ . '/FakeHost.php';
// Deliberately violates the required policy baseline. CLI must report failed SDK-POLICY and exit 1.
return static fn(\KnowKit\Conformance\Fixture $fixture): \KnowKit\Conformance\AdapterHost =>
    new \KnowKit\Examples\CustomAdapter\FakeHost($fixture, 'unknown-allow');
