<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/** Typed wire-contract rejection: stable error code plus RFC 6901 instance path ("" = document). */
final class ContractViolation extends \DomainException
{
    public function __construct(public readonly string $errorCode, public readonly string $path = '')
    {
        parent::__construct($errorCode . ' at ' . ($path === '' ? '/' : $path));
    }

    public function errorClass(): string
    {
        return ErrorCatalog::classOf($this->errorCode);
    }
}
