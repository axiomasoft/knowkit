<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Application;

use KnowKit\Domain\{
    Decision,
    DomainFailure,
    NodeRefs,
    Operation,
    Outcome,
    ProfileId,
    ResourceRef,
};
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Port\{AccessPolicy, DependencyStore};
use KnowKit\TrainingExport\Domain\TrainingTraceRecord;
use KnowKit\TrainingExport\Port\{PiiRedactor, TrainingRightsPolicy};

final readonly class ExportAdmissionGate
{
    public function __construct(
        private AccessPolicy $accessPolicy,
        private TrainingRightsPolicy $rightsPolicy,
        private DependencyStore $dependencyStore,
        private PiiRedactor $piiRedactor,
    ) {
    }

    public function redactorId(): ProfileId
    {
        return $this->piiRedactor->id();
    }

    public function admit(TrainingTraceRecord $trace): TrainingTraceRecord
    {
        $this->checkAccessAndRights($trace);
        $this->checkNoVerbatimLeak($trace);
        $this->checkAncestorsAndTombstones($trace);

        return $this->redactTrace($trace);
    }

    private function checkAccessAndRights(TrainingTraceRecord $trace): void
    {
        if ($trace->sourceRefs === []) {
            throw new DomainFailure(Outcome::Denied, 'training_rights_denied');
        }

        foreach ($trace->sourceRefs as $sourceRef) {
            if (!$trace->scope->tenantId->equals($sourceRef->tenantId)) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }

            $resource = new ResourceRef($trace->scope->tenantId, 'source_version', $sourceRef->sourceVersionId);
            $receipt = $this->accessPolicy->decide($trace->scope, $resource, Operation::TrainingExport);
            $receipt->requireAuthorized($trace->scope, Operation::TrainingExport, $resource);

            $rightsDecision = $this->rightsPolicy->decide($trace->scope, $sourceRef);
            if (!$rightsDecision->isAllowed()) {
                throw new DomainFailure(Outcome::Denied, $rightsDecision->reason ?? 'training_rights_denied');
            }
        }
    }

    private function checkNoVerbatimLeak(TrainingTraceRecord $trace): void
    {
        $requiresDerived = $trace->disclosureMode !== DisclosureMode::VerbatimAllowed;
        if (!$requiresDerived && $trace->layerStack !== null) {
            foreach ($trace->layerStack->nodes as $node) {
                if ($node->disclosureMode !== DisclosureMode::VerbatimAllowed) {
                    $requiresDerived = true;
                    break;
                }
            }
        }

        if (!$requiresDerived) {
            return;
        }

        $fragments = $trace->evidenceSet->toWire()->get('fragments');
        if (!is_array($fragments)) {
            return;
        }

        foreach ($fragments as $fragment) {
            $provenance = $fragment->get('provenanceKind');
            if ($provenance === null || $provenance === 'verbatim') {
                throw new DomainFailure(Outcome::Denied, 'verbatim_disclosure_forbidden');
            }
        }
    }

    private function checkAncestorsAndTombstones(TrainingTraceRecord $trace): void
    {
        $tombstonedSources = [];
        foreach ($trace->tombstones as $tombstone) {
            if ($tombstone->tenantId->equals($trace->scope->tenantId)) {
                $tombstonedSources[$tombstone->sourceId->value] = true;
            }
        }

        $resourceRefs = [];
        $seenVersionIds = [];
        foreach ($trace->sourceRefs as $sourceRef) {
            if (isset($tombstonedSources[$sourceRef->sourceId->value])) {
                throw new DomainFailure(Outcome::Denied, 'ancestor_revoked');
            }
            if (!isset($seenVersionIds[$sourceRef->sourceVersionId->value])) {
                $seenVersionIds[$sourceRef->sourceVersionId->value] = true;
                $resourceRefs[] = new ResourceRef($trace->scope->tenantId, 'source_version', $sourceRef->sourceVersionId);
            }
        }

        $report = $this->dependencyStore->checkAncestors(new NodeRefs($trace->scope->tenantId, $resourceRefs));
        if ($report->decision !== Decision::Allow || $report->blockedAncestors->refs !== []) {
            throw new DomainFailure(Outcome::Denied, 'ancestor_revoked');
        }
    }

    private function redactTrace(TrainingTraceRecord $trace): TrainingTraceRecord
    {
        $redactedMessages = $this->redactMessageList($trace->messages);
        $redactedTeacher = $this->redactMessageList($trace->teacherMessages);
        $redactedStudent = $this->redactMessageList($trace->studentMessages);
        $redactedTarget = $trace->targetResponse !== null ? $this->redactText($trace->targetResponse) : null;
        $redactedInvariants = [];
        foreach ($trace->lockedInvariants as $key => $value) {
            if ($this->redactText((string) $key) !== (string) $key) {
                throw new DomainFailure(Outcome::Denied, 'pii_redaction_failed');
            }
            $redactedInvariants[$key] = is_string($value) ? $this->redactText($value) : $value;
        }

        return $trace->withRedactedText(
            $redactedMessages,
            $redactedTeacher,
            $redactedStudent,
            $redactedTarget,
            $redactedInvariants,
        );
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    private function redactMessageList(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = [
                'role' => $message['role'],
                'content' => $this->redactText($message['content']),
            ];
        }

        return $out;
    }

    private function redactText(string $text): string
    {
        $redacted = $this->piiRedactor->redact($text);
        if ($this->piiRedactor->containsResidualPii($redacted)) {
            throw new DomainFailure(Outcome::Denied, 'pii_redaction_failed');
        }

        return $redacted;
    }
}
