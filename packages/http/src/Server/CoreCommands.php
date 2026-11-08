<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Catalog\CatalogCommands;
use KnowKit\Application\Catalog\SourceCommands;
use KnowKit\Application\Lifecycle;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Ready-to-use catalog/lifecycle mapping. Other host capabilities keep their existing owners. */
final readonly class CoreCommands implements HostApplication
{
    public function __construct(
        private HostApplication $remaining,
        private CatalogCommands $catalog,
        private SourceCommands $sources,
        private Lifecycle $lifecycle,
    ) {}

    public function execute(Command $command, AccessContext $context): Reply
    {
        $context->assertCurrent();
        return match ($command->route->operation) {
            'createKnowledgeBase' => $this->createKnowledgeBase(command: $command, context: $context),
            'createCollection' => $this->createCollection(command: $command, context: $context),
            'addMembership' => $this->addMembership(command: $command, context: $context),
            'registerSource' => $this->registerSource(command: $command, context: $context),
            'correct', 'revoke', 'requestPurge', 'placeLegalHold' => $this->lifecycle(command: $command, context: $context),
            default => $this->remaining->execute(command: $command, context: $context),
        };
    }

    private function createKnowledgeBase(Command $command, AccessContext $context): Reply
    {
        $body = $command->body();
        $kb = $this->catalog->createKnowledgeBase(
            scope: $context->scope(),
            command: new D\CreateKB(
                kbId: $context->scope()->kbId, name: $body->get('name'), policyRef: $body->get('policyRef'),
                defaultRetrievalProfile: new D\ProfileId($body->get('defaultRetrievalProfile')),
                coveragePolicy: new D\CoveragePolicy(allowPartial: $body->get('allowPartial') ?? false),
            ),
            context: $command->mutation,
        );

        return Reply::success(new JsonObject(['kbId' => $kb->kbId->value, 'revision' => $kb->revision->value]));
    }

    private function createCollection(Command $command, AccessContext $context): Reply
    {
        $body = $command->body();
        $collection = $this->catalog->createCollection(
            scope: $context->scope(),
            command: new D\CreateCollection(
                collectionId: new D\CollectionId($body->get('collectionId')),
                parentId: $body->has('parentId') ? new D\CollectionId($body->get('parentId')) : null,
                metadata: new D\CatalogMetadata($body->get('metadata') ?? new JsonObject()),
            ),
            context: $command->mutation,
        );

        return Reply::success(new JsonObject(['collectionId' => $collection->collectionId->value, 'revision' => $collection->revision->value]));
    }

    private function addMembership(Command $command, AccessContext $context): Reply
    {
        $body = $command->body();
        $member = $this->catalog->addMembership(
            scope: $context->scope(),
            command: new D\AddMembership(
                sourceId: new D\SourceId($body->get('sourceId')),
                collectionId: $body->has('collectionId') ? new D\CollectionId($body->get('collectionId')) : null,
                metadata: new D\CatalogMetadata($body->get('metadata') ?? new JsonObject()),
            ),
            context: $command->mutation,
        );

        return Reply::success(new JsonObject(['sourceId' => $member->sourceId->value, 'revision' => $member->revision->value]));
    }

    private function registerSource(Command $command, AccessContext $context): Reply
    {
        $body = $command->body();
        $source = $this->sources->register(
            scope: $context->scope(),
            command: new D\RegisterSource(
                sourceId: new D\SourceId($body->get('sourceId')), ownerRef: $body->get('ownerRef'), origin: $body->get('origin'),
            ),
            context: $command->mutation,
        );

        return Reply::success(new JsonObject(['sourceId' => $source->sourceId->value, 'lifecycleEpoch' => $source->lifecycleEpoch->value]));
    }

    private function lifecycle(Command $command, AccessContext $context): Reply
    {
        $body = $command->body();
        $epoch = $command->mutation->expectedEpoch();
        $scope = $context->scope();
        $key = $command->mutation->idempotencyKey();
        if ($command->route->operation === 'correct') {
            $this->correct(command: $command, scope: $scope, epoch: $epoch, key: $key);

            return Reply::success(new JsonObject());
        }
        $source = new D\SourceId($body->get('sourceId'));
        if ($command->route->operation === 'requestPurge') {
            $task = $this->lifecycle->requestPurge(scope: $scope, command: new D\PurgeRequest($source, $epoch), key: $key);

            return Reply::success(new JsonObject(['taskId' => $task->value]));
        }
        $this->setLifecycle(command: $command, scope: $scope, source: $source, epoch: $epoch, key: $key);

        return Reply::success(new JsonObject());
    }

    private function correct(Command $command, D\Scope $scope, D\Decimal $epoch, D\IdempotencyKey $key): void
    {
        $body = $command->body();
        $this->lifecycle->correct(
            scope: $scope,
            command: new D\Correction(
                oldVersion: $this->sourceRef($body->get('oldVersion')),
                replacement: $body->has('replacement') ? $this->sourceRef($body->get('replacement')) : null,
                expectedLifecycleEpoch: $epoch,
            ),
            key: $key,
        );
    }

    private function setLifecycle(Command $command, D\Scope $scope, D\SourceId $source, D\Decimal $epoch, D\IdempotencyKey $key): void
    {
        $body = $command->body();
        if ($command->route->operation === 'revoke') {
            $this->lifecycle->revoke(
                scope: $scope, command: new D\Revocation(sourceId: $source, expectedLifecycleEpoch: $epoch, reason: $body->get('reason')), key: $key,
            );

            return;
        }
        $this->lifecycle->placeLegalHold(
            scope: $scope, command: new D\LegalHold(sourceId: $source, enabled: $body->get('enabled'), expectedLifecycleEpoch: $epoch), key: $key,
        );
    }

    private function sourceRef(JsonObject $wire): D\SourceVersionRef
    {
        return new D\SourceVersionRef(
            tenantId: new D\TenantId($wire->get('tenantId')), sourceId: new D\SourceId($wire->get('sourceId')),
            sourceVersionId: new D\SourceVersionId($wire->get('sourceVersionId')), sourceDigest: new D\SourceDigest($wire->get('sourceDigest')),
        );
    }
}
