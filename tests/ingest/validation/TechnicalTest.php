<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Validation;

use KnowKit\Application\Validation\{ExtractionCacheEntry, SpanAssembler, TechnicalValidator, ValidationInput};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractViolation, Jcs, JsonObject, LocatorConversion};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('ingest:validation')]
final class TechnicalTest extends TestCase
{
    public function testVersionedCorpusRunsSchemaBeforeSemanticChecksAndRoundTrips(): void
    {
        $manifest = ValidationFixture::manifest();
        self::assertFalse($manifest['externalAdaptersImplemented']);
        foreach ($manifest['cases'] as $entry) {
            $bytes = ValidationFixture::read($entry['path']);
            self::assertSame($entry['sha256'], hash('sha256', $bytes), $entry['id']);
            $errors = ValidationFixture::validator()->validateBytes($bytes, $entry['schemaId']);
            if ($entry['expected']['status'] === 'invalid') {
                self::assertContains(['code' => $entry['expected']['code'], 'path' => $entry['expected']['path']], $errors, $entry['id']);
                try { ValidationFixture::ir($entry['id']); self::fail('Invalid IR constructed'); }
                catch (ContractViolation $e) { self::assertNotSame('', $e->errorCode); }
                continue;
            }
            self::assertSame([], $errors, $entry['id']);
            $ir = ValidationFixture::ir($entry['id']); $input = ValidationFixture::input($ir);
            self::assertSame('valid', (new TechnicalValidator($input))->validate($ir, $input->source->ref)->status);
            self::assertSame($entry['irDigest'], hash('sha256', $ir->canonical()));
            $roundTrip = new D\IrDocument(ValidationFixture::validator()->decodeValid($ir->canonical(), $entry['schemaId']), ValidationFixture::validator());
            self::assertSame($ir->canonical(), $roundTrip->canonical());
            self::assertSame($entry['expected']['coverage'], $roundTrip->toWire()->get('coverage')->get('state'));
        }
        self::assertCount(18, $manifest['cases']);
    }

    public function testSourceIrAndAssetTamperingCannotSelfSignOrOmitInventory(): void
    {
        $ir = ValidationFixture::ir('IR-STRUCTURED'); $input = ValidationFixture::input($ir);
        $validator = new TechnicalValidator($input);
        $pages = $ir->toWire()->get('pages'); $text = $pages[0]->get('text') . ' changed';
        $pages[0] = $pages[0]->with('text', $text)->with('textDigest', hash('sha256', $text));
        $changed = new D\IrDocument($ir->toWire()->with('pages', $pages), ValidationFixture::validator());
        self::assertContains('digest_mismatch', $validator->validate($changed, $input->source->ref)->errorCodes);
        foreach ([[], ['a1' => 'changed nonempty bytes'], $input->assets + ['extra' => 'nonempty']] as $assets) {
            $bad = new ValidationInput($input->source, $input->revision, $input->processing, $assets);
            self::assertSame('invalid', (new TechnicalValidator($bad))->validate($ir, $input->source->ref)->status);
        }
        $wrong = new D\SourceVersionRef($input->source->ref->tenantId, $input->source->ref->sourceId, new D\SourceVersionId(ValidationFixture::id(30)), $input->source->ref->sourceDigest);
        self::assertContains('immutable_conflict', $validator->validate($ir, $wrong)->errorCodes);
        $foreign = new D\SourceVersionRef(new D\TenantId(ValidationFixture::id(9)), $input->source->ref->sourceId,
            $input->source->ref->sourceVersionId, $input->source->ref->sourceDigest);
        self::assertContains('scope_mismatch', $validator->validate($ir, $foreign)->errorCodes);
        try { new D\SourceBytes($input->source->ref, 'changed source bytes', 'application/pdf'); self::fail('Changed source accepted'); }
        catch (D\DomainFailure $e) { self::assertSame('immutable_conflict', $e->errorCode); }
        self::assertStringNotContainsString('synthetic original bytes', print_r($input, true));
    }

