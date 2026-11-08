<?php

declare(strict_types=1);

namespace KnowKit\Tests\Bridge\UpstreamContract;

use Closure;
use KnowKit\Bridge\AgenTools\Qualification\Surface;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\BoundaryStage;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\ContextBlock;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\Contract;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\ContributionPin;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\EffectiveProfile;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\EvidenceContextRequest;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\EvidenceContribution;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\IncomingInput;
use KnowKit\Bridge\AgenTools\Testing\ContractFixture\TextComposition;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/Support.php';

#[Group('bridge:upstream-contract')]
final class SeamTest extends TestCase
{
    public function testA01ExactSignaturesSchemasAndPins(): void
    {
        Contract::assertPins();
        [$definitions, $map] = $this->fixtureSurface();
        Surface::assertMatches(definitions: $definitions, classMap: $map);
        $this->assertBaselineConstructorPrefixes();
        $fixture = Contract::fixture();
        foreach ($fixture['golden']['sourcePins'] as $source) {
            self::assertSame($source['sha256'], hash_file('sha256', Contract::root()
                . '/.swissknifeman/orch/donor-cache/agentools/' . $source['path']));
        }
        $receipt = Contract::json('tests/bridge/upstream-contract/evidence/P8.4-reuse.json');
        self::assertTrue($receipt['reviewedBeforeImplementation']);
        self::assertCount(6, $receipt['candidates']);
        self::assertSame(['P8.4-reuse.json'], array_values(array_diff(scandir(__DIR__ . '/evidence'), ['.', '..'])));
    }

    public function testA02EmptyDefaultPreservesBytesAndHash(): void
    {
        $scenario = new Scenario();
        $golden = Contract::fixture()['golden'];
        $legacy = static fn (): string => $golden['requestBytes'];
        foreach ([new TextComposition(legacyCompiler: $legacy),
            new TextComposition(legacyCompiler: $legacy, evidenceContributors: [])] as $composition) {
            $result = $composition->compile(context: $scenario->context, input: new IncomingInput('Question'),
                request: $scenario->request, profile: new EffectiveProfile('test'), authorities: []);
            self::assertSame($golden['requestBytes'], $result['requestBytes']);
            self::assertSame($golden['requestHash'], $result['requestHash']);
            self::assertTrue($result['fixture']);
            self::assertSame([], $result['compiled']->dependencies());
        }
        $parameter = (new ReflectionClass(TextComposition::class))->getConstructor()->getParameters()[1];
        self::assertSame('evidenceContributors', $parameter->getName());
        self::assertSame([], $parameter->getDefaultValue());
        self::assertSame(0, $scenario->contributor->collectCalls);
    }

    public function testA03PermittedBytesAreCompiled(): void
    {
        $scenario = new Scenario();
        $result = $scenario->compile();
        $scenario->exchange($result);
        self::assertStringContainsString($scenario->block->modelView, $scenario->sent[0]);
        self::assertSame('Question' . "\n" . $scenario->block->modelView,
            json_decode($scenario->sent[0], true)['messages'][0]['content']);
        self::assertSame(hash('sha256', $scenario->sent[0]), $result['requestHash']);
        self::assertSame(Contract::fixture()['golden']['expectedOutcomes']['allowed'],
            ['sendCount' => $scenario->sendCount, 'deliveryCount' => $scenario->deliveryCount]);
        self::assertSame(1, $scenario->contributor->collectCalls);
    }

    public function testA04TrustedScopeAndOperation(): void
    {
        $scenario = new Scenario();
        self::assertSame('support.read', $scenario->context->purpose);
        self::assertSame('ModelEgressContext', $scenario->request->operation);
        $scenario->request = new EvidenceContextRequest(...array_replace($scenario->requestData(), ['hostScopeHash' => str_repeat('b', 64)]));
        $this->rejects(static fn () => $scenario->compile());
        foreach (['tenant_id', 'actor_ref', 'acting_as', 'subject_ref', 'authorization_ref', 'policy_revision',
            'consent_ref', 'agent_principal_ref', 'session_ref', 'purpose'] as $field) {
            $wire = $scenario->context->toArray();
            self::assertArrayHasKey($field, $wire);
        }
        $this->rejects(static fn () => new EvidenceContextRequest(...array_replace($scenario->requestData(), ['operation' => 'support.read'])));
        $scenario->request = new EvidenceContextRequest(...$scenario->requestData());
        $scenario->contributor->contribution = $scenario->contribution(receipt: $scenario->newReceipt(['hostScopeHash' => str_repeat('c', 64)]));
        $this->rejects(static fn () => $scenario->compile());
        self::assertSame([], $scenario->sent);
    }

