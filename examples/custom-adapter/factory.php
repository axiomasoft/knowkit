<?php
declare(strict_types=1);

require_once __DIR__ . '/FakeHost.php';
return static fn(\KnowKit\Conformance\Fixture $fixture): \KnowKit\Conformance\AdapterHost =>
    new \KnowKit\Examples\CustomAdapter\FakeHost($fixture);
