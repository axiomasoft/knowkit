<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, JsonObject};

final readonly class ParserProfile
{
    public FrozenJson $options;
    public ParserSignature $signature;
    public function __construct(public string $id, string $name, string $version, JsonObject $options)
    {
        ValueGuard::text($id, 128);
        ValueGuard::semanticOptions($options);
        $this->options = new FrozenJson($options);
        $this->signature = new ParserSignature($name, $version, new Fingerprint(HashDomain::ParserProfile->digest($options)));
    }
}