    public function testA05BoundedAggregate(): void
    {
        $scenario = new Scenario();
        $scenario->request = new EvidenceContextRequest(...array_replace($scenario->requestData(), ['maxBytes' => 1]));
        $this->rejects(static fn () => $scenario->compile());
        $scenario->request = new EvidenceContextRequest(...$scenario->requestData());
        $this->rejects(static fn () => $scenario->compile(contributors: array_fill(0, 5, $scenario->contributor)));
        $this->rejects(static fn () => $scenario->compile(contributors: array_fill(0, 33, $scenario->contributor)));
        foreach ([['maxFragments' => 9], ['maxBytes' => 8193], ['maxFragments' => 0], ['maxBytes' => 0]] as $replace) {
            $this->rejects(static fn () => new EvidenceContextRequest(...array_replace($scenario->requestData(), $replace)));
        }
        $profile = new EffectiveProfile('test');
        self::assertSame(4, $profile->fragmentLimit($scenario->request));
        self::assertSame(4096, $profile->byteLimit($scenario->request));
        $request = new EvidenceContextRequest(...array_replace($scenario->requestData(), ['maxFragments' => 8, 'maxBytes' => 8192]));
        self::assertSame(4, $profile->fragmentLimit($request));
        self::assertSame(4096, $profile->byteLimit($request));
        $large = new ContextBlock(...array_replace(get_object_vars($scenario->block), ['modelView' => str_repeat('é', 3000)]));
        $scenario->contributor->contribution = $scenario->contribution(blocks: [$large]);
        $this->rejects(static fn () => $scenario->compile());
        self::assertSame(0, $scenario->sendCount);
    }

    public function testA06CurrentAuthorityRequired(): void
    {
        foreach ([[], ['knowkit.evidence:wrong-pin' => (new Scenario())->authority]] as $authorities) {
            $scenario = new Scenario();
            $this->rejects(static fn () => $scenario->compile(authorities: $authorities));
            self::assertSame([], $scenario->sent);
        }
        $scenario = new Scenario();
        $foreign = new Scenario();
        $foreignPin = new ContributionPin(id: 'other', version: '1.0.0',
            contractDigest: $scenario->pin->contractDigest, configDigest: $scenario->pin->configDigest);
        $authority = new SourceAuthority(host: $foreign->host, pin: $foreignPin, expected: $foreign->block);
        $this->rejects(static fn () => $scenario->compile(authorities: [$scenario->pin->id . ':' . $scenario->pin->digest() => $authority]));
    }

    public function testA07DeniedUnknownNeverSend(): void
    {
        foreach (['denied', 'unknown'] as $decision) {
            $scenario = new Scenario();
            $scenario->authority->decision = $decision;
            $this->rejects(static fn () => $scenario->exchange($scenario->compile()));
            self::assertSame(Contract::fixture()['golden']['expectedOutcomes'][$decision],
                ['sendCount' => $scenario->sendCount, 'deliveryCount' => $scenario->deliveryCount]);
            self::assertSame([], $scenario->sent);
        }
    }

    public function testA08EpochAndLifecycleChangesRefuse(): void
    {
        foreach (['epoch' => '2', 'ancestorsActive' => false] as $property => $value) {
            $scenario = new Scenario();
            $compiled = $scenario->compile();
            $scenario->authority->{$property} = $value;
            $this->rejects(static fn () => $scenario->exchange($compiled));
            self::assertSame(Contract::fixture()['golden']['expectedOutcomes']['stale'],
                ['sendCount' => $scenario->sendCount, 'deliveryCount' => $scenario->deliveryCount]);
        }
    }

