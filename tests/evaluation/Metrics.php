<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

/** Rank discounts and unique gold coverage adapted from DeepTutor metrics.py (Apache-2.0).
 * Copyright 2025 Data Intelligence Lab, The University of Hong Kong.
 * See docs/architecture/third-party/DeepTutor-LICENSE.txt and P5.4-reuse.json.
 * Modified: graded gain, exact identity and mandatory error accounting; no normalized-text authority.
 */
final class Metrics
{
    public static function score(array $case, array $actual, ?array $universe = null): array
    {
        $seen = [];
        $sources = [];
        $dcg = 0.0;
        $valid = 0;
        $utf8 = 0;
        $tenantLeaks = 0;
        $denied = 0;
        $unavailable = 0;
        $hardNegatives = 0;
        foreach ($actual['fragments'] as $rank => $fragment) {
            $citation = $fragment['citation'];
            unset($citation['citationId']);
            $match = self::match($citation, $fragment['text'], $case['relevant']);
            $utf8 += preg_match('//u', $fragment['text']) === 1 ? 1 : 0;
            $tenantLeaks += $citation['tenantId'] !== '01J' . str_pad((string) $case['securityFixture']['tenant'], 23, '0', STR_PAD_LEFT) ? 1 : 0;
            $canonical = self::match($citation, $fragment['text'], $universe ?? $case['relevant']);
            $valid += $canonical !== null ? 1 : 0;
            $name = $canonical === null ? null : ($universe[$canonical]['name'] ?? null);
            $hardNegatives += in_array($name, $case['hardNegatives'], true) ? 1 : 0;
            $denied += (in_array($case['scenario'], ['denied', 'unknown'], true)
                || ($name !== null && !in_array($name, $case['securityFixture']['allowed'], true))) ? 1 : 0;
            $unavailable += $case['scenario'] === 'missing-object' ? 1 : 0;
            if ($rank >= 10 || $match === null) {
                continue;
            }
            $gold = $case['relevant'][$match];
            $sources[$gold['sourceVersionId']] = true;
            if (!isset($seen[$match])) {
                $dcg += (2 ** $gold['grade'] - 1) / log($rank + 2, 2);
                $seen[$match] = true;
            }
        }
        $grades = array_column($case['relevant'], 'grade');
        rsort($grades, SORT_NUMERIC);
        $ideal = 0.0;
        foreach (array_slice($grades, 0, 10) as $rank => $grade) {
            $ideal += (2 ** $grade - 1) / log($rank + 2, 2);
        }
        $count = count($actual['fragments']);
        $goldSources = count(array_unique(array_column($case['relevant'], 'sourceVersionId')));

        return ['fragmentRecallAt10' => $grades === [] ? null : count($seen) / count($grades),
            'sourceRecallAt10' => $goldSources === 0 ? null : count($sources) / $goldSources,
            'ndcgAt10' => $ideal === 0.0 ? null : $dcg / $ideal,
            'locatorAccuracy' => $count === 0 ? null : $valid / $count,
            'utf8Validity' => $count === 0 ? null : $utf8 / $count,
            'tenantLeaks' => $tenantLeaks, 'provenanceViolations' => $count - $valid,
            'postRevokeViolations' => $case['scenario'] === 'revoke' ? $count : 0,
            'deniedCitations' => $denied, 'unavailableCitations' => $unavailable,
            'hardNegativeHits' => $hardNegatives,
            'refusalCorrectness' => $case['relevant'] === [] ? (int) ($actual['outcome'] === $case['expectedOutcome'] && $count === 0) : null,
            'tableRetention' => self::tableRetention($case, $actual['fragments']),
            'errors' => (int) ($actual['outcome'] !== $case['expectedOutcome'] || $hardNegatives > 0)];
    }

    private static function match(array $citation, string $text, array $relevant): ?int
    {
        foreach ($relevant as $index => $gold) {
            if (Corpus::fingerprint($citation) !== Corpus::fingerprint($gold['evidence'])) {
                continue;
            }
            if (hash('sha256', $text) === $citation['contentDigest'] && self::validSpans($citation, $text)) {
                return $index;
            }
        }

        return null;
    }

    private static function validSpans(array $citation, string $text): bool
    {
        foreach ($citation['spans'] as $span) {
            if ($span['byteStart'] !== 0 || $span['byteEnd'] !== strlen($text)
                || $span['textDigest'] !== hash('sha256', $text) || preg_match('//u', $text) !== 1) {
                return false;
            }
        }

        return $citation['spans'] !== [];
    }

    private static function tableRetention(array $case, array $fragments): ?float
    {
        $terms = $case['requiredTableTerms'] ?? [];
        if ($terms === []) {
            return null;
        }
        $text = implode("\n", array_column($fragments, 'text'));
        $found = array_filter($terms, static fn (string $term): bool => str_contains($text, $term));

        return count($found) / count($terms);
    }

    public static function aggregate(array $rows): array
    {
        $result = [];
        foreach (['fragmentRecallAt10', 'sourceRecallAt10', 'ndcgAt10', 'locatorAccuracy', 'utf8Validity', 'refusalCorrectness', 'tableRetention'] as $key) {
            $values = array_filter(array_column($rows, $key), static fn ($value): bool => $value !== null);
            $result[$key] = $values === [] ? null : array_sum($values) / count($values);
            $result[$key . 'Samples'] = count($values);
        }
        foreach (['tenantLeaks', 'provenanceViolations', 'postRevokeViolations', 'deniedCitations', 'unavailableCitations', 'hardNegativeHits', 'errors'] as $key) {
            $result[$key] = array_sum(array_column($rows, $key));
        }

        return $result;
    }
}
