<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Structure;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Application\Validation\TechnicalValidator;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\IrDocument;
use KnowKit\Domain\LocalId;
use KnowKit\Tests\Ingest\Chunking\ChunkingFixture;
use KnowKit\Tests\Ingest\Validation\ValidationFixture;
use KnowKit\Tests\Tooling\DatabaseSandbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:structure')]
final class StructuralTest extends TestCase
{
    public function testWholeMergedRowGroupsKeepRepeatedHeadersUnitsNotesAndFollowupBudget(): void
    {
        $ir = StructureFixture::ir();
        $builder = ChunkingFixture::chunker($ir);
        $wide = $builder->build($ir, ChunkingFixture::profile(['maxBytes' => 1024]));
        $entries = array_values(array_filter($wide->manifest()->get('chunks'), static fn ($entry) => $entry->get('table') !== null));
        self::assertSame([[0, 1], [1, 2], [2, 4], [4, 5], [5, 6]], array_map(static fn ($entry) => $entry->get('table')->get('rowRange'), $entries));
        $merged = $entries[2];
        self::assertSame(2, $merged->get('table')->get('cells')[0]->get('rowSpan'));
        $last = $entries[4];
        self::assertSame(['h-mass-2'], $last->get('table')->get('cells')[1]->get('headerRefs'));
        self::assertSame('kg', $last->get('table')->get('cells')[1]->get('unit'));
        self::assertSame(['note-2'], $last->get('table')->get('cells')[1]->get('noteRefs'));
        $context = $last->get('context');
        self::assertSame(['inherited_heading', 'repeated_table_header', 'repeated_table_header', 'table_note', 'table_unit'], array_map(static fn ($part) => $part->get('role'), $context));
        self::assertSame(2, $context[2]->get('spans')[0]->get('physicalNo'));
        foreach ($context as $part) {
            self::assertFalse($part->get('isQuote'));
        }
        $bounded = $builder->build($ir, ChunkingFixture::profile(['maxBytes' => 32]));
        $followups = array_values(array_filter($bounded->followups(), static fn ($read) => $read->get('blockId') === 'table'));
        self::assertContains([2, 4], array_map(static fn ($read) => $read->get('rowRange'), $followups));
        foreach ($followups as $read) {
            self::assertSame('structural_unit_exceeds_budget', $read->get('reason'));
            self::assertFalse($read->get('isQuote'));
            self::assertEquals($builder->revision->toWire(), $read->get('revisionRef'));
        }
        $tableChunks = array_filter($bounded->manifest()->get('chunks'), static fn ($entry) => $entry->get('table') !== null);
        foreach ($tableChunks as $entry) {
            self::assertNotSame([2, 4], $entry->get('table')->get('rowRange'));
        }
    }