    public function testUnicodeMultipleSpansResolveExactlyAndRejectForgedChunk(): void
    {
        $ir = ValidationFixture::ir(); $input = ValidationFixture::input($ir); $oracle = ValidationFixture::manifest()['multiSpan'];
        $spans = [];
        foreach ($oracle['spanIndices'] as [$block, $span]) { $spans[] = ValidationFixture::span($ir, $ir->toWire()->get('blocks')[$block]->get('spans')[$span]); }
        $assembler = new SpanAssembler($ir, $input->revision, new D\DocumentId(ValidationFixture::id(5)));
        $text = $assembler->assemble($spans, $oracle['joiner']);
        self::assertSame($oracle['text'], $text); self::assertSame($oracle['contentDigest'], hash('sha256', $text));
        self::assertStringContainsString("Cafe\u{0301}", $text); self::assertSame(1, $spans[0]->pageNo());
        self::assertSame('xii', $spans[0]->locator->toWire()->get('printedLabel'));
        $assembler->assertChunk(ValidationFixture::chunk($input->revision, $spans, $text), $oracle['joiner']);
        $chunk = ValidationFixture::chunk($input->revision, $spans, $text);
        $foreignDocument = new D\Chunk($chunk->ref, new D\DocumentId(ValidationFixture::id(6)), $spans, $text);
        try { $assembler->assertChunk($foreignDocument, $oracle['joiner']); self::fail('Changed document binding accepted'); }
        catch (D\DomainFailure $e) { self::assertSame('binding_mismatch', $e->errorCode); }
        try { (new SpanAssembler($ir, $input->revision))->assertChunk($chunk, $oracle['joiner']); self::fail('Missing document binding accepted'); }
        catch (D\DomainFailure $e) { self::assertSame('document_binding_required', $e->errorCode); }
        foreach ([[$spans, null, 'invalid_span'], [$spans, '', 'invalid_span'], [$spans, str_repeat('x', 17), 'invalid_span'],
            [[$spans[0]], '', 'invalid_span']] as [$selection, $joiner, $expected]) {
            try { $assembler->assemble($selection, $joiner); self::fail('Implicit span assembly accepted'); }
            catch (D\DomainFailure $e) { self::assertSame($expected, $e->errorCode); }
        }
        try { $assembler->assertChunk(ValidationFixture::chunk($input->revision, $spans, 'self-signed invented quote'), $oracle['joiner']); self::fail('Invented chunk accepted'); }
        catch (D\DomainFailure $e) { self::assertSame('source_text_mismatch', $e->errorCode); }
        try { $assembler->assemble([new D\Span($spans[0]->locator, new D\Sha256(str_repeat('0', 64)))], null); self::fail('Wrong text hash accepted'); }
        catch (D\DomainFailure $e) { self::assertSame('digest_mismatch', $e->errorCode); }
        $badBoundary = new D\Span(D\TextLocator::page(new D\LocalId('p1'), 1, 'xii', new D\LocalId('b1'), 1, 21), $spans[0]->textDigest);
        try { $assembler->assemble([$badBoundary], null); self::fail('Split UTF-8 accepted'); }
        catch (D\DomainFailure $e) { self::assertSame('utf8_boundary', $e->errorCode); }
        $outside = new D\Span(D\TextLocator::page(new D\LocalId('p1'), 1, 'xii', new D\LocalId('b1'), 0, 22), $spans[0]->textDigest);
        try { $assembler->assemble([$outside], null); self::fail('Fabricated block range accepted'); }
        catch (D\DomainFailure $e) { self::assertSame('binding_mismatch', $e->errorCode); }
        $other = ValidationFixture::input($ir, 5)->revision;
        try { $assembler->assertChunk(ValidationFixture::chunk($other, $spans, $text), $oracle['joiner']); self::fail('Historical revision redirected'); }
        catch (D\DomainFailure $e) { self::assertSame('immutable_conflict', $e->errorCode); }
    }

    public function testStructuredFixtureRetainsRelationshipsOrExplicitUnavailableCapability(): void
    {
        $ir = ValidationFixture::ir('IR-STRUCTURED')->toWire(); $blocks = $ir->get('blocks'); $table = $blocks[2]->get('table');
        self::assertSame(['heading','text','table','formula','image','text','text','text'], array_map(fn($b) => $b->get('kind'), $blocks));
        self::assertSame(range(0, 7), array_map(fn($b) => $b->get('order'), $blocks));
        self::assertSame(2, $table->get('cells')[2]->get('rowSpan'));
        self::assertSame(['c01'], $table->get('cells')[3]->get('headerRefs'));
        self::assertSame('kg', $table->get('cells')[3]->get('unit')); self::assertSame(['fn1'], $table->get('cells')[3]->get('noteRefs'));
        self::assertSame('h1', $blocks[7]->get('parentId')); self::assertSame('img1', $blocks[6]->get('parentId'));
        self::assertSame('generated', $blocks[6]->get('origin')); self::assertSame('description', $blocks[6]->get('provenance')->get('kind'));
        self::assertSame(['a1'], $blocks[6]->get('provenance')->get('supportingRefs'));
        self::assertSame('p1', $ir->get('assets')[0]->get('pageId')); self::assertSame('page-millionths-top-left-v1', $ir->get('assets')[0]->get('bbox')->get('space'));
        self::assertContains('column_layout', $ir->get('coverage')->get('missingFeatures'));
    }

