<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Observability;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Observability as O;
use KnowKit\Http\Server as H;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('ops:observability')]
final class DiagnosticsTest extends TestCase
{
    public function testOptionalOcrDownDegradesActualCapabilitiesWithoutBlockingRequiredProfile(): void
    {
        $f = new Fixture();
        $f->state = $f->runtime(ocr: D\Outcome::Unavailable);
        $snapshot = $f->diagnostics()->snapshot(Fixture::scope());
        $expected = Fixture::cases()['expected']['optionalOcrDown'];
        self::assertSame($expected['ready'], $snapshot->get('health')->get('ready'));
        self::assertSame($expected['degraded'], $snapshot->get('health')->get('degraded'));
        self::assertSame($expected['capabilities'], $snapshot->get('capabilities'));
        self::assertTrue($snapshot->get('effectiveProfile')->get('requiredSatisfied'));
        self::assertSame($f->profile->digest()->value, $snapshot->get('effectiveProfile')->get('fingerprint'));
        self::assertSame(0, $f->factoryCalls);
        self::assertSame('unavailable', $snapshot->get('dependencies')[1]->get('outcome'));

        $f->state = $f->runtime(ocr: D\Outcome::Unavailable, ocrRequired: true);
        self::assertFalse($f->diagnostics()->snapshot(Fixture::scope())->get('health')->get('ready'));
    }

    public function testRequiredSchemaTombstonesAndMissingVerificationFailClosed(): void
    {
        $f = new Fixture();
        foreach ([$f->runtime(schema: false), $f->runtime(replayed: false), new O\RuntimeState(
            scope: Fixture::scope(), profile: $f->profile->digest(), metadataSchemaReady: true,
            tombstonesReplayed: true, adapters: [], indexOutcome: D\Outcome::Success,
        )] as $state) {
            $f->state = $state;
            $snapshot = $f->diagnostics()->snapshot(Fixture::scope());
            self::assertTrue($snapshot->get('health')->get('live'));
            self::assertFalse($snapshot->get('health')->get('ready'));
        }
        self::assertSame([], $snapshot->get('capabilities'));
        self::assertSame('unknown', $snapshot->get('dependencies')[0]->get('outcome'));
        self::assertSame(0, $f->factoryCalls);
    }

    public function testUnavailableProviderAndCorruptIndexStayDistinctAndMetadataIsLazy(): void
    {
        $f = new Fixture();
        foreach ([D\Outcome::Denied, D\Outcome::Unknown, D\Outcome::Unavailable, D\Outcome::Incompatible] as $outcome) {
            $f->state = $f->runtime(ocr: $outcome, index: $outcome);
            $snapshot = $f->diagnostics()->snapshot(scope: Fixture::scope(), telemetry: $f->telemetry());
            self::assertSame($outcome->value, $snapshot->get('index')->get('outcome'));
            self::assertSame($outcome->value, $snapshot->get('dependencies')[1]->get('outcome'));
            self::assertSame([], $snapshot->get('capabilities'));
            self::assertFalse($snapshot->get('health')->get('ready'));
            self::assertSame('unknown', $snapshot->get('coverage')->get('state'));
        }
        self::assertSame(0, $f->factoryCalls);
        self::assertSame(4, $f->stateCalls);
        self::assertFalse(class_exists('KnowKit\\Adapter\\Postgres\\Catalog\\PdoCatalog', false));
        self::assertSame(D\Outcome::Incompatible, $f->observations[3]->outcome);
    }

