<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Client;

use KnowKit\Http\Client as C;
use KnowKit\Http\Server as H;

require_once dirname(__DIR__) . '/http/Fixture.php';

final class Cases
{
    public static function regression(string $name): \KnowKit\Domain\Contract\JsonObject
    {
        $corpus = \KnowKit\Domain\Contract\StrictJson::decode(file_get_contents(dirname(__DIR__, 2) . '/conformance/http/v1/cases.json'));

        return $corpus->get('regressions')->get($name);
    }
}

final class TestTime implements C\Time
{
    public int $now = 0;
    public array $sleeps = [];

    public function milliseconds(): int
    {
        return $this->now;
    }

    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        $this->now += $milliseconds;
    }
}

final class ScriptedTransport implements C\Transport
{
    public array $seen = [];

    public function __construct(
        private \Closure $handler,
    ) {}

    public function send(H\Request $request, int $timeoutMs): H\Response
    {
        $this->seen[] = [$request, $timeoutMs];

        return ($this->handler)($request, $timeoutMs, count($this->seen));
    }
}
