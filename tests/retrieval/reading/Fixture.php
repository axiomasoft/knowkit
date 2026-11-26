<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Reading;

use KnowKit\Adapter\LocalBlob\Preview\BoundedObjects;
use KnowKit\Adapter\Postgres\Dependencies\NodeMetadata;
use KnowKit\Adapter\Postgres\Reading\PdoReadingStore;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\Reading\CanonicalReads;
use KnowKit\Application\Reading\Readings;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Reading as R;
use KnowKit\Port\Tokenizer;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as DisclosureFixture;
use PHPUnit\Framework\Assert;

/** Actual PostgreSQL guards and Usage writer with synthetic historical resolver fixtures. */
final class Fixture
{
    public PdoReadingStore $store;
    public Readings $service;
    public D\IrDocument $document;
    public D\ChunkRef $chunk;
    public D\CitationRef $citation;
    public D\DocumentId $documentId;
    public D\SelectionId $selectionId;
    public D\UsageId $originalUsage;
    public D\Ulid $issuedCitation;

    public function __construct(
        public DisclosureFixture $upstream,
        private bool $ambiguous = false,
    )
    {
        $selected = $upstream->select();
        $issued = $upstream->disclose($selected)->result;
        Assert::assertSame(D\Outcome::Success, $issued->outcome, $issued->errorCode ?? 'issued');
        $this->issuedCitation = new D\Ulid($issued->data->toWire()->get('fragments')[0]->get('citation')->get('citationId'));
        $this->originalUsage = $issued->data->usageRef();
        $this->selectionId = $selected->result->data->id();
        $this->build();
        $this->seed();
        $this->store = new PdoReadingStore($upstream->db(), $upstream->lexical->catalog->tx,
            $upstream->usages, $upstream->dependencies, PortFixture::validator(), $upstream->host);
        $upstream->host->binding = $this->store->plan($this->scope(), $this->record())->binding();
        $this->service = $this->service();
    }

    public static function run(callable $test, bool $ambiguous = false): void
    {
        DisclosureFixture::run(static fn (DisclosureFixture $f) => $test(new self($f, $ambiguous)));
    }

