<?php
declare(strict_types=1);

namespace KnowKit\Application\Resources;

use KnowKit\Domain\{BlobRef, DomainFailure, ExactSourceRef, Outcome};
use KnowKit\Port\{BlobStore, ReadableStream};

/** Bounded private canonical buffer for projection/citation consumers.
 * The host verifies the exact source/version/artifact relation and current usability.
 * Backend hit text is deliberately absent from this boundary. Public disclosure still owns Usage commit.
 */
final readonly class CanonicalContent
{
    public function __construct(
        private BlobStore $store,
        private \Closure $requireExactUsable,
        private int $maxBytes,
    ) {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('Explicit buffer bound required');
        }
    }

    private function readChunk(ReadableStream $stream, int $bound): string
    {
        $chunk = $stream->read($bound);
        if (strlen($chunk) > $bound || ($chunk === '' && !$stream->eof())) {
            throw new DomainFailure(Outcome::Incompatible, 'stream_contract');
        }
        return $chunk;
    }

    public function read(ExactSourceRef $source, BlobRef $artifact): string
    {
        if (!$source->tenantId->equals($artifact->tenantId) || !$source->sourceId->equals($artifact->sourceId)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        ($this->requireExactUsable)($source, $artifact);
        $stream = $this->store->open($artifact);
        $buffer = '';
        try {
            while (!$stream->eof()) {
                $bound = min(65536, $this->maxBytes - strlen($buffer) + 1);
                $chunk = $this->readChunk($stream, $bound);
                $buffer .= $chunk;
                if (strlen($buffer) > $this->maxBytes) {
                    throw new DomainFailure(Outcome::Incompatible, 'blob_size_limit');
                }
            }
        } finally {
            $stream->close();
        }
        ($this->requireExactUsable)($source, $artifact);
        return $buffer;
    }
}
