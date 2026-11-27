<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Embedding;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\Domain\Embeddings\ReceiptStatus;
use KnowKit\Domain\Embeddings\RequestReceipt;
use KnowKit\PgVector\Embedding\PdoReceipts;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Tooling\DatabaseSandbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:embedding')]
final class PostgresTest extends TestCase
{
    private function database(\Closure $test): void
    {
        (new DatabaseSandbox())->run(function (DatabaseSandbox $sandbox) use ($test): void {
            $pdo = $sandbox->connection;
            $pdo->exec(file_get_contents(Fixture::root() . '/packages/pgvector/migrations/001_pgvector_schema.sql'));
            $role = 'embedding_test_' . bin2hex(random_bytes(8));
            $pdo->exec('CREATE ROLE ' . $role . ' NOLOGIN NOSUPERUSER');
            try {
                $pdo->exec('GRANT USAGE ON SCHEMA ' . $sandbox->schema . ' TO ' . $role);
                $pdo->exec('GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA ' . $sandbox->schema . ' TO ' . $role);
                $pdo->exec('SET ROLE ' . $role);
                $test(new Connection($pdo, $sandbox->schema), $role);
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->exec('RESET ROLE');
                $pdo->exec('DROP OWNED BY ' . $role);
                $pdo->exec('DROP ROLE ' . $role);
            }
        });
    }

    public function testDurableIntentAmbiguousTimeoutAndIdempotentLocalAcceptance(): void
    {
        $this->database(function (Connection $db): void {
            $store = new PdoReceipts($db);
            $scope = PortFixture::scope();
            $request = new D\Fingerprint(hash('sha256', 'request'));
            $space = Fixture::space()->digest();
            $intent = $store->begin($scope, $request, $space);
            self::assertSame(ReceiptStatus::Unknown, (new PdoReceipts($db))->receipts($scope, $request)[0]->status);
            $timeout = $intent->finish(ReceiptStatus::Unknown, requestId: 'remote-42', failure: D\Outcome::Unknown);
            $store->complete($scope, $timeout);
            $retry = $store->begin($scope, $request, $space);
            $accepted = $retry->finish(ReceiptStatus::Accepted, new D\Fingerprint(hash('sha256', 'result')), 'remote-43');
            $store->complete($scope, $accepted);
            $store->complete($scope, $accepted);
            $third = $store->begin($scope, $request, $space)->finish(ReceiptStatus::Accepted, $accepted->resultFingerprint, 'remote-44');
            (new PdoReceipts($db))->complete($scope, $third);
            $receipts = (new PdoReceipts($db))->receipts($scope, $request);
            self::assertEquals([$timeout, $accepted, $third], $receipts);
            self::assertSame([1, 2, 3], array_column($receipts, 'attempt'));
            $changed = $store->begin($scope, $request, $space)->finish(ReceiptStatus::Accepted, new D\Fingerprint(hash('sha256', 'different')));
            try {
                $store->complete($scope, $changed);
                self::fail('Different local result accepted');
            } catch (D\DomainFailure $failure) {
                self::assertSame('embedding_result_conflict', $failure->errorCode);
            }
            self::assertSame(ReceiptStatus::Unknown, $store->receipts($scope, $request)[3]->status);
            self::assertSame($accepted->resultFingerprint->value,
                $db->row('SELECT accepted_result_digest FROM ' . $db->table('embedding_requests'))['accepted_result_digest']);
        });
    }

    public function testEveryPersistenceBoundaryBindsTenantKbFullScopeAndImmutableSpace(): void
    {
        $this->database(function (Connection $db): void {
            $store = new PdoReceipts($db);
            $scope = PortFixture::scope();
            $request = new D\Fingerprint(hash('sha256', 'request'));
            $intent = $store->begin($scope, $request, Fixture::space()->digest());
            foreach (['tenantId', 'kbId', 'actorId', 'purpose', 'policyEpoch', 'hostScopeHash'] as $field) {
                $fields = get_object_vars($scope);
                $fields[$field] = match ($field) {
                    'tenantId' => new D\TenantId(PortFixture::id(500)), 'kbId' => new D\KbId(PortFixture::id(500)),
                    'actorId' => new D\ActorId(PortFixture::id(500)), 'purpose' => 'different.purpose',
                    'policyEpoch' => new D\Decimal('43'), 'hostScopeHash' => new D\Sha256(str_repeat('b', 64)),
                };
                $other = new D\Scope(...$fields);
                try {
                    $store->complete($other, $intent);
                    self::fail('Foreign scope accepted: ' . $field);
                } catch (D\DomainFailure $failure) {
                    self::assertSame(D\Outcome::Denied, $failure->outcome);
                }
                $this->assertReadIsolation($store, $other, $request, $field);
            }
            $wrongSpace = new RequestReceipt($intent->scopeDigest, $request, Fixture::space(['revision' => 'wrong'])->digest(), 1);
            try {
                $store->complete($scope, $wrongSpace);
                self::fail('Changed immutable space');
            } catch (D\DomainFailure $failure) {
                self::assertSame('embedding_receipt_mismatch', $failure->errorCode);
            }
            self::assertEquals([$intent], $store->receipts($scope, $request));
        });
    }

