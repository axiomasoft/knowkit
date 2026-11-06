<?php
declare(strict_types=1);

namespace KnowKit\Conformance;

use KnowKit\Domain\Contract\{JsonObject, StrictJson};
use KnowKit\Registry\HostProfile;

/** Plain PHP. A requested contract always runs its baseline; unsupported is never a pass/skip. */
final class AdapterHarness
{
    /** Version 1 mandatory baseline cannot be trimmed by a host manifest or capability flag. */
    public const REQUIRED_CASE_IDS = ['R36-A01','R36-A02','DT-R02','DT-R03','DT-R05','DT-R06','SDK-POLICY','SDK-BLOB','SDK-PARSER','SDK-SEARCH','SDK-SPACE','SDK-TOKENIZER','SDK-BUDGET','SDK-JOBS','SDK-OUTBOX','SDK-DELETION','SDK-EGRESS','SDK-ISOLATION','R41-A01','R41-A02'];
    private JsonObject $manifest;
    public readonly Fixture $fixture;

    public function __construct(\KnowKit\Domain\Contract\ContractValidator $validator, ?string $manifestPath = null)
    {
        $path = $manifestPath ?? __DIR__ . '/manifest.v1.json';
        $manifest = StrictJson::decode((string) file_get_contents($path));
        if (!$manifest instanceof JsonObject || $manifest->get('schemaVersion') !== 'adapter-corpus:1'
            || !is_string($manifest->get('fixtureVersion')) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$manifest->get('fixtureVersion')) !== 1
            || !is_string($manifest->get('versionRationale'))
            || trim($manifest->get('versionRationale')) === '' || !is_array($manifest->get('cases')) || $manifest->get('cases') === []) {
            throw new \InvalidArgumentException('Nonempty versioned adapter corpus required');
        }
        $ids = [];
        foreach ($manifest->get('cases') as $case) {
            if (!$case instanceof JsonObject || !is_string($case->get('id')) || $case->get('id') === ''
                || isset($ids[$case->get('id')]) || !is_string($case->get('contract'))) {
                throw new \InvalidArgumentException('Unique named corpus cases required');
            }
            $ids[$case->get('id')] = true;
        }
        $required = $manifest->get('requiredCaseIds');
        if (!is_array($required) || count($required) !== count($ids) || array_diff($required,array_keys($ids)) !== []
            || array_diff(array_keys($ids),$required) !== []) { throw new \InvalidArgumentException('Missing required corpus case'); }
        if (array_diff(self::REQUIRED_CASE_IDS,$required) !== []) { throw new \InvalidArgumentException('Mandatory baseline removed'); }
        $entry = $manifest->get('fixture');
        if (!$entry instanceof JsonObject || $entry->get('path') !== 'fixtures/corpus.v1.json'
            || hash_file('sha256', dirname($path) . '/' . $entry->get('path')) !== $entry->get('sha256')
            || !$entry->get('expected') instanceof JsonObject || !$manifest->get('rights') instanceof JsonObject) {
            throw new \InvalidArgumentException('Digest-bound fixture with expectations/rights required');
        }
        foreach (['license','origin'] as $field) {
            if (!is_string($manifest->get('rights')->get($field)) || trim($manifest->get('rights')->get($field)) === '') {
                throw new \InvalidArgumentException('Fixture rights provenance required');
            }
        }
        $this->manifest = $manifest;
        $this->fixture = new Fixture(StrictJson::decode((string) file_get_contents(dirname($path) . '/' . $entry->get('path'))), $validator);
        $data = $this->fixture->data;
        if ($data->get('sourceDigest') !== hash('sha256',$this->fixture->text())
            || $entry->get('expected')->get('sourceDigest') !== $data->get('sourceDigest')
            || $entry->get('expected')->get('irDigest') !== $data->get('irDigest')
            || $data->get('irDigest') !== hash('sha256',$this->fixture->ir()->canonical())
            || !is_array($data->get('tokenVectors')) || $data->get('tokenVectors') === []
            || $entry->get('expected')->get('tokenVectors') !== count($data->get('tokenVectors'))) {
            throw new \InvalidArgumentException('Authored fixture expectations do not match corpus');
        }
    }

    public function run(AdapterHost $host, HostProfile $profile, string $contract = 'all'): array
    {
        $known = array_map(static fn(JsonObject $c): string => $c->get('contract'), $this->manifest->get('cases'));
        if ($contract !== 'all' && ($contract === 'baseline' || !in_array($contract, $known, true))) {
            throw new \InvalidArgumentException('Unknown adapter contract');
        }
        $cases = $required = [];
        $suite = new CorpusContract();
        foreach ($this->manifest->get('cases') as $case) {
            if ($contract !== 'all' && !in_array($case->get('contract'), ['baseline', $contract], true)) { continue; }
            $id = $case->get('id'); $required[] = $id;
            try {
                $suite->execute($id, $host, $profile, $this->fixture);
                $cases[] = ['id'=>$id, 'status'=>'passed'];
            } catch (\KnowKit\Domain\DomainFailure $failure) {
                $cases[] = ['id'=>$id, 'status'=>'failed', 'outcome'=>$failure->outcome->value, 'code'=>$failure->errorCode];
            } catch (\Throwable) {
                // Deliberately omit exception text, document contents, provider inputs and paths.
                $cases[] = ['id'=>$id, 'status'=>'failed', 'code'=>'conformance_failure'];
            }
        }
        try { self::assertComplete($cases, $required); $ok = true; }
        catch (\InvalidArgumentException) { $ok = false; }
        $unsupported = [];
        foreach ($profile->toWire()->get('adapters') as $id => $descriptor) {
            foreach ($descriptor->get('features') as $feature => $supported) {
                if (!$supported) { $unsupported[$id . ':' . $feature] = 'unsupported'; }
            }
        }
        return ['schemaVersion'=>'adapter-report:1','fixtureVersion'=>$this->manifest->get('fixtureVersion'),
            'manifestDigest'=>hash('sha256', \KnowKit\Domain\Contract\Jcs::canonicalize($this->manifest)),
            'profileDigest'=>$profile->digest()->value,'contract'=>$contract,'ok'=>$ok,'executed'=>count($cases),
            'requiredCaseIds'=>$required,'cases'=>$cases,'optionalSuites'=>$unsupported,'qualification'=>'contract-fixtures-only'];
    }

    public static function assertComplete(array $cases, array $required): void
    {
        $ids = array_column($cases, 'id');
        if ($cases === [] || $required === [] || count($ids) !== count($cases) || count(array_unique($ids)) !== count($ids)
            || count(array_unique($required)) !== count($required) || array_diff($required, $ids) !== [] || array_diff($ids, $required) !== []) {
            throw new \InvalidArgumentException('Empty/missing/duplicate/unexpected executed cases');
        }
        foreach ($cases as $case) {
            if (!is_string($case['id'] ?? null) || $case['id'] === '' || ($case['status'] ?? null) !== 'passed') {
                throw new \InvalidArgumentException('Invalid/failed/skipped case');
            }
        }
    }
}
