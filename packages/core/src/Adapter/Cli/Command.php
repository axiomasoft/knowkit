<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Cli;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Transport data only. Scope, grants, credentials and usage context belong to the host. */
final readonly class Command
{
    public function __construct(
        public string $name,
        public JsonObject $input,
        public ?D\IdempotencyKey $key,
        public ?D\Decimal $expectedEpoch,
        public ?D\Decimal $expectedRevision,
        public ?string $uploadPath,
    ) {}

    public function text(string $name): string
    {
        $value = $this->input->get($name);
        if (!is_string($value)) {
            throw new UsageError();
        }

        return $value;
    }

    public function id(string $name): D\Ulid
    {
        return new D\Ulid($this->text($name));
    }

    public function epoch(): D\Decimal
    {
        return $this->expectedEpoch ?? throw new UsageError();
    }

    public function idempotencyKey(): D\IdempotencyKey
    {
        return $this->key ?? throw new UsageError();
    }
}
