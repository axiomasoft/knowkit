<?php
declare(strict_types=1);

namespace KnowKit\Application\Jobs;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractViolation, StrictJson};
use KnowKit\Port\ReadableStream;

/** Adapted Pipeline verification boundary: all storage I/O precedes the acceptance transaction. */
final readonly class VerifiedCheckpoints
{
    /**
     * @param \Closure(D\Sha256): string $manifestBytes
     * @param \Closure(D\TenantId,D\ResourceId): ReadableStream $resourceBytes
     */
    public function __construct(
        private \Closure $manifestBytes,
        private \Closure $resourceBytes,
    ) {
    }

    public function load(D\Lease $lease, D\Checkpoint $checkpoint): D\JobCheckpointManifest
    {
        $bytes = ($this->manifestBytes)($checkpoint->digest);
        try {
            $manifest = new D\JobCheckpointManifest(StrictJson::decode($bytes, 65536));
        } catch (ContractViolation|\InvalidArgumentException|\TypeError) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_checkpoint');
        }
        $wire = $manifest->toWire();
        if (!$manifest->digest()->equals($checkpoint->digest) || $wire->get('uncertainExternalEffect') !== $checkpoint->uncertainExternalEffect) {
            self::fail();
        }
        $total = 0;
        foreach ($wire->get('resources') as $resource) {
            $stream = ($this->resourceBytes)($lease->scope->tenantId, new D\ResourceId($resource->get('resourceId')));
            $hash = hash_init('sha256');
            $length = 0;
            try {
                while (!$stream->eof()) {
                    $chunk = $stream->read(65536);
                    if ($chunk === '') {
                        if (!$stream->eof()) {
                            self::fail();
                        }
                        break;
                    }
                    if (strlen($chunk) > 65536) {
                        self::fail();
                    }
                    $length += strlen($chunk);
                    $total += strlen($chunk);
                    if ($total > 268435456) {
                        self::fail();
                    }
                    hash_update($hash, $chunk);
                }
            } finally {
                $stream->close();
            }
            if ((string) $length !== $resource->get('byteLength') || hash_final($hash) !== $resource->get('digest')) {
                self::fail();
            }
        }
        return $manifest;
    }

    private static function fail(): never
    {
        throw new D\DomainFailure(D\Outcome::Incompatible, 'checkpoint_digest_mismatch');
    }
}
