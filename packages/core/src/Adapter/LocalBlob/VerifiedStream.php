<?php
declare(strict_types=1);

namespace KnowKit\Adapter\LocalBlob;

use KnowKit\Domain\{BlobRef, DomainFailure, Outcome};
use KnowKit\Port\ReadableStream;

/** Private buffers only. A disclosure workflow still owns Usage commit and exact-version checks. */
final class VerifiedStream implements ReadableStream
{
    private bool $closed = false;
    private ?BlobCompressionCodec $codec = null;
    /** @var resource|null */
    private mixed $filter = null;
    private mixed $zstdContext = null;
    private string $zstdBuffer = '';
    private int $uncompressedRead = 0;
    private int $expectedLength = 0;
    private bool $streamEof = false;

    /** @param resource $handle */
    public function __construct(
        private mixed $handle,
        private readonly PrivateFilesystem $fs,
        private readonly BlobRef $ref,
        private readonly int $maxBytes,
        private readonly \Closure $authorize,
    ) {
        $this->codec = self::verifyHandle($this->handle, $this->fs, $this->ref, $this->maxBytes);
        if ($this->codec !== BlobCompressionCodec::None) {
            $this->expectedLength = self::expectedByteLength($this->ref, $this->maxBytes);
            $this->attachDecodeStream($this->codec);
        }
    }

    /** @param resource $handle */
    public static function verifyHandle(mixed $handle, PrivateFilesystem $fs, BlobRef $ref, int $maxBytes): BlobCompressionCodec
    {
        $stat = fstat($handle);
        if ($stat === false) {
            throw new DomainFailure(Outcome::Unavailable, 'blob_io_failed');
        }
        $offset = ftell($handle);
        if ($offset === false || fseek($handle, 0) !== 0) {
            throw new DomainFailure(Outcome::Unavailable, 'blob_io_failed');
        }
        $peek = $stat['size'] >= strlen(BlobCompressionCodec::MAGIC)
            ? fread($handle, BlobCompressionCodec::HEADER_BYTES)
            : '';
        if ($peek === false) {
            throw new DomainFailure(Outcome::Unavailable, 'blob_io_failed');
        }
        if (fseek($handle, $offset) !== 0) {
            throw new DomainFailure(Outcome::Unavailable, 'blob_io_failed');
        }

        $header = BlobCompressionCodec::decodeHeader($peek);
        if ($header === null) {
            $fs->verify($handle, $ref, $maxBytes);
            return BlobCompressionCodec::None;
        }

        $codec = $header['codec'];
        $codec->assertAvailable();

        $expectedLength = self::expectedByteLength($ref, $maxBytes);
        if ($header['byteLength'] !== $expectedLength) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }

        if (fseek($handle, BlobCompressionCodec::HEADER_BYTES) !== 0) {
            throw new DomainFailure(Outcome::Unavailable, 'blob_io_failed');
        }

        self::verifyCompressedStream($handle, $codec, $expectedLength, $ref->digest->value);

        if (fseek($handle, $offset) !== 0) {
            throw new DomainFailure(Outcome::Unavailable, 'blob_io_failed');
        }