    public function testCoverageAndProfileExportContainOnlyCountsEnumsAndFingerprints(): void
    {
        $f = new Fixture();
        $coverage = new D\Coverage(new JsonObject([
            'state' => 'partial',
            'counts' => new JsonObject(['expected' => 3, 'processed' => 1, 'empty' => 0, 'failed' => 1, 'skipped' => 1]),
            'missingFeatures' => ['parser_ocr', strtolower(Fixture::cases()['contentCanary'])],
            'diagnostics' => [new JsonObject(['severity' => 'error', 'code' => strtolower(Fixture::cases()['secretCanary'])])],
        ]));
        $f->state = $f->runtime(coverage: $coverage);
        $snapshot = $f->diagnostics()->snapshot(Fixture::scope());
        $export = Jcs::canonicalize($snapshot);
        self::assertStringNotContainsString(Fixture::cases()['contentCanary'], $export);
        self::assertStringNotContainsString(Fixture::cases()['secretCanary'], $export);
        self::assertStringNotContainsString(strtolower(Fixture::cases()['contentCanary']), $export);
        self::assertStringNotContainsString(strtolower(Fixture::cases()['secretCanary']), $export);
        self::assertSame('partial', $snapshot->get('coverage')->get('state'));
        self::assertSame(1, $snapshot->get('coverage')->get('counts')->get('failed'));
        self::assertFalse($snapshot->get('coverage')->has('missingFeatures'));
        self::assertSame(2, $snapshot->get('coverage')->get('missingFeatureCount'));
        self::assertSame(1, $snapshot->get('coverage')->get('diagnosticCounts')->get('error'));
    }

