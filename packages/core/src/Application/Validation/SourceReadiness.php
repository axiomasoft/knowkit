<?php
declare(strict_types=1);

namespace KnowKit\Application\Validation;

use KnowKit\Domain\{Coverage, CoveragePolicy, Decision, DocumentRevisionRef, DomainFailure, IrDocument, Outcome, QualityDecision};
use KnowKit\Port\QualityPolicy;

/** Source-level eligibility only; job/batch success never changes this assessment. */
final readonly class SourceReadiness
{
    public QualityDecision $quality;
    /** @var list<array{kind:string,id:string,status:string,physicalNo?:int}> */
    public array $missingUnits;

    public function __construct(public DocumentRevisionRef $revision, IrDocument $document,
        CoveragePolicy $policy, QualityPolicy $qualityPolicy)
    {
        // Also pins this source's external revision identity to the immutable canonical IR.
        new SpanAssembler($document, $revision);
        $ir = $document->toWire();
        $this->quality = $qualityPolicy->evaluate(new Coverage($ir->get('coverage')), $policy);
        $missing = [];
        foreach (['pages' => 'page', 'segments' => 'segment'] as $collection => $kind) {
            foreach ($ir->get($collection) as $unit) {
                if (in_array($unit->get('status'), ['failed', 'skipped'], true)) {
                    $entry = ['kind' => $kind, 'id' => $unit->get($kind . 'Id'), 'status' => $unit->get('status')];
                    if ($kind === 'page') { $entry['physicalNo'] = $unit->get('physicalNo'); }
                    $missing[] = $entry;
                }
            }
        }
        $this->missingUnits = $missing;
    }

    /** Coverage gate only; ingestion still requires valid technical/resource checks and fresh publish authorization. */
    public function requireQualityAllowed(): void
    {
        if ($this->quality->decision !== Decision::Allow) {
            throw new DomainFailure($this->quality->decision === Decision::Unknown ? Outcome::Unknown : Outcome::Denied, 'quality_not_ready');
        }
    }

    public function eligibleForCompleteQuery(): bool
    {
        return $this->quality->decision === Decision::Allow
            && (new DefaultQualityPolicy())->evaluate($this->quality->coverage, new CoveragePolicy())->decision === Decision::Allow;
    }
}
