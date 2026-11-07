<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogRepository;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Lifecycle\PdoLifecycleStore;
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Application\Catalog\CatalogCommands;
use KnowKit\Application\Catalog\MutationAuthorizer;
use KnowKit\Application\Catalog\SourceCommands;
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Application\Lifecycle\LifecycleCommands;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Local host composition; every CLI operation delegates to the same library use cases. */
final class Runtime
{
    public readonly PdoCatalogTransaction $tx;
    public readonly MutationAuthorizer $authority;
    public readonly DomainEventFactory $events;
    public readonly PdoOutbox $outbox;
    public readonly PdoCatalogRepository $catalog;
    public readonly CatalogCommands $catalogCommands;
    public readonly SourceCommands $sources;
    public readonly PdoDependencyStore $dependencies;
    public readonly LifecycleCommands $lifecycle;
    public readonly Storage $storage;
    public readonly TextDelivery $text;
    public readonly QueryDelivery $queries;

    public function __construct(
        public readonly Host $host,
        string $blobRoot,
    ) {
        $this->tx = new PdoCatalogTransaction($host->db);
        $this->authority = new MutationAuthorizer($host, $host);
        $this->events = new DomainEventFactory($host, $host, $host->validator);
        $this->outbox = new PdoOutbox($host->db, $this->tx);
        $this->catalog = new PdoCatalogRepository($host->db, $this->tx, $this->authority->requireAllow(...),
            $host, $host, $this->events->create(...), $this->outbox, bin2hex(random_bytes(32)));
        $this->catalogCommands = new CatalogCommands($this->catalog, $this->tx, $this->authority);
        $this->sources = new SourceCommands($this->catalog->registerSourceMetadata(...),
            $this->catalog->acceptSourceVersion(...), $this->tx, $this->authority);
        $this->dependencies = new PdoDependencyStore($host->db, $this->tx, $host->scope()->tenantId, $host);
        $store = new PdoLifecycleStore($host->db, $this->tx, $host->scope()->tenantId, $host, $host,
            $host->validator, $this->events->create(...), $this->dependencies, $this->outbox, $this->authority->requireAllow(...));
        $this->lifecycle = new LifecycleCommands($store, $this->catalog, $this->tx, $this->authority,
            $this->catalog->acceptSourceVersion(...));
        $this->storage = new Storage($host, $this->tx, $blobRoot);
        $this->text = new TextDelivery($this);
        $this->queries = new QueryDelivery($this);
    }

    public function dispatch(Command $command): Response
    {
        try {
            return match (true) {
                $command->name === 'kb create' => $this->createKb($command),
                $command->name === 'collection create' => $this->createCollection($command),
                $command->name === 'source register' => $this->register($command),
                $command->name === 'source upload' => $this->text->upload($command),
                str_starts_with($command->name, 'ingest ') => $this->text->ingest($command),
                str_starts_with($command->name, 'publication ') => $this->text->publication($command),
                $command->name === 'search' => $this->queries->search($command),
                $command->name === 'disclose' => $this->queries->disclose($command),
                str_starts_with($command->name, 'read ') => $this->queries->read($command),
                str_starts_with($command->name, 'lifecycle ') => $this->mutateLifecycle($command),
                $command->name === 'purge status' => $this->purgeStatus($command),
                $command->name === 'capabilities' => self::ok(new JsonObject([
                    'profile' => 'postgres-fts-simple-v1', 'formats' => ['text/plain'],
                    'worker' => 'local-synchronous', 'recovery' => false,
                ])),
                default => throw new D\DomainFailure(D\Outcome::Unavailable, 'host_capability_unconfigured'),
            };
        } finally {
            $this->host->bindingOverride = null;
        }
    }

    public static function ok(JsonObject $data): Response
    {
        return Response::result(D\Result::success(new D\FrozenJson($data)), $data);
    }

    public function mutation(Command $command): D\MutationContext
    {
        $wire = new JsonObject(['idempotencyKey' => $command->idempotencyKey()->value,
            'requestId' => $this->host->next()->value]);
        if ($command->expectedEpoch !== null) {
            $wire = $wire->with('expectedEpoch', $command->expectedEpoch->value);
        }
        if ($command->expectedRevision !== null) {
            $wire = $wire->with('expectedRevision', $command->expectedRevision->value);
        }

        return new D\MutationContext($wire, $this->host->validator);
    }

