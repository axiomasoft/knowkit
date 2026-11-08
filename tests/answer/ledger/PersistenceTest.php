<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Ledger;

use KnowKit\Answer\Adapter\PostgresAnswerMigrations;
use KnowKit\Answer\Application\InvocationAdmission;
use KnowKit\Answer\Application\InvocationRecovery;
use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Tests\Answer\Contracts\ExactTokenizer;
use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:ledger')]
final class PersistenceTest extends TestCase
{
    public function testOptionalMigrationIsExplicitIdempotentAndRejectsChangedChecksum(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $db = $f->blobs->catalog->db;
            $migration = new PostgresAnswerMigrations($db->pdo, $db->schema);
            $migration->apply();
            self::assertSame(1, (int) $db->execute("SELECT count(*) FROM schema_migrations WHERE id='answer:001'")->fetchColumn());
            $db->execute("UPDATE schema_migrations SET checksum=repeat('0',64) WHERE id='answer:001'");
            try {
                $migration->apply();
                self::fail('Changed migration checksum accepted');
            } catch (\RuntimeException $error) {
                self::assertSame('Answer migration checksum mismatch', $error->getMessage());
            }
            self::assertFalse($db->pdo->inTransaction());
        });
    }

    public function testRevocationDuringResponseMaterializationPreventsDisclosureWithoutChangingCompletion(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $f->finish();
            $before = $f->row();
            $f->payloads->afterResponseRead = function () use ($f): void {
                $f->authority->historyVisible = false;
            };
            $outcome = $f->replay();
            self::assertSame(A\OutcomeCode::Denied, $outcome->code);
            self::assertNull($outcome->answeredContent());
            self::assertSame(1, $f->payloads->responseReads);
            self::assertSame(2, $f->authority->replays);
            self::assertSame($before, $f->row());
            self::assertSame(1, $f->transport->sends);
        });
    }

    public function testConcurrentDifferentBodyCannotAdoptWinningAdmission(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $db = $f->secondConnection();
            $other = $f->makeLedger('worker:b', 120, $db);
            $resolver = new BindingResolver($f->binding(), $db);
            $changed = $f->request(['question' => 'Winning different body']);
            $f->payloads->afterPins = fn () => (new InvocationAdmission($other, $resolver))->admit($changed);
            try {
                $f->admit();
                self::fail('Different original body adopted');
            } catch (D\DomainFailure $error) {
                self::assertSame('invocation_conflict', $error->errorCode);
            }
            self::assertSame($changed->originalRequestDigest()->value, $f->row()['original_request_digest']);
            self::assertSame('worker:b', $f->row()['lease_owner']);
            self::assertSame(0, $f->transport->sends);
        });
    }

    public function testPendingResourceReservationAndRetentionExistBeforePayloadIo(): void
    {
        $fixture = null;
        $observations = 0;
        LedgerFixture::run(function (LedgerFixture $f) use (&$fixture, &$observations): void {
            $fixture = $f;
            $f->finish();
            self::assertGreaterThan(0, $observations);
            self::assertSame(4, $f->blobs->catalog->count('knowkit_answer_payloads'));
        }, 120, function ($handle) use (&$fixture, &$observations): bool {
            self::assertInstanceOf(LedgerFixture::class, $fixture);
            $db = $fixture->blobs->catalog->db;
            self::assertFalse($db->pdo->inTransaction());
            $row = $db->row("SELECT r.* FROM resource_ledger r JOIN resource_writers w ON w.tenant_id=r.tenant_id AND w.resource_id=r.id WHERE r.state='reserved' AND w.state='active'");
            if ($row !== null) {
                self::assertSame(1, (int) $row['ref_count']);
                self::assertNotNull($row['digest']);
                self::assertNotNull($row['retention_until']);
                $observations++;
            }

            return fsync($handle);
        });
    }

    public function testSqlRejectsRewritingReservedBytesPinsAndManagedDependencyMetadata(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $reserved = $f->reserve();
            $pdo = $f->blobs->catalog->db->pdo;
            foreach ([
                "UPDATE knowkit_answer_invocations SET request_payload_hash=repeat('0',64),row_version=row_version+1,state='sending'",
                "UPDATE knowkit_answer_invocations SET binding_digest=repeat('0',64),row_version=row_version+1",
                "UPDATE knowkit_answer_invocations SET profile_revision='replacement',row_version=row_version+1",
                "UPDATE knowkit_answer_payloads SET dependencies_json='{}'",
                'DELETE FROM knowkit_answer_payloads',
            ] as $sql) {
                CatalogFixture::sqlReject($pdo, $sql);
            }
            self::assertEquals($reserved, $f->ledger->find($reserved->invocationRef));
            $sending = $f->ledger->markSending($reserved->claim, $f->requestBytes()->identity);
            CatalogFixture::sqlReject($pdo, "UPDATE knowkit_answer_invocations SET model_call_identity_hash=repeat('0',64),row_version=row_version+1,state='unknown',outcome_code='TransportUnknown'");
            self::assertEquals($sending, $f->ledger->find($reserved->invocationRef));
        });
    }

    public function testFreshGuardIsLastExternalCallBeforeTransportAndNoTransactionSurroundsSend(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $reserved = $f->reserve();
            $readsAtGuard = null;
            $f->authority->duringSend = function () use ($f, &$readsAtGuard): void {
                $readsAtGuard = $f->payloads->reads;
            };
            $f->transport->onSend = function () use ($f, &$readsAtGuard): void {
                self::assertFalse($f->blobs->catalog->db->pdo->inTransaction());
                self::assertNotNull($readsAtGuard);
                self::assertSame($readsAtGuard, $f->payloads->reads);
                self::assertSame('sending', $f->row()['state']);
                self::assertNotNull($f->row()['model_call_identity_ref']);
            };
            $result = $f->dispatcher()->send($reserved->claim, $f->requestBytes(), Fixture::cancellation());
            self::assertInstanceOf(A\TransportResult::class, $result);
            self::assertSame(1, $f->transport->sends);
        });
    }

    public function testPreparingRecoveryRequiresFreshPolicyAndCannotBorrowAnotherTenantClaim(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $first = $f->admit();
            $f->expireLease();
            $other = $f->makeLedger('worker:b');
            $f->authority->historyVisible = false;
            $recovery = new InvocationRecovery($other, $f->authority);
            $denied = $recovery->resume($f->request(), $f->scope());
            self::assertInstanceOf(A\AnswerOutcome::class, $denied);
            self::assertSame('0', (string) $f->row()['fencing_epoch']);
            $f->authority->historyVisible = true;
            $fresh = $recovery->resume($f->request(), $f->scope());
            self::assertInstanceOf(A\InvocationRecord::class, $fresh);
            self::assertSame(A\InvocationState::Preparing, $fresh->state);
            self::assertSame('1', $fresh->claim->fencingEpoch->value);
            $foreign = new A\LedgerClaim(new A\InvocationRef(new D\TenantId(BlobFixture::id(9)), $first->invocationRef->key),
                $first->claim->rowVersion, $first->claim->leaseOwner, $first->claim->leaseUntil, $first->claim->fencingEpoch);
            try {
                $other->resume($foreign);
                self::fail('Cross-tenant recovery accepted');
            } catch (D\DomainFailure $error) {
                self::assertSame('scope_mismatch', $error->errorCode);
            }
            self::assertSame(0, $f->transport->sends);
        }, 1);
    }

    public function testPinnedBindingRoundTripsExactTokenizerSettingsAndNoSendCompletion(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $binding = $f->binding([
                'capabilities' => new A\ModelCapabilities(new D\Sha256(str_repeat('1', 64)), new \DateTimeImmutable('2030-01-01'), true, 2000, 4000, ExactTokenizer::identity()),
                'settings' => new A\GenerationSettings(100, '0.5', '0.9', 7, ['stop marker']),
            ]);
            $f->resolver->binding = $binding;
            $first = $f->admit();
            self::assertSame(Jcs::canonicalize($binding->toWire()), Jcs::canonicalize($first->pins->binding->toWire()));
            $denied = new A\AnswerOutcome(A\OutcomeCode::EgressDenied, $first->invocationRef, A\DispatchCertainty::KnownNotSent);
            $completed = $f->ledger->complete($first->claim, $denied);
            self::assertSame(A\InvocationState::Completed, $completed->state);
            self::assertSame(A\DispatchCertainty::KnownNotSent, $completed->dispatchCertainty);
            self::assertEquals($denied, $f->replay());
            self::assertSame(0, $f->transport->sends);
            self::assertNull($f->row()['model_call_identity_ref']);
        });
    }
}