    public function testGeneratedDescriptionIndexHitRetainsOriginAssetDependenciesAndImmutableBindings(): void
    {
        $ir = StructureFixture::ir();
        $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile());
        $tenant = $result->chunkSet->revisionRef->sourceRef->tenantId;
        $inventory = new CanonicalChunkSets([$result]);
        self::assertSame($result, $inventory->get($tenant, $result->chunkSet->chunkSetId));
        // A real PostgreSQL FTS retention integration; no optional adapter qualification.
        (new DatabaseSandbox())->run(function (DatabaseSandbox $sandbox) use ($result, $ir): void {
            $pdo = $sandbox->connection;
            $pdo->exec("CREATE TABLE retained_structure_hits (tenant_id text, source_version_id text, ir_digest text, chunk_id text, body text, metadata jsonb, search tsvector GENERATED ALWAYS AS (to_tsvector('simple', body)) STORED)");
            $pdo->exec('CREATE INDEX ON retained_structure_hits USING gin(search)');
            $insert = $pdo->prepare('INSERT INTO retained_structure_hits (tenant_id, source_version_id, ir_digest, chunk_id, body, metadata) VALUES (?, ?, ?, ?, ?, ?)');
            $revision = $result->chunkSet->revisionRef;
            foreach ($result->chunkSet->chunks as $chunk) {
                $insert->execute([
                    $revision->sourceRef->tenantId->value,
                    $revision->sourceRef->sourceVersionId->value,
                    $revision->irDigest->value,
                    $chunk->ref->chunkId->value,
                    $chunk->text(),
                    Jcs::canonicalize($result->metadata($chunk->ref->chunkId->value)),
                ]);
            }
            $query = $pdo->prepare("SELECT body, metadata FROM retained_structure_hits WHERE tenant_id=? AND source_version_id=? AND ir_digest=? AND search @@ plainto_tsquery('simple', ?) ORDER BY chunk_id");
            $bindings = [$revision->sourceRef->tenantId->value, $revision->sourceRef->sourceVersionId->value, $revision->irDigest->value, 'blue apparatus'];
            $query->execute($bindings);
            $hits = $query->fetchAll(\PDO::FETCH_ASSOC);
            self::assertCount(1, $hits);
            self::assertSame('A blue apparatus on a bench.', $hits[0]['body']);
            $metadata = StrictJson::decode($hits[0]['metadata']);
            self::assertSame('generated', $metadata->get('origin'));
            self::assertSame('description', $metadata->get('kind'));
            self::assertSame(['original', 'caption'], $metadata->get('supportingRefs'));
            self::assertSame('segment', $metadata->get('spans')[0]->get('kind'));
            self::assertSame('source', $ir->block(new LocalId('caption'))->get('origin'));
            $bindings[0] = ValidationFixture::id(9);
            $query->execute($bindings);
            self::assertSame([], $query->fetchAll());
        });
    }

    public function testHeaderIdsAreResolvedWithinTheirOwnTable(): void
    {
        $ir = StructureFixture::ir();
        $wire = $ir->toWire();
        $blocks = $wire->get('blocks');
        $table = $ir->block(new LocalId('table'));
        $cells = $table->get('table')->get('cells');
        $secondPageHeader = $cells[7]->get('spans');
        $cells[2] = $cells[2]->with('spans', $secondPageHeader);
        foreach ($cells as $index => $cell) {
            $spans = array_map(static fn ($span) => $span->with('blockId', 'other-table'), $cell->get('spans'));
            $cells[$index] = $cell->with('spans', $spans);
        }
        $spans = array_map(static fn ($span) => $span->with('blockId', 'other-table'), $table->get('spans'));
        $blocks[] = $table->with('blockId', 'other-table')->with('order', count($blocks))
            ->with('spans', $spans)->with('table', $table->get('table')->with('cells', $cells));
        $twoTables = new IrDocument($wire->with('blocks', $blocks), ValidationFixture::validator());
        $result = ChunkingFixture::chunker($twoTables)->build($twoTables, ChunkingFixture::profile());
        $entries = array_values(array_filter($result->manifest()->get('chunks'), static fn ($entry) => $entry->get('blockIds') === ['table']));
        $headerContext = array_values(array_filter($entries[2]->get('context'), static fn ($part) => $part->get('role') === 'repeated_table_header'));
        self::assertSame('Mass', $headerContext[1]->get('text'));
        self::assertSame(1, $headerContext[1]->get('spans')[0]->get('physicalNo'));
    }

    public function testDigestConsistentOcrErrorNeverBecomesSemanticOrEntailmentProof(): void
    {
        $ir = StructureFixture::ir('uncertain-ocr');
        self::assertSame(hash('sha256', 'rnass = 12'), $ir->pages()[0]->get('textDigest'));
        $input = ValidationFixture::input($ir);
        self::assertSame('valid', (new TechnicalValidator($input))->validate($ir, $input->source->ref)->status);
        $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile());
        $chunk = $result->chunkSet->chunks[0];
        self::assertSame('rnass = 12', $chunk->text());
        self::assertSame('ocr', $result->metadata($chunk->ref->chunkId->value)->get('origin'));
        self::assertSame('partial', $result->manifest()->get('coverage')->get('state'));
        self::assertSame('ocr_low_confidence', $result->manifest()->get('coverage')->get('diagnostics')[0]->get('code'));
        self::assertFalse($result->metadata($chunk->ref->chunkId->value)->has('semanticCorrectness'));
    }
}
