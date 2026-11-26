<?php
declare(strict_types=1);

namespace KnowKit\Adapter\LocalBlob;

use KnowKit\Domain\{AttemptId, BlobRef, BlobWriteReservation, Cursor, DeletionReceipt, DeletionTaskId, DomainFailure, Outcome, ResourceId, ResourcePage, ResourceRef, Sha256, SourceId, TenantId, Uint64, Ulid, UtcInstant};
use KnowKit\Domain\Contract\{ContractValidator, Jcs, JsonObject};
use KnowKit\Port\{BlobStore, Clock, IdGenerator, ReadableStream};

/** v0.1 production store. Scoped host callbacks keep the core DAG vendor/framework free. */
final readonly class LocalBlobStore implements BlobStore
{
    private PrivateFilesystem $fs;

    /** @param array<string,\Closure> $ledger Bound methods of a tenant/storage-specific durable ledger. */
    public function __construct(
        private TenantId $tenant,
        string $root,
        private string $storageId,
        private int $maxBytes,
        private array $ledger,
        private \Closure $authorizeRead,
        private \Closure $deletionBinding,
        private IdGenerator $ids,
        private Clock $clock,
        private ContractValidator $validator,
        ?\Closure $sync = null,
    ) {
        if ($maxBytes < 1 || $maxBytes >= PHP_INT_MAX || $storageId === '' || strlen($storageId) > 128) {
            throw new \InvalidArgumentException('Explicit bounded storage configuration required');
        }
        $requiredCallbacks = [
            'inspectWriter',
            'finalizeIdentity',
            'closeWriter',
            'markUncertain',
            'requireAccepted',
            'listUnreferenced',
            'quarantine',
            'recoveryRef',
            'reconcileWriter',
        ];
        foreach ($requiredCallbacks as $name) {
            if (!($ledger[$name] ?? null) instanceof \Closure) {
                throw new \InvalidArgumentException('Durable ledger callbacks required');
            }
        }
        $this->fs = new PrivateFilesystem($root, $sync);
    }

    private function scoped(TenantId $tenant): void
    {
        if (!$this->tenant->equals($tenant)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }

    private function key(BlobRef $ref): string
    {
        $this->scoped($ref->tenantId);
        if ($ref->storageId !== $this->storageId || $ref->ownerKind !== 'owned') {
            throw new DomainFailure(Outcome::Incompatible, 'blob_store_mismatch');
        }
        $version = new ResourceId($ref->objectVersion);
        return $this->tenant->value . '/' . $ref->sourceId->value . '/sha256/' . $ref->digest->value . '/' . $version->value;
    }

    private function staging(BlobWriteReservation $r): string
    {
        return $this->tenant->value . '/' . $r->sourceId->value . '/staging/' . ($r->stagingId->value ?? $r->resource->id->value);
    }

    private function readChunk(ReadableStream $stream, int $bound): string
    {
        $chunk = $stream->read($bound);
        if (strlen($chunk) > $bound || ($chunk === '' && !$stream->eof())) {
            throw new DomainFailure(Outcome::Incompatible, 'stream_contract');
        }
        return $chunk;
    }

    private function authorize(BlobRef $ref): void
    {
        $this->scoped($ref->tenantId);
        ($this->ledger['requireAccepted'])($ref);
        ($this->authorizeRead)($ref);
        // Repeat the local authoritative barrier after the current owner's possible remote I/O.
        ($this->ledger['requireAccepted'])($ref);
    }

    public function putImmutable(BlobWriteReservation $r, ReadableStream $stream): BlobRef
    {
        $this->scoped($r->resource->tenantId);
        $handle = null;
        $lock = null;
        $active = false;
        try {
            $state = ($this->ledger['inspectWriter'])($r);
            $active = $state['writer']['state'] === 'active';
            $lock = $this->fs->lock($this->tenant->value . '/' . $r->sourceId->value . '/.locks/' . $r->resource->id->value);
            $state = ($this->ledger['inspectWriter'])($r);
            $active = $state['writer']['state'] === 'active';
            if ($active) {
                $handle = $this->fs->open($this->staging($r), true);
            }
            $hash = hash_init('sha256');
            $length = 0;
            while (!$stream->eof()) {
                $bound = min(65536, $this->maxBytes - $length + 1);
                $chunk = $this->readChunk($stream, $bound);
                $length += strlen($chunk);
                if ($length > $this->maxBytes) {
                    throw new DomainFailure(Outcome::Incompatible, 'blob_size_limit');
                }
                hash_update($hash, $chunk);
                if ($handle !== null) {
                    $offset = 0;
                    while ($offset < strlen($chunk)) {
                        $written = @fwrite($handle, substr($chunk, $offset));
                        if ($written === false || $written === 0) {
                            throw new DomainFailure(Outcome::Unavailable, 'blob_io_failed');
                        }
                        $offset += $written;
                    }
                }
            }
            $digest = new Sha256(hash_final($hash));
            $size = new Uint64((string) $length);
            if (
                ($r->digest !== null && ($r->digest->value !== $digest->value || $r->byteLength?->value !== $size->value))
                || ($state['resource']['digest'] !== null && ($state['resource']['digest'] !== $digest->value || (string) $state['resource']['byte_length'] !== $size->value))
            ) {
                throw new DomainFailure(Outcome::Incompatible, 'blob_digest_mismatch');
            }
            $ref = new BlobRef($this->tenant, $r->sourceId, $digest, $size, $this->storageId, $r->resource->id->value, 'owned');
            $key = $this->key($ref);
            if ($handle !== null) {
                $this->fs->syncFile($handle);
                fclose($handle);
                $handle = null;
                ($this->ledger['finalizeIdentity'])($r, $digest, $size);
                if ($this->fs->present($key)) {
                    $existing = $this->fs->open($key);
                    try {
                        $this->fs->verify($existing, $ref, $this->maxBytes);
                    } finally {
                        fclose($existing);
                    }
                    $this->fs->remove($this->staging($r));
                } else {
                    $this->fs->promote($this->staging($r), $key);
                }
            }
            $verify = $this->fs->open($key);
            try {
                $this->fs->verify($verify, $ref, $this->maxBytes);
            } finally {
                fclose($verify);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            $lock = null;
            $accepted = ($this->ledger['closeWriter'])($r);
            // Concurrent reservations finish outside the file lock; no writer is presumed dead.
            $deadline = hrtime(true) + 5_000_000_000;
            while ($accepted === null && hrtime(true) < $deadline) {
                usleep(10000);
                $accepted = ($this->ledger['closeWriter'])($r);
            }
            if (!$accepted instanceof BlobRef || !self::sameBlobRef($accepted, $ref)) {
                throw new DomainFailure(Outcome::Unknown, 'acceptance_pending');
            }
            return $accepted;
        } catch (\Throwable $e) {
            if ($handle !== null) {
                fclose($handle);
                $handle = null;
            }
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
                $lock = null;
            }
            if ($active) {
                ($this->ledger['markUncertain'])($r);
            }
            throw $e;
        } finally {
            if ($handle !== null) {
                fclose($handle);
            }
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $stream->close();
        }
    }

    public function open(BlobRef $ref): ReadableStream
    {
        $key = $this->key($ref);
        $this->authorize($ref);
        $h = $this->fs->open($key);
        try {
            return new VerifiedStream($h, $this->fs, $ref, $this->maxBytes, $this->authorize(...));
        } catch (\Throwable $e) {
            fclose($h);
            throw $e;
        }
    }

    public function exists(BlobRef $ref): bool
    {
        try {
            $stream = $this->open($ref);
            $stream->close();
            return true;
        } catch (DomainFailure $e) {
            if ($e->errorCode === 'blob_missing') {
                return false;
            }
            throw $e;
        }
    }

    public function listUnreferenced(UtcInstant $before, Cursor $cursor): ResourcePage
    {
        $this->scoped($cursor->tenantId);
        foreach ($this->fs->inventory($this->tenant->value) as $key) {
            $parts = explode('/', $key);
            $source = new SourceId($parts[1]);
            $staging = null;
            if (($parts[2] ?? '') === 'staging' && count($parts) === 4 && preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $parts[3]) === 1) {
                $staging = new Ulid($parts[3]);
            }
            ($this->ledger['quarantine'])($source, $key, new ResourceId($this->ids->next()->value), $staging);
        }
        return ($this->ledger['listUnreferenced'])($before, $cursor);
    }

    public function reconcileWriter(BlobWriteReservation $r, \Closure $proveRunnerStopped): ?BlobRef
    {
        $this->scoped($r->resource->tenantId);
        return ($this->ledger['reconcileWriter'])($r, $proveRunnerStopped, function () use ($r): bool {
            $ref = ($this->ledger['recoveryRef'])($r);
            $lock = $this->fs->lock($this->tenant->value . '/' . $r->sourceId->value . '/.locks/' . $r->resource->id->value);
            try {
                $this->fs->recoverPromotion($this->staging($r), $this->key($ref), $ref, $this->maxBytes);
                return true;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        });
    }

    public function delete(ResourceRef $resource, AttemptId $attemptId): DeletionReceipt
    {
        $this->scoped($resource->tenantId);
        if ($resource->kind !== 'resource') {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        // Host resolves the exact durable attempt/task and deleting state, never a raw object key from a request.
        $binding = ($this->deletionBinding)($resource, $attemptId);
        if (!isset($binding['taskId']) || !$binding['taskId'] instanceof DeletionTaskId) {
            throw new DomainFailure(Outcome::Denied, 'deletion_not_reserved');
        }
        $result = 'unsupported';
        if (($binding['ownerKind'] ?? null) === 'owned' && ($binding['allowed'] ?? false) === true) {
            $key = $binding['storageKey'] ?? '';
            if (!str_starts_with($key, $this->tenant->value . '/')) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }
            try {
                $result = $this->fs->remove($key) ? 'deleted' : 'absent';
            } catch (DomainFailure) {
                $result = 'unknown';
            }
        }
        return new DeletionReceipt(new JsonObject([
            'attemptId' => $attemptId->value,
            'taskId' => $binding['taskId']->value,
            'resourceId' => $resource->id->value,
            'inFlight' => false,
            'result' => $result,
            'providerReceipt' => in_array($result, ['deleted', 'absent'], true) ? 'local-fsync-v1' : null,
            'knownExpiry' => null,
            'observedAt' => $this->clock->now()->value,
        ]), $this->validator);
    }

    private static function sameBlobRef(BlobRef $left, BlobRef $right): bool
    {
        return $left->tenantId->equals($right->tenantId)
            && $left->sourceId->equals($right->sourceId)
            && $left->digest->equals($right->digest)
            && $left->byteLength->value === $right->byteLength->value
            && $left->storageId === $right->storageId
            && $left->objectVersion === $right->objectVersion
            && $left->ownerKind === $right->ownerKind;
    }
}