    public function testCrossScopeAndStaleProfileCannotExportAnotherSnapshot(): void
    {
        $f = new Fixture();
        foreach ([Fixture::scope(tenant: 9), Fixture::scope(kb: 8)] as $scope) {
            try {
                $f->diagnostics()->snapshot($scope);
                self::fail('Foreign scope exported');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Denied, $failure->outcome);
            }
        }
        $f->state = new O\RuntimeState(
            scope: Fixture::scope(), profile: new D\Fingerprint(str_repeat('d', 64)),
            metadataSchemaReady: true, tombstonesReplayed: true, adapters: [],
        );
        try {
            $f->diagnostics()->snapshot(Fixture::scope());
            self::fail('Stale profile accepted');
        } catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Incompatible, $failure->outcome);
        }
    }

    public function testHttpDiagnosticsAuthenticateAndRecheckAuthorityBeforeExport(): void
    {
        $f = new Fixture();
        $f->state = $f->runtime(ocr: D\Outcome::Unavailable);
        $next = $this->createMock(H\HostApplication::class);
        $next->expects(self::never())->method('execute');
        $server = $f->server($next);
        foreach (['readiness', 'capabilities', 'coverage'] as $route) {
            $response = $server->handle(new H\Request(
                method: 'GET', target: '/v1/' . $route, headers: ['Request-ID' => Fixture::id(91)],
            ));
            self::assertSame(200, $response->status, $response->body);
            self::assertSame(Fixture::id(91), $response->headers['Request-ID']);
            self::assertSame('no-store', $response->headers['Cache-Control']);
            $envelope = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($envelope['degraded']);
            self::assertSame($f->profile->digest()->value, $envelope['effectiveProfile']);
            self::assertSame(['optional_dependency_unavailable'], $envelope['warnings']);
            self::assertStringContainsString('diagnostics:1', $response->body);
            self::assertStringNotContainsString(Fixture::cases()['secretCanary'], $response->body);
            self::assertSame(Fixture::id(91), end($f->observations)->trace->correlationId->value);
        }
        $f->authenticated = false;
        self::assertSame(401, $server->handle(new H\Request(method: 'GET', target: '/v1/readiness'))->status);
        self::assertSame(3, $f->stateCalls);
        $f->authenticated = true;
        $f->readState = function () use ($f): O\RuntimeState {
            $f->identity = null;

            return $f->state;
        };
        $response = $server->handle(new H\Request(method: 'GET', target: '/v1/readiness'));
        self::assertSame(403, $response->status);
        self::assertStringNotContainsString('effectiveProfile', $response->body);
        self::assertSame(0, $f->factoryCalls);
    }

    public function testHttpQuotaFailureIsObservedAndCannotBeRewrittenAsEmpty(): void
    {
        $f = new Fixture();
        $next = $this->createMock(H\HostApplication::class);
        $next->expects(self::once())->method('execute')->willReturn(new H\Reply(D\Result::failure(new D\DomainFailure(
            outcome: D\Outcome::Denied, errorCode: 'quota_exceeded',
        ))));
        $response = $f->server($next)->handle(new H\Request(
            method: 'POST', target: '/v1/search', headers: ['Content-Type' => 'application/json', 'Request-ID' => Fixture::id(92)],
            body: json_encode(['query' => Fixture::cases()['contentCanary']], JSON_THROW_ON_ERROR),
        ));
        self::assertGreaterThanOrEqual(400, $response->status);
        self::assertSame(O\Event::Quota, $f->observations[0]->event);
        self::assertSame(O\Reason::QuotaExceeded, $f->observations[0]->reason);
        self::assertSame(D\Outcome::Denied, $f->observations[0]->outcome);
        self::assertSame(Fixture::id(92), $f->observations[0]->trace->correlationId->value);
        self::assertStringNotContainsString(Fixture::cases()['contentCanary'], Jcs::canonicalize($f->observations[0]->toWire()));
    }

    public function testUnavailableMetadataIsNotRewrittenIntoEmptyCoverage(): void
    {
        $f = new Fixture();
        $f->readState = static function (): never {
            throw new D\DomainFailure(outcome: D\Outcome::Unavailable, errorCode: 'source_corrupt');
        };
        $next = $this->createMock(H\HostApplication::class);
        $next->expects(self::never())->method('execute');
        $response = $f->server($next)->handle(new H\Request(method: 'GET', target: '/v1/coverage'));
        self::assertSame(503, $response->status);
        self::assertStringContainsString('unavailable', $response->body);
        self::assertStringNotContainsString('"state":"complete"', $response->body);
        self::assertSame(0, $f->factoryCalls);
    }

    public function testDisabledOptionalAndOldVerificationCannotAdvertiseCapabilities(): void
    {
        $f = new Fixture();
        foreach ([false, true] as $enabled) {
            $f->state = new O\RuntimeState(
                scope: Fixture::scope(), profile: $f->profile->digest(), metadataSchemaReady: true,
                tombstonesReplayed: true, indexOutcome: D\Outcome::Success,
                adapters: [
                    $f->runtime()->adapters[0],
                    new O\AdapterObservation(
                        adapterId: new D\AdapterId('fixture.ocr'), required: false, enabled: $enabled,
                        outcome: D\Outcome::Success, verifiedProfile: new D\Fingerprint(str_repeat('e', 64)),
                    ),
                ],
            );
            $snapshot = $f->diagnostics()->snapshot(Fixture::scope());
            self::assertTrue($snapshot->get('health')->get('ready'));
            self::assertSame($enabled, $snapshot->get('health')->get('degraded'));
            self::assertSame(['lexical.prefilter'], $snapshot->get('capabilities'));
            self::assertFalse($snapshot->get('dependencies')[1]->get('verified'));
            self::assertSame('unknown', $snapshot->get('dependencies')[1]->get('outcome'));
        }
    }

    public function testInvalidMeasurementsAndAmbiguousDependencyStateAreRejected(): void
    {
        $f = new Fixture();
        $invalid = [
            static fn () => new O\Measurements(durationMs: -1),
            static fn () => new O\Measurements(quotaLimit: -1),
            static fn () => new O\Measurements(count: PHP_INT_MAX),
            static fn () => O\TraceContext::forTenant(
                correlationId: new D\Ulid(Fixture::id(90)), tenant: Fixture::scope()->tenantId, redactionKey: 'short',
            ),
            static fn () => $f->telemetry(runRef: str_repeat('a', 257)),
            static fn () => new O\AdapterObservation(
                adapterId: new D\AdapterId('fixture.ocr'), required: false, enabled: true, outcome: D\Outcome::Empty,
            ),
            static fn () => new O\RuntimeState(
                scope: Fixture::scope(), profile: $f->profile->digest(), metadataSchemaReady: true,
                tombstonesReplayed: true, adapters: [$f->state->adapters[0], $f->state->adapters[0]],
            ),
            static fn () => $f->runtime(index: D\Outcome::Empty),
        ];
        foreach ($invalid as $construct) {
            try {
                $construct();
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
                continue;
            }
            self::fail('Invalid observation admitted');
        }
    }
}
