<?php

declare(strict_types=1);

namespace KnowKit\Adapter\LocalBlob\Preview;

use KnowKit\Domain as D;
use KnowKit\Domain\Reading\BlobObject;
use KnowKit\Port\BlobStore;
use KnowKit\Port\PreviewObjects;

/** Works with the real LocalBlobStore or a qualified external BlobStore; never returns a URL. */
final readonly class BoundedObjects implements PreviewObjects
{
    public function __construct(
        private BlobStore $blobs,
    ) {}

    public function read(BlobObject $object, int $maxBytes): string
    {
        $ref = $object->blob;
        $length = $ref->byteLength->value;
        if (strlen($length) > 8 || (int) $length > min($maxBytes, 8388608)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'bounded_read_required');
        }
        $stream = $this->blobs->open($ref);
        $bytes = '';
        try {
            while (true) {
                $bound = min(65536, (int) $length - strlen($bytes) + 1);
                $part = $stream->read($bound);
                $ended = $stream->eof();
                $bytes .= $part;
                if (strlen($part) > $bound || strlen($bytes) > (int) $length || ($part === '' && !$ended)) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
                }
                if ($ended) {
                    break;
                }
            }
        } finally {
            $stream->close();
        }
        if (strlen($bytes) !== (int) $length || hash('sha256', $bytes) !== $ref->digest->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
        }

        return $bytes;
    }
}
