<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Observability;

use KnowKit\Application\Access\HostIdentity;
use KnowKit\Application\Observability\Diagnostics;
use KnowKit\Application\Observability\Telemetry;
use KnowKit\Application\Observability\TelemetryFactory;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Observability as O;
use KnowKit\Http\Server as H;
use KnowKit\Port\Clock;
use KnowKit\Port\DiagnosticState;
use KnowKit\Port\IdGenerator;
use KnowKit\Port\ObservationSink;
use KnowKit\Registry as R;

require_once dirname(__DIR__, 3) . '/packages/core/conformance/autoload.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KnowKit\\Http\\')) {
        $path = dirname(__DIR__, 3) . '/packages/http/src/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
}, true, true);

/** Synthetic host only: no optional provider/OCR/vector implementation is qualified by these doubles. */
final class Fixture implements ObservationSink, Clock, DiagnosticState, H\Authenticator, IdGenerator
{
    /** @var list<O\Observation> */
    public array $observations = [];
    public R\AdapterRegistry $registry;
    public R\HostProfile $profile;
    public O\RuntimeState $state;
    public int $factoryCalls = 0;
    public int $stateCalls = 0;
    public ?\Closure $readState = null;
    public ?PolicyContext $identity;
    public bool $authenticated = true;

    public function __construct()
    {
        $this->identity = new PolicyContext(scope: self::scope(), audience: 'ops-host');
        $this->registry = new R\AdapterRegistry();
        $this->register(id: 'fixture.lexical', kind: D\AdapterKind::Lexical, capability: D\Capability::LexicalPrefilter);
        $this->register(id: 'fixture.ocr', kind: D\AdapterKind::Parser, capability: D\Capability::ParserOcr);
        $this->registry->boot();
        $document = StrictJson::decode((string) file_get_contents(__DIR__ . '/fixtures/v1/profile.json'));
        $this->profile = new R\HostProfile(document: $document, registry: $this->registry);
        $this->state = $this->runtime();
    }

    private function register(string $id, D\AdapterKind $kind, D\Capability $capability): void
    {
        $factory = new class($this) implements R\Factory {
            public function __construct(
                private Fixture $fixture,
            ) {}

            public function create(D\EffectiveProfile $profile): object
            {
                ++$this->fixture->factoryCalls;
                throw new \LogicException('Diagnostic discovery must not construct adapters');
            }
        };
        $this->registry->register(
            descriptor: new R\AdapterDescriptor(
                id: new D\AdapterId($id), kind: $kind, version: '1',
                configSchema: new JsonObject([
                    '$schema' => D\Contract\SchemaSet::DIALECT,
                    '$id' => 'urn:knowkit:ops-fixture:' . $id, 'type' => 'object',
                    'additionalProperties' => true, 'unevaluatedProperties' => false,
                ]),
                capabilities: [new D\CapabilityDeclaration($capability)],
            ),
            factory: $factory,
        );
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function id(int $number): string
    {
        return '01J' . str_pad((string) $number, 23, '0', STR_PAD_LEFT);
    }

    public static function scope(int $tenant = 1, int $kb = 2): D\Scope
    {
        return new D\Scope(
            tenantId: new D\TenantId(self::id($tenant)), kbId: new D\KbId(self::id($kb)),
            actorId: new D\ActorId(self::id(3)), purpose: 'ops.inspect',
            policyEpoch: new D\Decimal('0'), hostScopeHash: new D\Sha256(str_repeat('a', 64)),
        );
    }

    public static function cases(): array
    {
        static $cases;
        $cases ??= json_decode((string) file_get_contents(__DIR__ . '/fixtures/v1/cases.json'), true, 512, JSON_THROW_ON_ERROR);

        return $cases;
    }

    public static function validator(): \KnowKit\Domain\Contract\ContractValidator
    {
        static $validator;

        return $validator ??= \KnowKit\Conformance\SchemaLoader::load(self::root() . '/schemas');
    }

    public static function disclosureRequest(): D\DisclosureRequest
    {
        return new D\DisclosureRequest(
            value: new JsonObject([
                'selectionId' => self::id(20), 'disclosureAttemptId' => self::id(21),
                'chunkRefs' => [new JsonObject([
                    'tenantId' => self::id(1), 'sourceId' => self::id(12), 'sourceVersionId' => self::id(13),
                    'sourceDigest' => str_repeat('a', 64), 'documentRevisionId' => self::id(14),
                    'irDigest' => str_repeat('b', 64),
                    'parserSignature' => new JsonObject(['name' => 'fixture', 'version' => '1', 'profileDigest' => str_repeat('c', 64)]),
                    'chunkSetId' => self::id(15), 'chunkId' => self::id(16), 'chunkDigest' => str_repeat('d', 64),
                ])],
            ]),
            validator: self::validator(),
        );
    }

    public function runtime(
        D\Outcome $ocr = D\Outcome::Success,
        bool $schema = true,
        bool $replayed = true,
        bool $ocrRequired = false,
        ?D\Coverage $coverage = null,
        D\Outcome $index = D\Outcome::Success,
    ): O\RuntimeState {
        return new O\RuntimeState(
            scope: self::scope(), profile: $this->profile->digest(), metadataSchemaReady: $schema,
            tombstonesReplayed: $replayed,
            adapters: [
                new O\AdapterObservation(
                    adapterId: new D\AdapterId('fixture.lexical'), required: true, enabled: true,
                    outcome: D\Outcome::Success, verifiedProfile: $this->profile->digest(),
                ),
                new O\AdapterObservation(
                    adapterId: new D\AdapterId('fixture.ocr'), required: $ocrRequired, enabled: true,
                    outcome: $ocr, verifiedProfile: $this->profile->digest(),
                ),
            ],
            coverage: $coverage, indexOutcome: $index,
        );
    }

    public function current(D\Scope $scope): O\RuntimeState
    {
        ++$this->stateCalls;

        return $this->readState === null ? $this->state : ($this->readState)($scope);
    }

    public function authenticate(H\Request $request): ?HostIdentity
    {
        if (!$this->authenticated) {
            return null;
        }

        return new class($this) implements HostIdentity {
            public function __construct(
                private Fixture $fixture,
            ) {}

            public function current(): ?PolicyContext
            {
                return $this->fixture->identity;
            }
        };
    }

    public function next(): D\Ulid
    {
        return new D\Ulid(self::id(90));
    }

    public function now(): D\UtcInstant
    {
        return new D\UtcInstant('2026-11-09T00:00:00Z');
    }

    public function emit(O\Observation $observation): void
    {
        $this->observations[] = $observation;
    }

    public function telemetry(?ObservationSink $sink = null, ?string $runRef = null): Telemetry
    {
        return $this->factory($sink)->start(
            correlationId: new D\Ulid(self::id(90)), scope: self::scope(), runRef: $runRef,
        );
    }

    public function factory(?ObservationSink $sink = null): TelemetryFactory
    {
        return new TelemetryFactory(sink: $sink ?? $this, clock: $this, redactionKey: str_repeat('k', 32));
    }

    public function diagnostics(): Diagnostics
    {
        return new Diagnostics(registry: $this->registry, profile: $this->profile, state: $this);
    }

    public function server(H\HostApplication $next): H\Server
    {
        $application = new H\Diagnostics\Application(next: $next, diagnostics: $this->diagnostics(), telemetry: $this->factory());

        return new H\Server(
            authenticator: $this, application: $application, contract: new H\Contract(),
            commands: new H\CommandFactory(validator: self::validator(), clock: $this),
            ids: $this,
        );
    }
}
