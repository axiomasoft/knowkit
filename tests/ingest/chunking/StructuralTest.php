<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Chunking;

use KnowKit\Application\Chunking\{CanonicalMap, StructuralChunker};
use KnowKit\Application\Validation\TechnicalValidator;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\Tokenizer;
use KnowKit\Tests\Ingest\Validation\ValidationFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('ingest:chunking')]
final class StructuralTest extends TestCase
{
    public function testCrossPageParagraphRetainsExactSeparateSpansAndJoiner(): void
    {
        $ir = ChunkingFixture::ir('cross-page'); $builder = ChunkingFixture::chunker($ir); $profile = ChunkingFixture::profile();
        $result = $builder->build($ir, $profile); self::assertCount(1, $result->chunkSet->chunks);
        $chunk = $result->chunkSet->chunks[0]; $metadata = $result->metadata($chunk->ref->chunkId->value);
        self::assertCount(2, $chunk->spans); self::assertSame([1, 2], array_map(static fn($s) => $s->pageNo(), $chunk->spans));
        self::assertSame("Начало абзаца\n続き é.", $chunk->text()); self::assertSame("\n", $metadata->get('joiner'));
        self::assertSame(['p'], $metadata->get('structurePath'));
        self::assertSame($result->canonicalManifest(), $builder->build($ir, $profile)->canonicalManifest());
        self::assertEquals($result->chunkSet, $builder->chunk($ir, $profile));
        (new CanonicalMap($ir, $builder->revision, $builder->documentId))->assembler->assertChunk($chunk, "\n");
    }

