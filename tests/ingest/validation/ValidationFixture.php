<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Validation;

use KnowKit\Application\Validation\ValidationInput;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, JsonObject, StrictJson};
use KnowKit\Tests\Contract\Ports\PortFixture;

final class ValidationFixture
{
    public static function root(): string { return dirname(__DIR__, 3); }
    public static function read(string $path): string
    {
        static $cache = [];
        if (!array_key_exists($path, $cache)) {
            $bytes = file_get_contents(self::root() . '/' . $path);
            if (!is_string($bytes)) { throw new \RuntimeException('Unreadable fixture: ' . $path); }
            $cache[$path] = $bytes;
        }
        return $cache[$path];
    }
    public static function validator(): ContractValidator { return PortFixture::validator(); }
    public static function id(int $n): string { return PortFixture::id($n); }
    public static function manifest(): array
    {
        static $value;
        return $value ??= json_decode(self::read('tests/fixtures/ir/manifest.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    public static function entry(string $id): array
    {
        static $byId = null;
        if ($byId === null) {
            $byId = [];
            foreach (self::manifest()['cases'] as $entry) {
                if (!isset($byId[$entry['id']])) { $byId[$entry['id']] = $entry; }
            }
        }
        if (!isset($byId[$id])) { throw new \LogicException('Unknown fixture case'); }
        return $byId[$id];
    }
    public static function ir(string $id = 'IR-UNICODE'): D\IrDocument
    {
        static $cache = [];
        // Immutable documents only. A failed construct must not be cached or reused.
        if (!isset($cache[$id])) {
            $cache[$id] = new D\IrDocument(StrictJson::decode(self::read(self::entry($id)['path']), 67108864), self::validator());
        }
        return $cache[$id];
    }
    public static function input(D\IrDocument $ir, int $revisionId = 4, ?array $assets = null): ValidationInput
    {
        $wire = $ir->toWire(); $source = self::read(self::entry('IR-UNICODE')['sourcePath']);
        $ref = new D\SourceVersionRef(new D\TenantId($wire->get('tenantId')), new D\SourceId(self::id(2)),
            new D\SourceVersionId($wire->get('sourceVersionId')), new D\SourceDigest(hash('sha256', $source)));
        $signature = PortFixture::signature($wire->get('parserSignature'));
        $revision = new D\DocumentRevisionRef($ref, new D\DocumentRevisionId(self::id($revisionId)), new D\IrDigest(hash('sha256', $ir->canonical())), $signature);
        $processing = new D\ProcessingIdentity($ref->sourceVersionId, $ref->sourceDigest, $signature, $signature, $signature,
            'ir-1.0', new D\Fingerprint(str_repeat('b', 64)), null);
        if ($assets === null) {
            $assets = [];
            foreach ($wire->get('assets') as $asset) { $assets[$asset->get('assetId')] = self::read('tests/fixtures/ir/v1/original.png'); }
        }
        return new ValidationInput(new D\SourceBytes($ref, $source, 'application/pdf'), $revision, $processing, $assets);
    }
    public static function span(D\IrDocument $ir, JsonObject $locator): D\Span
    {
        $isPage = $locator->get('kind') === 'page'; $key = $isPage ? 'pageId' : 'segmentId';
        foreach ($ir->toWire()->get($isPage ? 'pages' : 'segments') as $unit) {
            if ($unit->get($key) !== $locator->get($key)) { continue; }
            $bbox = $locator->get('bbox');
            $box = $bbox === null ? null : new D\Bbox($bbox->get('x0'), $bbox->get('y0'), $bbox->get('x1'), $bbox->get('y1'));
            $typed = $isPage ? D\TextLocator::page(new D\LocalId($locator->get('pageId')), $locator->get('physicalNo'), $locator->get('printedLabel'),
                new D\LocalId($locator->get('blockId')), $locator->get('byteStart'), $locator->get('byteEnd'), $box)
                : D\TextLocator::segment(new D\LocalId($locator->get('segmentId')), new D\LocalId($locator->get('blockId')), $locator->get('byteStart'), $locator->get('byteEnd'));
            return new D\Span($typed, new D\Sha256($unit->get('textDigest')));
        }
        throw new \LogicException('Unknown fixture locator unit');
    }
    public static function chunk(D\DocumentRevisionRef $revision, array $spans, string $text): D\Chunk
    {
        return new D\Chunk(new D\ChunkRef($revision, new D\ChunkSetId(self::id(40)), new D\ChunkId(self::id(41)), new D\Sha256(hash('sha256', $text))),
            new D\DocumentId(self::id(5)), $spans, $text);
    }

    /** Schema-valid values can exceed the transport byte ceiling when constructed in memory. */
    public static function oversizedIr(): D\IrDocument
    {
        // Constructing a >64 MiB immutable value needs two canonical buffers in JCS.
        // This is test-fixture preparation, not a relaxation of the product ceiling.
        $previous = ini_get('memory_limit');
        if (ini_set('memory_limit', '384M') === false) { throw new \RuntimeException('Fixture buffer configuration unavailable'); }
        try {
            $ir = self::ir('IR-BLANK')->toWire(); $text = str_repeat('x', 2097152); $digest = hash('sha256', $text); $pages = [];
            for ($n = 1; $n <= 32; $n++) {
                $pages[] = new JsonObject(['pageId' => 'p' . $n, 'physicalNo' => $n, 'printedLabel' => null, 'status' => 'ok', 'text' => $text, 'textDigest' => $digest]);
            }
            $coverage = $ir->get('coverage')->with('counts', new JsonObject(['expected' => 32, 'processed' => 32, 'empty' => 0, 'failed' => 0, 'skipped' => 0]));
            return new D\IrDocument($ir->with('pages', $pages)->with('coverage', $coverage), self::validator());
        } finally { ini_set('memory_limit', $previous); }
    }
}
