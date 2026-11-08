<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Ocr;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Worker\Protocol\Fixture;

require_once dirname(__DIR__) . '/protocol/bootstrap.php';

final class OcrFixture
{
    public static function corpus(): string
    {
        return Fixture::root() . '/tests/fixtures/documents/scans/v1';
    }

    public static function document(string $name): D\IrDocument
    {
        return new D\IrDocument(
            StrictJson::decode(file_get_contents(self::corpus() . "/{$name}.ir.json")),
            Fixture::validator(),
        );
    }

    public static function revision(D\IrDocument $document, int $id = 1, string $source = 'approved-eng'): D\DocumentRevisionRef
    {
        $signature = $document->toWire()->get('parserSignature');

        return new D\DocumentRevisionRef(
            new D\ExactSourceRef(
                new D\TenantId($document->toWire()->get('tenantId')),
                new D\SourceId(Fixture::wire('envelope')->get('sourceId')),
                new D\SourceVersionId($document->toWire()->get('sourceVersionId')),
                new D\SourceDigest(hash_file('sha256', self::corpus() . "/{$source}.pdf")),
            ),
            new D\DocumentRevisionId(sprintf('%026d', $id)),
            new D\IrDigest(hash('sha256', $document->canonical())),
            new D\ParserSignature(
                $signature->get('name'),
                $signature->get('version'),
                new D\Fingerprint($signature->get('profileDigest')),
            ),
        );
    }

    public static function firstOcrId(D\IrDocument $document): D\LocalId
    {
        foreach ($document->toWire()->get('blocks') as $block) {
            if ($block->get('origin') === 'ocr') {
                return new D\LocalId($block->get('blockId'));
            }
        }

        throw new \RuntimeException('Expected a real OCR block');
    }
}