    private function assertReadIsolation(PdoReceipts $store, D\Scope $scope, D\Fingerprint $request, string $field): void
    {
        try {
            self::assertSame([], $store->receipts($scope, $request), $field);
        } catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Denied, $failure->outcome);
        }
    }

    public function testConcurrentWriterCannotReuseAnAttemptOrOverwriteATerminalReceipt(): void
    {
        $this->database(function (Connection $db, string $role): void {
            $secondPdo = (new DatabaseSandbox())->connection;
            $secondPdo->exec('SET ROLE ' . $role);
            $secondPdo->exec("SET lock_timeout = '100ms'");
            $second = new PdoReceipts(new Connection($secondPdo, $db->schema));
            $store = new PdoReceipts($db);
            $scope = PortFixture::scope();
            $request = new D\Fingerprint(hash('sha256', 'concurrent-request'));
            $intent = $store->begin($scope, $request, Fixture::space()->digest());
            $db->pdo->beginTransaction();
            $db->row('SELECT request_digest FROM ' . $db->table('embedding_requests') . ' FOR UPDATE');
            try {
                $second->begin($scope, $request, Fixture::space()->digest());
                self::fail('Concurrent writer bypassed row lock');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Unavailable, $failure->outcome);
            } finally {
                $db->pdo->rollBack();
            }
            self::assertSame(2, $second->begin($scope, $request, Fixture::space()->digest())->attempt);
            $accepted = $intent->finish(ReceiptStatus::Accepted, new D\Fingerprint(hash('sha256', 'accepted')));
            $store->complete($scope, $accepted);
            try {
                $second->complete($scope, $intent->finish(ReceiptStatus::Failed, failure: D\Outcome::Denied));
                self::fail('Terminal receipt overwritten');
            } catch (D\DomainFailure $failure) {
                self::assertSame('embedding_receipt_conflict', $failure->errorCode);
            }
            self::assertEquals($accepted, $second->receipts($scope, $request)[0]);
            $secondPdo->exec('RESET ROLE');
        });
    }

    public function testProviderPersistsRemoteTimeoutAndFreshPublishDenial(): void
    {
        $this->database(function (Connection $db): void {
            $f = new Fixture();
            $store = new PdoReceipts($db);
            $provider = $f->provider(store: $store);
            $f->transport->respond = static function (): never {
                throw new \KnowKit\Domain\Embeddings\ProviderTimeout('remotely-accepted');
            };
            $result = $provider->embedQuery(Fixture::access(), new D\QueryInput('provider input'), Fixture::space());
            self::assertSame(D\Outcome::Unknown, $result->outcome);
            $row = $db->row('SELECT request_digest FROM ' . $db->table('embedding_requests'));
            $receipt = $store->receipts(PortFixture::scope(), new D\Fingerprint($row['request_digest']))[0];
            self::assertSame('remotely-accepted', $receipt->providerRequestId);
            self::assertSame(ReceiptStatus::Unknown, $receipt->status);
            self::assertNull($receipt->resultFingerprint);
            $f->transport->respond = static function ($texts, $space, $role) use ($f): \KnowKit\Domain\Embeddings\ProviderResponse {
                $f->policy->publish = D\Decision::Deny;

                return new \KnowKit\Domain\Embeddings\ProviderResponse($space, $role, [[1, 0, 0]], 'remote-doc');
            };
            $inputs = Fixture::inputs();
            self::assertSame(D\Outcome::Denied, $provider->embedDocuments(Fixture::access($inputs), $inputs, Fixture::space())->outcome);
            $rows = $db->execute('SELECT status, provider_request_id, failure_outcome FROM ' . $db->table('embedding_receipts'))->fetchAll(\PDO::FETCH_ASSOC);
            self::assertCount(2, $rows);
            self::assertContains(['status' => 'failed', 'provider_request_id' => 'remote-doc', 'failure_outcome' => 'denied'], $rows);
            self::assertStringNotContainsString('provider input', json_encode($rows));
        });
    }

    public function testReceiptSchemaAndBeginRejectInconsistentIdentityAndOutcomes(): void
    {
        $this->database(function (Connection $db): void {
            $store = new PdoReceipts($db);
            $scope = PortFixture::scope();
            $request = new D\Fingerprint(hash('sha256', 'identity-request'));
            $intent = $store->begin($scope, $request, Fixture::space()->digest());
            try {
                $store->begin($scope, $request, Fixture::space(['model' => 'another'])->digest());
                self::fail('Same request rebound to another space');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
            }
            try {
                $store->begin(PortFixture::scope(str_repeat('b', 64)), $request, Fixture::space()->digest());
                self::fail('Same request rebound to another host scope');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Denied, $failure->outcome);
            }
            foreach (["status = 'accepted'", "status = 'failed', failure_outcome = NULL", "status = 'unknown', failure_outcome = 'denied'"] as $invalid) {
                try {
                    $db->execute('UPDATE ' . $db->table('embedding_receipts') . ' SET ' . $invalid);
                    self::fail('Inconsistent receipt row accepted');
                } catch (D\DomainFailure $failure) {
                    self::assertSame('storage_conflict', $failure->errorCode);
                }
            }
            self::assertEquals([$intent], $store->receipts($scope, $request));
            $unknown = $intent->finish(ReceiptStatus::Unknown, requestId: 'timeout-id', failure: D\Outcome::Unknown);
            $store->complete($scope, $unknown);
            try {
                $store->complete($scope, $intent);
                self::fail('Recorded timeout evidence erased');
            } catch (D\DomainFailure $failure) {
                self::assertSame('embedding_receipt_conflict', $failure->errorCode);
            }
        });
    }
}