        return $codec;
    }

    private static function expectedByteLength(BlobRef $ref, int $maxBytes): int
    {
        $raw = $ref->byteLength->value;
        if (strlen($raw) > 19 || (int) $raw > $maxBytes) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
        $expected = (int) $raw;
        if ((string) $expected !== $raw) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }

        return $expected;
    }

    /** @param resource $handle */
    private static function verifyCompressedStream(mixed $handle, BlobCompressionCodec $codec, int $expectedLength, string $expectedDigest): void
    {
        if ($codec === BlobCompressionCodec::Gzip) {
            $filter = @stream_filter_append($handle, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]);
            if ($filter === false) {
                throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
            }
            try {
                $hash = hash_init('sha256');
                $total = 0;
                while (!feof($handle)) {
                    $chunk = @fread($handle, BlobCompressionCodec::CHUNK_BYTES);
                    if ($chunk === false) {
                        throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                    }
                    if ($chunk === '') {
                        if (!feof($handle)) {
                            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                        }
                        break;
                    }
                    $total += strlen($chunk);
                    if ($total > $expectedLength) {
                        throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                    }
                    hash_update($hash, $chunk);
                }
                if ($total !== $expectedLength || !hash_equals($expectedDigest, hash_final($hash))) {
                    throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                }
            } finally {
                @stream_filter_remove($filter);
            }
            return;
        }

        if ($codec === BlobCompressionCodec::Zstd) {
            $codec->assertAvailable();
            if (!function_exists('zstd_uncompress_init') || !function_exists('zstd_uncompress_add')) {
                throw new DomainFailure(Outcome::Unavailable, 'missing_extension_zstd');
            }
            $ctx = @zstd_uncompress_init();
            if ($ctx === false || $ctx === null) {
                throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
            }
            $hash = hash_init('sha256');
            $total = 0;
            while (!feof($handle)) {
                $chunk = @fread($handle, BlobCompressionCodec::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                }
                if ($chunk === '') {
                    if (!feof($handle)) {
                        throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                    }
                    break;
                }
                $decoded = @zstd_uncompress_add($ctx, $chunk);
                if (!is_string($decoded)) {
                    throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                }
                if ($decoded !== '') {
                    $total += strlen($decoded);
                    if ($total > $expectedLength) {
                        throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                    }
                    hash_update($hash, $decoded);
                }
            }
            if ($total !== $expectedLength || !hash_equals($expectedDigest, hash_final($hash))) {
                throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
            }
        }
    }

    private function attachDecodeStream(BlobCompressionCodec $codec): void
    {
        if ($this->filter !== null) {
            @stream_filter_remove($this->filter);
            $this->filter = null;
        }
        $this->zstdContext = null;
        $this->zstdBuffer = '';
        if (fseek($this->handle, BlobCompressionCodec::HEADER_BYTES) !== 0) {
            throw new DomainFailure(Outcome::Unavailable, 'blob_io_failed');
        }
        if ($codec === BlobCompressionCodec::Gzip) {
            $filter = @stream_filter_append($this->handle, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]);
            if ($filter === false) {
                throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
            }
            $this->filter = $filter;
            return;
        }
        if ($codec === BlobCompressionCodec::Zstd) {
            $codec->assertAvailable();
            if (!function_exists('zstd_uncompress_init') || !function_exists('zstd_uncompress_add')) {
                throw new DomainFailure(Outcome::Unavailable, 'missing_extension_zstd');
            }
            $ctx = @zstd_uncompress_init();
            if ($ctx === false || $ctx === null) {
                throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
            }
            $this->zstdContext = $ctx;
        }
    }

    public function read(int $maxBytes): string
    {
        if ($this->closed || $maxBytes < 1) {
            throw new \InvalidArgumentException('Open bounded stream required');
        }
        $limit = max(1, min(BlobCompressionCodec::CHUNK_BYTES, $maxBytes));
        if ($this->codec === BlobCompressionCodec::None) {
            // Verify before exposing any buffer, including after the object changed since open.
            $this->fs->verify($this->handle, $this->ref, $this->maxBytes);
            ($this->authorize)($this->ref);
            $chunk = fread($this->handle, $limit);
            if ($chunk === false) {
                throw new DomainFailure(Outcome::Unavailable, 'blob_io_failed');
            }
            return $chunk;
        }

        ($this->authorize)($this->ref);
        if ($this->codec === BlobCompressionCodec::Zstd) {
            if (!function_exists('zstd_uncompress_add')) {
                $this->close();
                throw new DomainFailure(Outcome::Unavailable, 'missing_extension_zstd');
            }
            while (strlen($this->zstdBuffer) < $limit && !feof($this->handle)) {
                $compressed = @fread($this->handle, BlobCompressionCodec::CHUNK_BYTES);
                if ($compressed === false) {
                    $this->close();
                    throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                }
                if ($compressed === '') {
                    if (!feof($this->handle)) {
                        $this->close();
                        throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                    }
                    break;
                }
                $decoded = @zstd_uncompress_add($this->zstdContext, $compressed);
                if (!is_string($decoded)) {
                    $this->close();
                    throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                }
                $this->zstdBuffer .= $decoded;
                if ($this->uncompressedRead + strlen($this->zstdBuffer) > $this->expectedLength) {
                    $this->close();
                    throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
                }
            }
            $chunk = substr($this->zstdBuffer, 0, $limit);
            $this->zstdBuffer = substr($this->zstdBuffer, strlen($chunk));
        } else {
            $chunk = @fread($this->handle, $limit);
            if ($chunk === false) {
                $this->close();
                throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
            }
        }

        $this->uncompressedRead += strlen($chunk);
        if ($this->uncompressedRead > $this->expectedLength) {
            $this->close();
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
        if ($chunk === '') {
            if ($this->uncompressedRead < $this->expectedLength) {
                $this->close();
                throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
            }
            $this->streamEof = true;
        } elseif ($this->uncompressedRead >= $this->expectedLength) {
            $this->streamEof = true;
        }

        return $chunk;
    }

    public function eof(): bool
    {
        if ($this->closed || $this->streamEof) {
            return true;
        }
        if ($this->codec !== BlobCompressionCodec::None && $this->uncompressedRead >= $this->expectedLength) {
            return true;
        }
        if ($this->codec === BlobCompressionCodec::Zstd && $this->zstdBuffer !== '') {
            return false;
        }

        return feof($this->handle);
    }

    public function close(): void
    {
        if (!$this->closed) {
            if ($this->filter !== null) {
                @stream_filter_remove($this->filter);
                $this->filter = null;
            }
            $this->zstdContext = null;
            $this->zstdBuffer = '';
            fclose($this->handle);
            $this->closed = true;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
