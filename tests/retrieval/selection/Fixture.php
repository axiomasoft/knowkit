<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Selection;

use KnowKit\Adapter\Memory\InMemorySelections;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Access\ReceiptFactory;
use KnowKit\Application\Chunking\StructuralChunker;
use KnowKit\Application\Publication\Manifest\Builder;
use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;

/** Deterministic host, canonical corpus and fault-injectable boundaries; no provider simulation. */
final class Fixture implements HostIdentity, P\AccessPolicy, P\EgressPolicy, P\Clock, P\IdGenerator, P\LexicalIndex, P\SelectionCatalog
{
    public D\UtcInstant $now;
    public PolicyContext $identity;
    public JsonObject $binding;
    public D\SelectionInventory $inventory;
    public D\AuthorizedSearchScope $lastSearch;
    public InMemorySelections $store;
    public int $searchCalls = 0;
    public int $queryChecks = 0;
    public ?D\DomainFailure $failure = null;
    public ?\Closure $duringSearch = null;
    public ?array $candidates = null;
    public D\Decision $decision = D\Decision::Allow;
    public array $documents = [];
    public array $eligible = [];
    private int $id = 9000;

    public function __construct(string $document = 'plain')
    {
        $this->now = new D\UtcInstant('2026-11-08T00:00:00Z');
        $scope = new D\Scope(new D\TenantId(self::id(1)), new D\KbId(self::id(100)),
            new D\ActorId(self::id(101)), 'lesson.read', new D\Decimal('0'), new D\Sha256(str_repeat('a', 64)));
        $this->identity = new PolicyContext($scope, 'teacher', ['source:read']);
        $this->binding = new JsonObject(['tenantPolicyEpoch' => '0', 'kbPolicyEpoch' => '0',
            'sources' => [new JsonObject(['sourceId' => self::id(2), 'sourcePolicyEpoch' => '0', 'lifecycleEpoch' => '0'])]]);
        $this->store = new InMemorySelections();
        $this->eligible = [new D\SourceVersionId(self::id(3))];
        $this->inventory = $this->build($document);
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function id(int $id): string
    {
        return PortFixture::id($id);
    }

    public static function corpus(): array
    {
        return json_decode(file_get_contents(self::root() . '/tests/fixtures/retrieval/selection/corpus.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function current(): PolicyContext
    {
        return $this->identity;
    }

    public function now(): D\UtcInstant
    {
        return $this->now;
    }

    public function next(): D\Ulid
    {
        return new D\Ulid(self::id(++$this->id));
    }

    public function context(): AccessContext
    {
        return AccessContext::fromHost($this);
    }

    public function checks(): PolicyChecks
    {
        return new PolicyChecks($this, $this, $this);
    }

    public function service(?P\Tokenizer $tokenizer = null, ?P\SelectionStore $store = null): Selections
    {
        return new Selections($this->checks(), $this, $this, $store ?? $this->store,
            new BoundedSelector($tokenizer), $this, $this, PortFixture::validator());
    }

    public function query(?D\SelectionLimits $limits = null, string $text = 'gravity'): D\QueryBinding
    {
        return (new Normalizer())->bind(new D\Query($text), self::profile(), $limits ?? D\SelectionLimits::profile('compact'));
    }

    public static function profile(): D\EffectiveProfile
    {
        return new D\EffectiveProfile('selection-test', '1', ['lexical' => new D\AdapterId('test.lexical')]);
    }

    public function retrieve(?D\QueryBinding $query = null, ?Selections $service = null, ?string $run = 'run-1', int $ttl = 30): D\RetrievalOutcome
    {
        return ($service ?? $this->service())->retrieve($this->context(), $query ?? $this->query(),
            $this->inventory->snapshot, $this->inventory->generationId(),
            $this->eligible,
            $this->binding, $run, $ttl);
    }

    public function authorizeQuery(D\Scope $scope): D\QueryGrant
    {
        ++$this->queryChecks;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new D\QueryGrant(new JsonObject(['allowedCollectionIds' => [],
            'allowedSourceVersionIds' => array_map(static fn (D\SourceVersionId $id): string => $id->value, $this->eligible),
            'grantEpoch' => $scope->policyEpoch->value,
            'policyBindingDigest' => HashDomain::PolicyBinding->digest($this->binding),
            'expiresAt' => $this->now->plusSeconds(30)->value]), PortFixture::validator());
    }

    public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
    {
        return (new ReceiptFactory(PortFixture::validator(), $this))->issue($this->decision, $scope,
            [$resource], $operation, $this->binding, 'owner:1');
    }

    public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
    {
        throw new \LogicException('Selection has no egress');
    }

    public function index(D\GenerationId $generationId, D\ChunkSet $chunkSet): D\IndexReceipt
    {
        throw new \LogicException('Use actual P2.8 adapter for indexing');
    }

    public function search(D\AuthorizedSearchScope $scope, D\Query $query, int $k): D\CandidateList
    {
        ++$this->searchCalls;
        $this->lastSearch = $scope;
        if ($this->duringSearch !== null) {
            ($this->duringSearch)($scope);
        }
        $candidates = $this->candidates;
        if ($candidates === null) {
            $chunks = $this->inventory->manifest->sets[0]->chunkSet->chunks;
            $candidates = array_map(static fn (D\Chunk $chunk): D\Candidate => new D\Candidate($chunk->ref, 1, 1.0), $chunks);
        }

        return new D\CandidateList($scope, array_slice($candidates, 0, $k));
    }

    public function load(D\Scope $scope, D\SnapshotRef $snapshot, D\GenerationId $generation): D\SelectionInventory
    {
        return $this->inventory;
    }

    private function build(string $name): D\SelectionInventory
    {
        $data = array_values(array_filter(self::corpus()['documents'], static fn (array $doc): bool => $doc['name'] === $name))[0];
        $text = $data['text'];
        $ir = $this->ir($data);
        $revision = new D\DocumentRevisionRef(new D\SourceVersionRef($this->identity->scope->tenantId,
            new D\SourceId(self::id(2)), new D\SourceVersionId(self::id(3)), new D\SourceDigest(hash('sha256', $text))),
            new D\DocumentRevisionId(self::id(4)), new D\IrDigest(hash('sha256', $ir->canonical())),
            new D\ParserSignature('selection-fixture', '1', new D\Fingerprint(str_repeat('0', 64))));
        $profile = new D\ChunkerProfile('selection', 'structural', '1', new JsonObject([
            'maxBytes' => $name === 'overlap' ? 9 : 32768, 'overlapBlocks' => $name === 'overlap' ? 1 : 0,
        ]));
        $set = (new StructuralChunker($revision, new D\DocumentId(self::id(5))))->build($ir, $profile);
        $manifest = (new Builder(PortFixture::validator()))->build($this->identity->scope, new D\GenerationId(self::id(14)),
            [$set], [], $ir->toWire()->get('coverage'));
        $snapshot = new D\SnapshotRef($this->identity->scope->tenantId, $this->identity->scope->kbId,
            new D\SnapshotId(self::id(110)), new D\Fingerprint(HashDomain::PublicationSnapshotManifest->digest(new JsonObject([
                'tenantId' => self::id(1), 'kbId' => self::id(100), 'generations' => [new JsonObject([
                    'generationId' => self::id(14), 'manifestDigest' => $manifest->digest->value,
                    'members' => $manifest->manifest->toWire()->get('members'),
                ])],
            ]))));
        $this->documents = [$revision->irDigest->value => $ir];

        return (new CanonicalUnits())->build($snapshot, $manifest, $this->documents);
    }

    private function ir(array $data): D\IrDocument
    {
        $text = $data['text'];
        $blocks = [$this->block('b1', 'text', 0, strlen($text))];
        $assets = [];
        if ($data['kind'] === 'overlap') {
            $blocks = [$this->block('b1', 'text', 0, 4), $this->block('b2', 'text', 4, 8), $this->block('b3', 'text', 8, 12)];
        }
        if ($data['kind'] === 'table') {
            $blocks = $this->table($text);
        }
        if ($data['kind'] === 'visual') {
            $blocks = [];
            $offset = 0;
            foreach (explode("\n", $text) as $index => $line) {
                $asset = 'a' . $index;
                $blocks[] = $this->block('b' . $index, 'image', $offset, $offset + strlen($line)) + ['assetRefs' => [$asset]];
                $assets[] = ['assetId' => $asset, 'mediaType' => 'image/png', 'digest' => hash('sha256', $asset), 'byteLength' => '3', 'pageId' => null];
                $offset += strlen($line) + 1;
            }
        }
        foreach ($blocks as $index => &$block) {
            $block['order'] = $index;
        }
        unset($block);
        $wire = ['schemaVersion' => 'ir-1.0', 'tenantId' => self::id(1), 'sourceVersionId' => self::id(3),
            'parserSignature' => ['name' => 'selection-fixture', 'version' => '1', 'profileDigest' => str_repeat('0', 64)],
            'pages' => [], 'segments' => [['segmentId' => 's1', 'text' => $text, 'textDigest' => hash('sha256', $text), 'status' => 'ok']],
            'blocks' => $blocks, 'assets' => $assets, 'coverage' => ['state' => 'complete',
                'counts' => ['expected' => 1, 'processed' => 1, 'empty' => 0, 'failed' => 0, 'skipped' => 0],
                'missingFeatures' => [], 'diagnostics' => []]];

        return new D\IrDocument(StrictJson::decode(json_encode($wire, JSON_THROW_ON_ERROR)), PortFixture::validator());
    }

    private function block(string $id, string $kind, int $start, int $end): array
    {
        return ['blockId' => $id, 'kind' => $kind, 'parentId' => null, 'order' => 0, 'origin' => 'source',
            'spans' => [['kind' => 'segment', 'segmentId' => 's1', 'blockId' => $id, 'byteStart' => $start, 'byteEnd' => $end]],
            'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []]];
    }

    private function table(string $text): array
    {
        $end = strrpos($text, "\n");
        $heading = $this->block('heading', 'heading', 0, 7);
        $table = $this->block('table', 'table', 8, $end);
        $table['parentId'] = 'heading';
        $header = $table['spans'][0];
        $header['byteEnd'] = 12;
        $row = $table['spans'][0];
        $row['byteStart'] = 13;
        $cell = ['cellId' => 'h0', 'row' => 0, 'column' => 0, 'rowSpan' => 1, 'colSpan' => 1,
            'header' => true, 'headerRefs' => [], 'unit' => null, 'noteRefs' => [], 'spans' => [$header]];
        $value = array_replace($cell, ['cellId' => 'v1', 'row' => 1, 'header' => false,
            'headerRefs' => ['h0'], 'unit' => 'kg', 'noteRefs' => ['note'], 'spans' => [$row]]);
        $table['table'] = ['rows' => 2, 'columns' => 1, 'cells' => [$cell, $value]];

        return [$heading, $table, $this->block('note', 'text', $end + 1, strlen($text))];
    }
}
