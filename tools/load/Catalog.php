<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Application\Catalog\SourceCommands;
use KnowKit\Application\Ingest\Text\ParserSettings;
use KnowKit\Application\Ingest\Text\TextParser;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use KnowKit\Tests\Storage\Blobs\Bytes;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use KnowKit\Tooling\Qa;

require_once Report::root() . '/tests/storage/blobs/BlobFixture.php';

/** Sequential bounded input, real immutable blob writes, catalog acceptance and text extraction. */
final class Catalog
{
    public static function text(): string
    {
        return str_repeat(str_pad('KnowKit RU Привет EN capacity.', 2047, ' ') . "\n", 2048);
    }

    public static function run(): array
    {
        $latency = [];
        $cpu = Metrics::cpuMs();
        $bytes = 0;
        $digests = [];
        CatalogFixture::run(function (CatalogFixture $c) use (&$latency, &$bytes, &$digests): void {
            $c->tenant();
            $c->kb();
            self::batch(c: $c, latency: $latency, digests: $digests);
            $bytes = count($digests) * 4194304;
        });
        $peak = max(memory_get_peak_usage(true), getrusage()['ru_maxrss'] * 1024);
        Qa::ensure($bytes === 67108864 && count(array_unique($digests)) === 1, 'batch fingerprint mismatch');
        Qa::ensure($peak <= 268435456, 'TXT batch exceeds approved 256 MiB peak');

        return Report::save('catalog', ['documents' => 16, 'documentBytes' => 4194304, 'totalBytes' => $bytes,
            'concurrent' => 1, 'latencyMs' => Metrics::percentiles($latency), 'firstDocumentMs' => $latency[0],
            'cpuMs' => Metrics::cpuMs() - $cpu, 'peakBytes' => $peak, 'textSha256' => $digests[0],
            'providerCalls' => 0, 'tokens' => 0, 'scope' => 'Register, durable original blob, accept version, extract IR; no publication/index throughput claim.']);
    }

    private static function batch(CatalogFixture $c, array &$latency, array &$digests): void
    {
        $blobs = new BlobFixture($c);
        try {
            $commands = new SourceCommands(
                registerMetadata: $c->repo->registerSourceMetadata(...),
                acceptMetadata: $c->repo->acceptSourceVersion(...),
                transaction: $c->tx,
                authority: $c->authority,
            );
            $store = $blobs->makeStore(limit: 4194304);
            foreach (range(0, 15) as $number) {
                $digests[] = Metrics::timed(
                    operation: fn (): string => self::ingest($c, $commands, $blobs, $store, $number),
                    samples: $latency,
                );
            }
            Qa::ensure($c->count('source_versions') === 16, 'catalog batch incomplete');
            Qa::ensure($c->count('resource_ledger') === 16, 'unaccepted original resource');
        } finally {
            BlobFixture::removeTree($blobs->root);
        }
    }

    private static function ingest(
        CatalogFixture $c,
        SourceCommands $commands,
        BlobFixture $blobs,
        \KnowKit\Adapter\LocalBlob\LocalBlobStore $store,
        int $number,
    ): string {
        $sourceId = new D\SourceId(CatalogFixture::id(1000 + $number));
        $commands->register(scope: $c->scope(), command: new D\RegisterSource(
            sourceId: $sourceId, ownerRef: 'owner:capacity-v1', origin: 'host:synthetic',
        ), context: $c->context(key: 'register-' . $number));
        $raw = self::text();
        $digest = hash('sha256', $raw);
        $blob = self::putOriginal(c: $c, blobs: $blobs, store: $store, number: $number, sourceId: $sourceId, raw: $raw);
        $ref = new D\SourceVersionRef($c->scope()->tenantId, $sourceId,
            new D\SourceVersionId(CatalogFixture::id(5000 + $number)), new D\SourceDigest($digest));
        $commands->acceptVersion(scope: $c->scope(), version: new D\SourceVersion(
            ref: $ref, blob: $blob, mime: 'text/plain', rightsRef: 'project-authored:capacity-v1', createdAt: $c->clock->now(),
        ), context: $c->context(key: 'accept-' . $number));
        $result = (new TextParser(PortFixture::validator()))->extract(
            source: new D\SourceBytes(ref: $ref, bytes: $raw, mediaType: 'text/plain'),
            profile: ParserSettings::profile(format: 'text', maxSourceBytes: 4194304),
        );
        Qa::ensure($result->outcome === D\Outcome::Success && $result->normalization?->text === $raw, 'max TXT extraction failed');
        Qa::ensure(hash('sha256', $result->document->toWire()->get('segments')[0]->get('text')) === $digest, 'text identity changed');

        return $digest;
    }

    private static function putOriginal(
        CatalogFixture $c,
        BlobFixture $blobs,
        \KnowKit\Adapter\LocalBlob\LocalBlobStore $store,
        int $number,
        D\SourceId $sourceId,
        string $raw,
    ): D\BlobRef {
        $reservation = new D\BlobWriteReservation(
            resource: new D\ResourceRef($c->scope()->tenantId, 'resource', new D\ResourceId(CatalogFixture::id(2000 + $number))),
            sourceId: $sourceId,
            writerId: new D\WriterId(CatalogFixture::id(3000 + $number)),
            fence: new D\Decimal('0'),
            sourceEpoch: new D\Decimal('0'),
            digest: new D\Sha256(hash('sha256', $raw)),
            byteLength: new D\Uint64((string) strlen($raw)),
            stagingId: new D\Ulid(CatalogFixture::id(4000 + $number)),
        );

        return $store->putImmutable(r: $blobs->ledger->reserveWriter($reservation), stream: new Bytes($raw));
    }
}