    public function testPageConversionRequiresExplicitCoordinateOriginAndHalfEvenRounding(): void
    {
        $input = new JsonObject(['pageIndex' => 0, 'pageId' => 'p1', 'printedLabel' => 'xii', 'blockId' => 'b1', 'byteStart' => 0, 'byteEnd' => 1,
            'pageWidth' => '2', 'pageHeight' => '2', 'coordinateOrigin' => 'top-left',
            'bbox' => new JsonObject(['x0' => '0.000001', 'y0' => '0.000003', 'x1' => '2', 'y1' => '2'])]);
        $locator = LocatorConversion::pageIndexToLocator($input);
        self::assertSame(1, $locator->get('physicalNo')); self::assertSame('xii', $locator->get('printedLabel'));
        self::assertSame(0, $locator->get('bbox')->get('x0')); self::assertSame(2, $locator->get('bbox')->get('y0'));
        self::assertSame('rotated-page', $locator->get('bbox')->get('view'));
        try { LocatorConversion::pageIndexToLocator($input->without('coordinateOrigin')); self::fail('Inferred coordinates accepted'); }
        catch (ContractViolation $e) { self::assertSame('missing_coordinate_system', $e->errorCode); }
    }

    public function testCanonicalOffsetsAndNormalizedMappingNeverUseOriginalPdfOffsets(): void
    {
        $ir = ValidationFixture::ir('IR-SEGMENT'); $input = ValidationFixture::input($ir);
        $assembler = new SpanAssembler($ir, $input->revision); $mapping = ValidationFixture::manifest()['normalizedMapping'];
        $span = D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), $mapping['canonicalStart'], $mapping['canonicalEnd']);
        $digest = new D\Sha256($ir->toWire()->get('segments')[0]->get('textDigest'));
        self::assertSame($mapping['canonical'], $assembler->assemble([new D\Span($span, $digest)], null));
        self::assertSame($mapping['normalized'], str_replace("e\u{0301}", 'é', $mapping['canonical']));
        $ligature = D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), 7, 10);
        self::assertSame('ﬁ', $assembler->assemble([new D\Span($ligature, $digest)], null));
        $combining = D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), 4, 6);
        self::assertSame("\u{0301}", $assembler->assemble([new D\Span($combining, $digest)], null));
        self::assertNotSame('ﬁ', substr($input->source->bytes(), 7, 3));
        self::assertNotSame($input->source->ref->sourceDigest->value, $digest->value);
        try {
            $fabricated = D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), $mapping['normalizedStart'], $mapping['normalizedEnd']);
            $assembler->assemble([new D\Span($fabricated, $digest)], null); self::fail('Normalized byte offsets fabricated source text');
        } catch (D\DomainFailure $e) { self::assertSame('utf8_boundary', $e->errorCode); }
    }

    public function testFullTypedFingerprintsAndVerifiedManifestRejectCacheIdentityDrift(): void
    {
        $ir = ValidationFixture::ir('IR-STRUCTURED'); $input = ValidationFixture::input($ir); $manifest = ValidationFixture::manifest();
        $profile = new D\ParserProfile('synthetic', 'synthetic-ir', '1', new JsonObject($manifest['profileOptions']));
        self::assertSame($manifest['parserProfileDigest'], $profile->signature->profileDigest->value);
        self::assertSame($manifest['processingDigest'], $input->processing->digest()->value);
        self::assertSame(64, strlen($input->processing->digest()->value));
        foreach ([D\SourceDigest::class, D\Fingerprint::class] as $type) {
            try { new $type(substr($manifest['processingDigest'], 0, 16)); self::fail('Short cache identity accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $entry = ExtractionCacheEntry::accept($input, $ir);
        self::assertSame('valid', $entry->reuse($input, $ir)->status);
        $changedIndex = new D\ProcessingIdentity($input->processing->sourceVersionId, $input->processing->sourceDigest, $input->processing->parserSignature,
            $input->processing->ocrProfileSignature, $input->processing->chunkerSignature, 'ir-1.0', new D\Fingerprint(str_repeat('c', 64)), null);
        $changedInput = new ValidationInput($input->source, $input->revision, $changedIndex, $input->assets);
        self::assertSame('invalid', $entry->reuse($changedInput, $ir)->status);
        self::assertSame('invalid', $entry->reuse(new ValidationInput($input->source, $input->revision, $input->processing, ['a1' => 'changed nonempty']), $ir)->status);
        self::assertSame('invalid', $entry->reuse(ValidationFixture::input($ir, 5), $ir)->status);
        $wire = $ir->toWire()->with('parserSignature', $ir->toWire()->get('parserSignature')->with('version', '2'));
        $newParser = new D\IrDocument($wire, ValidationFixture::validator());
        self::assertSame('invalid', $entry->reuse(ValidationFixture::input($newParser), $newParser)->status);
        $newSource = new D\IrDocument($ir->toWire()->with('sourceVersionId', ValidationFixture::id(33)), ValidationFixture::validator());
        self::assertSame('invalid', $entry->reuse(ValidationFixture::input($newSource), $newSource)->status);
        $foreignIr = new D\IrDocument($ir->toWire()->with('tenantId', ValidationFixture::id(9)), ValidationFixture::validator());
        $foreignInput = ValidationFixture::input($foreignIr);
        self::assertContains('scope_mismatch', $entry->reuse($foreignInput, $foreignIr)->errorCodes);
        $badBinding = new ValidationInput($input->source, $foreignInput->revision, $input->processing, $input->assets);
        try { ExtractionCacheEntry::accept($badBinding, $ir); self::fail('Foreign manifest accepted'); }
        catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Denied, $e->outcome); self::assertSame('scope_mismatch', $e->errorCode); }
    }

    public function testSchemaObjectAndByteLimitsAndStrictDecodeFailClosed(): void
    {
        $schema = 'urn:knowkit:schema:document-ir:1.0'; $validator = ValidationFixture::validator();
        $ir = ValidationFixture::ir('IR-BLANK')->toWire(); $page = $ir->get('pages')[0]; $pages = [];
        for ($n = 0; $n < 1001; $n++) { $pages[] = $page->with('pageId', 'p' . $n)->with('physicalNo', $n + 1); }
        self::assertContains(['code' => 'max_items', 'path' => '/pages'], $validator->validateValue($ir->with('pages', $pages), $schema));
        $assets = []; $asset = ValidationFixture::ir('IR-STRUCTURED')->toWire()->get('assets')[0]->with('pageId', null);
        for ($n = 0; $n < 2001; $n++) { $assets[] = $asset->with('assetId', 'a' . $n); }
        self::assertContains(['code' => 'max_items', 'path' => '/assets'], $validator->validateValue($ir->with('assets', $assets), $schema));
        self::assertSame([['code' => 'document_too_large', 'path' => '']], $validator->validateBytes(str_repeat(' ', 67108865), $schema));
        foreach (["\xff" => 'invalid_utf8', '{"x":1,"x":2}' => 'duplicate_key', '{"x":"\ud800"}' => 'invalid_surrogate'] as $bytes => $code) {
            self::assertSame([['code' => $code, 'path' => '']], $validator->validateBytes($bytes, $schema));
        }
        $badSchema = $ir->with('schemaVersion', 'ir-2.0')->with('pages', []);
        self::assertSame([['code' => 'unsupported_schema_version', 'path' => '/schemaVersion']], $validator->validateValue($badSchema, $schema));
    }

    public function testInMemoryIrCannotBypassTransportByteCeiling(): void
    {
        $input = ValidationFixture::input(ValidationFixture::ir('IR-BLANK')); $ir = ValidationFixture::oversizedIr();
        self::assertGreaterThan(67108864, strlen($ir->canonical()));
        self::assertSame(['document_too_large'], (new TechnicalValidator($input))->validate($ir, $input->source->ref)->errorCodes);
    }
}

