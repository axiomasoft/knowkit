<?php

declare(strict_types=1);

namespace KnowKit\Application\Embeddings;

use KnowKit\Domain\Capability;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\EmbeddingAccess;
use KnowKit\Domain\EmbeddingBatch;
use KnowKit\Domain\EmbeddingCapabilities;
use KnowKit\Domain\EmbeddingInput;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\QueryInput;
use KnowKit\Domain\Result;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\SpaceRef;
use KnowKit\Domain\Vector;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Embeddings\ProviderLimits;
use KnowKit\Domain\Embeddings\ProviderResponse;
use KnowKit\Domain\Embeddings\ProviderTimeout;
use KnowKit\Domain\Embeddings\ReceiptStatus;
use KnowKit\Domain\Embeddings\RequestReceipt;
use KnowKit\Port\EmbeddingProvider;
use KnowKit\Port\EmbeddingReceiptStore;
use KnowKit\Port\EmbeddingTransport;

/** One attempt per call. Retries are an explicit caller decision with a new receipt. */
final readonly class ConfiguredProvider implements EmbeddingProvider
{
    public function __construct(
        private SpaceRef $configuredSpace,
        private ProviderLimits $limits,
        private ProviderAuthorization $authorization,
        private EmbeddingTransport $transport,
        private EmbeddingReceiptStore $receipts,
    ) {}

    public function capabilities(): EmbeddingCapabilities
    {
        return new EmbeddingCapabilities([Capability::EmbeddingDocuments, Capability::EmbeddingQuery], ['ir-1.0']);
    }

    /** @phpstan-impure */
    public function embedDocuments(EmbeddingAccess $access, array $inputs, SpaceRef $space): Result
    {
        try {
            $access->assertDocuments($inputs, $space);
            $identities = array_map(static fn(EmbeddingInput $i): mixed => $i->ref->toWire(), $inputs);
            $texts = array_map(static fn(EmbeddingInput $i): string => $i->text(), $inputs);

            return $this->execute($access, $texts, $identities, $space, false);
        } catch (DomainFailure $failure) {
            return Result::failure($failure);
        } catch (\InvalidArgumentException) {
            return Result::failure(new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation'));
        }
    }

    /** @phpstan-impure */
    public function embedQuery(EmbeddingAccess $access, QueryInput $input, SpaceRef $space): Result
    {
        try {
            $access->assertQuery($input, $space);

            return $this->execute($access, [$input->text()], [], $space, true);
        } catch (DomainFailure $failure) {
            return Result::failure($failure);
        }
    }

    /**
     * @param list<string> $texts
     * @param list<mixed> $identities
     * @return ($query is true ? Result<Vector> : Result<EmbeddingBatch>)
     */
    private function execute(EmbeddingAccess $access, array $texts, array $identities, SpaceRef $space, bool $query): Result
    {
        try {
            $this->configuredSpace->assertCompatible($space);
            $this->limits->assertInputs($texts);
            $role = $query ? $space->queryRole : $space->documentRole;
            $digest = $this->requestDigest($access, $texts, $identities, $space, $role);
            $binding = $this->authorization->processing($access);
            $attempt = $this->receipts->begin($access->scope, $digest, $space->digest());
        } catch (DomainFailure $failure) {
            return Result::failure($failure);
        } catch (\Throwable) {
            return Result::failure(new DomainFailure(Outcome::Unavailable, 'embedding_unavailable'));
        }

        return $this->transmit($access, $texts, $space, $query, $binding, $attempt);
    }

    /**
     * @param list<string> $texts
     * @return ($query is true ? Result<Vector> : Result<EmbeddingBatch>)
     */
    private function transmit(EmbeddingAccess $access, array $texts, SpaceRef $space, bool $query, Sha256 $binding, RequestReceipt $attempt): Result
    {
        $response = null;
        $dispatched = false;
        try {
            $this->authorization->egress($access, $binding);
            $dispatched = true;
            $role = $query ? $space->queryRole : $space->documentRole;
            $response = $this->transport->embed($texts, $space, $role);

            return $this->accept($access, $response, $space, $query, count($texts), $binding, $attempt);
        } catch (ProviderTimeout $timeout) {
            $failure = new DomainFailure(Outcome::Unknown, 'embedding_request_unknown');
            $requestId = $timeout->providerRequestId;
        } catch (DomainFailure $error) {
            $failure = $error;
            $requestId = $response?->providerRequestId;
        } catch (\Throwable) {
            // A transport exception may occur after remote acceptance. Never log it.
            $failure = new DomainFailure($dispatched ? Outcome::Unknown : Outcome::Unavailable, 'embedding_unavailable');
            $requestId = $response?->providerRequestId;
        }

        return $this->reject($access, $attempt, $failure, $requestId);
    }

    /** @return ($query is true ? Result<Vector> : Result<EmbeddingBatch>) */
    private function accept(
        EmbeddingAccess $access,
        ProviderResponse $response,
        SpaceRef $space,
        bool $query,
        int $count,
        Sha256 $binding,
        RequestReceipt $attempt,
    ): Result {
        $role = $query ? $space->queryRole : $space->documentRole;
        $batch = $response->validated($space, $role, $count);
        $this->authorization->acceptance($access, $binding);
        $receipt = $attempt->finish(ReceiptStatus::Accepted, ProviderResponse::fingerprint($batch, $role), $response->providerRequestId);
        $this->receipts->complete($access->scope, $receipt);

        return $query ? Result::success($batch->vectors[0]) : Result::success($batch);
    }

    /** @return Result<never> */
    private function reject(EmbeddingAccess $access, RequestReceipt $attempt, DomainFailure $failure, ?string $requestId): Result
    {
        $status = $failure->outcome === Outcome::Unknown ? ReceiptStatus::Unknown : ReceiptStatus::Failed;
        try {
            $this->receipts->complete($access->scope, $attempt->finish($status, requestId: $requestId, failure: $failure->outcome));
        } catch (\Throwable) {
            // The durable pre-send unknown remains if final recording fails.
            return Result::failure(new DomainFailure(Outcome::Unavailable, 'embedding_receipt_unavailable'));
        }

        return Result::failure($failure);
    }

    /**
     * @param list<string> $texts
     * @param list<mixed> $identities
     */
    private function requestDigest(EmbeddingAccess $access, array $texts, array $identities, SpaceRef $space, string $role): Fingerprint
    {
        return new Fingerprint(hash('sha256', "knowkit:embedding-request:v1\0" . Jcs::canonicalize([
            'scope' => $access->scope->toWire(),
            'egress' => $access->request->toWire(),
            'inputs' => array_map(static fn(string $text): string => hash('sha256', $text), $texts),
            'identities' => $identities,
            'space' => $space->digest()->value,
            'role' => $role,
        ])));
    }
}
