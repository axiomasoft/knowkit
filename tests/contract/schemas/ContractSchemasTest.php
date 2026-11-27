<?php
declare(strict_types=1);

namespace KnowKit\Tests\Contract\Schemas;

use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\Digests;
use KnowKit\Domain\Contract\ErrorCatalog;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\LocatorConversion;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Tooling\Support;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/bootstrap.php';

/**
 * Wire schemas, error registry and golden contract corpus (P1.4). PHP and Python load the same
 * corpus and must agree byte-for-byte; every negative has an exact code and path. Required case
 * IDs: tests/fixtures/check-groups/contract-schemas.json.
 */
#[Group('contract:schemas')]
final class ContractSchemasTest extends Support
{
    private const PYTHON_CLI = 'tests/contract/schemas/knowkit_contract.py';
    private static ?ContractHarness $harness = null;
    /** @var array<string, mixed>|null */
    private static ?array $phpReport = null;
    private static ?string $pythonStdout = null;

    private function harness(): ContractHarness
    {
        return self::$harness ??= new ContractHarness($this->qa->root);
    }

    /** @return array<string, mixed> */
    private function phpReport(): array
    {
        return self::$phpReport ??= $this->harness()->report();
    }

    /** @return array<string, mixed> plain decoded Python report */
    private function pythonReport(): array
    {
        if (self::$pythonStdout === null) {
            $result = $this->python(['report', '--root', $this->qa->root]);
            self::assertSame(0, $result['exit'], 'Python contract report failed: ' . $result['stdout'] . $result['stderr']);
            self::$pythonStdout = $result['stdout'];
        }
        return json_decode(self::$pythonStdout, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function python(array $args): array
    {
        // A missing interpreter is a failure, never a skip: the corpus is cross-language by contract.
        return $this->qa->process(array_merge(['python3', $this->qa->root . '/' . self::PYTHON_CLI], $args));
    }

    /** @return array<string, array<string, mixed>> */
    private function entries(): array
    {
        $manifest = json_decode($this->harness()->read(ContractHarness::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        return array_column($manifest['entries'], null, 'id');
    }

    /** @return list<array{code: string, path: string}> */
    private function phpErrors(string $id): array
    {
        foreach ($this->phpReport()['corpus'] as $entry) {
            if ($entry['id'] === $id) {
                return $entry['errors'];
            }
        }
        self::fail('unknown corpus entry ' . $id);
    }

    /** @return list<array{code: string, path: string}> */
    private function pythonErrors(string $id): array
    {
        foreach ($this->pythonReport()['corpus'] as $entry) {
            if ($entry['id'] === $id) {
                return $entry['errors'];
            }
        }
        self::fail('unknown Python corpus entry ' . $id);
    }

    /** Every entry carrying $tag is classified as expected, with identical PHP and Python errors. */
    private function assertTag(string $tag, int $minValid = 0, int $minInvalid = 0): void
    {
        $valid = $invalid = 0;
        foreach ($this->entries() as $id => $entry) {
            if (!in_array($tag, $entry['tags'], true)) {
                continue;
            }
            $errors = $this->phpErrors($id);
            self::assertSameJson($errors, $this->pythonErrors($id), $id . ': PHP/Python disagree');
            if ($entry['expected'] === 'valid') {
                self::assertSame([], $errors, $id . ' must be valid');
                $valid++;
            } else {
                self::assertNotSame([], $errors, $id . ' must be rejected');
                self::assertSame(['code' => $entry['expectedErrorCode'], 'path' => $entry['expectedErrorPath']], $errors[0], $id);
                $invalid++;
            }
        }
        self::assertGreaterThan(0, $valid + $invalid, 'empty case group: ' . $tag);
        self::assertGreaterThanOrEqual($minValid, $valid, $tag . ' valid cases');
        self::assertGreaterThanOrEqual($minInvalid, $invalid, $tag . ' invalid cases');
    }

    private function fixture(string $id): JsonObject
    {
        return $this->harness()->json($this->entries()[$id]['path']);
    }

    /** @return array<string, mixed> */
    private function vector(string $file, string $schema, string $id): array
    {
        foreach ($this->harness()->vectors($file, $schema) as $vector) {
            if ($vector->get('id') === $id) {
                return json_decode(Jcs::canonicalize($vector), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        self::fail('unknown vector ' . $id);
    }

    /** @return array<string, mixed> */
    private function reportItem(array $report, string $section, string $id): array
    {
        foreach ($report[$section] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        self::fail('missing report item ' . $section . '/' . $id);
    }

    private static function assertSameJson(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertSame(Jcs::canonicalize($expected), Jcs::canonicalize($actual), $message);
    }

    private function rejectsWith(string $code, string $path, callable $operation): void
    {
        try {
            $operation();
        } catch (ContractViolation $violation) {
            self::assertSame([$code, $path], [$violation->errorCode, $violation->path]);
            return;
        }
        self::fail('expected rejection ' . $code . ' at ' . $path);
    }

    public function testSchemaRegistryIsOfflineClosedAndVersioned(): void
    {
        $ids = $this->harness()->schemas->ids();
        self::assertCount(35, $ids);
        $corpusSchemas = array_count_values(array_map(static fn(array $entry): string
            => $entry['schemaId'] . '#' . $entry['expected'], $this->entries()));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^urn:knowkit:schema:[a-z][a-z0-9-]*:1\.0$/D', $id);
            $text = $this->harness()->read('schemas/' . explode(':', $id)[3] . '.schema.json');
            preg_match_all('/"\$ref":\s*"([^"]*)"/', $text, $refs);
            foreach ($refs[1] as $ref) {
                self::assertMatchesRegularExpression('/^(#|urn:knowkit:schema:)/', $ref, 'network/relative ref in ' . $id);
            }
            if ($id === SchemaSet::COMMON_ID || in_array(explode(':', $id)[3], ['fixture-manifest', 'hash-vectors', 'locator-vectors', 'binding-vectors'], true)) {
                continue;
            }
            $root = $this->harness()->schemas->root($id);
            self::assertTrue($root->get('unevaluatedProperties') === false || $root->has('$ref'), 'open root ' . $id);
            self::assertIsInt($root->get('x-knowkit-maxDocumentBytes'), 'unbounded ' . $id);
            self::assertGreaterThan(0, $corpusSchemas[$id . '#valid'] ?? 0, 'no valid fixture for ' . $id);
            self::assertGreaterThan(0, $corpusSchemas[$id . '#invalid'] ?? 0, 'no invalid fixture for ' . $id);
        }
        $this->rejectsWith('unknown_schema', '', fn() => $this->harness()->schemas->root(SchemaSet::COMMON_ID));
        $this->expectException(\LogicException::class);
        new SchemaSet(['urn:knowkit:schema:x:1.0' => new JsonObject(['$schema' => SchemaSet::DIALECT,
            '$id' => 'urn:knowkit:schema:x:1.0', 'oneOf' => []])]);
    }

    public function testErrorRegistryMatchesBothImplementations(): void
    {
        $registry = json_decode($this->harness()->read('specs/contracts/error-codes.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(array_map(static fn(array $code): string => $code['class'], $registry['codes']), ErrorCatalog::CODES);
        $seen = [];
        foreach ([$this->phpReport(), $this->pythonReport()] as $report) {
            foreach ($report['corpus'] as $entry) {
                foreach ($entry['errors'] as $error) {
                    $seen[$error['code']] = true;
                }
            }
            foreach (['hashVectors', 'locatorVectors'] as $section) {
                foreach ($report[$section] as $item) {
                    if (isset($item['error'])) {
                        $seen[$item['error']] = true;
                    }
                }
            }
        }
        foreach ($this->entries() as $entry) {
            if (isset($entry['expectedErrorCode'])) {
                $seen[$entry['expectedErrorCode']] = true;
            }
        }
        foreach (array_keys($seen) as $code) {
            self::assertArrayHasKey($code, ErrorCatalog::CODES, 'unregistered error code ' . $code);
        }
        self::assertSame('incompatible', ErrorCatalog::classOf('unsupported_schema_version'));
        self::assertSame('denied', (new ContractViolation('scope_mismatch'))->errorClass());
        $this->expectException(\LogicException::class);
        ErrorCatalog::classOf('made_up_code');
    }

    public function testHashDomainTableIsSingleAndExact(): void
    {
        $table = json_decode($this->harness()->read('specs/contracts/hash-domains.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        $domains = [];
        foreach (HashDomain::cases() as $case) {
            $domains[$case->key()] = $case->value;
            self::assertMatchesRegularExpression('/^knowkit:[a-z-]+:v1$/D', $case->value);
        }
        self::assertSame($table['domains'], $domains);
        self::assertSameJson($domains, $this->pythonReport()['hashDomains']);
        self::assertCount(33, array_unique($domains));
        foreach ($domains as $key => $domain) {
            self::assertStringNotContainsStringIgnoringCase('legacy', $key . $domain);
        }
        self::assertSame(hash('sha256', "knowkit:event:v1\x00{}"), HashDomain::DomainEventPayload->digest(new JsonObject()));
        $this->rejectsWith('unknown_reference', '', static fn() => HashDomain::fromKey('irDigest'));
    }

    public function testGoldenHashVectorsMatchInPhp(): void
    {
        $vectors = $this->harness()->vectors(ContractHarness::HASH_VECTORS, 'urn:knowkit:schema:hash-vectors:1.0');
        $report = array_column($this->harness()->hashReport(), null, 'id');
        $sameDomainPayload = [];
        foreach ($vectors as $vector) {
            $id = $vector->get('id');
            $actual = $report[$id];
            if ($vector->get('algorithm') === 'decode') {
                self::assertSame($vector->get('expectedErrorCode'), $actual['error'], $id);
                continue;
            }
            self::assertSame($vector->get('expectedSha256'), $actual['sha256'], $id);
            if ($vector->has('expectedCanonical')) {
                self::assertSame($vector->get('expectedCanonical'), $actual['canonical'], $id);
            }
            if (str_starts_with($id, 'domain.table-')) {
                $sameDomainPayload[] = $actual['sha256'];
            }
        }
        self::assertCount(23, $sameDomainPayload);
        self::assertCount(23, array_unique($sameDomainPayload), 'same payload across domains must differ');
        self::assertSame(['{}', '[]'], [Jcs::canonicalize(new JsonObject()), Jcs::canonicalize([])]);
        self::assertNotSame($report['jcs.combining-precomposed']['sha256'], $report['jcs.combining-decomposed']['sha256']);
        self::assertSame($report['domain.worker-result-self-excluded']['sha256'],
            $this->fixture('worker-result.valid')->get('resultDigest'));
        self::assertNotSame($report['domain.scope-binding']['sha256'], $report['domain.scope-binding-other-host']['sha256']);
        $this->rejectsWith('non_integer_number', '', static fn() => Jcs::canonicalize(0.5));
        $this->rejectsWith('unsafe_integer', '', static fn() => Jcs::canonicalize(9007199254740992));
        $this->rejectsWith('duplicate_key', '', static fn() => Jcs::canonicalizeJson('{"a":1,"a":1}'));
    }

    public function testPhpAndPythonProduceIdenticalReports(): void
    {
        $this->pythonReport();
        $python = StrictJson::decode((string) self::$pythonStdout, 67108864);
        self::assertSame(Jcs::canonicalize($python), Jcs::canonicalize($this->phpReport()));
        self::assertCount(count($this->entries()), $this->phpReport()['corpus']);
        self::assertGreaterThanOrEqual(60, count($this->phpReport()['hashVectors']));
    }

    public function testCorpusClassificationAndExactNegativePaths(): void
    {
        $valid = $invalid = 0;
        foreach ($this->entries() as $id => $entry) {
            $errors = $this->phpErrors($id);
            if ($entry['expected'] === 'valid') {
                self::assertSame([], $errors, $id);
                $valid++;
            } else {
                self::assertSame(['code' => $entry['expectedErrorCode'], 'path' => $entry['expectedErrorPath']], $errors[0] ?? null, $id);
                $invalid++;
            }
            self::assertSameJson($errors, $this->pythonErrors($id), $id);
        }
        self::assertGreaterThanOrEqual(30, $valid);
        self::assertGreaterThanOrEqual(90, $invalid);
    }

    public function testCorpusManifestIntegrityRejectsTampering(): void
    {
        $base = json_decode($this->harness()->read(ContractHarness::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        $firstInvalid = array_search('invalid', array_column($base['entries'], 'expected'), true);
        $cases = [
            'missing-file' => [fn(array $m) => $this->set($m, 'entries.0.path', 'tests/fixtures/contracts/v1/valid/missing.json'), 'missing_file', '/entries/0/path'],
            'sha-mismatch' => [fn(array $m) => $this->set($m, 'entries.0.sha256', hash('sha256', 'x')), 'digest_mismatch', '/entries/0/sha256'],
            'duplicate-id' => [fn(array $m) => $this->set($m, 'entries.1.id', $m['entries'][0]['id']), 'duplicate_identifier', '/entries/1/id'],
            'unknown-schema' => [fn(array $m) => $this->set($m, 'entries.0.schemaId', 'urn:knowkit:schema:nope:1.0'), 'unknown_schema', '/entries/0/schemaId'],
            'common-schema' => [fn(array $m) => $this->set($m, 'entries.0.schemaId', SchemaSet::COMMON_ID), 'unknown_schema', '/entries/0/schemaId'],
            'empty-group' => [fn(array $m) => $this->set($m, 'requiredTags.' . count($m['requiredTags']), 'never-used'), 'empty_case_group', '/requiredTags/' . count($base['requiredTags'])],
            'escaping-path' => [fn(array $m) => $this->set($m, 'entries.0.path', '../outside.json'), 'pattern', '/entries/0/path'],
            'negative-without-code' => [function (array $m) use ($firstInvalid): array {
                unset($m['entries'][$firstInvalid]['expectedErrorCode']);
                return $m;
            }, 'required', '/entries/' . $firstInvalid . '/expectedErrorCode'],
            'version-2' => [fn(array $m) => $this->set($m, 'schemaVersion', 'fixture-manifest-2.0'), 'unsupported_schema_version', '/schemaVersion'],
        ];
        foreach ($cases as $name => [$mutate, $code, $path]) {
            $file = $this->temporary . '/' . $name . '.json';
            file_put_contents($file, json_encode($mutate($base), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->rejectsWith($code, $path, fn() => $this->harness()->loadManifest($file));
            $result = $this->python(['manifest', '--root', $this->qa->root, '--manifest', $file]);
            self::assertSame(1, $result['exit'], $name . ': ' . $result['stdout'] . $result['stderr']);
            self::assertSame(['error' => ['code' => $code, 'path' => $path]], json_decode($result['stdout'], true), $name);
        }
        $ok = $this->python(['manifest', '--root', $this->qa->root, '--manifest', $this->qa->root . '/' . ContractHarness::MANIFEST]);
        self::assertSame([0, ['ok' => true]], [$ok['exit'], json_decode($ok['stdout'], true)]);
    }

    private function set(array $data, string $path, mixed $value): array
    {
        $cursor = &$data;
        foreach (explode('.', $path) as $key) {
            $cursor = &$cursor[ctype_digit($key) ? (int) $key : $key];
        }
        $cursor = $value;
        return $data;
    }

    public function testRequiredCaseManifestIsComplete(): void
    {
        $group = $this->qa->json('tests/fixtures/check-groups/contract-schemas.json');
        $this->qa->fixtures($group);
        foreach (['R07-A01', 'R07-A02', 'R08-A01', 'R08-A02', 'R10-A01', 'R10-A02', 'R28-A01', 'R28-A02'] as $audit) {
            self::assertContains($audit, $group['requiredCaseIds']);
        }
        foreach ($group['requiredCaseIds'] as $caseId) {
            foreach ($group['testCaseMap'][$caseId] as $test) {
                [$class, $method] = explode('::', $test);
                self::assertSame(self::class, $class, $caseId);
                self::assertTrue(method_exists($this, $method), 'unmapped test ' . $test);
            }
        }
        $corpus = json_decode($this->harness()->read(ContractHarness::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.3.0', $corpus['corpusVersion']);
        self::assertSame($corpus['corpusVersion'], $group['fixtureVersion']);
        $passed = array_map(static fn(string $test): array => ['id' => $test, 'status' => 'passed'],
            array_merge(...array_values($group['testCaseMap'])));
        self::assertCount(count($group['requiredCaseIds']), Qa::validateCases(array_values(array_unique($passed, SORT_REGULAR)), $group));
        $this->rejects(static fn() => Qa::validateCases(array_slice($passed, 1), $group), 'missing required case');
        $skipped = $passed;
        $skipped[0]['status'] = 'skipped';
        $this->rejects(static fn() => Qa::validateCases($skipped, $group), 'failed/skipped executed case');
    }

    public function testR07A01StructuredFixtureRetainsStructureOrDeclaresGaps(): void
    {
        $this->assertTag('r07-a01', 1, 5);
        $ir = $this->fixture('document-ir.structured');
        $blocks = [];
        foreach ($ir->get('blocks') as $block) {
            $blocks[$block->get('blockId')] = $block;
        }
        $kinds = array_map(static fn(JsonObject $block): string => $block->get('kind'), $blocks);
        foreach (['heading', 'table', 'formula', 'image'] as $kind) {
            self::assertContains($kind, $kinds);
        }
        self::assertSame('h1', $blocks['tbl1']->get('parentId'));
        self::assertSame('img1', $blocks['cap1']->get('parentId'));
        $cells = [];
        foreach ($blocks['tbl1']->get('table')->get('cells') as $cell) {
            $cells[$cell->get('cellId')] = $cell;
        }
        self::assertSame(2, $cells['c10']->get('rowSpan'), 'merged cell retained');
        self::assertSame(['c00'], $cells['c10']->get('headerRefs'));
        self::assertTrue($cells['c00']->get('header'));
        self::assertSame('kg', $cells['c11']->get('unit'));
        self::assertSame(['fn1'], $cells['c11']->get('noteRefs'));
        $page = $ir->get('pages')[0];
        $footnote = $blocks['fn1']->get('spans')[0];
        self::assertSame("\u{00B9} Measured at 20 \u{00B0}C.", LocatorConversion::resolveSpan(new JsonObject(['text' => $page->get('text'),
            'byteStart' => $footnote->get('byteStart'), 'byteEnd' => $footnote->get('byteEnd')]))->get('text'));
        $formula = $blocks['f1']->get('spans')[0];
        self::assertSame('E = mc^2', substr($page->get('text'), $formula->get('byteStart'), $formula->get('byteEnd') - $formula->get('byteStart')));
        $asset = $ir->get('assets')[0];
        self::assertSame(['a1'], $blocks['img1']->get('assetRefs'));
        self::assertSame(['a1', 'p1'], [$asset->get('assetId'), $asset->get('pageId')]);
        self::assertTrue($asset->has('bbox') && $asset->has('digest'));
        self::assertSame(['column_layout'], $ir->get('coverage')->get('missingFeatures'), 'unavailable column capability declared');
        self::assertSame('partial', $ir->get('coverage')->get('state'));
        $orders = array_map(static fn(JsonObject $block): int => $block->get('order'), array_values($blocks));
        $sorted = $orders;
        sort($sorted);
        self::assertSame($sorted, $orders, 'reading order retained');
    }

    public function testR07A02IrRoundTripPreservesCanonicalBytes(): void
    {
        $this->assertTag('r07-a02', 4, 0);
        $php = array_column($this->phpReport()['irRoundTrip'], null, 'id');
        self::assertSameJson($php, array_column($this->pythonReport()['irRoundTrip'], null, 'id'));
        $expected = array_column(array_map(static fn(JsonObject $v): array => ['id' => $v->get('id'), 'sha' => $v->get('expectedSha256')],
            $this->harness()->vectors(ContractHarness::HASH_VECTORS, 'urn:knowkit:schema:hash-vectors:1.0')), 'sha', 'id');
        self::assertSame($expected['jcs.ir-structured'], $php['document-ir.structured']['irDigest']);
        self::assertSame($expected['jcs.ir-unicode'], $php['document-ir.unicode-multipage']['irDigest']);
        foreach ($php as $id => $item) {
            self::assertTrue($item['stable'], $id);
            $first = $this->fixture($id);
            $second = StrictJson::decode(Jcs::canonicalize($first), 67108864);
            self::assertSame([], $this->harness()->validator->validateValue($second, 'urn:knowkit:schema:document-ir:1.0'), $id);
            self::assertTrue(SchemaSet::jsonEqual($first->get('blocks'), $second->get('blocks')), $id . ' spans/ids');
            self::assertTrue(SchemaSet::jsonEqual($first->get('coverage'), $second->get('coverage')), $id . ' coverage');
            self::assertSame(Digests::ir($first), Digests::ir($second));
        }
    }

    public function testR08A01UnicodeMultipleSpansResolveExactText(): void
    {
        $this->assertTag('r08-a01', 3, 1);
        $ir = $this->fixture('document-ir.unicode-multipage');
        $pages = [];
        foreach ($ir->get('pages') as $page) {
            $pages[$page->get('pageId')] = $page;
        }
        $citation = $this->fixture('citation-ref.multi-span');
        self::assertSame(Digests::ir($ir), $citation->get('irDigest'));
        $texts = [];
        foreach ($citation->get('spans') as $span) {
            $page = $pages[$span->get('pageId')];
            self::assertSame($page->get('textDigest'), $span->get('textDigest'));
            self::assertSame([$page->get('physicalNo'), $page->get('printedLabel')], [$span->get('physicalNo'), $span->get('printedLabel')]);
            $texts[] = LocatorConversion::resolveSpan(new JsonObject(['text' => $page->get('text'),
                'byteStart' => $span->get('byteStart'), 'byteEnd' => $span->get('byteEnd')]))->get('text');
        }
        self::assertSame(["東京タワーは高い。", "Продолжение: 続きの文章。"], $texts);
        self::assertSame([[1, 'xii'], [3, null]], array_map(static fn(JsonObject $s): array
            => [$s->get('physicalNo'), $s->get('printedLabel')], $citation->get('spans')));
        $fragment = $this->fixture('evidence-set.valid')->get('fragments')[0];
        self::assertSame(implode($fragment->get('joiner'), $texts), $fragment->get('text'));
        self::assertSame($citation->get('contentDigest'), Digests::sha256($fragment->get('text')));
        foreach (['span.multi-page-1', 'span.multi-page-2'] as $id) {
            $vector = $this->vector(ContractHarness::LOCATOR_VECTORS, 'urn:knowkit:schema:locator-vectors:1.0', $id);
            self::assertSameJson($vector['expected'], $this->reportItem($this->pythonReport(), 'locatorVectors', $id)['result']);
        }
    }

    public function testR08A02DeepTutorPageConversion(): void
    {
        $id = 'page.deeptutor-index-zero-label-xii';
        $vector = $this->vector(ContractHarness::LOCATOR_VECTORS, 'urn:knowkit:schema:locator-vectors:1.0', $id);
        $locator = LocatorConversion::pageIndexToLocator(StrictJson::decode(json_encode($vector['input'], JSON_THROW_ON_ERROR)));
        self::assertSame(0, $vector['input']['pageIndex']);
        self::assertSame([1, 'xii'], [$locator->get('physicalNo'), $locator->get('printedLabel')]);
        $bbox = $locator->get('bbox');
        self::assertSame(['page-millionths-top-left-v1', 'top-left', 'rotated-page'], [$bbox->get('space'), $bbox->get('origin'), $bbox->get('view')]);
        self::assertSame(Jcs::canonicalize(StrictJson::decode(json_encode($vector['expected'], JSON_THROW_ON_ERROR))), Jcs::canonicalize($locator));
        self::assertSameJson($vector['expected'], $this->reportItem($this->pythonReport(), 'locatorVectors', $id)['result']);
        $missing = $this->vector(ContractHarness::LOCATOR_VECTORS, 'urn:knowkit:schema:locator-vectors:1.0', 'page.missing-coordinate-system');
        $this->rejectsWith('missing_coordinate_system', '', static fn() => LocatorConversion::pageIndexToLocator(
            StrictJson::decode(json_encode($missing['input'], JSON_THROW_ON_ERROR))));
        self::assertSame('missing_coordinate_system', $this->reportItem($this->pythonReport(), 'locatorVectors', 'page.missing-coordinate-system')['error']);
        self::assertSame(Jcs::canonicalize($this->phpReport()['locatorVectors']), Jcs::canonicalize(
            StrictJson::decode(json_encode($this->pythonReport()['locatorVectors'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 1048576)));
    }

    public function testR10A01GeneratedDescriptionIsLabelledNotVerbatim(): void
    {
        $this->assertTag('r10-a01', 3, 4);
        $evidence = $this->fixture('evidence-set.valid');
        $generated = $evidence->get('fragments')[1];
        $citation = $generated->get('citation');
        self::assertSame('generated', $citation->get('origin'));
        self::assertSame('description', $generated->get('provenanceKind'));
        self::assertSame(['a1'], $citation->get('supportingRefs'));
        $ir = $this->fixture('document-ir.structured');
        self::assertSame($ir->get('assets')[0]->get('digest'), $citation->get('assetRefs')[0]->get('digest'));
        self::assertSame('segment', $citation->get('spans')[0]->get('kind'), 'generated text is never a page quote');
        $verbatim = $evidence->get('fragments')[0];
        self::assertSame(['source', 'verbatim'], [$verbatim->get('citation')->get('origin'), $verbatim->get('provenanceKind')]);
    }

    public function testR10A02OcrIntegrityDoesNotClaimSemanticCorrectness(): void
    {
        $this->assertTag('r10-a02', 2, 1);
        $ir = $this->fixture('document-ir.ocr-uncertain');
        $expected = $this->vector(ContractHarness::HASH_VECTORS, 'urn:knowkit:schema:hash-vectors:1.0', 'jcs.ir-ocr')['expectedSha256'];
        self::assertSame($expected, Digests::ir($ir), 'integrity passes despite recognition error');
        self::assertStringStartsWith('Tbe quick', $ir->get('pages')[0]->get('text'));
        self::assertSame('partial', $ir->get('coverage')->get('state'));
        self::assertSame('ocr_low_confidence', $ir->get('coverage')->get('diagnostics')[0]->get('code'));
        self::assertSame('ocr', $this->fixture('citation-ref.ocr')->get('origin'));
        $fields = $this->harness()->json('schemas/citation-ref.schema.json')->get('$defs')->get('citationFields')->get('properties')->keys();
        foreach ($fields as $field) {
            self::assertDoesNotMatchRegularExpression('/semantic|entail|verified|correct/i', $field);
        }
    }

    public function testR28A01OutcomesStayDistinctWithoutEvidence(): void
    {
        $this->assertTag('r28-a01', 6, 5);
        $outcomes = [];
        foreach (['result.empty', 'result.denied', 'result.unknown', 'result.unavailable', 'result.incompatible-space'] as $id) {
            $result = $this->fixture($id);
            $outcomes[] = $result->get('outcome');
            if ($result->get('outcome') === 'empty') {
                self::assertSame([], $result->get('data'));
            } else {
                self::assertFalse($result->has('data'), $id . ' carries no evidence');
                self::assertTrue($result->has('error'));
            }
        }
        self::assertSame(['empty', 'denied', 'unknown', 'unavailable', 'incompatible'], $outcomes);
        $manifest = json_decode($this->harness()->read('specs/architecture/manifest.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($manifest['outcomes'], $this->harness()->json('schemas/common.schema.json')->get('$defs')->get('outcome')->get('enum'));
    }

    public function testR28A02DegradationIsExplicitAndSpaceMismatchIsNot(): void
    {
        $this->assertTag('r28-a02', 2, 3);
        $degraded = $this->fixture('result.degraded');
        self::assertSame(['success', true, 'lexical-v1'], [$degraded->get('outcome'), $degraded->get('degraded'), $degraded->get('effectiveProfile')]);
        self::assertSame(['semantic_stage_unavailable'], $degraded->get('warnings'));
        $space = $this->fixture('result.incompatible-space');
        self::assertSame(['incompatible', false, 'space_mismatch'], [$space->get('outcome'), $space->get('degraded'), $space->get('error')->get('code')]);
    }

    public function testCombiningMarksAreNotNormalized(): void
    {
        $this->assertTag('combining-marks', 1, 1);
        self::assertNotSame(Jcs::canonicalize("Caf\u{00E9}"), Jcs::canonicalize("Cafe\u{0301}"));
        $whole = $this->vector(ContractHarness::LOCATOR_VECTORS, 'urn:knowkit:schema:locator-vectors:1.0', 'span.combining-whole');
        self::assertSame(6, $whole['expected']['byteLength']);
        foreach (['span.combining-whole', 'span.combining-base-only', 'span.inside-combining-mark'] as $id) {
            self::assertSameJson($this->reportItem($this->phpReport(), 'locatorVectors', $id), $this->reportItem($this->pythonReport(), 'locatorVectors', $id));
        }
        self::assertSame('utf8_boundary', $this->reportItem($this->phpReport(), 'locatorVectors', 'span.inside-combining-mark')['error']);
    }

    public function testCyrillicAndCjkSpansUseUtf8Boundaries(): void
    {
        $this->assertTag('cyrillic-cjk', 3, 2);
        self::assertSame('utf8_boundary', $this->reportItem($this->phpReport(), 'locatorVectors', 'span.inside-cjk')['error']);
        $vector = $this->vector(ContractHarness::HASH_VECTORS, 'urn:knowkit:schema:hash-vectors:1.0', 'jcs.cyrillic-cjk');
        self::assertStringContainsString('東京タワー', $vector['expectedCanonical'], 'non-ASCII emitted literally, not \u-escaped');
    }

    public function testEmptyPageHasNoSpansAndCannotMaskFailure(): void
    {
        $this->assertTag('empty-page', 1, 3);
        $page = $this->fixture('document-ir.unicode-multipage')->get('pages')[1];
        self::assertSame(['', 'empty', Digests::sha256('')], [$page->get('text'), $page->get('status'), $page->get('textDigest')]);
        self::assertSame('span_out_of_range', $this->reportItem($this->phpReport(), 'locatorVectors', 'span.out-of-range')['error']);
    }

    public function testPhysicalPageOneAndLabelXiiStayDistinct(): void
    {
        $this->assertTag('page-label', 2, 4);
        $page = $this->fixture('document-ir.unicode-multipage')->get('pages')[0];
        self::assertSame([1, 'xii'], [$page->get('physicalNo'), $page->get('printedLabel')]);
    }

    public function testMultipleSpansKeepOrderAndExplicitJoiner(): void
    {
        $this->assertTag('multiple-spans', 3, 5);
        $vector = $this->vector(ContractHarness::HASH_VECTORS, 'urn:knowkit:schema:hash-vectors:1.0', 'jcs.ordered-spans');
        self::assertStringStartsWith('{"spans":[{"byteEnd":40,"byteStart":30}', $vector['expectedCanonical']);
        self::assertSame(" \u{2026} ", $this->fixture('evidence-set.valid')->get('fragments')[0]->get('joiner'));
    }

    public function testInvalidBboxIsRejected(): void
    {
        $this->assertTag('invalid-bbox', 0, 4);
        foreach (['bbox.degenerate-after-rounding', 'bbox.outside-page', 'bbox.inverted', 'bbox.number-input', 'bbox.exponent-input'] as $id) {
            $item = $this->reportItem($this->phpReport(), 'locatorVectors', $id);
            self::assertArrayHasKey('error', $item, $id);
            self::assertSameJson($item, $this->reportItem($this->pythonReport(), 'locatorVectors', $id));
        }
        foreach (['bbox.half-even-down', 'bbox.half-even-up', 'bbox.float-free-thirds'] as $id) {
            $vector = $this->vector(ContractHarness::LOCATOR_VECTORS, 'urn:knowkit:schema:locator-vectors:1.0', $id);
            self::assertSame(Jcs::canonicalize(StrictJson::decode(json_encode($vector['expected'], JSON_THROW_ON_ERROR))),
                Jcs::canonicalize($this->reportItem($this->phpReport(), 'locatorVectors', $id)['result']), $id);
        }
    }

    public function testUnsupportedSchemaVersionIsIncompatible(): void
    {
        $this->assertTag('schema-version', 0, 4);
        foreach ($this->entries() as $entry) {
            if (($entry['expectedErrorCode'] ?? '') === 'unsupported_schema_version') {
                self::assertSame('incompatible', ErrorCatalog::classOf($entry['expectedErrorCode']));
            }
        }
    }

    public function testLegacyHashIsNeverRenamedIntoDigest(): void
    {
        $this->assertTag('legacy-hash', 1, 3);
        $vector = $this->vector(ContractHarness::HASH_VECTORS, 'urn:knowkit:schema:hash-vectors:1.0', 'legacy.agentools-release-hash');
        $payload = iterator_to_array($this->harness()->vectors(ContractHarness::HASH_VECTORS, 'urn:knowkit:schema:hash-vectors:1.0')[
            array_search('legacy.agentools-release-hash', array_column($this->harness()->hashReport(), 'id'), true)]->get('payload'));
        // Donor recipe (AgenTools Integrity::digest at 2054acda): json_encode with unescaped Unicode.
        $legacy = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        self::assertSame($vector['legacyReleaseHash'], $legacy);
        self::assertNotSame($legacy, $vector['expectedSha256']);
        self::assertSame($vector['expectedSha256'], $this->reportItem($this->phpReport(), 'hashVectors', 'legacy.agentools-release-hash')['sha256']);
        foreach (HashDomain::cases() as $domain) {
            self::assertNotSame($legacy, $domain->digest(StrictJson::decode(json_encode($payload, JSON_THROW_ON_ERROR))));
        }
        $ref = $this->fixture('legacy-evidence-ref.valid');
        self::assertSame([$legacy, false, 'legacy_unverified'], [$ref->get('legacyReleaseHash'), $ref->get('freshlyValidated'), $ref->get('provenance')]);
    }

    public function testStrictJsonRejectsDuplicatesAndNonCanonicalNumbers(): void
    {
        $this->assertTag('strict-json', 0, 7);
        foreach ($this->harness()->vectors(ContractHarness::HASH_VECTORS, 'urn:knowkit:schema:hash-vectors:1.0') as $vector) {
            if ($vector->get('algorithm') === 'decode') {
                $id = $vector->get('id');
                self::assertSameJson($this->reportItem($this->phpReport(), 'hashVectors', $id), $this->reportItem($this->pythonReport(), 'hashVectors', $id));
            }
        }
        $value = StrictJson::decode('{"a":{},"b":[],"\u00e9":"\ud83d\ude00"}');
        self::assertSame('{"a":{},"b":[],"é":"😀"}', Jcs::canonicalize($value));
        $this->rejectsWith('document_too_large', '', static fn() => StrictJson::decode('[1]', 2));
    }

    public function testUnknownFieldsAreRejectedEverywhere(): void
    {
        $this->assertTag('unknown-fields', 1, 8);
    }

    public function testDisclosureAttemptIsRequiredAndUnique(): void
    {
        $this->assertTag('disclosure-attempt', 3, 4);
        $schema = $this->harness()->json('schemas/disclosure-request.schema.json');
        self::assertSame(['selectionId', 'chunkRefs', 'disclosureAttemptId'], $schema->get('required'));
        self::assertSame(['selectionId', 'chunkRefs', 'disclosureAttemptId', 'usageContext', 'resolutionTier', 'pruningPolicy'], $schema->get('properties')->keys());
    }

    public function testUsageContextIsTrustedHostDataOnly(): void
    {
        $this->assertTag('usage-context', 1, 2);
        $usage = $this->fixture('usage.valid');
        self::assertNull($usage->get('runRef'), 'direct library call stores runRef=null');
    }

    public function testWrongScopeIsDenied(): void
    {
        $this->assertTag('wrong-scope', 5, 10);
        $php = $this->phpReport()['bindingVectors'];
        self::assertSameJson($php, $this->pythonReport()['bindingVectors']);
        foreach ($this->harness()->vectors(ContractHarness::BINDING_VECTORS, 'urn:knowkit:schema:binding-vectors:1.0') as $vector) {
            $item = $this->reportItem($this->phpReport(), 'bindingVectors', $vector->get('id'));
            self::assertArrayNotHasKey('inputErrors', $item, $vector->get('id'));
            $expected = json_decode(Jcs::canonicalize($vector->get('expected')), true);
            self::assertSameJson($expected, array_diff_key($item, ['id' => true]), $vector->get('id'));
            if ($expected['outcome'] === 'denied') {
                self::assertSame('denied', ErrorCatalog::classOf($expected['code']));
            }
        }
        self::assertSame('denied', $this->reportItem($this->phpReport(), 'bindingVectors', 'scope.other-host-scope-hash')['outcome']);
    }

    public function testEgressRequestCoversCompleteManifest(): void
    {
        $this->assertTag('egress-manifest', 1, 5);
        $categories = array_map(static fn(JsonObject $item): string => $item->get('category'), $this->fixture('egress-request.model-context')->get('items'));
        self::assertContains('QUESTION', $categories);
        self::assertContains('HISTORY', $categories);
        self::assertContains('EVIDENCE', $categories);
    }

    public function testJobEnvelopeAndWorkerResultUseCanonicalFields(): void
    {
        $this->assertTag('jobs', 2, 10);
        $envelope = $this->fixture('job-envelope.valid');
        self::assertSame($envelope->get('parserSignature')->get('profileDigest'), $envelope->get('profileFingerprint'));
        $result = $this->fixture('worker-result.valid');
        self::assertSame(HashDomain::WorkerResult->digest($result->without('resultDigest')), $result->get('resultDigest'));
    }

    public function testEventsRestoreAndDeletionReceipts(): void
    {
        $this->assertTag('events', 2, 4);
        $this->assertTag('deletion', 2, 5);
        self::assertSame('done', \KnowKit\Domain\Contract\SemanticRules::deletionStatus(['deleted', 'absent']));
        self::assertSame('unsupported', \KnowKit\Domain\Contract\SemanticRules::deletionStatus(['unsupported', 'unsupported']));
        self::assertSame('unknown', \KnowKit\Domain\Contract\SemanticRules::deletionStatus(['pending', 'unknown']));
        self::assertSame('partial', \KnowKit\Domain\Contract\SemanticRules::deletionStatus(['deleted', 'unknown']));
        self::assertSame('pending', \KnowKit\Domain\Contract\SemanticRules::deletionStatus(['pending', 'in_flight']));
    }

    public function testSelectionRunBindingIsEnforced(): void
    {
        $this->assertTag('run-binding', 2, 2);
        $schema = $this->harness()->json('schemas/selection.schema.json');
        self::assertContains('runRef', $schema->get('required'), 'run binding cannot be dropped');
        $direct = $this->fixture('selection.valid');
        $bound = $this->fixture('selection.run-bound');
        self::assertNull($direct->get('runRef'), 'direct library call has no run');
        self::assertSame($this->fixture('disclosure-request.usage-context')->get('usageContext')->get('runRef'), $bound->get('runRef'));
        self::assertSame($direct->get('scopeBindingDigest'), $bound->get('scopeBindingDigest'), 'runRef stays outside the scopeBinding payload');
        self::assertSame($direct->get('hostScopeHash'), $bound->get('hostScopeHash'), 'hostScopeHash verbatim');
        $expected = ['disclosure.subset' => ['outcome' => 'success'], 'disclosure.same-run' => ['outcome' => 'success'],
            'disclosure.other-run' => ['outcome' => 'denied', 'code' => 'scope_mismatch'],
            'disclosure.run-binding-dropped' => ['outcome' => 'denied', 'code' => 'scope_mismatch'],
            'disclosure.run-on-direct-selection' => ['outcome' => 'denied', 'code' => 'scope_mismatch'],
            'scope.match-run-bound' => ['outcome' => 'success']];
        foreach ($expected as $id => $outcome) {
            self::assertSameJson(['id' => $id] + $outcome, $this->reportItem($this->phpReport(), 'bindingVectors', $id), $id);
            self::assertSameJson(['id' => $id] + $outcome, $this->reportItem($this->pythonReport(), 'bindingVectors', $id), $id . ' (Python)');
        }
        $other = $this->harness()->vectors(ContractHarness::BINDING_VECTORS, 'urn:knowkit:schema:binding-vectors:1.0');
        foreach ($other as $vector) {
            if ($vector->get('id') === 'disclosure.other-run') {
                $input = $vector->get('input');
                self::assertSame([$bound->get('tenantId'), $bound->get('hostScopeHash')], [$input->get('selection')->get('tenantId'), $input->get('selection')->get('hostScopeHash')]);
                self::assertNotSame($bound->get('runRef'), $input->get('request')->get('usageContext')->get('runRef'));
            }
        }
    }

    public function testEventPayloadsAreClosedPerType(): void
    {
        $this->assertTag('event-payload', 13, 6);
        $types = $this->harness()->json('schemas/domain-event.schema.json')->get('properties')->get('type')->get('enum');
        $covered = [];
        foreach ($this->entries() as $id => $entry) {
            if ($entry['schemaId'] !== 'urn:knowkit:schema:domain-event:1.0') {
                continue;
            }
            $event = $this->fixture($id);
            // Every payload negative carries a correct payloadDigest, so only the closed shape rejects it.
            if (in_array('event-payload', $entry['tags'], true)) {
                self::assertSame(HashDomain::DomainEventPayload->digest($event->get('payload')), $event->get('payloadDigest'), $id);
            }
            if ($entry['expected'] === 'valid') {
                $covered[$event->get('type')] = true;
                foreach (['documentText', 'credentials', 'signedUrl'] as $field) {
                    $payload = $event->get('payload')->with($field, 'x');
                    $leaky = $event->with('payload', $payload)->with('payloadDigest', HashDomain::DomainEventPayload->digest($payload));
                    self::assertSame([['code' => 'unknown_field', 'path' => '/payload/' . $field]],
                        $this->harness()->validator->validateValue($leaky, 'urn:knowkit:schema:domain-event:1.0'), $id . ' + ' . $field);
                }
            }
        }
        self::assertSame($types, array_values(array_intersect($types, array_keys($covered))), 'one valid payload per event type');
        foreach (['domain-event.payload-document-text', 'domain-event.payload-credentials', 'domain-event.payload-unknown-field'] as $id) {
            self::assertSame('unknown_field', $this->phpErrors($id)[0]['code'], $id);
        }
    }

    public function testEvidenceRevisionIdentityIsConsistent(): void
    {
        $this->assertTag('revision-identity', 2, 2);
        self::assertSame([], $this->phpReport()['identityConflicts']);
        self::assertSame([], $this->pythonReport()['identityConflicts']);
        $evidence = $this->fixture('evidence-set.valid');
        $revisions = [];
        foreach ($evidence->get('fragments') as $fragment) {
            $citation = $fragment->get('citation');
            $revisions[$citation->get('documentRevisionId')][] = $citation->get('irDigest');
        }
        self::assertCount(2, $revisions, 'two IRs need two immutable revisions');
        self::assertSame(Digests::ir($this->fixture('document-ir.structured')), $this->fixture('citation-ref.generated')->get('irDigest'));
        // Negative example: the pre-1.1.0 corpus pair (same revision, two IRs) is a conflict.
        $multi = $this->fixture('citation-ref.multi-span');
        $conflicting = $this->fixture('citation-ref.generated')->with('documentRevisionId', $multi->get('documentRevisionId'));
        self::assertSame([['entry' => 'generated', 'path' => '/irDigest', 'field' => 'irDigest']],
            $this->harness()->identityConflicts(['multi' => $multi, 'generated' => $conflicting]));
        self::assertSame([], $this->harness()->identityConflicts(['multi' => $multi, 'generated' => $this->fixture('citation-ref.generated')]));
    }

    public function testApprovedErrorCodesKeepOriginalCase(): void
    {
        $this->assertTag('error-codes', 4, 2);
        $registry = json_decode($this->harness()->read('specs/contracts/error-codes.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        $outcomes = $registry['outcomeCodes']['codes'];
        self::assertSame(['denied', 'unavailable', 'incompatible', 'incompatible'], [$outcomes['POLICY_CHANGED']['outcome'],
            $outcomes['RETRYABLE_LOCK_TIMEOUT']['outcome'], $outcomes['POINTER_CONFLICT']['outcome'], $outcomes['HOLD_TOO_LATE']['outcome']]);
        foreach ($outcomes as $code => $meta) {
            $result = new JsonObject(['outcome' => $meta['outcome'], 'error' => new JsonObject(['code' => $code, 'retryable' => $meta['retryable']]),
                'degraded' => false, 'warnings' => []]);
            self::assertSame([], $this->harness()->validator->validateValue($result, 'urn:knowkit:schema:result:1.0'), $code);
        }
        self::assertSame('denied', ErrorCatalog::classOf('POLICY_CHANGED'));
        self::assertArrayNotHasKey('policy_changed', ErrorCatalog::CODES, 'no lowercase alias of an approved code');
        foreach ($this->entries() as $id => $entry) {
            if (!in_array('error-codes', $entry['tags'], true) || $entry['expected'] !== 'valid') {
                continue;
            }
            $first = $this->fixture($id);
            $canonical = Jcs::canonicalize($first);
            $second = StrictJson::decode($canonical);
            self::assertSame($first->get('error')->get('code'), $second->get('error')->get('code'), $id . ' round trip keeps case');
            self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]+$/D', $second->get('error')->get('code'), $id);
            $file = $this->temporary . '/' . $id . '.json';
            file_put_contents($file, $canonical);
            $python = $this->python(['validate', '--root', $this->qa->root, '--schema', 'urn:knowkit:schema:result:1.0', '--file', $file]);
            self::assertSame([0, ['errors' => []]], [$python['exit'], json_decode($python['stdout'], true)], $id . ' (Python)');
        }
        foreach (['Policy_Changed', 'policy-changed', 'POLICY CHANGED', ''] as $bad) {
            $result = new JsonObject(['outcome' => 'denied', 'error' => new JsonObject(['code' => $bad, 'retryable' => false]), 'degraded' => false, 'warnings' => []]);
            self::assertSame([['code' => 'pattern', 'path' => '/error/code']], $this->harness()->validator->validateValue($result, 'urn:knowkit:schema:result:1.0'), $bad);
        }
        self::assertSame('POLICY_CHANGED', $this->reportItem($this->pythonReport(), 'bindingVectors', 'scope.policy-epoch-changed')['code']);
    }

    public function testJobRecordStatesAndLeaseFields(): void
    {
        $this->assertTag('job-record', 5, 6);
        $schema = $this->harness()->json('schemas/job.schema.json');
        $states = $schema->get('properties')->get('state')->get('enum');
        self::assertSame(['pending', 'leased', 'succeeded', 'failed', 'cancelled'], $states);
        foreach (['tenantId', 'kbId', 'sourceId', 'sourceVersionId', 'generationId', 'type', 'state', 'attempt', 'fence',
            'leaseTokenHash', 'leaseUntil', 'attemptDeadline', 'availableAt', 'checkpointDigest'] as $field) {
            self::assertContains($field, $schema->get('required'), $field);
        }
        $seen = [];
        foreach ($states as $state) {
            $job = $this->fixture('job.' . $state);
            $seen[] = $job->get('state');
            self::assertIsString($job->get('fence'), 'fence is a decimal string');
        }
        self::assertSame($states, $seen);
        $leased = $this->fixture('job.leased');
        self::assertSame(64, strlen($leased->get('leaseTokenHash')), 'only the token hash is stored');
        self::assertFalse($leased->has('leaseToken'));
        self::assertNull($this->fixture('job.pending')->get('generationId'));
    }

    public function testManifestsSelectionAndEvidenceRecords(): void
    {
        $this->assertTag('manifests', 3, 6);
        $this->assertTag('selection', 1, 4);
        $this->assertTag('evidence', 2, 5);
        $selection = $this->fixture('selection.valid');
        self::assertSame($this->fixture('scope.valid')->get('hostScopeHash'), $selection->get('hostScopeHash'));
        self::assertSame($selection->get('hostScopeHash'), $this->fixture('evidence-set.valid')->get('hostScopeHash'));
        self::assertSame($selection->get('hostScopeHash'), $this->fixture('usage.valid')->get('hostScopeHash'));
    }

    public function testExtendedHashVectorsAndW02Schemas(): void
    {
        $this->assertTag('w02-schemas', 6, 6);
        $vectors = $this->harness()->vectors('tests/fixtures/contracts/v1/extended-hash-vectors.json', 'urn:knowkit:schema:hash-vectors:1.0');
        self::assertCount(13, $vectors);
        foreach ($vectors as $vector) {
            $id = $vector->get('id');
            if ($vector->get('algorithm') === 'domain') {
                $payload = $vector->get('payload');
                self::assertSame($vector->get('expectedCanonical'), Jcs::canonicalize($payload), $id);
                self::assertSame($vector->get('expectedSha256'), HashDomain::fromKey($vector->get('domain'))->digest($payload), $id);
            } elseif ($vector->get('algorithm') === 'decode') {
                $this->rejectsWith($vector->get('expectedErrorCode'), '', static fn() => StrictJson::decode((string) base64_decode($vector->get('inputBase64'), true)));
            }
        }
        foreach (['directive-artifact', 'layer-stack', 'resolution-manifest', 'concept-edges', 'cache-profile', 'training-export'] as $name) {
            self::assertStringNotContainsString('"number"', $this->harness()->read("schemas/{$name}.schema.json"), $name);
        }
        $space = new \KnowKit\Domain\EmbeddingSpace('openai', 'text-embedding-3-large', '2024-01-25', 1536, 'l2', 'v1', 'doc', 'query', 'halfvec', 3072);
        self::assertSame([], $this->harness()->validator->validateValue($space->toWire(), 'urn:knowkit:schema:embedding-space:1.0'));
        self::assertTrue(\KnowKit\Domain\Layers\DisclosureMode::DerivedOnly->allowsTier(\KnowKit\Domain\Layers\ResolutionTier::R2Propositions));
        self::assertFalse(\KnowKit\Domain\Layers\DisclosureMode::DerivedOnly->allowsTier(\KnowKit\Domain\Layers\ResolutionTier::R3Verbatim));
        self::assertFalse(\KnowKit\Domain\Layers\DisclosureMode::DerivedOnly->allowsDirectRead());
    }
}
