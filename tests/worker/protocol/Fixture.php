<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Protocol;

use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\JobEnvelope;

final class Fixture
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function validator(): ContractValidator
    {
        static $validator;
        if ($validator instanceof ContractValidator) {
            return $validator;
        }
        $schemas = [];
        foreach (glob(self::root() . '/schemas/*.schema.json') as $file) {
            $schema = StrictJson::decode(file_get_contents($file));
            $schemas[$schema->get('$id')] = $schema;
        }

        return $validator = new ContractValidator(new SchemaSet($schemas));
    }

    public static function wire(string $name): JsonObject
    {
        static $wires = [];

        return $wires[$name] ??= StrictJson::decode(file_get_contents(__DIR__ . '/fixtures/v1/' . $name . '.json'));
    }

    public static function job(string $source = "KnowKit protocol fixture.\n", array $changes = []): JobEnvelope
    {
        $wire = self::wire('envelope')->with('inputDigest', hash('sha256', $source))
            ->with('leaseExpiresAt', gmdate('Y-m-d\TH:i:s\Z', time() + 60));
        foreach ($changes as $key => $value) {
            $wire = $wire->with($key, $value);
        }
        return new JobEnvelope($wire, self::validator());
    }

    public static function sign(JsonObject $wire): JsonObject
    {
        return $wire->with('resultDigest', HashDomain::WorkerResult->digest($wire->without('resultDigest')));
    }

    public static function output(string $directory): void
    {
        mkdir($directory, 0700);
        copy(__DIR__ . '/fixtures/v1/ir.json', $directory . '/ir.json');
        copy(__DIR__ . '/fixtures/v1/result.json', $directory . '/result.json');
    }

    public static function temporary(): string
    {
        $path = sys_get_temp_dir() . '/knowkit-protocol-' . bin2hex(random_bytes(12));
        mkdir($path, 0700);
        return $path;
    }
}
