<?php
declare(strict_types=1);
namespace KnowKit\Application\Publication;

use KnowKit\Domain\ManifestCandidate as Candidate;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\{GenerationReadBackend, ReadableStream};

/** Full inventory verification, independent of successful writes. No query embedding dependency. */
final readonly class IndexReadabilityProbe
{
    /** @param \Closure(D\TenantId,JsonObject): ReadableStream $openResource */
    public function __construct(private GenerationReadBackend $backend, private \Closure $openResource) {}
    /** @return list<JsonObject> Verified immutable resource receipts */
    public function verify(Candidate $candidate): array
    {
        $tenant = new D\TenantId($candidate->manifest->toWire()->get('tenantId')); $verified = []; $total = 0;
        foreach ($candidate->resources as $resource) {
            $stream = ($this->openResource)($tenant, $resource); $hash = hash_init('sha256'); $length = 0;
            try {
                while (!$stream->eof()) {
                    $bytes = $this->readChunk($stream);
                    $length += strlen($bytes); $total += strlen($bytes);
                    if ($total > 268435456 || strlen((string)$length) > strlen($resource->get('byteLength'))
                        || (strlen((string)$length) === strlen($resource->get('byteLength')) && strcmp((string)$length, $resource->get('byteLength')) > 0)) {
                        self::fail('resource_digest_mismatch');
                    }
                    hash_update($hash, $bytes);
                }
            } finally { $stream->close(); }
            if ((string)$length !== $resource->get('byteLength') || hash_final($hash) !== $resource->get('digest')) { self::fail('resource_digest_mismatch'); }
            $verified[] = $resource;
        }
        $this->verifyBackend($candidate);
        return $verified;
    }
    /** Also called under seal locks to close the probe/write race on the local backend. */
    public function verifyBackend(Candidate $candidate): void
    {
        $actual = $this->backend->reload($candidate->manifest); $seen = []; $wire = $candidate->manifest->toWire();
        if ($actual->profile->value !== $wire->get('profile')->get('id') || $actual->embeddingSpaceDigest?->value !== $wire->get('embeddingSpaceDigest')) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'space_or_profile_mismatch');
        }
        foreach ($actual->chunks as $ref) {
            $candidate->assertCandidate($ref); $id = $ref->chunkId->value;
            if (isset($seen[$id])) { self::fail('index_membership_mismatch'); } $seen[$id] = true;
        }
        if (count($seen) !== $candidate->manifest->toWire()->get('counts')->get('chunks')) { self::fail('index_membership_mismatch'); }
    }
    private function readChunk(ReadableStream $stream): string
    {
        $bytes=$stream->read(65536);
        if (strlen($bytes)>65536 || ($bytes==='' && !$stream->eof())) { self::fail('resource_stream_invalid'); }
        return $bytes;
    }
    private static function fail(string $code): never { throw new D\DomainFailure(D\Outcome::Unavailable, $code); }
}
