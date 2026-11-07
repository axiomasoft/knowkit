<?php

declare(strict_types=1);

namespace KnowKit\Tests\Reading\Navigation;

use KnowKit\Adapter\Postgres\Dependencies\NodeMetadata;
use KnowKit\Adapter\Postgres\Navigation\PdoNavigationStore;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\SourceNavigation;
use KnowKit\Application\SourceNavigation\CanonicalDocument;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as Upstream;

/** Real PostgreSQL catalog/guards with synthetic canonical object storage. No search is performed. */
final class Fixture
{
    public D\IrDocument $ir;
    public D\DocumentRevisionRef $revision;
    public D\DocumentId $documentId;
    public PdoNavigationStore $store;
    public SourceNavigation $service;
    private int $next = 31000;

    public function __construct(
        public Upstream $upstream,
    ) {
        [$this->ir, $this->revision, $this->documentId] = self::document();
        $this->seed();
        $this->store = new PdoNavigationStore($upstream->db(), $upstream->lexical->catalog->tx,
            $upstream->usages, PortFixture::validator(), $upstream->host);
        $upstream->host->binding = $this->store->plan($this->scope(), $this->revision)->guards->binding();
        $upstream->host->eligible = [$this->revision->sourceRef->sourceVersionId];
        $this->service = new SourceNavigation($upstream->host, $upstream->owner, $upstream->checks(), $this->store,
            new PdoCanonicalDocuments($upstream->db(), $upstream->objects, PortFixture::validator()),
            $upstream->host, $upstream->host);
    }

    public static function run(callable $test): void
    {
        Upstream::run(static fn (Upstream $upstream) => $test(new self($upstream)));
    }

