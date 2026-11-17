<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use DateTimeImmutable;
use DateTimeZone;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use RuntimeException;

final class Contract
{
    public static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    public static function fixture(): array
    {
        return self::json('contracts/agentools/evidence-context/fixture.v1.json');
    }

    public static function json(string $path): array
    {
        $bytes = file_get_contents(self::root() . '/' . $path);
        if ($bytes === false) {
            throw new RuntimeException('Missing contract fixture: ' . $path);
        }

        return json_decode($bytes, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', Jcs::canonicalizeJson(json_encode($value, JSON_THROW_ON_ERROR)));
    }

    public static function hostScopeHash(ExecutionContext $context): string
    {
        return substr($context->scopeHash(new CanonicalHasher(new CanonicalEncoding()))->value, strlen('sha-256:'));
    }

    public static function assertDto(string $name, array $value): void
    {
        $schema = self::fixture()['schemas'][$name];
        self::assertSchema(schema: $schema, value: $value);
        foreach (['now', 'expiresAt'] as $field) {
            if (!isset($value[$field])) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value[$field], new DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value[$field]) {
                throw new RuntimeException('Valid UTC instant required');
            }
        }
    }

    public static function assertSchema(array $schema, array $value): void
    {
        $wire = StrictJson::decode(json_encode($schema, JSON_THROW_ON_ERROR));
        $schemas = new SchemaSet([$schema['$id'] => $wire]);
        $errors = $schemas->validate(
            instance: StrictJson::decode(json_encode($value, JSON_THROW_ON_ERROR)),
            schemaId: $schema['$id'],
        );
        if ($errors !== []) {
            throw new RuntimeException('Strict contract schema mismatch: ' . json_encode($errors, JSON_THROW_ON_ERROR));
        }
    }

    public static function assertPins(?array $fixture = null, ?array $request = null, ?array $matrix = null): void
    {
        $fixture ??= self::fixture();
        $request ??= self::json('contracts/agentools/evidence-context/change-request.v1.json');
        $matrix ??= self::json('contracts/agentools/pin-matrix.json');
        self::assertSchema(schema: $request['fixtureSchema'], value: $fixture);
        if (self::digest($request['signatures']) !== $matrix['signatureDigest']
            || self::digest($fixture['schemas']) !== $matrix['schemaDigest']
            || hash_file('sha256', self::root() . '/contracts/agentools/evidence-context/change-request.v1.json') !== $matrix['contractDigest']
            || $matrix['sourcePins'] !== $fixture['golden']['sourcePins']
            || $matrix['emptyDefaultRequestHash'] !== $fixture['golden']['requestHash']
            || $fixture['golden']['requestHash'] !== hash('sha256', $fixture['golden']['requestBytes'])) {
            throw new RuntimeException('Signature/schema/source/request pin mismatch');
        }
        foreach ($matrix['artifacts'] as $artifact) {
            if (hash_file('sha256', self::root() . '/' . $artifact['path']) !== $artifact['sha256']) {
                throw new RuntimeException('Artifact hash mismatch');
            }
        }
    }
}
