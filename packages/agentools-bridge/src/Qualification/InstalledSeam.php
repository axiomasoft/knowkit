<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Qualification;

use Composer\InstalledVersions;
use KnowKit\Domain\Contract\Jcs;
use ReflectionClass;
use RuntimeException;

/** Read-only installed surface probe. It performs no provider, database or network IO. */
final class InstalledSeam
{
    public static function probe(string $root): array
    {
        $matrix = self::json($root . '/contracts/agentools/pin-matrix.json');
        $delivery = $matrix['upstreamDelivery'];
        if ($delivery['status'] !== 'merged' || !InstalledVersions::isInstalled('agentools/platform-chatom')) {
            throw new RuntimeException('unavailable: real merged+installed seam required; owner task KAT-EVIDENCE-CONTEXT-1 (Agentools)');
        }
        if (!is_string($delivery['mergedCommit'])
            || InstalledVersions::getReference('agentools/platform-chatom') !== $delivery['mergedCommit']
            || InstalledVersions::getPrettyVersion('agentools/platform-chatom') !== $delivery['packageVersion']) {
            throw new RuntimeException('unavailable: installed package pin differs from merged delivery');
        }
        $request = self::json($root . '/contracts/agentools/evidence-context/change-request.v1.json');
        $fixture = self::json($root . '/contracts/agentools/evidence-context/fixture.v1.json');
        self::assertDigest(value: $request['signatures'], expected: $delivery['signatureDigest']);
        self::assertDigest(value: $fixture['signatures'], expected: $delivery['signatureDigest']);
        self::assertDigest(value: $fixture['schemas'], expected: $delivery['schemaDigest']);
        Surface::assertMatches($request['signatures']);
        self::assertSources($delivery);
        self::assertInstalledOrigin(definitions: $request['signatures'], delivery: $delivery);

        return ['fixture' => false, 'upstream_seam' => 'installed-surface-qualified',
            'runtimeQualified' => false, 'owningTask' => $request['id']];
    }

    private static function assertInstalledOrigin(array $definitions, array $delivery): void
    {
        $install = realpath(InstalledVersions::getInstallPath('agentools/platform-chatom') ?? '');
        if ($install === false) {
            throw new RuntimeException('unavailable: installed schema manifest missing');
        }
        $manifest = realpath($install . '/' . ($delivery['schemaManifest'] ?? ''));
        $pinned = array_column($delivery['sourceFiles'], 'path');
        if ($manifest === false || !is_file($manifest) || !str_starts_with($manifest, $install . DIRECTORY_SEPARATOR)
            || !in_array($delivery['schemaManifest'], $pinned, true)) {
            throw new RuntimeException('unavailable: installed schema manifest missing');
        }
        self::assertDigest(value: self::json($manifest), expected: $delivery['schemaDigest']);
        foreach (array_keys($definitions) as $name) {
            if (!is_string($name) || (!class_exists($name) && !interface_exists($name) && !enum_exists($name))) {
                throw new RuntimeException('unavailable: installed FQN/source pin mismatch');
            }
            $class = new ReflectionClass($name);
            $file = realpath($class->getFileName() ?: '');
            if ($class->getName() !== $name || $file === false || !str_starts_with($file, $install . DIRECTORY_SEPARATOR)
                || !in_array(substr($file, strlen($install) + 1), $pinned, true)) {
                throw new RuntimeException('unavailable: installed FQN/source pin mismatch');
            }
        }
    }

    private static function assertSources(array $delivery): void
    {
        $install = realpath(InstalledVersions::getInstallPath('agentools/platform-chatom') ?? '');
        if ($install === false || $delivery['sourceFiles'] === []) {
            throw new RuntimeException('unavailable: missing installed source/hash manifest');
        }
        foreach ($delivery['sourceFiles'] as $source) {
            $path = realpath($install . '/' . $source['path']);
            if ($path === false || !str_starts_with($path, $install . DIRECTORY_SEPARATOR)
                || !is_file($path) || hash_file('sha256', $path) !== $source['sha256']) {
                throw new RuntimeException('unavailable: installed source/hash mismatch');
            }
        }
    }

    private static function assertDigest(mixed $value, mixed $expected): void
    {
        $bytes = Jcs::canonicalizeJson(json_encode($value, JSON_THROW_ON_ERROR));
        if (!is_string($expected) || hash('sha256', $bytes) !== $expected) {
            throw new RuntimeException('unavailable: signature/schema digest mismatch');
        }
    }

    private static function json(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('unavailable: missing contract file: ' . $path);
        }

        return json_decode($bytes, associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
