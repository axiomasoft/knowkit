<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Ocr;

use KnowKit\Application\Validation\SpanAssembler;
use KnowKit\Domain as D;
use KnowKit\Tests\Worker\Protocol\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OcrFixture.php';

#[Group('worker:ocr')]
final class RevisionTest extends TestCase
{
    public function testOldCitationStillResolvesItsOriginalRevisionAfterReOcr(): void
    {
        $old = OcrFixture::document('approved-eng');
        $next = OcrFixture::document('wrong-language');
        $oldRevision = OcrFixture::revision($old, 1);
        $nextRevision = OcrFixture::revision($next, 2);
        self::assertEquals($oldRevision->sourceRef->toWire(), $nextRevision->sourceRef->toWire());
        self::assertNotEquals($oldRevision->documentRevisionId, $nextRevision->documentRevisionId);
        self::assertNotEquals($oldRevision->irDigest, $nextRevision->irDigest);
        $citation = $this->citation($old, $oldRevision);
        $before = $citation->canonical();
        $assembler = new SpanAssembler($old, $oldRevision);
        $text = $assembler->assemble($citation->spans(), null);
        self::assertSame('Approved', $text);
        $oldChunk = $this->chunk($oldRevision, $text);
        $citation->requireChunk($oldChunk);
        self::assertSame($text, (new SpanAssembler($old, $oldRevision))->assemble($citation->spans(), null));
        self::assertSame($before, $citation->canonical());
        $this->expectExceptionMessage('immutable_conflict');
        $citation->requireChunk($this->chunk($nextRevision, $text));
    }

    private function citation(D\IrDocument $document, D\DocumentRevisionRef $revision): D\CitationRef
    {
        $block = $document->block(OcrFixture::firstOcrId($document));
        $span = $block->get('spans')[0];
        $page = $document->pages()[0];
        $text = substr($page->get('text'), $span->get('byteStart'), $span->get('byteEnd') - $span->get('byteStart'));
        $wire = $revision->sourceRef->toWire()->with('documentRevisionId', $revision->documentRevisionId->value)
            ->with('irDigest', $revision->irDigest->value)->with('citationId', sprintf('%026d', 10))
            ->with('documentId', sprintf('%026d', 11))->with('origin', 'ocr')
            ->with('contentDigest', hash('sha256', $text))
            ->with('spans', [$span->with('textDigest', $page->get('textDigest'))]);

        return new D\CitationRef($wire, Fixture::validator());
    }

    private function chunk(D\DocumentRevisionRef $revision, string $text): D\ChunkRef
    {
        return new D\ChunkRef(
            $revision,
            new D\ChunkSetId(sprintf('%026d', 12)),
            new D\ChunkId(sprintf('%026d', 13)),
            new D\Sha256(hash('sha256', $text)),
        );
    }

    private function processing(D\ParserSignature $ocr, int $chunk = 1, int $embedding = 1): D\ProcessingIdentity
    {
        $revision = OcrFixture::revision(OcrFixture::document('approved-eng'));

        return new D\ProcessingIdentity(
            $revision->sourceRef->sourceVersionId,
            $revision->sourceRef->sourceDigest,
            $revision->parserSignature,
            $ocr,
            new D\ParserSignature('chunker', (string) $chunk, new D\Fingerprint(hash('sha256', "chunk-{$chunk}"))),
            'ir-1.0',
            new D\Fingerprint(hash('sha256', 'index-profile')),
            new D\Fingerprint(hash('sha256', "embedding-{$embedding}")),
        );
    }

    public function testReOcrRechunkAndReembedChangeOnlyTheirImmutableAxes(): void
    {
        $old = OcrFixture::revision(OcrFixture::document('approved-eng'))->parserSignature;
        $changed = new D\ParserSignature('docling', '2.148.0', new D\Fingerprint(hash('sha256', 'new-approved-model')));
        $base = $this->processing($old);
        self::assertSame(['documentRevisionId', 'chunkSetId', 'generationId'], $base->changedAxes($this->processing($changed)));
        self::assertSame(['chunkSetId', 'generationId'], $base->changedAxes($this->processing($old, 2)));
        self::assertSame(['generationId'], $base->changedAxes($this->processing($old, 1, 2)));
        self::assertSame([], $base->changedAxes($this->processing($old)));
        self::assertNotEquals($base->digest(), $this->processing($changed)->digest());
        self::assertNotEquals($base->digest(), $this->processing($old, 2)->digest());
        self::assertNotEquals($base->digest(), $this->processing($old, 1, 2)->digest());
    }

    public function testChangedOriginalCannotReuseSourceVersionOrHistoricalCitation(): void
    {
        $ref = OcrFixture::revision(OcrFixture::document('approved-eng'))->sourceRef;
        $bytes = file_get_contents(OcrFixture::corpus() . '/approved-eng.pdf');
        $ref->assertBytes($bytes);
        $this->expectExceptionMessage('immutable_conflict');
        $ref->assertBytes($bytes . 'changed bytes');
    }

    public function testChangedBytesRequireNewSourceVersionAcrossProcessingAxes(): void
    {
        $signature = OcrFixture::revision(OcrFixture::document('approved-eng'))->parserSignature;
        $base = $this->processing($signature);
        $changed = new D\ProcessingIdentity(
            $base->sourceVersionId,
            new D\SourceDigest(hash('sha256', 'changed original')),
            $base->parserSignature,
            $base->ocrProfileSignature,
            $base->chunkerSignature,
            $base->irSchema,
            $base->indexProfileDigest,
            $base->embeddingSpaceDigest,
        );
        $this->expectExceptionMessage('immutable_conflict');
        $base->changedAxes($changed);
    }

    public function testForeignTenantAndLatestIrCannotReplaceHistoricalBuffers(): void
    {
        $document = OcrFixture::document('approved-eng');
        $revision = OcrFixture::revision($document);
        $foreign = new D\ExactSourceRef(
            new D\TenantId(sprintf('%026d', 99)),
            $revision->sourceRef->sourceId,
            $revision->sourceRef->sourceVersionId,
            $revision->sourceRef->sourceDigest,
        );
        try {
            $revision->sourceRef->assertSame($foreign);
            self::fail('Foreign tenant accepted');
        } catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Denied, $failure->outcome);
        }
        $this->expectExceptionMessage('immutable_conflict');
        new SpanAssembler(OcrFixture::document('wrong-language'), $revision);
    }
}
