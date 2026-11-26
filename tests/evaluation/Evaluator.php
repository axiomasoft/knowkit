<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

use KnowKit\Tests\Retrieval\Disclosure\Fixture;
use KnowKit\Tooling\Qa;

/** Test-tool orchestration only: no providers, fallback, judge model or runtime dependencies. */
final readonly class Evaluator
{
    public const THRESHOLDS = [
        'recallAt10' => 0.90, 'ndcgAt10' => 0.80, 'exactRecallAt10' => 1.0,
        'locatorAccuracy' => 1.0, 'utf8Validity' => 1.0, 'coverageAccuracy' => 1.0,
        'tableRetention' => 0.95, 'refusalCorrectness' => 0.95,
        'tenantLeaks' => 0, 'provenanceViolations' => 0, 'postRevokeViolations' => 0,
        'deniedCitations' => 0, 'unavailableCitations' => 0, 'errors' => 0,
    ];

    public function __construct(
        private Corpus $corpus,
    ) {}

    public function run(string $profile): array
    {
        $manifest = $this->corpus->manifest;
        Qa::ensure($profile === $manifest['profiles']['baseline']['id'], 'profile_not_qualified');
        Qa::ensure($manifest['thresholds'] == self::THRESHOLDS, 'approved thresholds changed');
        $report = PostgresCorpus::run($this->corpus, fn (Fixture $f): array => $this->measure($f));
        $report['auditCases'] = [...$this->auditCases($report['cases']), RewriteProbe::run()];
        $report += ['schemaVersion' => 'evaluation-report:1', 'corpusVersion' => $manifest['version'],
            'annotationVersion' => $manifest['annotationVersion'], 'corpusFingerprint' => $this->corpus->manifestSha256,
            'configFingerprint' => hash('sha256', json_encode(['profile' => $profile, 'seed' => $manifest['seed'], 'k' => 10,
                'thresholds' => self::THRESHOLDS], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)),
            'seed' => $manifest['seed'], 'profile' => $profile, 'hiddenFallback' => false,
            'thresholds' => self::THRESHOLDS, 'optionalProfiles' => $manifest['profiles']['optional'],
            'confidence' => ['method' => 'exhaustive deterministic fixture assertions; no population confidence interval',
                'limitation' => 'Small synthetic corpus; no claim about production language distribution or OCR correctness.']];
        $report['qualified'] = self::qualifies($report);

        return $report;
    }

    private function auditCases(array $cases): array
    {
        $map = ['R24-A01' => ['exact-document', 'exact-function', 'exact-phrase'],
            'R24-A02' => ['cyrillic', 'cjk-literal', 'english', 'mixed-identifier', 'no-evidence'],
            'DT-Q09' => ['cyrillic', 'english', 'cjk-literal', 'wrong-version-phrase', 'unavailable-basis', 'no-evidence']];
        $audits = [];
        foreach ($map as $id => $required) {
            $rows = array_values(array_filter($cases, static fn (array $case): bool => in_array($case['id'], $required, true)));
            $passed = count($rows) === 2 * count($required);
            foreach ($rows as $row) {
                $passed = $passed && $row['metrics']['errors'] === 0 && $row['metrics']['provenanceViolations'] === 0
                    && ($row['metrics']['fragmentRecallAt10'] === null || $row['metrics']['fragmentRecallAt10'] == 1.0);
            }
            $audits[] = ['id' => $id, 'status' => $passed ? 'passed' : 'failed', 'caseIds' => $required];
        }

        return $audits;
    }

    private function measure(Fixture $f): array
    {
        $runner = new CaseRunner($f);
        $cases = $groups = $all = $exact = [];
        $universe = $this->universe();
        foreach ($this->corpus->questions as $case) {
            foreach (['cold', 'warm'] as $phase) {
                $actual = $runner->execute($case, $phase);
                $metrics = Metrics::score($case, $actual, $universe);
                $all[] = $metrics;
                $groups['language:' . $case['language']][] = $metrics;
                $groups['type:' . $case['type']][] = $metrics;
                $groups['phase:' . $phase][] = $metrics;
                if ($case['exact']) {
                    $exact[] = $metrics;
                }
                $actual['metrics'] = $metrics;
                // Reports contain IDs, digests, outcomes and counters; never source or query text.
                $actual['citations'] = array_column($actual['fragments'], 'citation');
                unset($actual['fragments']);
                $cases[] = $actual;
            }
        }
        return $this->report($f, $cases, $groups, $all, $exact);
    }

    private function universe(): array
    {
        $records = [];
        foreach ($this->corpus->documents as $doc) {
            $m = $doc['chunkSetManifest'];
            $evidence = $m['revisionRef'];
            unset($evidence['parserSignature']);
            $evidence += ['documentId' => $m['documentId'], 'contentDigest' => $doc['textDigest'],
                'origin' => 'source', 'spans' => $m['chunks'][0]['spans']];
            $records[] = ['name' => $doc['name'], 'evidence' => $evidence];
        }

        return $records;
    }

    private function report(Fixture $f, array $cases, array $groups, array $all, array $exact): array
    {
        $summary = Metrics::aggregate($all);
        $summary['exactRecallAt10'] = Metrics::aggregate($exact)['fragmentRecallAt10'];
        $summary['coverageAccuracy'] = $this->coverage($f);
        $latencies = array_column($cases, 'latencyMs');
        $stages = [];
        foreach (['selectionMs', 'disclosureMs'] as $stage) {
            $values = array_column(array_column($cases, 'stages'), $stage);
            $stages[$stage] = ['p50' => self::percentile($values, 0.50), 'p95' => self::percentile($values, 0.95)];
        }

        return ['datasetSize' => count($this->corpus->questions), 'documentCount' => count($this->corpus->documents),
            'executionCount' => count($cases), 'cases' => $cases, 'summary' => $summary,
            'slices' => array_map(Metrics::aggregate(...), $groups),
            'latencyMs' => ['p50' => self::percentile($latencies, 0.50), 'p95' => self::percentile($latencies, 0.95)],
            'stageLatencyMs' => $stages, 'stageCost' => ['providerCalls' => 0, 'tokens' => 0, 'currencyCost' => null],
            'dependencies' => ['php' => PHP_VERSION, 'postgres' => $f->db()->execute('SHOW server_version')->fetchColumn(),
                'model' => null, 'tokenizer' => null, 'composerLockSha256' => hash_file('sha256', Fixture::root() . '/composer.lock')],
            'implementationFingerprints' => $this->implementationFingerprints(),
            'extraction' => ['qualifiedProfile' => 'preannotated-ir-1.0', 'coverageAccuracy' => $summary['coverageAccuracy'],
                'pdfOcrQualified' => false, 'pdfOcrCoverage' => null],
            'measurementConditions' => 'Cold = first execution of this question; warm = immediate repeat in same migrated schema/connection. Setup excluded; no OS or PostgreSQL cache flush; no performance SLA inferred.'];
    }

    private function implementationFingerprints(): array
    {
        $paths = ['packages/core/src/Adapter/Lexical/PdoLexicalIndex.php',
            'packages/core/src/Application/Retrieval/Selection/Selections.php',
            'packages/core/src/Application/Retrieval/Disclosure/Disclosures.php', 'tests/evaluation/Metrics.php'];
        $hashes = [];
        foreach ($paths as $path) {
            $hashes[$path] = hash_file('sha256', Fixture::root() . '/' . $path);
        }

        return $hashes;
    }

    private function coverage(Fixture $f): float
    {
        $correct = 0;
        foreach ($this->corpus->documents as $doc) {
            $r = $doc['chunkSetManifest']['revisionRef'];
            $row = $f->db()->row('SELECT coverage_json FROM ' . $f->db()->table('document_revisions') . ' WHERE tenant_id=? AND id=?',
                [$r['tenantId'], $r['documentRevisionId']]);
            if ($row === null || !is_string($row['coverage_json'] ?? null)) {
                throw new \RuntimeException('Document coverage unavailable');
            }
            $coverage = json_decode($row['coverage_json'], true, 512, JSON_THROW_ON_ERROR);
            $correct += Corpus::fingerprint($coverage) === Corpus::fingerprint($doc['ir']['coverage']) ? 1 : 0;
        }

        return $correct / count($this->corpus->documents);
    }

    public static function qualifies(array $report): bool
    {
        if ($report['executionCount'] !== 2 * $report['datasetSize'] || $report['datasetSize'] === 0
            || (array_key_exists('auditCases', $report) && (!is_array($report['auditCases']) || $report['auditCases'] === []))) {
            return false;
        }
        $s = $report['summary'];
        foreach ($report['auditCases'] ?? [] as $case) {
            if (!is_array($case) || ($case['status'] ?? '') !== 'passed') {
                return false;
            }
        }
        foreach (self::THRESHOLDS as $name => $threshold) {
            $key = $name === 'recallAt10' ? 'fragmentRecallAt10' : $name;
            if (!isset($s[$key]) || (!is_int($s[$key]) && !is_float($s[$key]))
                || (is_float($s[$key]) && !is_finite($s[$key]))
                || ($threshold === 0 ? $s[$key] !== 0 : $s[$key] < $threshold)) {
                return false;
            }
        }

        return true;
    }

    public static function percentile(array $values, float $fraction): float
    {
        Qa::ensure($values !== [], 'empty latency sample');
        sort($values, SORT_NUMERIC);

        return (float) $values[max(0, (int) ceil(count($values) * $fraction) - 1)];
    }
}
