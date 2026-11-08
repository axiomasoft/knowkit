<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Embedding;

use KnowKit\Application\Embeddings\ConfiguredProvider;
use KnowKit\Application\Embeddings\ProviderAuthorization;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Embeddings\ProviderLimits;
use KnowKit\Domain\Embeddings\ProviderResponse;
use KnowKit\Domain\Embeddings\ReceiptStatus;
use KnowKit\Domain\Embeddings\RequestReceipt;
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;

final class MemoryReceipts implements P\EmbeddingReceiptStore
{
    /** @var list<RequestReceipt> */
    public array $records = [];

    public function begin(D\Scope $scope, D\Fingerprint $request, D\Fingerprint $space): RequestReceipt
    {
        $receipt = new RequestReceipt(RequestReceipt::scopeDigest($scope), $request, $space, count($this->records) + 1);
        $this->records[] = $receipt;

        return $receipt;
    }

    public function complete(D\Scope $scope, RequestReceipt $receipt): void
    {
        $receipt->assertScope($scope);
        foreach ($this->records as $existing) {
            if ($existing->requestDigest->equals($receipt->requestDigest)
                && $existing->status === ReceiptStatus::Accepted && $receipt->status === ReceiptStatus::Accepted
                && !$existing->resultFingerprint->equals($receipt->resultFingerprint)) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'embedding_result_conflict');
            }
        }
        $this->records[$receipt->attempt - 1] = $receipt;
    }

    public function receipts(D\Scope $scope, D\Fingerprint $request): array
    {
        return array_values(array_filter($this->records, static fn(RequestReceipt $r): bool =>
            $r->scopeDigest->equals(RequestReceipt::scopeDigest($scope)) && $r->requestDigest->equals($request)));
    }
}

final class FailingReceipts implements P\EmbeddingReceiptStore
{
    public bool $failBegin = true;

    public function __construct(private MemoryReceipts $inner) {}

    public function begin(D\Scope $scope, D\Fingerprint $request, D\Fingerprint $space): RequestReceipt
    {
        if ($this->failBegin) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'storage_unavailable');
        }

        return $this->inner->begin($scope, $request, $space);
    }

    public function complete(D\Scope $scope, RequestReceipt $receipt): void
    {
        throw new D\DomainFailure(D\Outcome::Unavailable, 'storage_unavailable');
    }

    public function receipts(D\Scope $scope, D\Fingerprint $request): array
    {
        return $this->inner->receipts($scope, $request);
    }
}

final class Policy implements P\AccessPolicy, P\EgressPolicy, P\Clock
{
    public D\Decision $processing = D\Decision::Allow;
    public D\Decision $outbound = D\Decision::Allow;
    public D\Decision $publish = D\Decision::Allow;
    public bool $throwEgress = false;
    public bool $changeBinding = false;
    public int $egressCalls = 0;
    public array $operations = [];

    public function now(): D\UtcInstant
    {
        return new D\UtcInstant('2026-11-06T12:00:01Z');
    }

    public function authorizeQuery(D\Scope $scope): D\QueryGrant
    {
        throw new \LogicException('Not used by embedding');
    }

    public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
    {
        $this->operations[] = $operation;
        $decision = $operation === D\Operation::Publish ? $this->publish : $this->processing;

        return $this->receipt($scope, $operation, [$resource], $decision);
    }

    public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
    {
        $this->egressCalls++;
        if ($this->throwEgress) {
            throw new \RuntimeException('secret provider input');
        }
        $resources = [];
        foreach ($request->toWire()->get('items') as $item) {
            foreach ($item->get('sourceVersionRefs') as $ref) {
                $resources[$ref->get('sourceVersionId')] = new D\ResourceRef($scope->tenantId, 'source_version', new D\SourceVersionId($ref->get('sourceVersionId')));
            }
        }
        if ($resources === []) {
            $resources[] = new D\ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId);
        }

        return $this->receipt($scope, D\Operation::from($request->toWire()->get('operation')), array_values($resources), $this->outbound);
    }

    private function receipt(D\Scope $scope, D\Operation $operation, array $resources, D\Decision $decision): D\DecisionReceipt
    {
        $binding = PortFixture::binding($scope);
        if ($this->changeBinding) {
            $binding = $binding->with('tenantPolicyEpoch', '8');
        }
        $wire = new JsonObject([
            'decision' => $decision->value, 'operation' => $operation->value,
            'resourceRefs' => array_map(static fn(D\ResourceRef $r): JsonObject => $r->toWire(), $resources),
            'binding' => $binding, 'ownerRevision' => 'current-owner',
            'checkedAt' => '2026-11-06T12:00:00Z', 'expiresAt' => '2026-11-06T12:00:05Z',
            'hostScopeHash' => $scope->hostScopeHash->value,
        ]);

        return new D\DecisionReceipt($wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)), PortFixture::validator());
    }
}

final class Transport implements P\EmbeddingTransport
{
    public array $seen = [];
    public ?\Closure $respond = null;

    public function embed(array $texts, D\SpaceRef $space, string $role): ProviderResponse
    {
        $this->seen[] = ['texts' => $texts, 'space' => $space, 'role' => $role];
        if ($this->respond !== null) {
            return ($this->respond)($texts, $space, $role);
        }

        return new ProviderResponse($space, $role, array_fill(0, count($texts), [1.0, 0.0, 0.0]), 'remote-1');
    }
}

final class Fixture
{
    public Policy $policy;
    public Transport $transport;
    public MemoryReceipts $store;

    public function __construct()
    {
        $this->policy = new Policy();
        $this->transport = new Transport();
        $this->store = new MemoryReceipts();
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function corpus(): array
    {
        return json_decode(file_get_contents(self::root() . '/tests/fixtures/embeddings/corpus.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function space(array $changes = []): D\SpaceRef
    {
        return new D\SpaceRef(...array_replace(self::corpus()['space'], $changes));
    }

    public static function inputs(): array
    {
        return [new D\EmbeddingInput(PortFixture::chunk(), 'provider input')];
    }

    public static function access(?array $inputs = null, ?D\Scope $scope = null): D\EmbeddingAccess
    {
        $items = [];
        foreach ($inputs ?? [new D\QueryInput('provider input')] as $input) {
            $document = $input instanceof D\EmbeddingInput;
            $items[] = new JsonObject([
                'category' => $document ? 'DOCUMENT_CONTENT' : 'QUERY',
                'contentDigest' => $input->digest()->value,
                'sourceVersionRefs' => $document ? [$input->ref->revisionRef->sourceRef->toWire()] : [],
            ]);
        }
        $wire = new JsonObject([
            'operation' => $inputs === null ? 'retrieve' : 'ingest',
            'destination' => new JsonObject(['providerId' => 'recorded', 'accountId' => 'test', 'region' => 'local', 'modelId' => 'deterministic']),
            'items' => $items,
        ]);

        return new D\EmbeddingAccess($scope ?? PortFixture::scope(),
            new D\EgressRequest($wire->with('manifestDigest', hash('sha256', Jcs::canonicalize($wire))), PortFixture::validator()));
    }

    public function provider(?ProviderLimits $limits = null, ?P\EmbeddingTransport $transport = null, ?P\EmbeddingReceiptStore $store = null): ConfiguredProvider
    {
        return new ConfiguredProvider(self::space(), $limits ?? new ProviderLimits(4, 64, 128),
            new ProviderAuthorization($this->policy, $this->policy, $this->policy),
            $transport ?? $this->transport, $store ?? $this->store);
    }
}
