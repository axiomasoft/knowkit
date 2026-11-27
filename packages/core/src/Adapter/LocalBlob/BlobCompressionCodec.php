<?php
declare(strict_types=1);

namespace KnowKit\Adapter\LocalBlob;

use KnowKit\Domain\{DomainFailure, Outcome};

enum BlobCompressionCodec: int
{
    case None = 0;
    case Gzip = 1;
    case Zstd = 2;

    public const MAGIC = "KKBLOB1\0";
    public const HEADER_BYTES = 17;
    public const CHUNK_BYTES = 65536;

    public function requiredExtension(): ?string
    {
        return match ($this) {
            self::None => null,
            self::Gzip => 'zlib',
            self::Zstd => 'zstd',
        };
    }

    public function assertAvailable(): void
    {
        $ext = $this->requiredExtension();
        if ($ext !== null && !extension_loaded($ext)) {
            throw new DomainFailure(Outcome::Unavailable, 'missing_extension_' . $ext);
        }
    }

    public function encodeHeader(int $uncompressedLength): string
    {
        if ($uncompressedLength < 0) {
            throw new \InvalidArgumentException('Non-negative uncompressed length required');
        }

        return self::MAGIC . chr($this->value) . pack('J', $uncompressedLength);
    }

    /**
     * @return array{codec: self, byteLength: int}|null Null when header does not start with KKBLOB1\0.
     */
    public static function decodeHeader(string $header): ?array
    {
        if (strlen($header) < strlen(self::MAGIC) || !str_starts_with($header, self::MAGIC)) {
            return null;
        }
        if (strlen($header) < self::HEADER_BYTES) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
        $codec = self::tryFrom(ord($header[8]));
        if ($codec === null || $codec === self::None) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
        $unpacked = unpack('Jlength', substr($header, 9, 8));
        if ($unpacked === false || !isset($unpacked['length']) || !is_int($unpacked['length']) || $unpacked['length'] < 0) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }

        return ['codec' => $codec, 'byteLength' => $unpacked['length']];
    }
}
