<?php

declare(strict_types=1);

namespace KnowKit\Tests\Training;

require_once __DIR__ . '/TrainingTestSupport.php';

use KnowKit\Domain\{
    Decision,
    DomainFailure,
    EventId,
    NodeRefs,
    Outcome,
    ResourceRef,
    Tombstone,
    Decimal,
};
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\TrainingExport\Adapter\StrictRegexPiiRedactor;
use KnowKit\TrainingExport\Application\ExportAdmissionGate;
use KnowKit\TrainingExport\Domain\TrainingRightsDecision;
use KnowKit\TrainingExport\Port\DenyAllTrainingRightsPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('training:export')]
final class RightsGateTest extends TestCase
{
    public function testDenyAllTrainingRightsPolicyIsFailClosedByDefault(): void
    {
        $trace = TrainingTestSupport::makeTrace();
        $policy = new DenyAllTrainingRightsPolicy();

        $decision = $policy->decide($trace->scope, $trace->sourceRefs[0]);
        self::assertFalse($decision->isAllowed());
        self::assertSame('training_rights_denied', $decision->reason);

        $gate = new ExportAdmissionGate(
            TrainingTestSupport::allowAccessPolicy($trace->scope),
            $policy,
            TrainingTestSupport::allowDependencyStore(),
            new StrictRegexPiiRedactor(),
        );

        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('training_rights_denied');
        $gate->admit($trace);
    }

    public function testAccessPolicyAndPerSourceRightsCheckedBeforeExport(): void
    {
        $source1 = TrainingTestSupport::makeSourceRef('01JSR000000000000000000001', '01JSV000000000000000000001');
        $source2 = TrainingTestSupport::makeSourceRef('01JSR000000000000000000002', '01JSV000000000000000000002');
        $trace = TrainingTestSupport::makeTrace(sourceRefs: [$source1, $source2]);

        $rightsPolicy = TrainingTestSupport::callbackRightsPolicy(
            static fn ($scope, $source): TrainingRightsDecision => $source->sourceId->equals($source1->sourceId)
                ? TrainingRightsDecision::allowed()
                : TrainingRightsDecision::denied('training_rights_denied'),
        );

        $gate = new ExportAdmissionGate(
            TrainingTestSupport::allowAccessPolicy($trace->scope),
            $rightsPolicy,
            TrainingTestSupport::allowDependencyStore(),
            new StrictRegexPiiRedactor(),
        );

        try {
            $gate->admit($trace);
            self::fail('Expected DomainFailure when second source is denied');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Denied, $failure->outcome);
            self::assertSame('training_rights_denied', $failure->errorCode);
        }

        $deniedAccessGate = new ExportAdmissionGate(
            TrainingTestSupport::denyAccessPolicy($trace->scope),
            TrainingTestSupport::allowRightsPolicy(),
            TrainingTestSupport::allowDependencyStore(),
            new StrictRegexPiiRedactor(),
        );

        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('policy_denied');
        $deniedAccessGate->admit($trace);
    }

    public function testDerivedOnlyRejectsVerbatimEvidenceLeak(): void
    {
        $verbatimTrace = TrainingTestSupport::makeTrace(
            disclosureMode: DisclosureMode::DerivedOnly,
            provenanceKind: 'verbatim',
        );

        $gate = new ExportAdmissionGate(
            TrainingTestSupport::allowAccessPolicy($verbatimTrace->scope),
            TrainingTestSupport::allowRightsPolicy(),
            TrainingTestSupport::allowDependencyStore(),
            new StrictRegexPiiRedactor(),
        );

        try {
            $gate->admit($verbatimTrace);
            self::fail('Expected verbatim_disclosure_forbidden');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Denied, $failure->outcome);
            self::assertSame('verbatim_disclosure_forbidden', $failure->errorCode);
        }

        $derivedTrace = TrainingTestSupport::makeTrace(
            disclosureMode: DisclosureMode::DerivedOnly,
            provenanceKind: 'summary',
        );
        $admitted = $gate->admit($derivedTrace);
        self::assertSame($derivedTrace->traceId->value, $admitted->traceId->value);
    }

    public function testDependencyStoreRevocationAndTombstoneExcludeTrace(): void
    {
        $trace = TrainingTestSupport::makeTrace();
        $blockedRef = new ResourceRef($trace->scope->tenantId, 'source_version', $trace->sourceRefs[0]->sourceVersionId);

        $revokedGate = new ExportAdmissionGate(
            TrainingTestSupport::allowAccessPolicy($trace->scope),
            TrainingTestSupport::allowRightsPolicy(),
            TrainingTestSupport::denyDependencyStore(Decision::Deny, new NodeRefs($trace->scope->tenantId, [$blockedRef])),
            new StrictRegexPiiRedactor(),
        );

        try {
            $revokedGate->admit($trace);
            self::fail('Expected ancestor_revoked from DependencyStore');
        } catch (DomainFailure $failure) {
            self::assertSame('ancestor_revoked', $failure->errorCode);
        }

        $tombstone = new Tombstone(
            $trace->scope->tenantId,
            $trace->sourceRefs[0]->sourceId,
            'consent_revoked',
            new EventId('01JEV000000000000000000001'),
            new Decimal('42'),
        );
        $tombstonedTrace = TrainingTestSupport::makeTrace(tombstones: [$tombstone]);

        $allowGate = new ExportAdmissionGate(
            TrainingTestSupport::allowAccessPolicy($tombstonedTrace->scope),
            TrainingTestSupport::allowRightsPolicy(),
            TrainingTestSupport::allowDependencyStore(),
            new StrictRegexPiiRedactor(),
        );

        $this->expectException(DomainFailure::class);
        $this->expectExceptionMessage('ancestor_revoked');
        $allowGate->admit($tombstonedTrace);
    }
}