    public static function corpus(): array
    {
        static $corpus;
        return $corpus ??= json_decode(file_get_contents(PortFixture::root() . '/tests/fixtures/citations/v1/corpus.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function historyIr(): \KnowKit\Domain\Contract\JsonObject
    {
        static $ir;
        return $ir ??= PortFixture::fixture()->get('history')[0]->get('ir');
    }

    public static function id(int $id): string
    {
        return PortFixture::id($id);
    }

    public function scope(): D\Scope
    {
        return $this->upstream->host->identity->scope;
    }

    public function record(): R\CitationRecord
    {
        return new R\CitationRecord($this->citation, $this->chunk, $this->selectionId,
            $this->scope()->kbId, $this->scope()->hostScopeHash, null, true);
    }

    public function request(?R\ReadTarget $target = null, ?D\SelectionLimits $limits = null,
        ?D\UtcInstant $expires = null, ?D\Sha256 $etag = null, int $maxBytes = 8388608): R\ReadRequest
    {
        $this->upstream->host->now = DisclosureFixture::databaseNow($this->upstream->db());

        return new R\ReadRequest($this->citation->id(), $this->upstream->host->next(), $target ?? R\ReadTarget::quote(),
            $limits ?? D\SelectionLimits::profile('visual'), $maxBytes,
            $expires ?? $this->upstream->host->now->plusSeconds(30), $etag, 'read-run');
    }

    public function service(?Tokenizer $tokenizer = null): Readings
    {
        $f = $this->upstream;
        $canonical = new CanonicalReads(new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()),
            $this->store, new BoundedObjects($f->objects), $tokenizer);

        return new Readings($this->store, $f->checks(), $canonical, $f->host);
    }

    public function read(?R\ReadRequest $request = null): D\Result
    {
        return $this->service->read($this->upstream->host->context(), $request ?? $this->request());
    }

    public function guarded(callable $change): void
    {
        $f = $this->upstream;
        $f->lexical->catalog->tx->run(function () use ($f, $change): void {
            $tx = $f->lexical->catalog->tx;
            $tx->lockTenant($this->scope()->tenantId, D\LockMode::Update);
            $tx->lockKb($this->scope()->kbId, D\LockMode::Update);
            $tx->lockSources([$this->chunk->revisionRef->sourceRef->sourceId], D\LockMode::Update);
            $change();
        });
    }

    private function build(): void
    {
        $case = self::corpus();
        $source = $this->upstream->ref()->revisionRef->sourceRef;
        $source = new D\SourceVersionRef($source->tenantId, $source->sourceId,
            new D\SourceVersionId(self::id(21003)), new D\SourceDigest($case['expectedDigests']['source']));
        $ir = self::historyIr();
        $page = new JsonObject(['pageId' => 'p1', 'physicalNo' => 1, 'printedLabel' => 'xii',
            'text' => $case['page'], 'textDigest' => $case['expectedDigests']['page'], 'status' => 'ok']);
        $span = D\TextLocator::page(new D\LocalId('p1'), 1, 'xii', new D\LocalId('b1'), 0, strlen($case['page']))->toWire();
        $block = new JsonObject(['blockId' => 'b1', 'kind' => 'text', 'parentId' => null, 'order' => 0,
            'origin' => 'ocr', 'spans' => [$span], 'provenance' => new JsonObject(['kind' => 'verbatim', 'supportingRefs' => []])]);
        $assets = [$this->asset('a1', $case['asset']), $this->asset('a2', $case['preview'], new D\Bbox(0, 0, 500000, 500000))];
        if ($this->ambiguous) {
            $assets[] = $this->asset('a3', $case['preview'], new D\Bbox(0, 0, 500000, 500000));
        }
        $this->document = new D\IrDocument($ir->with('sourceVersionId', $source->sourceVersionId->value)
            ->with('pages', [$page, $page->with('pageId', 'p2')->with('physicalNo', 2)])
            ->with('segments', [new JsonObject(['segmentId' => 's1', 'text' => 'segment', 'textDigest' => hash('sha256', 'segment'), 'status' => 'ok'])])
            ->with('blocks', [$block, $this->table($block, $span, $case['page'])])
            ->with('assets', $assets)
            ->with('coverage', $ir->get('coverage')->with('counts', new JsonObject([
                'expected' => 3, 'processed' => 3, 'empty' => 0, 'failed' => 0, 'skipped' => 0,
            ]))), PortFixture::validator());
        $revision = new D\DocumentRevisionRef($source, new D\DocumentRevisionId(self::id(21004)),
            new D\IrDigest(hash('sha256', $this->document->canonical())), PortFixture::signature($ir->get('parserSignature')));
        $this->chunk = new D\ChunkRef($revision, new D\ChunkSetId(self::id(21005)),
            new D\ChunkId(self::id(21006)), new D\Sha256($case['expectedDigests']['page']));
        $this->documentId = new D\DocumentId(self::id(21007));
        $this->citation = new D\CitationRef($source->toWire()->with('citationId', self::id(21008))
            ->with('documentRevisionId', $revision->documentRevisionId->value)->with('documentId', $this->documentId->value)
            ->with('irDigest', $revision->irDigest->value)->with('contentDigest', $this->chunk->chunkDigest->value)
            ->with('origin', 'ocr')->with('spans', [$span->with('textDigest', $case['expectedDigests']['page'])]), PortFixture::validator());
    }

    private function table(JsonObject $block, JsonObject $span, string $text): JsonObject
    {
        $span = $span->with('blockId', 'tb');
        $split = strpos($text, "\n");
        $cell = new JsonObject(['cellId' => 'h', 'row' => 0, 'column' => 0, 'rowSpan' => 1, 'colSpan' => 1,
            'header' => true, 'headerRefs' => [], 'unit' => 'кг', 'noteRefs' => [], 'spans' => [$span->with('byteEnd', $split)]]);
        $value = $cell->with('cellId', 'v')->with('row', 1)->with('header', false)->with('headerRefs', ['h'])
            ->with('spans', [$span->with('byteStart', $split + 1)->with('byteEnd', strlen($text) - 1)]);

        return $block->with('blockId', 'tb')->with('kind', 'table')->with('order', 1)->with('spans', [$span])
            ->with('table', new JsonObject(['rows' => 2, 'columns' => 1, 'cells' => [$cell, $value]]));
    }

    private function asset(string $id, string $bytes, ?D\Bbox $bbox = null): JsonObject
    {
        $asset = new JsonObject(['assetId' => $id, 'mediaType' => 'image/png', 'digest' => hash('sha256', $bytes),
            'byteLength' => (string) strlen($bytes), 'pageId' => 'p1']);

        return $bbox === null ? $asset : $asset->with('bbox', $bbox->toWire());
    }

    private function seed(): void
    {
        $f = $this->upstream;
        $db = $f->db();
        $source = $this->chunk->revisionRef->sourceRef;
        $t = $source->tenantId->value;
        $case = self::corpus();
        foreach ([$case['source'], $this->document->canonical(), $case['page'], $case['asset'], $case['preview'], $case['preview'], 'segment'] as $offset => $bytes) {
            $f->objects->bodies[hash('sha256', $bytes)] = $bytes;
            $db->execute('INSERT INTO ' . $db->table('resource_ledger')
                . " (tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state) VALUES (?,?,?,'owned','test',?,?,?,?, 'accepted')",
                [$t, self::id(21100 + $offset), $source->sourceId->value, 'reading-' . $offset, 'v1', hash('sha256', $bytes), strlen($bytes)]);
        }
        $this->seedRevision();
        $this->seedUnits();
        $this->seedChunk();
        $this->seedCitation();
        $this->guarded(function () use ($f, $source): void {
            $metadata = new NodeMetadata($f->db(), $f->lexical->catalog->tx);
            $parents = [];
            foreach ([['source_version', $source->sourceVersionId], ['document_revision', $this->chunk->revisionRef->documentRevisionId],
                ['chunk', $this->chunk->chunkId]] as [$kind, $id]) {
                $ref = new D\ResourceRef($source->tenantId, $kind, $id);
                $node = new D\Node($ref, new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($metadata->resolve($ref)['identity'])));
                $f->dependencies->register($node, new D\Parents($source->tenantId, $parents));
                $parents = [$ref];
            }
        });
    }

    private function seedRevision(): void
    {
        $db = $this->upstream->db();
        $r = $this->chunk->revisionRef;
        $s = $r->sourceRef;
        $t = $s->tenantId->value;
        $db->execute('INSERT INTO ' . $db->table('source_versions')
            . ' (tenant_id,id,source_id,blob_resource_id,source_digest,byte_length,media_type,rights_ref,idempotency_fingerprint,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$t, $s->sourceVersionId->value, $s->sourceId->value, self::id(21100), $s->sourceDigest->value,
                strlen(self::corpus()['source']), 'application/pdf', 'synthetic', str_repeat('c', 64), $this->upstream->host->now->value]);
        $db->execute('INSERT INTO ' . $db->table('source_version_state') . " VALUES (?,?,'usable',NULL,NULL)", [$t, $s->sourceVersionId->value]);
        $db->execute('INSERT INTO ' . $db->table('document_revisions')
            . ' (tenant_id,id,source_version_id,parser_name,parser_version,profile_digest,ir_schema,ir_digest,ir_resource_id,coverage_state,coverage_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?::jsonb,?)',
            [$t, $r->documentRevisionId->value, $s->sourceVersionId->value, $r->parserSignature->name,
                $r->parserSignature->version, $r->parserSignature->profileDigest->value, 'ir-1.0', $r->irDigest->value,
                self::id(21101), 'complete', Jcs::canonicalize($this->document->toWire()->get('coverage')), $this->upstream->host->now->value]);
        $db->execute('INSERT INTO ' . $db->table('documents') . ' VALUES (?,?,?,?)', [$t, $this->documentId->value, $r->documentRevisionId->value, 'historical']);
    }

