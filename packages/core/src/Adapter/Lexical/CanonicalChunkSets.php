<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Lexical;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject};

/** Immutable canonical buffers loaded by the host from canonical storage, before any SQL transaction.
 * This is a content inventory, never an authorization cache or a backend snippet reader.
 */
final readonly class CanonicalChunkSets
{
    private array $sets;
    /** @param list<D\ChunkingResult> $sets */
    public function __construct(array $sets)
    {
        $inventory = [];
        foreach ($sets as $result) {
            $set = $result->chunkSet;
            $manifest = $result->manifest();
            if ($manifest->get('chunkSetId') !== $set->chunkSetId->value
                || Jcs::canonicalize($manifest->get('revisionRef')) !== Jcs::canonicalize($set->revisionRef->toWire())
                || Jcs::canonicalize($manifest->get('chunkerSignature')) !== Jcs::canonicalize($set->chunkerSignature->toWire())
                || !is_array($manifest->get('chunks')) || count($manifest->get('chunks')) !== count($set->chunks)) {
                self::invalid();
            }
            $entries = [];
            foreach ($manifest->get('chunks') as $entry) {
                if (!$entry instanceof JsonObject || !is_string($entry->get('chunkId')) || isset($entries[$entry->get('chunkId')])) { self::invalid(); }
                $entries[$entry->get('chunkId')] = $entry;
            }
            foreach ($set->chunks as $chunk) {
                $entry = $entries[$chunk->ref->chunkId->value] ?? null;
                $spans = array_map(static fn(D\Span $s): JsonObject => $s->locator->toWire()->with('textDigest', $s->textDigest->value), $chunk->spans);
                if ($entry === null || $entry->get('chunkDigest') !== $chunk->ref->chunkDigest->value
                    || $entry->get('byteLength') !== strlen($chunk->text())
                    || Jcs::canonicalize($entry->get('spans')) !== Jcs::canonicalize($spans)
                    || $manifest->get('documentId') !== $chunk->documentId->value) { self::invalid(); }
            }
            $key = $set->revisionRef->sourceRef->tenantId->value . ':' . $set->chunkSetId->value;
            if (isset($inventory[$key])) { self::invalid(); }
            $inventory[$key] = $result;
        }
        $this->sets = $inventory;
    }
    public function get(D\TenantId $tenant, D\ChunkSetId $id): D\ChunkingResult
    {
        return $this->sets[$tenant->value . ':' . $id->value]
            ?? throw new D\DomainFailure(D\Outcome::Unavailable, 'canonical_chunkset_unavailable');
    }
    private static function invalid(): never { throw new D\DomainFailure(D\Outcome::Incompatible, 'manifest_mismatch'); }
    public function __debugInfo(): array { return ['chunkSetCount' => count($this->sets)]; }
}
