<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Disclosure;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoSelectionCatalog;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Dependencies\NodeMetadata;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use KnowKit\Tests\Retrieval\Selection\Fixture as SelectionFixture;
use PHPUnit\Framework\Assert;

final class Fixture
{
    public SelectionFixture $host;
    public PdoSelectionCatalog $catalog;
    public PdoDependencyStore $dependencies;
    public Selections $selections;
    public PdoUsages $usages;
    public ObjectStore $objects;
    public Owner $owner;
    public Disclosures $service;

    public function __construct(public LexicalFixture $lexical)
    {
        $this->host = new SelectionFixture();
        $this->host->now = self::databaseNow($this->db());
        $this->seed();
        $documents = [];
        $this->objects = new ObjectStore($this->db());
        foreach ($lexical->data as $data) {
            $document = new D\IrDocument(StrictJson::decode(json_encode($data['ir'], JSON_THROW_ON_ERROR)), PortFixture::validator());
            $documents[hash('sha256', $document->canonical())] = $document;
            $this->objects->bodies[hash('sha256', $document->canonical())] = $document->canonical();
            $this->objects->bodies[hash('sha256', $data['text'])] = $data['text'];
        }
        $this->catalog = new PdoSelectionCatalog($this->db(), new CanonicalChunkSets(array_values($lexical->sets)),
            PortFixture::validator(), $documents, new CanonicalUnits());
        $snapshot = new D\SnapshotRef($this->host->identity->scope->tenantId, $this->host->identity->scope->kbId,
            new D\SnapshotId(self::id(112)), new D\Fingerprint(str_repeat('b', 64)));
        $this->host->inventory = $this->catalog->load($this->host->identity->scope, $snapshot, new D\GenerationId(self::id(14)));
        $source = $this->ref()->revisionRef->sourceRef;
        $this->host->eligible = [$source->sourceVersionId];
        $this->host->binding = new JsonObject(['tenantPolicyEpoch' => '0', 'kbPolicyEpoch' => '0',
            'sources' => [new JsonObject(['sourceId' => $source->sourceId->value, 'sourcePolicyEpoch' => '0', 'lifecycleEpoch' => '0'])]]);
        $this->owner = new Owner($this->host, $this->db());
        $store = new PdoSelections($this->db(), new D\SelectionRecordCodec(PortFixture::validator()));
        $this->selections = new Selections($this->checks(), $lexical->index, $this->catalog, $store,
            new BoundedSelector(), $this->host, $this->host, PortFixture::validator());
        $this->usages = new PdoUsages($this->db(), $lexical->catalog->tx, $this->dependencies);
        $canonical = new CanonicalBuffer($this->catalog,
            new PdoCanonicalDocuments($this->db(), $this->objects, PortFixture::validator()), $this->host);
        $this->service = new Disclosures($this->selections, $this->checks(), $canonical,
            $this->usages, $this->host, PortFixture::validator());
    }

    public static function run(callable $test): void
    {
        LexicalFixture::run(static fn (LexicalFixture $lexical) => $test(new self($lexical)));
    }