    private function seedUnits(): void
    {
        $db = $this->upstream->db();
        $t = $this->scope()->tenantId->value;
        foreach ($this->document->pages() as $index => $page) {
            $db->execute('INSERT INTO ' . $db->table('pages')
                . ' (tenant_id,id,document_id,local_page_id,physical_no,printed_label,text_resource_id,text_digest,status) VALUES (?,?,?,?,?,?,?,?,?)',
                [$t, self::id(21200 + $index), $this->documentId->value, $page->get('pageId'), $page->get('physicalNo'),
                    $page->get('printedLabel'), self::id(21102), $page->get('textDigest'), 'ok']);
        }
        foreach ($this->document->toWire()->get('blocks') as $index => $block) {
            $db->execute('INSERT INTO ' . $db->table('blocks')
                . ' (tenant_id,id,revision_id,document_id,local_block_id,kind,origin,provenance_json,reading_order,spans_json,structure_json) VALUES (?,?,?,?,?,?,?,?::jsonb,?,?::jsonb,?::jsonb)',
                [$t, self::id(21300 + $index), $this->chunk->revisionRef->documentRevisionId->value, $this->documentId->value,
                    $block->get('blockId'), $block->get('kind'), $block->get('origin'), Jcs::canonicalize($block->get('provenance')),
                    $block->get('order'), Jcs::canonicalize($block->get('spans')), Jcs::canonicalize($block->without('spans')->without('provenance'))]);
        }
        foreach ($this->document->assets() as $index => $asset) {
            $db->execute('INSERT INTO ' . $db->table('assets') . ' VALUES (?,?,?,?,?,?::jsonb,?,NULL)',
                [$t, self::id(21400 + $index), $this->chunk->revisionRef->documentRevisionId->value, self::id(21103 + $index),
                    $asset->get('mediaType'), Jcs::canonicalize([$asset]), 'source']);
        }
    }