    public static function corpus(): array
    {
        return json_decode(file_get_contents(PortFixture::root() . '/tests/fixtures/navigation/v1/corpus.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{D\IrDocument,D\DocumentRevisionRef,D\DocumentId} */
    public static function document(): array
    {
        $case = self::corpus();
        $base = PortFixture::fixture()->get('history')[0]->get('ir');
        $texts = $case['texts'];
        $late = $case['late'];
        $texts['late'] = str_repeat($late['prefix'], $late['prefixCount']) . $late['quote'] . str_repeat($late['suffix'], $late['suffixCount']);
        $texts['white'] = $case['whitespace']['canonical'];
        $pages = $segments = $blocks = [];
        foreach ($texts as $id => $text) {
            $page = in_array($id, ['p1', 'p3', 'empty'], true);
            $physical = ['p1' => 1, 'p3' => 3, 'empty' => 4][$id] ?? null;
            $unit = new JsonObject([($page ? 'pageId' : 'segmentId') => $id, 'text' => $text,
                'textDigest' => hash('sha256', $text), 'status' => $text === '' ? 'empty' : 'ok']);
            if ($page) {
                $pages[] = $unit->with('physicalNo', $physical)->with('printedLabel', $id === 'p1' ? 'xii' : '1');
            } else {
                $segments[] = $unit;
            }
            if ($text !== '') {
                $span = $page ? D\TextLocator::page(new D\LocalId($id), $physical, $id === 'p1' ? 'xii' : '1',
                    new D\LocalId('b-' . $id), 0, strlen($text))
                    : D\TextLocator::segment(new D\LocalId($id), new D\LocalId('b-' . $id), 0, strlen($text));
                $blocks[] = new JsonObject(['blockId' => 'b-' . $id, 'kind' => $id === 'p1' ? 'heading' : 'text',
                    'parentId' => $id === 'p3' ? 'b-p1' : null, 'order' => count($blocks), 'origin' => 'source',
                    'spans' => [$span->toWire()], 'provenance' => new JsonObject(['kind' => 'verbatim', 'supportingRefs' => []])]);
            }
        }
        $source = new D\SourceVersionRef(new D\TenantId(PortFixture::id(1)), new D\SourceId(PortFixture::id(1004)),
            new D\SourceVersionId(PortFixture::id(30001)), new D\SourceDigest(hash('sha256', 'navigation-source')));
        $ir = new D\IrDocument($base->with('sourceVersionId', $source->sourceVersionId->value)
            ->with('pages', $pages)->with('segments', $segments)->with('blocks', $blocks)->with('assets', [])
            ->with('coverage', $base->get('coverage')->with('state', 'complete')->with('counts', new JsonObject([
                'expected' => count($texts), 'processed' => count($texts), 'empty' => 1, 'failed' => 0, 'skipped' => 0,
            ]))->with('missingFeatures', [])), PortFixture::validator());
        $revision = new D\DocumentRevisionRef($source, new D\DocumentRevisionId(PortFixture::id(30002)),
            new D\IrDigest(hash('sha256', $ir->canonical())), PortFixture::signature($ir->toWire()->get('parserSignature')));

        return [$ir, $revision, new D\DocumentId(PortFixture::id(30003))];
    }

    public static function canonical(): CanonicalDocument
    {
        [$ir, $revision, $id] = self::document();

        return new CanonicalDocument($ir, $revision, $id);
    }

    public function scope(): D\Scope
    {
        return $this->upstream->host->identity->scope;
    }

    public function count(string $table): int
    {
        return (int) $this->upstream->db()->execute('SELECT count(*) FROM ' . $this->upstream->db()->table($table))->fetchColumn();
    }

    private function blob(string $bytes): string
    {
        $id = PortFixture::id(++$this->next);
        $this->upstream->objects->bodies[hash('sha256', $bytes)] = $bytes;
        $this->upstream->db()->execute('INSERT INTO ' . $this->upstream->db()->table('resource_ledger')
            . " (tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state) VALUES (?,?,?,'owned','test',?,?,?,?, 'accepted')",
            [$this->scope()->tenantId->value, $id, $this->revision->sourceRef->sourceId->value,
                $id, 'v1', hash('sha256', $bytes), strlen($bytes)]);

        return $id;
    }

    private function seed(): void
    {
        $db = $this->upstream->db();
        $r = $this->revision;
        $s = $r->sourceRef;
        $t = $s->tenantId->value;
        $original = $this->blob('navigation-source');
        $irObject = $this->blob($this->ir->canonical());
        $db->execute('INSERT INTO ' . $db->table('source_versions')
            . ' (tenant_id,id,source_id,blob_resource_id,source_digest,byte_length,media_type,rights_ref,idempotency_fingerprint,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$t, $s->sourceVersionId->value, $s->sourceId->value, $original, $s->sourceDigest->value,
                strlen('navigation-source'), 'text/plain', 'synthetic', str_repeat('d', 64), $this->upstream->host->now->value]);
        $db->execute('INSERT INTO ' . $db->table('source_version_state') . " VALUES (?,?,'usable',NULL,NULL)", [$t, $s->sourceVersionId->value]);
        $db->execute('INSERT INTO ' . $db->table('document_revisions')
            . ' (tenant_id,id,source_version_id,parser_name,parser_version,profile_digest,ir_schema,ir_digest,ir_resource_id,coverage_state,coverage_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?::jsonb,?)',
            [$t, $r->documentRevisionId->value, $s->sourceVersionId->value, $r->parserSignature->name, $r->parserSignature->version,
                $r->parserSignature->profileDigest->value, 'ir-1.0', $r->irDigest->value, $irObject, 'complete',
                Jcs::canonicalize($this->ir->toWire()->get('coverage')), $this->upstream->host->now->value]);
        $db->execute('INSERT INTO ' . $db->table('documents') . ' VALUES (?,?,?,?)', [$t, $this->documentId->value, $r->documentRevisionId->value, 'navigation']);
        $this->seedUnits();
        $this->upstream->lexical->catalog->tx->run(function () use ($t, $s, $r): void {
            $tx = $this->upstream->lexical->catalog->tx;
            $tx->lockTenant($s->tenantId, D\LockMode::Update);
            $metadata = new NodeMetadata($this->upstream->db(), $tx);
            $parents = [];
            foreach ([['source_version', $s->sourceVersionId], ['document_revision', $r->documentRevisionId]] as [$kind, $id]) {
                $ref = new D\ResourceRef($s->tenantId, $kind, $id);
                $node = new D\Node($ref, new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($metadata->resolve($ref)['identity'])));
                $this->upstream->dependencies->register($node, new D\Parents($s->tenantId, $parents));
                $parents = [$ref];
            }
        });
    }

    private function seedUnits(): void
    {
        $db = $this->upstream->db();
        $t = $this->scope()->tenantId->value;
        foreach (['page' => $this->ir->pages(), 'segment' => $this->ir->segments()] as $kind => $items) {
            foreach ($items as $index => $unit) {
                $resource = $this->blob($unit->get('text'));
                $values = [$t, PortFixture::id(++$this->next), $this->documentId->value,
                    $unit->get($kind === 'page' ? 'pageId' : 'segmentId')];
                if ($kind === 'page') {
                    $db->execute('INSERT INTO ' . $db->table('pages')
                        . ' (tenant_id,id,document_id,local_page_id,physical_no,printed_label,text_resource_id,text_digest,status) VALUES (?,?,?,?,?,?,?,?,?)',
                        [...$values, $unit->get('physicalNo'), $unit->get('printedLabel'), $resource, $unit->get('textDigest'), $unit->get('status')]);
                    continue;
                }
                $db->execute('INSERT INTO ' . $db->table('segments')
                    . ' (tenant_id,id,document_id,local_segment_id,text_resource_id,text_digest,status,logical_order) VALUES (?,?,?,?,?,?,?,?)',
                    [...$values, $resource, $unit->get('textDigest'), $unit->get('status'), $index]);
            }
        }
    }
}