    public function testA09ExpiryRefuses(): void
    {
        $scenario = new Scenario();
        $compiled = $scenario->compile();
        $scenario->authority->now = $scenario->receipt->expiresAt;
        $this->rejects(static fn () => $scenario->exchange($compiled));
        self::assertSame([], $scenario->sent);
        self::assertSame(0, $scenario->deliveryCount);
    }

    public function testA10ExactSourceAndModelDigest(): void
    {
        foreach (['sourceRef' => 'source:latest', 'sourceVersion' => 'version:2', 'sourceHash' => str_repeat('b', 64),
            'irDigest' => str_repeat('c', 64), 'spanRef' => 'span:1:25', 'modelView' => 'Protected changed bytes'] as $field => $value) {
            $scenario = new Scenario();
            $block = new ContextBlock(...array_replace(get_object_vars($scenario->block), [$field => $value]));
            $this->rejects(static function () use ($scenario, $block): void {
                $contribution = new EvidenceContribution(pin: $scenario->pin, blocks: [$block],
                    dependencies: [['ref' => $block->sourceRef, 'version' => $block->sourceVersion, 'hash' => $block->sourceHash]],
                    receipt: $scenario->receipt);
                $scenario->contributor->contribution = $contribution;
                $scenario->compile();
            });
            self::assertSame([], $scenario->sent);
        }
    }

    public function testA11RestoreDoesNotRecollect(): void
    {
        $scenario = new Scenario();
        $compiled = $scenario->compile();
        $compiled['compiled']->authorize(context: $scenario->context, stage: BoundaryStage::Restore);
        $scenario->authority->epoch = '2';
        $this->rejects(static fn () => $compiled['compiled']->authorize(context: $scenario->context, stage: BoundaryStage::Restore));
        self::assertSame(1, $scenario->contributor->collectCalls);
        self::assertSame(hash('sha256', $compiled['requestBytes']), $compiled['requestHash']);
        self::assertSame([], $scenario->sent);
    }

    public function testA12BeforeModelIoRechecks(): void
    {
        $scenario = new Scenario();
        $compiled = $scenario->compile();
        $this->rejects(static fn () => $scenario->exchange(compiled: $compiled, beforeSend: static function () use ($scenario): void {
            $scenario->authority->decision = 'denied';
        }));
        self::assertSame([], $scenario->sent);
        self::assertContains(BoundaryStage::BeforeModelIo, $scenario->authority->stages);
    }

    public function testA13AfterIntentRechecks(): void
    {
        $scenario = new Scenario();
        $this->rejects(static fn () => $scenario->exchange(compiled: $scenario->compile(), afterSend: static function () use ($scenario): void {
            $scenario->authority->ancestorsActive = false;
        }));
        self::assertSame(1, $scenario->sendCount);
        self::assertSame(0, $scenario->deliveryCount);
        self::assertContains(BoundaryStage::AfterDurableIntent, $scenario->authority->stages);
    }

    public function testA14BothDeliveryBoundariesRecheck(): void
    {
        foreach ([BoundaryStage::BufferedDelivery, BoundaryStage::ActualDelivery] as $stage) {
            $scenario = new Scenario();
            $compiled = $scenario->compile();
            $scenario->authority->deniedStage = $stage;
            $this->rejects(static fn () => $scenario->exchange($compiled));
            self::assertSame(1, $scenario->sendCount);
            self::assertSame(0, $scenario->deliveryCount);
            self::assertContains($stage, $scenario->authority->stages);
        }
    }

    public function testA15HostGuardStillApplies(): void
    {
        foreach (['denied' => true, 'revision' => 'policy-2'] as $property => $value) {
            $scenario = new Scenario();
            $compiled = $scenario->compile();
            $scenario->host->{$property} = $value;
            $this->rejects(static fn () => $scenario->exchange($compiled));
            self::assertSame([], $scenario->sent);
            self::assertGreaterThan(1, $scenario->host->calls);
        }
    }

