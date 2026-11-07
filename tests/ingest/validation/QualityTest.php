<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Validation;

use KnowKit\Application\Validation\{DefaultQualityPolicy, SourceReadiness, TechnicalValidator};
use KnowKit\Domain as D;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('ingest:validation')]
final class QualityTest extends TestCase
{
    public function testStrictCompletenessBlocksNineOfTenAndPreservesMissingScan(): void
    {
        $ir = ValidationFixture::ir('IR-MISSING-SCAN'); $input = ValidationFixture::input($ir);
        self::assertSame('valid', (new TechnicalValidator($input))->validate($ir, $input->source->ref)->status);
        $readiness = new SourceReadiness($input->revision, $ir, new D\CoveragePolicy(), new DefaultQualityPolicy());
        self::assertSame(D\Decision::Deny, $readiness->quality->decision);
        self::assertSame([['kind' => 'page', 'id' => 'p10', 'status' => 'failed', 'physicalNo' => 10]], $readiness->missingUnits);
        self::assertContains('scan_missing', $readiness->quality->diagnosticCodes);
        try { $readiness->requireQualityAllowed(); self::fail('Incomplete source became ready'); }
        catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Denied, $e->outcome); }
        self::assertFalse($readiness->eligibleForCompleteQuery());
    }

    public function testExplicitPartialAllowsReadinessButExcludesCompletenessRequiredQueries(): void
    {
        foreach (['IR-MISSING-SCAN', 'IR-UNKNOWN'] as $id) {
            $ir = ValidationFixture::ir($id); $input = ValidationFixture::input($ir);
            $readiness = new SourceReadiness($input->revision, $ir, new D\CoveragePolicy(true), new DefaultQualityPolicy());
            $readiness->requireQualityAllowed();
            self::assertSame(D\Decision::Allow, $readiness->quality->decision);
            self::assertEquals($ir->toWire()->get('coverage'), $readiness->quality->coverage->toWire());
            self::assertSame(10, $readiness->missingUnits[0]['physicalNo']);
            self::assertFalse($readiness->eligibleForCompleteQuery());
        }
    }

    public function testBatchStatusNeverReplacesIndependentSourceCoverage(): void
    {
        $assessments = [];
        foreach (['good' => 'IR-BLANK', 'failed' => 'IR-MISSING-SCAN'] as $source => $id) {
            $ir = ValidationFixture::ir($id); $input = ValidationFixture::input($ir, $source === 'good' ? 4 : 5);
            $assessments[$source] = new SourceReadiness($input->revision, $ir, new D\CoveragePolicy(), new DefaultQualityPolicy());
        }
        $assessments['good']->requireQualityAllowed();
        self::assertSame(D\Decision::Allow, $assessments['good']->quality->decision);
        self::assertSame(D\Decision::Deny, $assessments['failed']->quality->decision);
        self::assertSame('complete', $assessments['good']->quality->coverage->toWire()->get('state'));
        self::assertSame('partial', $assessments['failed']->quality->coverage->toWire()->get('state'));
        self::assertSame('empty', ValidationFixture::ir('IR-BLANK')->toWire()->get('pages')[0]->get('status'));
        self::assertSame([], $assessments['good']->missingUnits);
        self::assertNotSame($assessments['good']->revision->documentRevisionId, $assessments['failed']->revision->documentRevisionId);
    }

    public function testUnknownLowConfidenceReviewAndRequiredFeaturesKeepSeparateDecisions(): void
    {
        $policy = new DefaultQualityPolicy(); $strict = new D\CoveragePolicy();
        $ir = ValidationFixture::ir('IR-UNKNOWN'); $input = ValidationFixture::input($ir);
        $unknown = new SourceReadiness($input->revision, $ir, $strict, $policy);
        self::assertSame(D\Decision::Unknown, $unknown->quality->decision);
        try { $unknown->requireQualityAllowed(); self::fail('Unknown source became ready'); }
        catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Unknown, $e->outcome); }
        $ocr = new D\Coverage(ValidationFixture::ir('IR-OCR')->toWire()->get('coverage'));
        self::assertSame(D\Decision::Deny, $policy->evaluate($ocr, $strict)->decision);
        $partial = $policy->evaluate($ocr, new D\CoveragePolicy(true));
        self::assertSame(D\Decision::Allow, $partial->decision); self::assertContains('ocr_low_confidence', $partial->diagnosticCodes);
        $claim = new D\Coverage($ocr->toWire()->with('state', 'complete'));
        self::assertSame(D\Decision::Deny, $policy->evaluate($claim, $strict)->decision);
        $reviewWire = $ocr->toWire()->with('diagnostics', [new \KnowKit\Domain\Contract\JsonObject(['code' => 'manual_review_needed', 'severity' => 'warning'])]);
        self::assertSame(D\Decision::Deny, $policy->evaluate(new D\Coverage($reviewWire), new D\CoveragePolicy(true))->decision);
        $structured = new D\Coverage(ValidationFixture::ir('IR-STRUCTURED')->toWire()->get('coverage'));
        self::assertSame(D\Decision::Deny, $policy->evaluate($structured, new D\CoveragePolicy(true, ['column_layout']))->decision);
        self::assertContains('required_feature_missing', $policy->evaluate($structured, new D\CoveragePolicy(true, ['column_layout']))->diagnosticCodes);
        $blank = ValidationFixture::ir('IR-BLANK'); $blankInput = ValidationFixture::input($blank);
        $denied = new class implements \KnowKit\Port\QualityPolicy {
            public function evaluate(D\Coverage $coverage, D\CoveragePolicy $policy): D\QualityDecision
            { return new D\QualityDecision(D\Decision::Deny, $coverage, ['review_policy_denied']); }
        };
        self::assertFalse((new SourceReadiness($blankInput->revision, $blank, $strict, $denied))->eligibleForCompleteQuery());
    }
}
