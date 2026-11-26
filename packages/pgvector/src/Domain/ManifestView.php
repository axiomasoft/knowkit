<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Domain;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;

/** Typed view of the approved core manifest; no new public manifest schema. */
final readonly class ManifestView
{
    public const PROFILE = 'pgvector-l2-v1';

    public D\ManifestCandidate $candidate;
    public D\TenantId $tenant;
    public D\KbId $kb;
    public D\GenerationId $generation;
    public D\Fingerprint $fingerprint;
    /** @var array<string, D\Chunk> */
    private array $chunks;
    /** @var array<string, string> */
    private array $setDigests;

    /** @param list<D\ChunkingResult> $sets */
    public function __construct(D\IndexManifest $manifest, array $sets, D\EmbeddingSpace $space)
    {
        VectorPayload::assertSpace($space);
        $wire = $manifest->toWire();
        if ($wire->get('profile')->get('id') !== self::PROFILE || $wire->get('profile')->get('kind') !== 'vector'
            || $wire->get('embeddingSpaceDigest') !== $space->digest()->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'space_or_profile_mismatch');
        }
        $this->tenant = new D\TenantId($wire->get('tenantId'));
        $this->kb = new D\KbId($wire->get('kbId'));
        $this->generation = new D\GenerationId($wire->get('generationId'));
        $this->fingerprint = $space->digest();
        $chunks = [];
        $digests = [];
        foreach ($sets as $result) {
            $digests[$result->chunkSet->chunkSetId->value] = $result->manifestDigest->value;
            foreach ($result->chunkSet->chunks as $chunk) {
                $chunks[$chunk->ref->chunkId->value] = $chunk;
            }
        }
        $this->candidate = new D\ManifestCandidate($manifest, $sets, $wire->get('resources'));
        if ($wire->get('counts')->get('chunks') !== count($chunks)
            || $wire->get('counts')->get('members') !== count($sets)) {
            self::invalid();
        }
        $this->chunks = $chunks;
        $this->setDigests = $digests;
    }

    public function count(): int
    {
        return count($this->chunks);
    }

    /** @return list<D\ChunkRef> */
    public function refs(): array
    {
        return array_values(array_map(static fn (D\Chunk $chunk): D\ChunkRef => $chunk->ref, $this->chunks));
    }

    public function chunk(string $id): D\Chunk
    {
        return $this->chunks[$id] ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'candidate_not_in_manifest');
    }

    public function setDigest(D\ChunkSet $set): D\Fingerprint
    {
        $digest = $this->setDigests[$set->chunkSetId->value] ?? null;
        if ($digest === null) {
            self::invalid();
        }
        foreach ($this->candidate->sets as $result) {
            if (self::sameChunkSet($result->chunkSet, $set)) {
                return new D\Fingerprint($digest);
            }
        }
        self::invalid();
    }

    public function assertGeneration(array $row): void
    {
        if ($row['tenant_id'] !== $this->tenant->value || $row['kb_id'] !== $this->kb->value
            || $row['id'] !== $this->generation->value || $row['profile_id'] !== self::PROFILE
            || $row['embedding_space_digest'] !== $this->fingerprint->value
            || $row['manifest_digest'] !== $this->candidate->digest->value
            || Jcs::canonicalize(StrictJson::decode($row['manifest_json'])) !== $this->candidate->manifest->canonical()) {
            self::invalid();
        }
    }

    public function assertMembership(array $row): void
    {
        $chunk = $this->chunk($row['chunk_id']);
        $ref = $chunk->ref;
        $source = $ref->revisionRef->sourceRef;
        $expected = [$source->sourceVersionId->value, $ref->revisionRef->documentRevisionId->value,
            $ref->chunkSetId->value, $ref->chunkDigest->value, $chunk->documentId->value];
        $actual = [$row['source_version_id'], $row['revision_id'], $row['chunk_set_id'],
            $row['chunk_digest'], $row['document_id']];
        $spans = self::wireSpans($chunk);
        if ($actual !== $expected || (int) $row['byte_length'] !== strlen($chunk->text())
            || $row['content_digest'] !== $ref->chunkDigest->value
            || Jcs::canonicalize(StrictJson::decode($row['spans_json'])) !== Jcs::canonicalize($spans)) {
            self::invalid();
        }
    }

    private static function sameChunkSet(D\ChunkSet $expected, D\ChunkSet $actual): bool
    {
        if (!$expected->chunkSetId->equals($actual->chunkSetId)
            || Jcs::canonicalize($expected->revisionRef->toWire()) !== Jcs::canonicalize($actual->revisionRef->toWire())
            || Jcs::canonicalize($expected->chunkerSignature->toWire()) !== Jcs::canonicalize($actual->chunkerSignature->toWire())
            || count($expected->chunks) !== count($actual->chunks)) {
            return false;
        }
        foreach ($expected->chunks as $index => $chunk) {
            $candidate = $actual->chunks[$index] ?? null;
            if ($candidate === null
                || Jcs::canonicalize($chunk->ref->toWire()) !== Jcs::canonicalize($candidate->ref->toWire())
                || !$chunk->documentId->equals($candidate->documentId)
                || $chunk->text() !== $candidate->text()
                || Jcs::canonicalize(self::wireSpans($chunk)) !== Jcs::canonicalize(self::wireSpans($candidate))) {
                return false;
            }
        }

        return true;
    }

    /** @return list<JsonObject> */
    private static function wireSpans(D\Chunk $chunk): array
    {
        return array_map(static fn (D\Span $span): JsonObject => $span->locator->toWire()
            ->with('textDigest', $span->textDigest->value), $chunk->spans);
    }

    private static function invalid(): never
    {
        throw new D\DomainFailure(D\Outcome::Incompatible, 'vector_manifest_mismatch');
    }
}
