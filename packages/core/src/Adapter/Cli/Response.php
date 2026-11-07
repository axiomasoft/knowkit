<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Cli;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;

final readonly class Response
{
    public function __construct(
        public JsonObject $envelope,
        public int $exit,
    ) {}

    public static function result(D\Result $result, ?JsonObject $data = null): self
    {
        $exit = match ($result->outcome) {
            D\Outcome::Success, D\Outcome::Empty => 0,
            D\Outcome::Denied => 3,
            D\Outcome::Unknown, D\Outcome::Unavailable => 4,
            D\Outcome::Incompatible => 5,
        };
        $wire = ['outcome' => $result->outcome->value, 'degraded' => $result->degraded,
            'warnings' => $result->warnings];
        if ($result->effectiveProfile !== null) {
            $wire['effectiveProfile'] = $result->effectiveProfile;
        }
        if ($exit !== 0) {
            $wire['error'] = new JsonObject(['code' => $result->errorCode, 'retryable' => $result->retryable]);
        } else {
            $wire['data'] = $result->outcome === D\Outcome::Empty ? [] : ($data ?? new JsonObject([]));
        }

        return new self(new JsonObject($wire), $exit);
    }

    public static function error(string $code, int $exit): self
    {
        return new self(new JsonObject(['outcome' => 'error', 'error' => new JsonObject([
            'code' => $code, 'retryable' => false,
        ]), 'degraded' => false, 'warnings' => []]), $exit);
    }

    public function json(): string
    {
        return Jcs::canonicalize($this->envelope) . "\n";
    }
}
