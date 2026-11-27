<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Domain;

use KnowKit\ContextCompilers\Domain\CitationAliasMap;
use KnowKit\Domain\{
    EvidenceSet,
    Scope,
    Sha256,
    SnapshotRef,
    SourceVersionRef,
    Tombstone,
    Ulid,
    ValueGuard,
};
use KnowKit\Domain\Layers\{DisclosureMode, ResolvedLayerStack};

final readonly class TrainingTraceRecord
{
    /**
     * @param list<SnapshotRef> $snapshotRefs
     * @param list<SourceVersionRef> $sourceRefs
     * @param list<array{role: string, content: string}> $messages
     * @param list<string> $citations
     * @param list<array{role: string, content: string}> $teacherMessages
     * @param list<array{role: string, content: string}> $studentMessages
     * @param array<string, bool|int|string> $lockedInvariants
     * @param list<Tombstone> $tombstones
     */
    public function __construct(
        public Ulid $traceId,
        public Scope $scope,
        public Sha256 $layerStackDigest,
        public array $snapshotRefs,
        public array $sourceRefs,
        public EvidenceSet $evidenceSet,
        public CitationAliasMap $citationAliasMap,
        public array $messages,
        public bool $hostOutcomeVerified,
        public ?ResolvedLayerStack $layerStack = null,
        public array $citations = [],
        public array $teacherMessages = [],
        public array $studentMessages = [],
        public ?string $targetResponse = null,
        public array $lockedInvariants = [],
        public ?Sha256 $promptEnvelopeDigest = null,
        public DisclosureMode $disclosureMode = DisclosureMode::VerbatimAllowed,
        public array $tombstones = [],
    ) {
        ValueGuard::objects($snapshotRefs, SnapshotRef::class, 256);
        ValueGuard::objects($sourceRefs, SourceVersionRef::class, 256);
        ValueGuard::objects($tombstones, Tombstone::class, 256);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param list<array{role: string, content: string}> $teacherMessages
     * @param list<array{role: string, content: string}> $studentMessages
     */
    public function withRedactedText(
        array $messages,
        array $teacherMessages,
        array $studentMessages,
        ?string $targetResponse,
    ): self {
        return new self(
            traceId: $this->traceId,
            scope: $this->scope,
            layerStackDigest: $this->layerStackDigest,
            snapshotRefs: $this->snapshotRefs,
            sourceRefs: $this->sourceRefs,
            evidenceSet: $this->evidenceSet,
            citationAliasMap: $this->citationAliasMap,
            messages: $messages,
            hostOutcomeVerified: $this->hostOutcomeVerified,
            layerStack: $this->layerStack,
            citations: $this->citations,
            teacherMessages: $teacherMessages,
            studentMessages: $studentMessages,
            targetResponse: $targetResponse,
            lockedInvariants: $this->lockedInvariants,
            promptEnvelopeDigest: $this->promptEnvelopeDigest,
            disclosureMode: $this->disclosureMode,
            tombstones: $this->tombstones,
        );
    }
}