    public static function id(int $id): string
    {
        return PortFixture::id($id);
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function databaseNow(Connection $db): D\UtcInstant
    {
        $value = $db->execute('SELECT clock_timestamp()')->fetchColumn();

        return new D\UtcInstant((new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function db(): Connection
    {
        return $this->lexical->catalog->db;
    }

    public function checks(): PolicyChecks
    {
        return new PolicyChecks($this->owner, $this->owner, $this->host);
    }

    public function ref(): D\ChunkRef
    {
        return $this->lexical->sets['exact']->chunkSet->chunks[0]->ref;
    }

    public function query(): D\QueryBinding
    {
        return $this->host->query(text: 'DOC-2026/0042');
    }

    public function select(?string $run = 'run-1', int $ttl = 30): D\RetrievalOutcome
    {
        $outcome = $this->host->retrieve($this->query(), $this->selections, $run, $ttl);
        Assert::assertSame(D\Outcome::Success, $outcome->result->outcome, $outcome->result->errorCode ?? 'success');

        return $outcome;
    }

    public function request(D\RetrievalOutcome $selected, ?string $run = 'run-1', ?string $attempt = null): D\DisclosureRequest
    {
        $wire = new JsonObject(['selectionId' => $selected->result->data->id()->value,
            'chunkRefs' => [$this->ref()->toWire()], 'disclosureAttemptId' => $attempt ?? $this->host->next()->value]);
        if ($run !== null) {
            $wire = $wire->with('usageContext', new JsonObject(['runRef' => $run]));
        }

        return new D\DisclosureRequest($wire, PortFixture::validator());
    }

    public function disclose(D\RetrievalOutcome $selected, ?D\DisclosureRequest $request = null): D\DisclosureOutcome
    {
        return $this->service->disclose($this->host->context(), $selected->token,
            $this->query(), $this->host->inventory->snapshot, $request ?? $this->request($selected));
    }

    public function revoke(bool $epoch = true): void
    {
        $source = $this->ref()->revisionRef->sourceRef;
        $this->lexical->catalog->tx->run(function () use ($source, $epoch): void {
            $this->lexical->catalog->tx->lockTenant($source->tenantId, D\LockMode::Update);
            $this->lexical->catalog->tx->lockKb($this->host->identity->scope->kbId, D\LockMode::Update);
            $this->lexical->catalog->tx->lockSources([$source->sourceId], D\LockMode::Update);
            $this->db()->execute('UPDATE ' . $this->db()->table('sources')
                . " SET lifecycle_state='revoked',lifecycle_epoch=lifecycle_epoch+? WHERE tenant_id=? AND id=?",
                [$epoch ? 1 : 0, $source->tenantId->value, $source->sourceId->value]);
        });
    }

    private function seed(): void
    {
        $db = $this->db();
        $db->execute('INSERT INTO ' . $db->table('publication_snapshots') . ' VALUES (?,?,?,?,?,false)',
            [self::id(1), self::id(112), self::id(100), str_repeat('b', 64), $this->host->now->value]);
        $db->execute('INSERT INTO ' . $db->table('snapshot_generations') . ' VALUES (?,?,?,?,?)',
            [self::id(1), self::id(112), self::id(100), self::id(14), 'postgres-fts-simple-v1']);
        $this->dependencies = new PdoDependencyStore($db, $this->lexical->catalog->tx,
            $this->host->identity->scope->tenantId, $this->lexical->catalog->ids);
        foreach ($this->lexical->sets as $result) {
            $set = $result->chunkSet;
            $source = $set->revisionRef->sourceRef;
            if ($source->tenantId->value !== self::id(1)) {
                continue;
            }
            $this->seedMember($set);
            $this->seedGraph($set);
        }
        $db->execute('UPDATE ' . $db->table('publication_snapshots') . ' SET sealed=true WHERE tenant_id=? AND id=?',
            [self::id(1), self::id(112)]);
    }

    private function seedMember(D\ChunkSet $set): void
    {
        $source = $set->revisionRef->sourceRef;
        $db = $this->db();
        $db->execute('INSERT INTO ' . $db->table('snapshot_memberships')
            . ' (tenant_id,id,snapshot_id,kb_id,source_id,source_version_id,revision_id,chunk_set_id,generation_id,profile_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [self::id(1), $this->lexical->catalog->ids->next()->value, self::id(112), self::id(100), $source->sourceId->value,
                $source->sourceVersionId->value, $set->revisionRef->documentRevisionId->value, $set->chunkSetId->value, self::id(14), 'postgres-fts-simple-v1']);
        $db->execute('INSERT INTO ' . $db->table('source_memberships') . ' VALUES (?,?,?,?,NULL,0,?::jsonb)',
            [self::id(1), $this->lexical->catalog->ids->next()->value, $source->sourceId->value, self::id(100), '{}']);
        $db->execute('INSERT INTO ' . $db->table('source_version_state') . " VALUES (?,?,'usable',NULL,NULL)",
            [self::id(1), $source->sourceVersionId->value]);
    }

    private function seedGraph(D\ChunkSet $set): void
    {
        $source = $set->revisionRef->sourceRef;
        $this->lexical->catalog->tx->run(function () use ($source, $set): void {
            $this->lexical->catalog->tx->lockTenant($source->tenantId, D\LockMode::Update);
            $metadata = new NodeMetadata($this->db(), $this->lexical->catalog->tx);
            $refs = [new D\ResourceRef($source->tenantId, 'source_version', $source->sourceVersionId),
                new D\ResourceRef($source->tenantId, 'document_revision', $set->revisionRef->documentRevisionId),
                new D\ResourceRef($source->tenantId, 'chunk', $set->chunks[0]->ref->chunkId)];
            $parents = [];
            foreach ($refs as $ref) {
                $node = new D\Node($ref, new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($metadata->resolve($ref)['identity'])));
                $this->dependencies->register($node, new D\Parents($source->tenantId, $parents));
                $parents = [$ref];
            }
        });
    }
}

final class Owner implements P\AccessPolicy, P\EgressPolicy
{
    public int $calls = 0;
    public ?\Closure $beforeDecision = null;

    public function __construct(
        private SelectionFixture $host,
        private Connection $db,
    ) {}

    public function authorizeQuery(D\Scope $scope): D\QueryGrant
    {
        $this->outside();

        return $this->host->authorizeQuery($scope);
    }

    public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
    {
        $this->outside();
        ++$this->calls;
        if ($this->beforeDecision !== null) {
            ($this->beforeDecision)($resource);
        }

        return $this->host->decide($scope, $resource, $operation);
    }

    public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
    {
        throw new \LogicException('Disclosure cannot call a provider');
    }

    private function outside(): void
    {
        Assert::assertFalse($this->db->pdo->inTransaction(), 'Owner callback under SQL guards');
    }
}

final class ObjectStore implements P\BlobStore
{
    public array $bodies = [];
    public ?\Closure $beforeOpen = null;
    public int $reads = 0;
    public int $closes = 0;

    public function __construct(private Connection $db) {}

    public function open(D\BlobRef $ref): P\ReadableStream
    {
        Assert::assertFalse($this->db->pdo->inTransaction(), 'Object I/O under SQL guards');
        ++$this->reads;
        if ($this->beforeOpen !== null) {
            $callback = $this->beforeOpen;
            $this->beforeOpen = null;
            $callback();
        }
        $bytes = $this->bodies[$ref->digest->value] ?? throw new D\DomainFailure(D\Outcome::Unavailable, 'canonical_object_missing');

        return new ObjectStream($bytes, function (): void { ++$this->closes; });
    }

    public function putImmutable(D\BlobWriteReservation $reservation, P\ReadableStream $stream): D\BlobRef
    {
        throw new \LogicException('Read-only fixture');
    }

    public function exists(D\BlobRef $ref): bool
    {
        return isset($this->bodies[$ref->digest->value]);
    }

    public function listUnreferenced(D\UtcInstant $before, D\Cursor $cursor): D\ResourcePage
    {
        throw new \LogicException('Read-only fixture');
    }

    public function delete(D\ResourceRef $resource, D\AttemptId $attemptId): D\DeletionReceipt
    {
        throw new \LogicException('Read-only fixture');
    }
}

final class ObjectStream implements P\ReadableStream
{
    private int $offset = 0;

    public function __construct(
        private string $bytes,
        private \Closure $onClose,
    ) {}

    public function read(int $maxBytes): string
    {
        $part = substr($this->bytes, $this->offset, $maxBytes);
        $this->offset += strlen($part);

        return $part;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->bytes);
    }

    public function close(): void
    {
        ($this->onClose)();
    }
}