    public function testOversizedCodeAndInheritedHeadingProduceExplicitBoundedReadRequirement(): void
    {
        $ir = ChunkingFixture::ir('code'); $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile());
        self::assertCount(1, $result->chunkSet->chunks); self::assertSame('# API', $result->chunkSet->chunks[0]->text());
        self::assertCount(1, $result->followups()); $read = $result->followups()[0];
        self::assertFalse($read->get('isQuote')); self::assertSame('read_requirement', $read->get('kind'));
        self::assertSame('structural_unit_exceeds_budget', $read->get('reason'));
        self::assertSame(['h', 'code'], $read->get('structurePath')); self::assertSame('code', $read->get('blockId'));
        self::assertEquals($result->chunkSet->revisionRef->toWire(), $read->get('revisionRef'));
        self::assertLessThan(4096, strlen(\KnowKit\Domain\Contract\Jcs::canonicalize($read)));
        self::assertFalse($read->has('text'));
        // A small code block preserves its whole canonical range; heading is separate context.
        $wide = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile(['maxBytes' => 6000]));
        self::assertCount(2, $wide->chunkSet->chunks); self::assertSame([], $wide->followups());
        $code = $wide->chunkSet->chunks[1]; $metadata = $wide->metadata($code->ref->chunkId->value);
        self::assertStringStartsWith('```php', $code->text()); self::assertStringEndsWith('```', $code->text());
        self::assertSame('inherited_heading', $metadata->get('context')[0]->get('role'));
        self::assertFalse($metadata->get('context')[0]->get('isQuote'));
        self::assertSame(6, $code->spans[0]->locator->toWire()->get('byteStart'));
        $table = ChunkingFixture::ir('repeated-table'); $wire = $table->toWire(); $blocks = $wire->get('blocks'); $cells = [];
        $template = $blocks[0]->get('table')->get('cells')[0];
        for ($i = 0; $i < 100; $i++) { $cells[] = $template->with('cellId', 'wide' . $i)->with('column', $i); }
        $blocks[0] = $blocks[0]->with('table', new JsonObject(['rows' => 1, 'columns' => 100, 'cells' => $cells]));
        $wideIr = new D\IrDocument($wire->with('blocks', $blocks), ValidationFixture::validator());
        $bounded = ChunkingFixture::chunker($wideIr)->build($wideIr, ChunkingFixture::profile());
        $read = $bounded->followups()[0]; self::assertSame([0, 1], $read->get('rowRange'));
        self::assertNull($read->get('spans')); self::assertSame('t', $read->get('blockId'));
        self::assertLessThanOrEqual(4096, strlen(\KnowKit\Domain\Contract\Jcs::canonicalize($read)));
    }

    public function testRepeatedHeadersHaveRealContextRefsAndRowsAreNeverCut(): void
    {
        $ir = ChunkingFixture::ir('repeated-table'); $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile());
        self::assertSame(["Item\nMass", "A\n12", "Item\nMass", "B\n13", '¹ Dry mass.'], array_map(static fn($c) => $c->text(), $result->chunkSet->chunks));
        $row = $result->chunkSet->chunks[3]; $entry = $result->metadata($row->ref->chunkId->value);
        self::assertSame([3, 4], $entry->get('table')->get('rowRange'));
        self::assertSame([2, 2], array_map(static fn($s) => $s->pageNo(), $row->spans));
        self::assertSame(['repeated_table_header', 'repeated_table_header', 'table_note', 'table_unit'], array_map(static fn($c) => $c->get('role'), $entry->get('context')));
        self::assertSame('Item', $entry->get('context')[0]->get('text'));
        self::assertSame(2, $entry->get('context')[0]->get('spans')[0]->get('physicalNo'));
        foreach ($entry->get('context') as $context) { self::assertFalse($context->get('isQuote')); }
        self::assertStringNotContainsString('Mass', $row->text());
    }

    public function testMergedCellsKeepRowspanClosureUnitsNotesAndExactRanges(): void
    {
        $ir = ChunkingFixture::ir('structured'); $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile());
        $entries = array_filter($result->manifest()->get('chunks'), static fn($e) => $e->get('table') !== null);
        self::assertCount(2, $entries); $entry = array_values($entries)[1];
        self::assertSame([1, 3], $entry->get('table')->get('rowRange'));
        $cells = $entry->get('table')->get('cells');
        self::assertSame(2, $cells[0]->get('rowSpan')); self::assertSame('kg', $cells[1]->get('unit'));
        self::assertSame(['fn1'], $cells[1]->get('noteRefs'));
        self::assertContains('table_note', array_map(static fn($c) => $c->get('role'), $entry->get('context')));
        $chunk = array_values(array_filter($result->chunkSet->chunks, static fn($c) => $c->ref->chunkId->value === $entry->get('chunkId')))[0];
        self::assertSame("Mass\n12.5\n13.1", $chunk->text()); self::assertCount(3, $chunk->spans);
        $small = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile(['maxBytes' => 40]));
        $reads = array_filter($small->followups(), static fn($f) => $f->get('blockId') === 'tbl1' && $f->get('rowRange') === [1, 3]);
        self::assertCount(1, $reads);
        foreach ($small->chunkSet->chunks as $bounded) { self::assertNotSame($chunk->ref->chunkDigest->value, $bounded->ref->chunkDigest->value); }
        $blocks = $ir->toWire()->get('blocks');
        foreach ($blocks as $i => $block) {
            if ($block->get('blockId') === 'fn1') { $blocks[$i] = $block->with('spans', []); }
        }
        $unlocated = new D\IrDocument($ir->toWire()->with('blocks', $blocks), ValidationFixture::validator());
        $missingNote = ChunkingFixture::chunker($unlocated)->build($unlocated, ChunkingFixture::profile());
        $reads = array_values(array_filter($missingNote->followups(), static fn($f) => $f->get('blockId') === 'tbl1'));
        self::assertCount(1, $reads); self::assertSame([1, 3], $reads[0]->get('rowRange'));
        self::assertSame('canonical_text_required', $reads[0]->get('reason'));
    }

    public function testMultibyteSplitsStayOnCodepointsAndKeepEveryByte(): void
    {
        $ir = ChunkingFixture::ir('multibyte'); $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile(['maxBytes' => 17]));
        $original = $ir->toWire()->get('pages')[0]->get('text'); $offset = 0; $all = '';
        self::assertSame([], $result->followups());
        foreach ($result->chunkSet->chunks as $chunk) {
            self::assertSame(1, preg_match('//u', $chunk->text())); self::assertLessThanOrEqual(17, strlen($chunk->text()));
            $span = $chunk->spans[0]->locator->toWire(); self::assertSame($offset, $span->get('byteStart')); $offset = $span->get('byteEnd');
            $all .= $chunk->text();
        }
        self::assertSame($original, $all); self::assertSame(strlen($original), $offset);
        $impossible = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile(['maxBytes' => 1]));
        self::assertSame([], $impossible->chunkSet->chunks); self::assertSame('budget_exhausted', $impossible->followups()[0]->get('reason'));
    }

    public function testSentencesPrecedeByteSplitsAndWholeBlockOverlapProgresses(): void
    {
        $ir = ChunkingFixture::ir('sentences'); $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile(['maxBytes' => 20]));
        self::assertSame(['One sentence. ', 'Two sentences. ', 'Three sentences.'], array_map(static fn($c) => $c->text(), $result->chunkSet->chunks));
        $ir = ChunkingFixture::ir('overlap'); $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile(['maxBytes' => 13]));
        self::assertSame(["aaaaaa\nbbbbbb", "bbbbbb\ncccccc"], array_map(static fn($c) => $c->text(), $result->chunkSet->chunks));
        self::assertSame(['p2', 'p3'], $result->manifest()->get('chunks')[1]->get('blockIds'));
        $noOverlap = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile(['maxBytes' => 13, 'overlapBlocks' => 0]));
        self::assertSame('cccccc', $noOverlap->chunkSet->chunks[1]->text());
    }

    public function testNewFullChunkerFingerprintCannotOverwriteHistoricalRefs(): void
    {
        $ir = ChunkingFixture::ir('cross-page'); $builder = ChunkingFixture::chunker($ir);
        $old = $builder->build($ir, ChunkingFixture::profile());
        $new = $builder->build($ir, ChunkingFixture::profile(['maxBytes' => 1199]));
        self::assertSame($old->chunkSet->revisionRef, $new->chunkSet->revisionRef);
        self::assertSame($old->chunkSet->chunks[0]->text(), $new->chunkSet->chunks[0]->text());
        self::assertNotEquals($old->chunkSet->chunkSetId, $new->chunkSet->chunkSetId);
        self::assertNotEquals($old->chunkSet->chunks[0]->ref, $new->chunkSet->chunks[0]->ref);
        self::assertNotEquals($old->manifestDigest, $new->manifestDigest);
        self::assertEquals($old->chunkSet->chunkSetId, $builder->build($ir, ChunkingFixture::profile())->chunkSet->chunkSetId);
        // Profile display ID is not a semantic option; the full engine signature is.
        $alias = new D\ChunkerProfile('other-display-id', 'structural', '1', new JsonObject());
        self::assertSame($old->canonicalManifest(), $builder->build($ir, $alias)->canonicalManifest());
    }

    public function testProcessingAxesAndChangedOriginalRemainIndependent(): void
    {
        $ir = ChunkingFixture::ir('cross-page'); $ref = ChunkingFixture::chunker($ir)->revision;
        $sig = $ref->parserSignature; $newSig = new D\ParserSignature($sig->name, '2', $sig->profileDigest);
        $old = new D\ProcessingIdentity($ref->sourceRef->sourceVersionId, $ref->sourceRef->sourceDigest, $sig, $sig, $sig, 'ir-1.0', new D\Fingerprint(str_repeat('a', 64)), new D\Fingerprint(str_repeat('b', 64)));
        $copy = static fn($ocr, $chunk, $space, $digest) => new D\ProcessingIdentity($old->sourceVersionId, $digest, $sig, $ocr, $chunk, 'ir-1.0', $old->indexProfileDigest, $space);
        self::assertSame(['documentRevisionId', 'chunkSetId', 'generationId'], $old->changedAxes($copy($newSig, $sig, $old->embeddingSpaceDigest, $old->sourceDigest)));
        self::assertSame(['chunkSetId', 'generationId'], $old->changedAxes($copy($sig, $newSig, $old->embeddingSpaceDigest, $old->sourceDigest)));
        self::assertSame(['generationId'], $old->changedAxes($copy($sig, $sig, new D\Fingerprint(str_repeat('c', 64)), $old->sourceDigest)));
        ChunkingFixture::failure(fn() => $old->changedAxes($copy($sig, $sig, $old->embeddingSpaceDigest, new D\SourceDigest(str_repeat('f', 64)))), 'immutable_conflict');
    }

    public function testGeneratedDescriptionNeverBecomesVerbatimSource(): void
    {
        $ir = ChunkingFixture::ir('structured'); $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile());
        $entry = array_values(array_filter($result->manifest()->get('chunks'), static fn($e) => $e->get('origin') === 'generated'))[0];
        self::assertSame('description', $entry->get('kind')); self::assertSame(['a1'], $entry->get('supportingRefs'));
        self::assertSame('segment', $entry->get('spans')[0]->get('kind'));
        self::assertSame('gen-desc-a1', $entry->get('spans')[0]->get('segmentId'));
        self::assertSame(['h1', 'img1', 'gen1'], $entry->get('structurePath'));
    }

    public function testDigestConsistentOcrKeepsQualityLimitations(): void
    {
        $ir = ChunkingFixture::ir('ocr'); $input = ValidationFixture::input($ir);
        self::assertSame('valid', (new TechnicalValidator($input))->validate($ir, $input->source->ref)->status);
        $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile());
        self::assertSame('partial', $result->manifest()->get('coverage')->get('state'));
        self::assertContains('ocr_low_confidence', array_map(static fn($d) => $d->get('code'), $result->manifest()->get('coverage')->get('diagnostics')));
        self::assertContains('ocr', array_map(static fn($e) => $e->get('origin'), $result->manifest()->get('chunks')));
        self::assertEquals($ir->toWire()->get('coverage'), $result->manifest()->get('coverage'));
    }

    public function testRevisionTenantDigestAndCanonicalTextTamperingReject(): void
    {
        $ir = ChunkingFixture::ir('cross-page'); $builder = ChunkingFixture::chunker($ir); $wire = $ir->toWire();
        $foreign = new D\IrDocument($wire->with('tenantId', ValidationFixture::id(9)), ValidationFixture::validator());
        ChunkingFixture::failure(fn() => $builder->build($foreign, ChunkingFixture::profile()), 'scope_mismatch', D\Outcome::Denied);
        $pages = $wire->get('pages'); $pages[0] = $pages[0]->with('text', 'invented')->with('textDigest', hash('sha256', 'invented'));
        // Same immutable revision with different valid IR cannot be relabelled as the historical document.
        $blocks = $wire->get('blocks'); $spans = $blocks[0]->get('spans'); $spans[0] = $spans[0]->with('byteEnd', 8);
        $blocks[0] = $blocks[0]->with('spans', $spans);
        $changed = new D\IrDocument($wire->with('pages', $pages)->with('blocks', $blocks), ValidationFixture::validator());
        ChunkingFixture::failure(fn() => $builder->build($changed, ChunkingFixture::profile()), 'immutable_conflict');
        $result = $builder->build($ir, ChunkingFixture::profile()); $chunk = $result->chunkSet->chunks[0];
        $forged = new D\Chunk(new D\ChunkRef($builder->revision, $chunk->ref->chunkSetId, $chunk->ref->chunkId, new D\Sha256(hash('sha256', 'invented'))), $builder->documentId, $chunk->spans, 'invented');
        ChunkingFixture::failure(fn() => (new CanonicalMap($ir, $builder->revision, $builder->documentId))->assembler->assertChunk($forged, "\n"), 'source_text_mismatch');
        self::assertStringNotContainsString('invented', print_r($result, true));
    }

    public function testTokenBudgetsRequireExactFullCounterIdentityAndCountContext(): void
    {
        $ir = ChunkingFixture::ir('sentences');
        $identity = new D\TokenizerIdentity(new D\AdapterId('fixture.counter'), '1', new D\Sha256(str_repeat('a', 64)));
        $tokenizer = new class($identity) implements Tokenizer {
            public bool $exact = true;
            public function __construct(private D\TokenizerIdentity $identity) {}
            public function capabilities(): D\TokenizerCapabilities { return new D\TokenizerCapabilities($this->identity, $this->exact); }
            public function count(string $utf8, D\TokenizerIdentity $identity): int { $this->identity->assertCompatible($identity); return mb_strlen($utf8, 'UTF-8'); }
        };
        ChunkingFixture::failure(fn() => ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile(['maxCount' => 10])), 'tokenizer_required');
        $options = ['maxCount' => 10, 'counter' => new JsonObject(['id' => 'fixture.counter', 'version' => '1', 'vocabularyDigest' => str_repeat('a', 64)])];
        ChunkingFixture::failure(fn() => ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile($options)), 'tokenizer_required');
        $result = ChunkingFixture::chunker($ir, null, $tokenizer)->build($ir, ChunkingFixture::profile($options));
        self::assertSame([], $result->followups());
        foreach ($result->chunkSet->chunks as $chunk) { self::assertLessThanOrEqual(10, $tokenizer->count($chunk->text(), $identity)); }
        $tokenizer->exact = false;
        ChunkingFixture::failure(fn() => ChunkingFixture::chunker($ir, null, $tokenizer)->build($ir, ChunkingFixture::profile($options)), 'unsupported_capability');
        $tokenizer->exact = true;
        $table = ChunkingFixture::ir('repeated-table');
        $bounded = ChunkingFixture::chunker($table, null, $tokenizer)->build($table, ChunkingFixture::profile(array_merge($options, ['maxCount' => 5])));
        // A four-character row cannot fit once its real headers, units and notes are counted.
        self::assertSame([], array_values(array_filter($bounded->manifest()->get('chunks'), static fn($e) => $e->get('table') !== null)));
        self::assertCount(4, $bounded->followups());
        $options['counter'] = $options['counter']->with('vocabularyDigest', str_repeat('b', 64));
        ChunkingFixture::failure(fn() => ChunkingFixture::chunker($ir, null, $tokenizer)->build($ir, ChunkingFixture::profile($options)), 'tokenizer_mismatch');
        foreach ([['maxBytes' => 0], ['overlapBlocks' => 2], ['unknown' => true]] as $invalid) {
            ChunkingFixture::failure(fn() => ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile($invalid)), isset($invalid['unknown']) ? 'unknown_field' : 'limit_exceeded');
        }
    }
}