    private function createKb(Command $command): Response
    {
        $scope = $this->host->scope();
        $kb = $this->catalogCommands->createKnowledgeBase($scope,
            new D\CreateKB(new D\KbId($command->text('kbId')), $command->text('name'), 'local-owner:1',
                new D\ProfileId('postgres-fts-simple-v1'), new D\CoveragePolicy()), $this->mutation($command));

        return self::ok(new JsonObject(['kbId' => $kb->kbId->value, 'revision' => $kb->revision->value]));
    }

    private function createCollection(Command $command): Response
    {
        $collection = $this->catalogCommands->createCollection($this->host->scope(),
            new D\CreateCollection(new D\CollectionId($command->text('collectionId')),
                $command->input->has('parentId') ? new D\CollectionId($command->text('parentId')) : null,
                new D\CatalogMetadata()), $this->mutation($command));

        return self::ok(new JsonObject(['collectionId' => $collection->collectionId->value]));
    }

    private function register(Command $command): Response
    {
        $scope = $this->host->scope();
        $source = $this->sources->register($scope, new D\RegisterSource(new D\SourceId($command->text('sourceId')),
            $command->text('ownerRef'), $command->text('origin')), $this->mutation($command));
        // This host attaches each registered source to its configured KB. It is a separate library mutation.
        $membershipKey = 'membership-' . hash('sha256', $command->idempotencyKey()->value);
        $context = new D\MutationContext(new JsonObject(['idempotencyKey' => $membershipKey,
            'requestId' => $this->host->next()->value]), $this->host->validator);
        $this->catalogCommands->addMembership($scope,
            new D\AddMembership($source->sourceId, null, new D\CatalogMetadata()), $context);

        return self::ok(new JsonObject(['sourceId' => $source->sourceId->value,
            'lifecycleEpoch' => $source->lifecycleEpoch->value]));
    }

    private function mutateLifecycle(Command $command): Response
    {
        $scope = $this->host->scope();
        $key = $command->idempotencyKey();
        if ($command->name === 'lifecycle correct') {
            $old = $this->sourceRef($command->input->get('oldVersion'));
            $replacement = $command->input->get('replacement');
            $this->lifecycle->correct($scope, new D\Correction($old,
                $replacement === null ? null : $this->sourceRef($replacement), $command->epoch()), $key);

            return self::ok(new JsonObject(['sourceId' => $old->sourceId->value]));
        }
        $source = new D\SourceId($command->text('sourceId'));
        if ($command->name === 'lifecycle delete') {
            $task = $this->lifecycle->requestPurge($scope, new D\PurgeRequest($source, $command->epoch()), $key);

            return self::ok(new JsonObject(['taskId' => $task->value]));
        }
        if ($command->name === 'lifecycle hold') {
            $this->lifecycle->placeLegalHold($scope, new D\LegalHold($source,
                $command->input->get('enabled'), $command->epoch()), $key);
        } else {
            $this->lifecycle->revoke($scope, new D\Revocation($source, $command->epoch(), $command->text('reason')), $key);
        }

        return self::ok(new JsonObject(['sourceId' => $source->value, 'lifecycleEpoch' => $command->epoch()->next()->value]));
    }

    private function purgeStatus(Command $command): Response
    {
        $scope = $this->host->scope();
        $this->host->decide($scope, new D\ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId),
            D\Operation::LifecycleDelete)->requireAllow($scope,
                new D\ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId), D\Operation::LifecycleDelete,
                $this->host->now(), new D\Sha256(\KnowKit\Domain\Contract\HashDomain::PolicyBinding->digest($this->host->binding([]))));
        $store = new \KnowKit\Adapter\Postgres\Deletion\PdoDeletionStore($this->host->db, $this->tx,
            $scope->tenantId, $this->host, $this->host->validator);

        return self::ok(new JsonObject(['taskId' => $command->text('taskId'),
            'state' => $store->aggregate($scope->tenantId, new D\DeletionTaskId($command->text('taskId')))]));
    }

    private function sourceRef(JsonObject $wire): D\SourceVersionRef
    {
        if (count($wire) !== 4) {
            throw new \KnowKit\Adapter\Cli\UsageError();
        }

        return new D\SourceVersionRef(new D\TenantId($wire->get('tenantId')), new D\SourceId($wire->get('sourceId')),
            new D\SourceVersionId($wire->get('sourceVersionId')), new D\SourceDigest($wire->get('sourceDigest')));
    }
}
