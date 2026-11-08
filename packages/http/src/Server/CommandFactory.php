<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\Clock;

final readonly class CommandFactory
{
    public const MAX_UPLOAD_BYTES = 1048576;

    public function __construct(
        private ContractValidator $validator,
        private Clock $clock,
    ) {}

    public function create(
        Request $request,
        Route $route,
        array $parameters,
        JsonObject $body,
        D\Ulid $requestId,
        AccessContext $context,
    ): Command {
        $this->assertRefs(value: $body, scope: $context->scope());
        $mutation = $this->mutation(request: $request, route: $route, requestId: $requestId);
        $filters = $body->get('filters') ?? new JsonObject();
        $search = $route->operation === 'search';
        if ($search && ($body->get('limit') ?? 64) > 100) {
            throw new InputFailure();
        }

        return new Command(
            route: $route, parameters: $parameters, body: $body, requestId: $requestId, mutation: $mutation,
            query: $search ? new D\Query(
                text: $body->get('query'),
                sourceVersionIds: $this->ids(values: $filters->get('sourceVersionIds'), class: D\SourceVersionId::class),
                collectionIds: $this->ids(values: $filters->get('collectionIds'), class: D\CollectionId::class),
            ) : null,
            limit: $search ? new D\Limit($body->get('limit') ?? 64) : null,
            disclosure: $route->operation === 'disclose' ? new D\DisclosureRequest($body, $this->validator) : null,
            read: $this->read(route: $route, parameters: $parameters, body: $body, requestId: $requestId),
            upload: $route->operation === 'addVersion'
                ? $this->upload(body: $body, source: $parameters['source'], context: $context) : null,
        );
    }

    private function mutation(Request $request, Route $route, D\Ulid $requestId): ?D\MutationContext
    {
        $key = $request->header('Idempotency-Key');
        $epoch = $request->header('Expected-Epoch');
        $revision = $request->header('Expected-Revision');
        if ((!$route->mutation && ($key !== null || $epoch !== null || $revision !== null))
            || ($route->mutation && $key === null) || ($route->epoch && $epoch === null)
            || ($route->revision && $revision === null) || (!$route->revisionAllowed && $revision !== null)) {
            throw new InputFailure();
        }
        if (!$route->mutation) {
            return null;
        }
        $wire = new JsonObject(['idempotencyKey' => (new D\IdempotencyKey($key))->value, 'requestId' => $requestId->value]);
        if ($epoch !== null) {
            $wire = $wire->with('expectedEpoch', (new D\Decimal($epoch))->value);
        }
        if ($revision !== null) {
            $wire = $wire->with('expectedRevision', (new D\Decimal($revision))->value);
        }

        return new D\MutationContext($wire, $this->validator);
    }

    private function read(Route $route, array $parameters, JsonObject $body, D\Ulid $requestId): ?D\Reading\ReadRequest
    {
        $target = match ($route->operation) {
            'resolveCitation' => D\Reading\ReadTarget::quote(),
            'readSource' => D\Reading\ReadTarget::source(),
            'readPage' => D\Reading\ReadTarget::page(
                id: $body->has('pageId') ? new D\LocalId($body->get('pageId')) : null,
                physicalNo: $body->get('physicalNo'), printedLabel: $body->get('printedLabel'),
            ),
            'readRegion' => D\Reading\ReadTarget::region(
                pageId: new D\LocalId($body->get('pageId')),
                region: new D\Bbox(...iterator_to_array($body->get('bbox'))),
            ),
            'readTable' => D\Reading\ReadTarget::table(new D\LocalId($body->get('tableId'))),
            'readAsset' => D\Reading\ReadTarget::asset(new D\LocalId($body->get('assetId'))),
            default => null,
        };
        if ($target === null) {
            return null;
        }

        return new D\Reading\ReadRequest(
            citationId: $parameters['opaque'] ?? new D\Ulid($body->get('citationId')),
            attemptId: $route->operation === 'resolveCitation' ? $requestId : new D\Ulid($body->get('attemptId')),
            target: $target, limits: D\SelectionLimits::profile($body->get('profile') ?? 'visual'),
            maxBytes: $body->get('maxBytes') ?? 1048576, expiresAt: $this->clock->now()->plusSeconds(30),
        );
    }

    private function upload(JsonObject $body, D\Ulid $source, AccessContext $context): D\SourceBytes
    {
        $bytes = base64_decode($body->get('contentBase64'), true);
        if ($bytes === false || base64_encode($bytes) !== $body->get('contentBase64')) {
            throw new InputFailure();
        }
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new InputFailure(status: 413, errorCode: 'body_limit');
        }

        return new D\SourceBytes(
            ref: new D\SourceVersionRef(
                tenantId: $context->scope()->tenantId, sourceId: new D\SourceId($source->value),
                sourceVersionId: new D\SourceVersionId($body->get('sourceVersionId')),
                sourceDigest: new D\SourceDigest($body->get('sourceDigest')),
            ),
            bytes: $bytes, mediaType: $body->get('mediaType'),
        );
    }

    private function assertRefs(mixed $value, D\Scope $scope): void
    {
        if ($value instanceof JsonObject) {
            if ($value->has('tenantId') && $value->get('tenantId') !== $scope->tenantId->value) {
                throw new D\DomainFailure(outcome: D\Outcome::Denied, errorCode: 'scope_mismatch');
            }
            foreach ($value as $child) {
                $this->assertRefs(value: $child, scope: $scope);
            }
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->assertRefs(value: $child, scope: $scope);
            }
        }
    }

    private function ids(?array $values, string $class): ?array
    {
        return $values === null ? null : array_map(static fn (string $id): D\Ulid => new $class($id), $values);
    }
}