    public function testA16DependenciesAndRetentionBindings(): void
    {
        $scenario = new Scenario();
        $compiled = $scenario->compile()['compiled'];
        self::assertSame($scenario->contributor->contribution->dependencies, $compiled->dependencies());
        $manifest = $compiled->retentionManifest();
        self::assertSame(get_object_vars($scenario->pin), $manifest['contributions'][0]['pin']);
        self::assertSame(get_object_vars($scenario->receipt), $manifest['contributions'][0]['receipt']);
        $before = Contract::digest($manifest);
        $manifest['contributions'][0]['receipt']['selectionRefs'] = ['selection:2'];
        self::assertNotSame($before, Contract::digest($manifest));
        $this->rejects(static fn () => new EvidenceContribution(pin: $scenario->pin, blocks: [$scenario->block],
            dependencies: [], receipt: $scenario->receipt));
        $this->rejects(static fn () => (new ReflectionClass(EvidenceContribution::class))->newInstanceArgs([
            'pin' => $scenario->pin, 'blocks' => [get_object_vars($scenario->block)],
            'dependencies' => $scenario->contributor->contribution->dependencies, 'receipt' => $scenario->receipt,
        ]));
    }

    public function testA17NoRetryOrFallback(): void
    {
        $scenario = new Scenario();
        $this->rejects(static fn () => $scenario->exchange(compiled: $scenario->compile(), afterSend: static function (): void {
            throw new RuntimeException('Scripted provider unknown outcome');
        }));
        self::assertSame(1, $scenario->sendCount);
        self::assertSame(1, $scenario->contributor->collectCalls);
        self::assertSame(0, $scenario->deliveryCount);
    }

    public function testA18ManifestCompleteAndRunnerNegatives(): void
    {
        $manifest = Contract::json('tests/fixtures/check-groups/bridge-upstream-contract.json');
        $cases = array_map(static fn (string $id): array => ['id' => $id, 'status' => 'passed'],
            array_values(array_unique(array_merge(...array_values($manifest['testCaseMap'])))));
        self::assertCount(26, CaseManifest::assertExecuted(cases: $cases, manifest: $manifest));
        $actualMethods = array_map(static fn ($method): string => self::class . '::' . $method->getName(),
            array_values(array_filter((new ReflectionClass(self::class))->getMethods(), static fn ($method): bool => str_starts_with($method->getName(), 'test'))));
        self::assertEqualsCanonicalizing($actualMethods, array_column($cases, 'id'));
        foreach ([[], array_slice($cases, 1), [...$cases, $cases[0]], [...$cases, ['id' => 'unknown', 'status' => 'passed']],
            array_replace($cases, [0 => ['id' => $cases[0]['id'], 'status' => 'skipped']])] as $invalid) {
            $this->rejects(static fn () => CaseManifest::assertExecuted(cases: $invalid, manifest: $manifest));
        }
        foreach ([array_slice($manifest['requiredCaseIds'], 1), [...$manifest['requiredCaseIds'], 'A19'],
            [...$manifest['requiredCaseIds'], 'A01']] as $ids) {
            $this->rejects(static fn () => CaseManifest::assertDefinition(array_replace($manifest, ['requiredCaseIds' => $ids])));
        }
        self::assertSame(array_slice($manifest['requiredCaseIds'], 8),
            Contract::json('contracts/agentools/evidence-context/change-request.v1.json')['requiredCaseIds']);
        (new Qa(Contract::root()))->fixtures($manifest);
    }

