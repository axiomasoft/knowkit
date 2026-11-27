<?php

declare(strict_types=1);

namespace KnowKit\Tests\Training;

require_once __DIR__ . '/TrainingTestSupport.php';

use KnowKit\Domain\{DomainFailure, Fingerprint, Sha256, SnapshotId, SnapshotRef, Ulid, UtcInstant};
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\TrainingExport\Adapter\StrictRegexPiiRedactor;
use KnowKit\TrainingExport\Application\{
    ContextDistillationExporter,
    ExportAdmissionGate,
    GroundedSftExporter,
    GrpoTaskExporter,
    TrainingExportJobHandler,
};
use KnowKit\TrainingExport\Domain\TrainingFormat;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('training:export')]
final class ExportersDeterminismTest extends TestCase
{
    public function testContextDistillationExporterProducesCanonicalDistillPairs(): void
    {
        $trace = TrainingTestSupport::makeTrace(
            teacherMessages: [
                ['role' => 'system', 'content' => '[FULL_L0_L1_L2]'],
                ['role' => 'user', 'content' => 'Explain conservation of momentum'],
            ],
            studentMessages: [
                ['role' => 'system', 'content' => '[COMPACT_L0]'],
                ['role' => 'user', 'content' => 'Explain conservation of momentum'],
            ],
            targetResponse: 'Momentum is conserved [#1].',
        );

        $exporter = new ContextDistillationExporter();
        $line = $exporter->exportJsonlLine($trace);
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($trace->traceId->value, $decoded['traceId']);
        self::assertSame($trace->layerStackDigest->value, $decoded['layerStackDigest']);
        self::assertSame('Momentum is conserved [#1].', $decoded['targetResponse']);
        self::assertCount(2, $decoded['teacherMessages']);
        self::assertCount(2, $decoded['studentMessages']);
        self::assertSame([$trace->sourceRefs[0]->sourceDigest->value], $decoded['sourceDigests']);
    }

    public function testGroundedSftExporterRequiresVerifiedOutcomeAndValidCitations(): void
    {
        $exporter = new GroundedSftExporter();

        $unverified = TrainingTestSupport::makeTrace(hostOutcomeVerified: false);
        try {
            $exporter->exportJsonlLine($unverified);
            self::fail('Expected host_outcome_unverified');
        } catch (DomainFailure $failure) {
            self::assertSame('host_outcome_unverified', $failure->errorCode);
        }

        $badCitation = TrainingTestSupport::makeTrace(
            messages: [
                ['role' => 'user', 'content' => 'Question'],
                ['role' => 'assistant', 'content' => 'Answer [#99]'],
            ],
            citations: ['#99'],
            hostOutcomeVerified: true,
        );
        try {
            $exporter->exportJsonlLine($badCitation);
            self::fail('Expected invalid_citation');
        } catch (DomainFailure $failure) {
            self::assertSame('invalid_citation', $failure->errorCode);
        }

        $valid = TrainingTestSupport::makeTrace(hostOutcomeVerified: true);
        $line = $exporter->exportJsonlLine($valid);
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($valid->traceId->value, $decoded['traceId']);
        self::assertNotEmpty($decoded['citations']);
    }

