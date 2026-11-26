<?php

declare(strict_types=1);

namespace KnowKit\Domain\Reading;

use KnowKit\Domain as D;

/** Private immutable resolver metadata; retaining it never retains a right to read. */
final readonly class CitationRecord
{
    public function __construct(
        public D\CitationRef $citation,
        public D\ChunkRef $chunk,
        public D\SelectionId $selectionId,
        public D\KbId $kbId,
        public D\Sha256 $hostScopeHash,
        public ?string $joiner,
        public bool $payloadPresent,
    ) {
        $citation->requireChunk($chunk);
        if ((count($citation->spans()) > 1) !== ($joiner !== null)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_span');
        }
    }

    public function requireScope(D\Scope $scope): void
    {
        $scope->assertTenant($this->chunk->revisionRef->sourceRef->tenantId);
        if (!$scope->kbId->equals($this->kbId) || !$scope->hostScopeHash->equals($this->hostScopeHash)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
    }

    public function requirePayload(): void
    {
        if (!$this->payloadPresent) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'payload_purged');
        }
    }
}
