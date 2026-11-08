<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Embedding;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Adapter\Embeddings\SuppliedVectors;
use KnowKit\Domain as D;
use KnowKit\Domain\Embeddings\ProviderLimits;
use KnowKit\Domain\Embeddings\ProviderResponse;
use KnowKit\Domain\Embeddings\ProviderTimeout;
use KnowKit\Domain\Embeddings\ReceiptStatus;
use KnowKit\Domain\Embeddings\RequestReceipt;
use KnowKit\Registry\EmbeddingProviderRegistry;
use KnowKit\Registry\EmbeddingRegistration;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:embedding')]
final class ProviderTest extends TestCase
{
    public function testFullFingerprintAndExplicitRegistryNeverMixEqualDimensions(): void
    {
        $f = new Fixture();
        $space = Fixture::space();
        self::assertSame(Fixture::corpus()['spaceDigest'], $space->digest()->value);
        $provider = $f->provider();
        $provider->capabilities()->require(D\Capability::EmbeddingDocuments, 'ir-1.0');
        $registry = new EmbeddingProviderRegistry([new EmbeddingRegistration($space, $provider)]);
        self::assertSame($provider, $registry->resolve(Fixture::space()));
        foreach (Fixture::corpus()['spaceMutations'] as $case) {
            $other = Fixture::space($case['changes']);
            self::assertNotSame($space->digest()->value, $other->digest()->value, $case['id']);
            try {
                $registry->resolve($other);
                self::fail('Unregistered space resolved: ' . $case['id']);
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
            }
            $result = $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), $other);
            self::assertSame(D\Outcome::Incompatible, $result->outcome, $case['id']);
        }
        self::assertSame([], $f->transport->seen);
        self::assertSame([], $f->store->records);
        $this->expectException(\InvalidArgumentException::class);
        new EmbeddingProviderRegistry([new EmbeddingRegistration($space, $provider), new EmbeddingRegistration($space, $provider)]);
    }

    public function testAsymmetricDocumentAndQueryRolesAreSentAndObservedExactly(): void
    {
        $f = new Fixture();
        $provider = $f->provider();
        $inputs = Fixture::inputs();
        self::assertSame(D\Outcome::Success, $provider->embedDocuments(Fixture::access($inputs), $inputs, Fixture::space())->outcome);
        self::assertSame(D\Outcome::Success, $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())->outcome);
        self::assertSame(['search_document', 'search_query'], array_column($f->transport->seen, 'role'));
        self::assertTrue($f->store->records[0]->spaceDigest->equals($f->store->records[1]->spaceDigest));
        self::assertFalse($f->store->records[0]->requestDigest->equals($f->store->records[1]->requestDigest));
        foreach ([true, false] as $query) {
            $f->transport->respond = static fn($texts, $space, $role): ProviderResponse =>
                new ProviderResponse($space, $query ? $space->documentRole : $space->queryRole, [[1, 0, 0]]);
            $result = $query
                ? $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())
                : $provider->embedDocuments(Fixture::access($inputs), $inputs, Fixture::space());
            self::assertSame(D\Outcome::Incompatible, $result->outcome);
            self::assertNull($result->data);
        }
    }

    public function testEveryBatchAndHostSuppliedVectorUsesTheSameStrictValidation(): void
    {
        foreach (Fixture::corpus()['invalidVectors'] as $case) {
            foreach ([false, true] as $hostSupplied) {
                $f = new Fixture();
                $space = Fixture::space($case['spaceChanges']);
                $vectors = $case['vectors'];
                array_walk_recursive($vectors, static function (&$value): void {
                    $value = match ($value) {
                        '__NAN__' => NAN, '__INF__' => INF, '__NEG_INF__' => -INF, default => $value,
                    };
                });
                $response = new ProviderResponse($space, $space->documentRole, $vectors, 'invalid-1');
                $f->transport->respond = static fn(): ProviderResponse => $response;
                $transport = $hostSupplied ? new SuppliedVectors($response) : $f->transport;
                $provider = new \KnowKit\Application\Embeddings\ConfiguredProvider($space, new ProviderLimits(4, 64, 128),
                    new \KnowKit\Application\Embeddings\ProviderAuthorization($f->policy, $f->policy, $f->policy), $transport, $f->store);
                $inputs = Fixture::inputs();
                if ($case['inputCount'] === 2) {
                    $inputs[] = new D\EmbeddingInput(PortFixture::chunk(), 'second document');
                }
                $result = $provider->embedDocuments(Fixture::access($inputs), $inputs, $space);
                self::assertSame(D\Outcome::Incompatible, $result->outcome, $case['id']);
                self::assertNull($result->data, $case['id']);
                self::assertSame(ReceiptStatus::Failed, $f->store->records[0]->status);
                self::assertNull($f->store->records[0]->resultFingerprint);
            }
        }
    }

    public function testL2UnitNormToleranceAndUnnormalizedFiniteVectors(): void
    {
        foreach (Fixture::corpus()['validVectors'] as $case) {
            $space = Fixture::space(['normalization' => $case['normalization']]);
            $batch = (new ProviderResponse($space, $space->queryRole, [$case['values']]))->validated($space, $space->queryRole, 1);
            self::assertSame($case['values'], $batch->vectors[0]->values);
        }
        $a = new D\EmbeddingBatch([new D\Vector([1, 0, 0], Fixture::space())], Fixture::space());
        $b = new D\EmbeddingBatch([new D\Vector([1.0, -0.0, 0.0], Fixture::space())], Fixture::space());
        self::assertTrue(ProviderResponse::fingerprint($a, 'search_query')->equals(ProviderResponse::fingerprint($b, 'search_query')));
    }

    public function testReadPermissionDoesNotAuthorizeEgressAndEveryCallIsFresh(): void
    {
        foreach ([D\Decision::Deny, D\Decision::Unknown] as $decision) {
            foreach ([true, false] as $query) {
                $f = new Fixture();
                $f->policy->outbound = $decision;
                $provider = $f->provider();
                $inputs = Fixture::inputs();
                $call = static fn(): D\Result => $query
                    ? $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())
                    : $provider->embedDocuments(Fixture::access($inputs), $inputs, Fixture::space());
                $result = $call();
                self::assertSame($decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown, $result->outcome);
                self::assertSame([], $f->transport->seen);
                $f->policy->outbound = D\Decision::Allow;
                self::assertSame(D\Outcome::Success, $call()->outcome);
                $f->policy->outbound = $decision;
                self::assertSame($result->outcome, $call()->outcome);
                self::assertCount(1, $f->transport->seen);
                self::assertSame(3, $f->policy->egressCalls);
            }
        }
    }

    public function testFreshPublishDenialOrChangedBindingRejectsRemoteResult(): void
    {
        foreach (['deny', 'unknown', 'binding'] as $change) {
            $f = new Fixture();
            $f->transport->respond = static function ($texts, $space, $role) use ($f, $change): ProviderResponse {
                $f->policy->publish = $change === 'deny' ? D\Decision::Deny : ($change === 'unknown' ? D\Decision::Unknown : D\Decision::Allow);
                $f->policy->changeBinding = $change === 'binding';

                return new ProviderResponse($space, $role, [[1, 0, 0]], 'accepted-remotely');
            };
            $inputs = Fixture::inputs();
            $result = $f->provider()->embedDocuments(Fixture::access($inputs), $inputs, Fixture::space());
            self::assertSame($change === 'unknown' ? D\Outcome::Unknown : D\Outcome::Denied, $result->outcome);
            self::assertNull($result->data);
            self::assertCount(1, $f->transport->seen);
            self::assertContains(D\Operation::Publish, $f->policy->operations);
            self::assertNull($f->store->records[0]->resultFingerprint);
            self::assertSame('accepted-remotely', $f->store->records[0]->providerRequestId);
        }
    }

    public function testTimeoutAfterRemoteAcceptanceKeepsUnknownReceiptAndExplicitRetry(): void
    {
        $f = new Fixture();
        $remoteAccepted = false;
        $f->transport->respond = static function () use (&$remoteAccepted): never {
            $remoteAccepted = true;
            throw new ProviderTimeout('remote-accepted-42');
        };
        $provider = $f->provider();
        $result = $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space());
        self::assertSame(D\Outcome::Unknown, $result->outcome);
        self::assertTrue($remoteAccepted);
        self::assertFalse($result->retryable);
        self::assertCount(1, $f->transport->seen);
        self::assertSame(ReceiptStatus::Unknown, $f->store->records[0]->status);
        self::assertSame('remote-accepted-42', $f->store->records[0]->providerRequestId);
        $f->transport->respond = null;
        self::assertSame(D\Outcome::Success, $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())->outcome);
        self::assertSame([1, 2], array_column($f->store->records, 'attempt'));
        self::assertTrue($f->store->records[0]->requestDigest->equals($f->store->records[1]->requestDigest));
        self::assertSame(ReceiptStatus::Unknown, $f->store->records[0]->status);
    }

    public function testLimitsAndManifestMismatchBlockBeforeAnyTransmission(): void
    {
        foreach ([[1, 5, 5], [1, 64, 64], [4, 64, 64]] as $limits) {
            $f = new Fixture();
            $inputs = [new D\EmbeddingInput(PortFixture::chunk(), str_repeat('x', 40)), new D\EmbeddingInput(PortFixture::chunk(), str_repeat('y', 40))];
            $provider = $f->provider(new ProviderLimits(...$limits));
            self::assertSame(D\Outcome::Incompatible, $provider->embedDocuments(Fixture::access($inputs), $inputs, Fixture::space())->outcome);
            self::assertSame([], $f->transport->seen);
            self::assertSame([], $f->store->records);
        }
        $f = new Fixture();
        $provider = $f->provider(new ProviderLimits(1, 5, 5));
        self::assertSame(D\Outcome::Incompatible, $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())->outcome);
        self::assertSame(D\Outcome::Incompatible, $provider->embedQuery(Fixture::access(), new D\QueryInput('different'), Fixture::space())->outcome);
        self::assertSame([], $f->transport->seen);
    }

    public function testTypedFailuresRedactExceptionsAndNeverRetry(): void
    {
        $f = new Fixture();
        $f->policy->throwEgress = true;
        $provider = $f->provider();
        $result = $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space());
        self::assertSame(D\Outcome::Unavailable, $result->outcome);
        self::assertSame([], $f->transport->seen);
        $f->policy->throwEgress = false;
        foreach ([D\Outcome::Denied, D\Outcome::Unknown, D\Outcome::Unavailable, D\Outcome::Incompatible] as $outcome) {
            $f->transport->respond = static function () use ($outcome): never {
                throw new D\DomainFailure($outcome, 'provider_failure');
            };
            self::assertSame($outcome, $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())->outcome);
        }
        $f->transport->respond = static function (): never {
            throw new \RuntimeException('credential secret provider input');
        };
        self::assertSame(D\Outcome::Unknown, $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())->outcome);
        self::assertCount(5, $f->transport->seen);
        $evidence = json_encode([$result, $f->store->records], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('provider input', $evidence);
        self::assertStringNotContainsString('secret', $evidence);
    }

    public function testChangedObservedSpaceAndLaterInvalidBatchCannotBecomeSuccess(): void
    {
        foreach (Fixture::corpus()['spaceMutations'] as $case) {
            $f = new Fixture();
            $f->transport->respond = static fn($texts, $space, $role): ProviderResponse =>
                new ProviderResponse(Fixture::space($case['changes']), $role, [[1, 0, 0]]);
            $result = $f->provider()->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space());
            self::assertSame(D\Outcome::Incompatible, $result->outcome, $case['id']);
            self::assertNull($result->data);
        }
        $f = new Fixture();
        $provider = $f->provider();
        self::assertSame(D\Outcome::Success, $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())->outcome);
        $f->transport->respond = static fn($texts, $space, $role): ProviderResponse => new ProviderResponse($space, $role, [[true, 0, 0]]);
        self::assertSame(D\Outcome::Incompatible, $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())->outcome);
        self::assertSame([ReceiptStatus::Accepted, ReceiptStatus::Failed], array_column($f->store->records, 'status'));
    }

    public function testReceiptMetadataAndStatusInvariantsRejectMalformedEvidence(): void
    {
        $fingerprint = Fixture::space()->digest();
        $scope = RequestReceipt::scopeDigest(PortFixture::scope());
        foreach ([
            [ReceiptStatus::Accepted, null, null],
            [ReceiptStatus::Unknown, $fingerprint, null],
            [ReceiptStatus::Unknown, null, D\Outcome::Denied],
            [ReceiptStatus::Failed, null, null],
            [ReceiptStatus::Failed, null, D\Outcome::Unknown],
        ] as [$status, $result, $outcome]) {
            try {
                new RequestReceipt($scope, $fingerprint, $fingerprint, 1, $status, $result, failureOutcome: $outcome);
                self::fail('Invalid receipt accepted');
            } catch (\InvalidArgumentException $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
        $this->expectException(D\DomainFailure::class);
        new ProviderTimeout('https://secret.example/provider input');
    }

    public function testFreshProcessingDenialAndCrossTenantManifestStopBeforeProvider(): void
    {
        $f = new Fixture();
        $provider = $f->provider();
        foreach ([D\Decision::Deny, D\Decision::Unknown] as $decision) {
            $f->policy->processing = $decision;
            $result = $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space());
            self::assertSame($decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown, $result->outcome);
        }
        self::assertSame([], $f->transport->seen);
        self::assertSame([], $f->store->records);
        self::assertSame(0, $f->policy->egressCalls);
        $fields = get_object_vars(PortFixture::scope());
        $fields['tenantId'] = new D\TenantId(PortFixture::id(500));
        $this->expectException(D\DomainFailure::class);
        Fixture::access(Fixture::inputs(), new D\Scope(...$fields));
    }

    public function testRequestDigestBindsImmutableChunkScopeAndDestination(): void
    {
        $f = new Fixture();
        $provider = $f->provider();
        $inputs = Fixture::inputs();
        $access = Fixture::access($inputs);
        self::assertSame(D\Outcome::Success, $provider->embedDocuments($access, $inputs, Fixture::space())->outcome);
        self::assertSame(D\Outcome::Success, $provider->embedDocuments($access, $inputs, Fixture::space())->outcome);
        self::assertTrue($f->store->records[0]->requestDigest->equals($f->store->records[1]->requestDigest));
        self::assertTrue($f->store->records[0]->resultFingerprint->equals($f->store->records[1]->resultFingerprint));
        $differentChunk = [new D\EmbeddingInput(PortFixture::chunk(1), 'provider input')];
        self::assertSame(D\Outcome::Success, $provider->embedDocuments(Fixture::access($differentChunk), $differentChunk, Fixture::space())->outcome);
        $differentScope = Fixture::access($inputs, PortFixture::scope(str_repeat('b', 64)));
        self::assertSame(D\Outcome::Success, $provider->embedDocuments($differentScope, $inputs, Fixture::space())->outcome);
        $wire = $access->request->toWire();
        $wire = $wire->with('destination', $wire->get('destination')->with('accountId', 'different-account'));
        $differentDestination = new D\EmbeddingAccess($access->scope, new D\EgressRequest($wire, PortFixture::validator()));
        self::assertSame(D\Outcome::Success, $provider->embedDocuments($differentDestination, $inputs, Fixture::space())->outcome);
        self::assertCount(4, array_unique(array_map(static fn(RequestReceipt $r): string => $r->requestDigest->value, $f->store->records)));
    }

    public function testDurableIntentPrecedesBytesAndStorageFailureNeverReturnsVectors(): void
    {
        $f = new Fixture();
        $f->transport->respond = static function ($texts, $space, $role) use ($f): ProviderResponse {
            self::assertCount(1, $f->store->records);
            self::assertSame(ReceiptStatus::Unknown, $f->store->records[0]->status);

            return new ProviderResponse($space, $role, [[1, 0, 0]]);
        };
        $store = new FailingReceipts($f->store);
        $provider = $f->provider(store: $store);
        self::assertSame(D\Outcome::Unavailable, $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space())->outcome);
        self::assertSame([], $f->transport->seen);
        $store->failBegin = false;
        $result = $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space());
        self::assertSame(D\Outcome::Unavailable, $result->outcome);
        self::assertSame('embedding_receipt_unavailable', $result->errorCode);
        self::assertNull($result->data);
        self::assertSame(ReceiptStatus::Unknown, $f->store->records[0]->status);
    }
}