    public function testStrictFixtureSchemaRejectsUnknownNestedAndInvalidTypes(): void
    {
        $fixture = Contract::fixture();
        $schema = Contract::json('contracts/agentools/evidence-context/change-request.v1.json')['fixtureSchema'];
        Contract::assertSchema(schema: $schema, value: $fixture);
        $invalid = $fixture;
        $invalid['unexpected'] = true;
        $this->rejects(static fn () => Contract::assertSchema(schema: $schema, value: $invalid));
        $invalid = $fixture;
        $invalid['golden']['sourcePins'][0]['credential'] = 'forbidden';
        $this->rejects(static fn () => Contract::assertSchema(schema: $schema, value: $invalid));
        $invalid = $fixture;
        $invalid['production'] = 'false';
        $this->rejects(static fn () => Contract::assertSchema(schema: $schema, value: $invalid));
        $scenario = new Scenario();
        $this->rejects(static fn () => new EvidenceContextRequest(...array_replace($scenario->requestData(), ['now' => '2026-99-09T00:00:00Z'])));
    }

    public function testFixtureCannotRunInProductionOrProductionAutoload(): void
    {
        foreach (['production', 'development', ''] as $environment) {
            $this->rejects(static fn () => new EffectiveProfile($environment));
        }
        $this->rejects(static fn () => new EffectiveProfile(environment: 'test', name: 'agentools-v1'));
        $manifest = Contract::json('packages/agentools-bridge/composer.json');
        self::assertArrayHasKey('KnowKit\\Bridge\\AgenTools\\Testing\\ContractFixture\\', $manifest['autoload-dev']['psr-4']);
        self::assertArrayNotHasKey('files', $manifest['autoload']);
        $result = (new Qa(Contract::root()))->process([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; $loader = new Composer\\Autoload\\ClassLoader(); '
            . '$loader->addPsr4("KnowKit\\\\Bridge\\\\AgenTools\\\\", "packages/agentools-bridge/src"); $loader->register(); '
            . 'exit(class_exists("KnowKit\\\\Bridge\\\\AgenTools\\\\Testing\\\\ContractFixture\\\\TextComposition") ? 7 : 0);']);
        self::assertSame(0, $result['exit'], $result['stderr']);
    }

    public function testNoRealFqnShadowOrAlias(): void
    {
        foreach (glob(Contract::root() . '/packages/agentools-bridge/tests/ContractFixture/*.php') as $file) {
            $tokens = token_get_all(file_get_contents($file), TOKEN_PARSE);
            foreach ($tokens as $index => $token) {
                if (is_array($token) && $token[0] === T_NAMESPACE) {
                    self::assertSame('KnowKit\\Bridge\\AgenTools\\Testing\\ContractFixture', $tokens[$index + 2][1]);
                }
                if (is_array($token) && $token[0] === T_STRING) {
                    self::assertNotSame('class_alias', strtolower($token[1]));
                }
            }
        }
        foreach (array_keys(Contract::fixture()['signatures']) as $name) {
            self::assertFalse(interface_exists($name, false));
            self::assertFalse(class_exists($name, false));
        }
    }

    public function testMissingInstalledSeamIsNonzero(): void
    {
        $result = (new Qa(Contract::root()))->process([PHP_BINARY, 'packages/agentools-bridge/bin/probe-evidence-context.php']);
        self::assertSame(2, $result['exit']);
        self::assertSame('', $result['stdout']);
        $report = json_decode(trim($result['stderr']), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report['fixture']);
        self::assertSame('unavailable', $report['upstream_seam']);
        self::assertSame('KAT-EVIDENCE-CONTEXT-1', $report['owningTask']);
        self::assertStringContainsString('real merged+installed seam required', $report['error']);
    }

    public function testPinSignatureSchemaAndHashMismatchRefused(): void
    {
        [$definitions, $map] = $this->fixtureSurface();
        $definitions['AgenToolsPlatform\\Chatom\\EvidenceContextContributor']['methods']['pin']['returnType'] = 'string';
        $this->rejects(static fn () => Surface::assertMatches(definitions: $definitions, classMap: $map));
        foreach (['signatureDigest', 'schemaDigest', 'contractDigest', 'emptyDefaultRequestHash'] as $field) {
            $matrix = Contract::json('contracts/agentools/pin-matrix.json');
            $matrix[$field] = str_repeat('0', 64);
            $this->rejects(static fn () => Contract::assertPins(matrix: $matrix));
        }
        $matrix = Contract::json('contracts/agentools/pin-matrix.json');
        $matrix['artifacts'][0]['sha256'] = str_repeat('0', 64);
        $this->rejects(static fn () => Contract::assertPins(matrix: $matrix));
        $scenario = new Scenario();
        $scenario->contributor->declaredPin = new ContributionPin(id: $scenario->pin->id, version: '2.0.0',
            contractDigest: $scenario->pin->contractDigest, configDigest: $scenario->pin->configDigest);
        $this->rejects(static fn () => $scenario->compile());
        $this->rejects(static fn () => $scenario->contribution(receipt: $scenario->newReceipt(['pinDigest' => str_repeat('0', 64)])));
    }

