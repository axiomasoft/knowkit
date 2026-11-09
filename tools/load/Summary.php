<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Tooling\Qa;

final class Summary
{
    public static function collect(): array
    {
        $reports = $digests = $measurements = [];
        foreach (Qualification::CASES as $case) {
            $path = Report::root() . '/.phpunit.cache/P9.4/' . $case . '.json';
            Qa::ensure(is_file($path), 'missing measured workload: ' . $case);
            $reports[$case] = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            foreach ($reports[$case]['fingerprints'] as $file => $expected) {
                Qa::ensure(hash_file('sha256', Report::root() . '/' . $file) === $expected, 'measurement candidate changed: ' . $file);
            }
            $digests[$case] = hash_file('sha256', $path);
            $measurements[$case] = $reports[$case]['measurements'];
        }

        $fixture = json_decode(file_get_contents(Report::root() . '/tests/ops/performance/fixtures/v1/cases.json'), true, 64, JSON_THROW_ON_ERROR);

        return ['schemaVersion' => 'capacity-observation:1', 'fixtureVersion' => $fixture['fixtureVersion'],
            'profileSha256' => hash_file('sha256', Report::root() . '/deploy/profiles/capacity.v1.json'),
            'corpusSha256' => hash_file('sha256', Report::root() . '/tests/ops/performance/fixtures/v1/cases.json'),
            'environment' => $reports['catalog']['environment'], 'reportSha256' => $digests,
            'referenceQualified' => Qualification::failures($reports) === [], 'qualificationFailures' => Qualification::failures($reports),
            'measurements' => $measurements,
            'limits' => 'Shared development host. No server CPU/memory attribution or monetary tariff. Hybrid is a small pinned-vector adapter profile; no external embedding model or reference-scale hybrid capacity claim.'];
    }
}