    public function testGrpoTaskExporterAndJobHandlerProduceBitwiseIdenticalManifestAndShards(): void
    {
        $traceA = TrainingTestSupport::makeTrace(
            traceId: new Ulid('01JTR000000000000000000001'),
            promptEnvelopeDigest: new Sha256(str_repeat('7', 64)),
            lockedInvariants: ['answer.must_cite' => true, 'safety.no_harm' => true],
        );
        $traceB = TrainingTestSupport::makeTrace(
            traceId: new Ulid('01JTR000000000000000000002'),
            promptEnvelopeDigest: new Sha256(str_repeat('8', 64)),
            lockedInvariants: ['answer.must_cite' => true, 'safety.no_harm' => true],
        );

        $grpoExporter = new GrpoTaskExporter();
        $grpoLine = $grpoExporter->exportJsonlLine($traceA);
        $grpoDecoded = json_decode($grpoLine, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(str_repeat('7', 64), $grpoDecoded['promptEnvelopeDigest']);
        self::assertCount(2, $grpoDecoded['verifierSpecs']);

        $redactor = new StrictRegexPiiRedactor();
        $gate = new ExportAdmissionGate(
            TrainingTestSupport::allowAccessPolicy($traceA->scope),
            TrainingTestSupport::allowRightsPolicy(),
            TrainingTestSupport::allowDependencyStore(),
            $redactor,
        );
        $handler = new TrainingExportJobHandler($gate, $redactor->id());
        self::assertSame('export_training_traces', TrainingExportJobHandler::jobType()->value);

        $exportId = new Ulid('01JEX000000000000000000001');
        $createdAt = new UtcInstant('2026-11-27T12:00:00Z');
        $validator = TrainingTestSupport::validator();

        $run1 = $handler->handle($exportId, $traceA->scope->tenantId, TrainingFormat::GrpoTasksV1, $createdAt, [$traceA, $traceB], $validator);
        $run2 = $handler->handle($exportId, $traceA->scope->tenantId, TrainingFormat::GrpoTasksV1, $createdAt, [$traceB, $traceA], $validator);

        self::assertSame($run1['shardsContent'], $run2['shardsContent']);
        self::assertSame($run1['manifest']->shards[0]->sha256->value, $run2['manifest']->shards[0]->sha256->value);
        self::assertSame($run1['manifest']->digest()->value, $run2['manifest']->digest()->value);
        self::assertSame(2, $run1['manifest']->includedCount);
    }

    public function testDeniedTraceDoesNotPublishItsSnapshotOnTheManifest(): void
    {
        $admitted = TrainingTestSupport::makeTrace(traceId: new Ulid('01JTR000000000000000000001'));
        $deniedSnapshot = new SnapshotRef(
            $admitted->scope->tenantId,
            $admitted->scope->kbId,
            new SnapshotId('01JSN000000000000000000099'),
            new Fingerprint(str_repeat('9', 64)),
        );
        $denied = TrainingTestSupport::makeTrace(
            traceId: new Ulid('01JTR000000000000000000099'),
            disclosureMode: DisclosureMode::DerivedOnly,
            layerStackDigest: new Sha256(str_repeat('9', 64)),
            snapshotRef: $deniedSnapshot,
        );

        $unverified = TrainingTestSupport::makeTrace(
            traceId: new Ulid('01JTR000000000000000000098'),
            hostOutcomeVerified: false,
            layerStackDigest: new Sha256(str_repeat('8', 64)),
            snapshotRef: $deniedSnapshot,
        );

        $redactor = new StrictRegexPiiRedactor();
        $handler = new TrainingExportJobHandler(
            new ExportAdmissionGate(
                TrainingTestSupport::allowAccessPolicy($admitted->scope),
                TrainingTestSupport::allowRightsPolicy(),
                TrainingTestSupport::allowDependencyStore(),
                $redactor,
            ),
            $redactor->id(),
        );
        $result = $handler->handle(
            new Ulid('01JEX000000000000000000001'),
            $admitted->scope->tenantId,
            TrainingFormat::GroundedSftV1,
            new UtcInstant('2026-11-27T12:00:00Z'),
            [$denied, $admitted, $unverified],
            TrainingTestSupport::validator(),
        );

        self::assertSame(1, $result['manifest']->includedCount);
        self::assertSame(['host_outcome_unverified' => 1, 'verbatim_disclosure_forbidden' => 1], $result['manifest']->excludedCountsByReason);
        self::assertCount(1, $result['manifest']->snapshotRefs);
        self::assertSame('01JSN000000000000000000001', $result['manifest']->snapshotRefs[0]->snapshotId->value);
        self::assertSame([str_repeat('3', 64)], array_map(
            static fn (Sha256 $digest): string => $digest->value,
            $result['manifest']->layerStackDigests,
        ));
        $serialized = \KnowKit\Domain\Contract\Jcs::canonicalize($result['manifest']->toWire());
        self::assertStringNotContainsString('01JSN000000000000000000099', $serialized);
        self::assertStringNotContainsString(str_repeat('9', 64), $serialized);
    }

    public function testGrpoInvariantStringsAreRedactedBeforeSerialization(): void
    {
        $secret = 'alice.ivanova@example.org';
        $trace = TrainingTestSupport::makeTrace(
            promptEnvelopeDigest: new Sha256(str_repeat('7', 64)),
            lockedInvariants: ['contact' => $secret],
        );
        $redactor = new StrictRegexPiiRedactor();
        $handler = new TrainingExportJobHandler(
            new ExportAdmissionGate(
                TrainingTestSupport::allowAccessPolicy($trace->scope),
                TrainingTestSupport::allowRightsPolicy(),
                TrainingTestSupport::allowDependencyStore(),
                $redactor,
            ),
            $redactor->id(),
        );
        $result = $handler->handle(
            new Ulid('01JEX000000000000000000001'),
            $trace->scope->tenantId,
            TrainingFormat::GrpoTasksV1,
            new UtcInstant('2026-11-27T12:00:00Z'),
            [$trace],
            TrainingTestSupport::validator(),
        );

        $bytes = implode('', $result['shardsContent']);
        self::assertStringNotContainsString($secret, $bytes);
        self::assertStringContainsString('[REDACTED_EMAIL]', $bytes);
    }
}
