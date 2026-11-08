<?php

declare(strict_types=1);

namespace KnowKit\Tests\Conformance\Http;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Http\Client as C;

require_once __DIR__ . '/Remote.php';

final readonly class Mode
{
    public function __construct(
        private Host|Remote $host,
        private bool $cli = false,
    ) {}

    public function invoke(C\Call $call): C\Response
    {
        if ($this->host instanceof Remote) {
            return $this->host->client->execute($call);
        }

        return $this->cli ? $this->host->invokeCli($call) : $this->host->invoke($call);
    }

    public function control(string $action): JsonObject
    {
        return $this->host->control($action);
    }
}