    private function assertBaselineConstructorPrefixes(): void
    {
        $request = Contract::json('contracts/agentools/evidence-context/change-request.v1.json');
        foreach (['TextComposition' => 'evidenceContributors', 'TextContextAuthority' => 'currentSourceAuthorities'] as $class => $parameter) {
            $source = file_get_contents(Contract::root() . '/.swissknifeman/orch/donor-cache/agentools/packages/agentools-chatom/src/' . $class . '.php');
            $baseline = $this->baselineParameters($source);
            self::assertNotEmpty($baseline);
            $proposed = $request['signatures']['AgenToolsPlatform\\Chatom\\' . $class]['methods']['__construct']['parameters'];
            self::assertSame($baseline, array_slice($proposed, 0, -1));
            self::assertSame(['name' => $parameter, 'type' => 'array', 'optional' => true, 'default' => []], $proposed[array_key_last($proposed)]);
        }
    }

    private function baselineParameters(string $source): array
    {
        preg_match_all('/^use ([A-Za-z0-9_\\\\]+);$/m', $source, $imports);
        $names = [];
        foreach ($imports[1] as $import) {
            $separator = strrpos($import, '\\');
            $names[$separator === false ? $import : substr($import, $separator + 1)] = $import;
        }
        preg_match('/public function __construct\((.*?)\) \{\}/s', $source, $constructor);
        preg_match_all('/private (\\??[A-Za-z0-9_]+) \$([A-Za-z0-9_]+)( = null)?/', $constructor[1], $parameters, PREG_SET_ORDER);
        $baseline = [];
        foreach ($parameters as $entry) {
            $nullable = str_starts_with($entry[1], '?');
            $type = ltrim($entry[1], '?');
            $type = ($nullable ? '?' : '') . ($names[$type] ?? 'AgenToolsPlatform\\Chatom\\' . $type);
            $definition = ['name' => $entry[2], 'type' => $type, 'optional' => isset($entry[3])];
            if (isset($entry[3])) {
                $definition['default'] = null;
            }
            $baseline[] = $definition;
        }

        return $baseline;
    }

    private function fixtureSurface(): array
    {
        $definitions = Contract::fixture()['signatures'];
        unset($definitions['AgenToolsPlatform\\Chatom\\TextComposition'], $definitions['AgenToolsPlatform\\Chatom\\TextContextAuthority']);
        $map = [];
        foreach (array_keys($definitions) as $name) {
            $map[$name] = 'KnowKit\\Bridge\\AgenTools\\Testing\\ContractFixture\\' . substr($name, strrpos($name, '\\') + 1);
        }
        $map['AgenTools\\Chatom\\Data\\IncomingInput'] = 'KnowKit\\Bridge\\AgenTools\\Testing\\ContractFixture\\IncomingInput';
        $map['AgenToolsPlatform\\Core\\Context\\ContextBlock'] = ContextBlock::class;
        $map['AgenToolsPlatform\\Core\\Context\\ContextSnapshot'] = 'KnowKit\\Bridge\\AgenTools\\Testing\\ContractFixture\\ContextSnapshot';

        return [$definitions, $map];
    }

    private function rejects(Closure $operation): void
    {
        $failure = null;
        try {
            $operation();
        } catch (Throwable $caught) {
            $failure = $caught;
        }
        self::assertNotNull($failure, 'Missing fail-closed check');
        self::assertNotInstanceOf(\Error::class, $failure, 'PHP errors are not contract refusals');
    }
}
