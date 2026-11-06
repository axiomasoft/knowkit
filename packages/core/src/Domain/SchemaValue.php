<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{ContractValidator, ContractViolation, JsonObject};

/** Schema-bound immutable DTO. The host supplies the offline validator; Domain does no file I/O. */
abstract readonly class SchemaValue
{
    protected const SCHEMA = '';
    private FrozenJson $value;

    final public function __construct(JsonObject $value, ContractValidator $validator)
    {
        $errors = $validator->validateValue($value, 'urn:knowkit:schema:' . static::SCHEMA . ':1.0');
        if ($errors !== []) { throw new ContractViolation($errors[0]['code'], $errors[0]['path']); }
        $this->value = new FrozenJson($value);
    }

    final public function toWire(): JsonObject { return $this->value->toWire(); }
    final public function canonical(): string { return $this->value->canonical(); }
    final public function __debugInfo(): array { return ['schema' => static::SCHEMA]; }
}
