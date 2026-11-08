<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

use AgenToolsPlatform\Core\Common\CanonicalJson;
use KnowKit\Domain\Contract\Jcs;

final class CanonicalEncoding implements CanonicalJson
{
    public function canonicalize(mixed $value): string
    {
        return $this->canonicalizeJson(json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function canonicalizeJson(string $json): string
    {
        return Jcs::canonicalizeJson($json);
    }
}