    private function seedChunk(): void
    {
        $db = $this->upstream->db();
        $t = $this->scope()->tenantId->value;
        $db->execute('INSERT INTO ' . $db->table('chunk_sets') . ' VALUES (?,?,?,?::jsonb,?)',
            [$t, $this->chunk->chunkSetId->value, $this->chunk->revisionRef->documentRevisionId->value,
                Jcs::canonicalize($this->chunk->revisionRef->parserSignature->toWire()), str_repeat('e', 64)]);
        $db->execute('INSERT INTO ' . $db->table('chunks') . ' VALUES (?,?,?,?,?,?::jsonb,?::jsonb,?,?::jsonb,?)',
            [$t, $this->chunk->chunkId->value, $this->chunk->chunkSetId->value, $this->chunk->chunkDigest->value,
                $this->documentId->value, Jcs::canonicalize($this->citation->toWire()->get('spans')), '{"kind":"verbatim"}',
                strlen(self::corpus()['page']), '[]', self::id(21102)]);
    }

    private function seedCitation(): void
    {
        $db = $this->upstream->db();
        $s = $this->chunk->revisionRef->sourceRef;
        $db->execute('INSERT INTO ' . $db->table('citations') . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb,?::jsonb,?)',
            [$s->tenantId->value, $this->citation->id()->value, $this->originalUsage->value, $this->scope()->hostScopeHash->value,
                $s->sourceId->value, $s->sourceVersionId->value, $this->chunk->revisionRef->documentRevisionId->value,
                $this->documentId->value, $this->chunk->chunkSetId->value, $this->chunk->chunkId->value,
                $this->chunk->chunkDigest->value, $s->sourceDigest->value, $this->chunk->revisionRef->irDigest->value,
                Jcs::canonicalize($this->citation->toWire()->get('spans')),
                Jcs::canonicalize(new JsonObject(['citation' => $this->citation->toWire(), 'joiner' => null])), 'present']);
    }
}
