<?php
declare(strict_types=1);
namespace KnowKit\Domain;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};

/** Exact, private build inventory. No backend snippets or mutable/latest references. */
final readonly class ManifestCandidate
{
    public D\Fingerprint $digest;
    /** @var array<string, D\ChunkRef> */
    private array $chunkRefs;

    /**
     * @param list<D\ChunkingResult> $sets
     * @param list<JsonObject> $resources
     */
    public function __construct(public D\IndexManifest $manifest, public array $sets, public array $resources)
    {
        $wire = $manifest->toWire(); $members = []; $chunks = [];
        foreach ($sets as $result) {
            $set = $result->chunkSet; $r = $set->revisionRef; $s = $r->sourceRef;
            if ($s->tenantId->value !== $wire->get('tenantId')) { self::fail('scope_mismatch', D\Outcome::Denied); }
            $profile = $wire->get('profile');
            $sidecar = $result->manifest();
            if ($sidecar->get('chunkSetId') !== $set->chunkSetId->value
                || Jcs::canonicalize($sidecar->get('revisionRef')) !== Jcs::canonicalize($r->toWire())
                || Jcs::canonicalize($sidecar->get('chunkerSignature')) !== Jcs::canonicalize($set->chunkerSignature->toWire())
                || Jcs::canonicalize($r->parserSignature->toWire()) !== Jcs::canonicalize($profile->get('parserSignature'))
                || Jcs::canonicalize($set->chunkerSignature->toWire()) !== Jcs::canonicalize($profile->get('chunkerSignature'))
                || HashDomain::ChunkSetManifest->digest($result->manifest()) !== $result->manifestDigest->value) { self::fail(); }
            $entries = $result->manifest()->get('chunks');
            if (!is_array($entries) || count($entries) !== count($set->chunks)) { self::fail(); }
            foreach ($set->chunks as $chunk) {
                $id = $chunk->ref->chunkId->value;
                if (isset($chunks[$id]) || !$chunk->ref->revisionRef->equals($r) || !$chunk->ref->chunkSetId->equals($set->chunkSetId)) {
                    self::fail();
                }
                $entry = $result->metadata($id);
                $spans = array_map(static fn(D\Span $s): JsonObject => $s->locator->toWire()->with('textDigest', $s->textDigest->value), $chunk->spans);
                if ($entry->get('chunkDigest') !== $chunk->ref->chunkDigest->value || $entry->get('byteLength') !== strlen($chunk->text())
                    || $sidecar->get('documentId') !== $chunk->documentId->value
                    || Jcs::canonicalize($entry->get('spans')) !== Jcs::canonicalize($spans)) { self::fail(); }
                $chunks[$id] = $chunk->ref;
            }
            $members[] = new JsonObject(['sourceId'=>$s->sourceId->value, 'sourceVersionId'=>$s->sourceVersionId->value,
                'sourceDigest'=>$s->sourceDigest->value, 'documentRevisionId'=>$r->documentRevisionId->value, 'irDigest'=>$r->irDigest->value,
                'chunkSetId'=>$set->chunkSetId->value, 'chunkSetDigest'=>$result->manifestDigest->value, 'chunkCount'=>count($set->chunks)]);
        }
        usort($members, self::compareMembers(...));
        if (Jcs::canonicalize($members) !== Jcs::canonicalize($wire->get('members'))
            || Jcs::canonicalize($resources) !== Jcs::canonicalize($wire->get('resources'))) { self::fail(); }
        $this->digest = new D\Fingerprint(HashDomain::IndexManifest->digest($wire));
        $this->chunkRefs = $chunks;
    }
    public static function compareMembers(JsonObject $a, JsonObject $b): int
    {
        foreach (['sourceId','sourceVersionId','documentRevisionId','chunkSetId'] as $key) {
            $order = strcmp($a->get($key), $b->get($key)); if ($order !== 0) { return $order; }
        }
        return 0;
    }
    /** Backend identities must match every immutable coordinate; text is never accepted here. */
    public function assertCandidate(D\ChunkRef $ref): void
    {
        $expected = $this->chunkRefs[$ref->chunkId->value] ?? null;
        if ($expected !== null && Jcs::canonicalize($expected->toWire()) === Jcs::canonicalize($ref->toWire())) { return; }
        self::fail('candidate_not_in_manifest');
    }
    private static function fail(string $code = 'manifest_mismatch', D\Outcome $outcome = D\Outcome::Incompatible): never
    { throw new D\DomainFailure($outcome, $code); }
}
