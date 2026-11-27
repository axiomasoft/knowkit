<?php
declare(strict_types=1);

namespace KnowKit\Tests\Storage\Blobs;

use KnowKit\Adapter\LocalBlob\{BlobCompressionCodec, LocalBlobStore, PrivateFilesystem, VerifiedStream};
use KnowKit\Application\Resources\{ReadAuthority, Upload};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Registry\AdapterDescriptor;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('storage:blobs')]
final class CompressionTest extends TestCase
{
    public function testGzipCompressionPreservesUncompressedIdentityAndReadsTransparently(): void
    {
        BlobFixture::run(function (BlobFixture $f): void {
            $payload = str_repeat("KnowKit canonical blob payload 0123456789\n", 10);
            $gzipStore = $this->makeCodecStore($f, BlobCompressionCodec::Gzip);
            $put = new Upload($gzipStore, $f->ledger->reserveWriter(...), $f->ledger->requireAccepted(...), $f->ledger->markUncertain(...));
            $r = $f->reservation(400, 401);
            $ref = $put->put($r, new Bytes($payload));

            self::assertSame(hash('sha256', $payload), $ref->digest->value);
            self::assertSame((string) strlen($payload), $ref->byteLength->value);

            $onDisk = file_get_contents($f->path($ref));
            self::assertIsString($onDisk);
            self::assertTrue(str_starts_with($onDisk, BlobCompressionCodec::MAGIC));
            self::assertSame(BlobCompressionCodec::Gzip->value, ord($onDisk[8]));
            self::assertLessThan(strlen($payload), strlen($onDisk));

            $stream = $gzipStore->open($ref);
            $decoded = '';
            try {
                while (!$stream->eof()) {
                    $decoded .= $stream->read(65536);
                }
            } finally {
                $stream->close();
            }
            self::assertSame($payload, $decoded);

            // A store configured with None can still transparently read an existing compressed blob.
            self::assertSame($payload, BlobFixture::read($f->store->open($ref)));
        });
    }

    public function testZipBombExceedingExpectedByteLengthByOneByteAbortsClosed(): void
    {
        $root = sys_get_temp_dir() . '/knowkit-zipbomb-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            $fs = new PrivateFilesystem($root);
            $expectedLength = 100;
            $canonical = str_repeat('A', $expectedLength);
            $ref = new D\BlobRef(
                new D\TenantId(BlobFixture::id(1)),
                new D\SourceId(BlobFixture::id(2)),
                new D\Sha256(hash('sha256', $canonical)),
                new D\Uint64((string) $expectedLength),
                'local-v1',
                BlobFixture::id(500),
                'owned',
            );

            // Case 1: Exceeds expectedByteLength by exactly 1 byte (101 vs 100).
            $oneByteOver = str_repeat('A', $expectedLength + 1);
            $gzOneOver = gzencode($oneByteOver);
            self::assertIsString($gzOneOver);
            $rel1 = 'bomb1.bin';
            $h1 = $fs->open($rel1, true);
            fwrite($h1, BlobCompressionCodec::Gzip->encodeHeader($expectedLength) . $gzOneOver);
            fclose($h1);

            $open1 = $fs->open($rel1);
            try {
                new VerifiedStream($open1, $fs, $ref, 1048576, static function (): void {});
                self::fail('Expected zip-bomb abort on +1 byte');
            } catch (D\DomainFailure $e) {
                self::assertSame(D\Outcome::Incompatible, $e->outcome);
                self::assertSame('digest_mismatch', $e->errorCode);
            } finally {
                if (is_resource($open1)) {
                    fclose($open1);
                }
            }

            // Case 2: Large multi-chunk zip-bomb (256 KiB compressed zeros) claiming 100 bytes.
            $largeBomb = gzencode(str_repeat("\0", 262144));
            self::assertIsString($largeBomb);
            $rel2 = 'bomb2.bin';
            $h2 = $fs->open($rel2, true);
            fwrite($h2, BlobCompressionCodec::Gzip->encodeHeader($expectedLength) . $largeBomb);
            fclose($h2);

            $open2 = $fs->open($rel2);
            try {
                new VerifiedStream($open2, $fs, $ref, 1048576, static function (): void {});
                self::fail('Expected zip-bomb abort on multi-chunk bomb');
            } catch (D\DomainFailure $e) {
                self::assertSame(D\Outcome::Incompatible, $e->outcome);
                self::assertSame('digest_mismatch', $e->errorCode);
            } finally {
                if (is_resource($open2)) {
                    fclose($open2);
                }
            }
        } finally {
            BlobFixture::removeTree($root);
        }
    }

    public function testZstdWithoutExtensionFailsExplicitlyWithoutFallback(): void
    {
        if (extension_loaded('zstd')) {
            self::markTestSkipped('ext-zstd is loaded in this environment');
        }
        $descriptor = new AdapterDescriptor(
            new D\AdapterId('local.blob.zstd'),
            D\AdapterKind::BlobStore,
            '1.0.0',
            new JsonObject(['$id' => 'https://knowkit.test/blob-zstd.schema.json', 'type' => 'object', 'unevaluatedProperties' => false]),
            [],
            ['zstd'],
        );
        $readiness = $descriptor->extensionReadiness();
        self::assertSame(D\Readiness::Failed, $readiness['readiness']);
        self::assertSame('missing_extension:zstd', $readiness['reason']);

        try {
            $descriptor->assertExtensionsLoaded();
            self::fail('Expected missing_extension_zstd');
        } catch (D\DomainFailure $e) {
            self::assertSame(D\Outcome::Unavailable, $e->outcome);
            self::assertSame('missing_extension_zstd', $e->errorCode);
        }

        BlobFixture::run(function (BlobFixture $f): void {
            try {
                $this->makeCodecStore($f, BlobCompressionCodec::Zstd);
                self::fail('Expected missing_extension_zstd without fallback');
            } catch (D\DomainFailure $e) {
                self::assertSame(D\Outcome::Unavailable, $e->outcome);
                self::assertSame('missing_extension_zstd', $e->errorCode);
            }
        });
    }

    private function makeCodecStore(BlobFixture $f, BlobCompressionCodec $codec): LocalBlobStore
    {
        $l = $f->ledger;
        $callbacks = [];
        foreach (['inspectWriter', 'finalizeIdentity', 'closeWriter', 'markUncertain', 'requireAccepted', 'listUnreferenced', 'quarantine', 'recoveryRef', 'reconcileWriter'] as $name) {
            $callbacks[$name] = $l->$name(...);
        }
        $authority = new ReadAuthority($f->catalog->scope(), $f->catalog->owner, $f->catalog->clock, $l->readBinding(...));

        return new LocalBlobStore(
            $f->catalog->scope()->tenantId,
            $f->root,
            'local-v1',
            1048576,
            $callbacks,
            $authority->requireAllow(...),
            $l->deletionBinding(...),
            $f->catalog->ids,
            $f->catalog->clock,
            PortFixture::validator(),
            null,
            $codec,
        );
    }
}
