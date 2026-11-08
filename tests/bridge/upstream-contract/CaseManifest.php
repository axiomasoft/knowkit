<?php

declare(strict_types=1);

namespace KnowKit\Tests\Bridge\UpstreamContract;

use KnowKit\Tooling\Qa;
use RuntimeException;

final class CaseManifest
{
    public const LOCAL_IDS = [
        'bridge-upstream:strict-fixture-schema',
        'bridge-upstream:no-production-fixture',
        'bridge-upstream:no-real-fqn-shadow',
        'bridge-upstream:empty-contributor-defaultcompatibility',
        'bridge-upstream:missing-installed-seam-nonzero',
        'bridge-upstream:pin-signature-mismatch',
        'bridge-upstream:currentauthority-required',
        'bridge-upstream:A01-A18-manifest-complete',
    ];

    public static function assertDefinition(array $manifest): void
    {
        $required = [...self::LOCAL_IDS, ...array_map(static fn (int $id): string => sprintf('A%02d', $id), range(1, 18))];
        if (($manifest['requiredCaseIds'] ?? null) !== $required
            || array_keys($manifest['testCaseMap'] ?? []) !== [...array_slice($required, 8), ...self::LOCAL_IDS]) {
            throw new RuntimeException('Missing, duplicate or unknown required case/mapping');
        }
        foreach ($manifest['testCaseMap'] as $mapped) {
            if (!is_array($mapped) || $mapped === [] || count(array_unique($mapped)) !== count($mapped)) {
                throw new RuntimeException('Invalid case mapping');
            }
        }
    }

    public static function assertExecuted(array $cases, array $manifest): array
    {
        self::assertDefinition($manifest);
        $expected = array_unique(array_merge(...array_values($manifest['testCaseMap'])));
        if (array_diff(array_column($cases, 'id'), $expected) !== []) {
            throw new RuntimeException('Unknown executed case');
        }

        return Qa::validateCases(cases: $cases, manifest: $manifest);
    }
}
