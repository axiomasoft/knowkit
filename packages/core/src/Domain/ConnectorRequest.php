<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class ConnectorRequest
{
    public function __construct(public string $sourceNamespace, public string $selector)
    { ValueGuard::text($sourceNamespace, 256); ValueGuard::text($selector, 256); }
}
