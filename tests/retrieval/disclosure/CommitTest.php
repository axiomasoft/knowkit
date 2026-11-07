<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Disclosure;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:disclosure')]
final class CommitTest extends TestCase
{
    public function testAmbiguousCommitNeverReleasesTextAndReplayReturnsOnlyMetadata(): void
    {
        foreach ([false, true] as $committed) {
            Fixture::run(function (Fixture $f) use ($committed): void {
                $selected = $f->select();
                $request = $f->request($selected);
                $role = $f->db()->execute('SELECT current_user')->fetchColumn();
                $pdo = new CommitFaultPdo($committed);
                $pdo->exec('SET ROLE "' . $role . '"');
                $db = new Connection($pdo, $f->db()->schema);
                $tx = new PdoCatalogTransaction($db);
                $dependencies = new PdoDependencyStore($db, $tx, $f->host->identity->scope->tenantId, $f->host);
                $store = new PdoUsages($db, $tx, $dependencies);
                $canonical = new CanonicalBuffer($f->catalog,
                    new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()), $f->host);
                $service = new Disclosures($f->selections, $f->checks(), $canonical, $store, $f->host, PortFixture::validator());
                $result = $service->disclose($f->host->context(), $selected->token,
                    $f->query(), $f->host->inventory->snapshot, $request);
                self::assertSame(D\Outcome::Unknown, $result->result->outcome);
                self::assertSame('commit_unknown', $result->result->errorCode);
                self::assertNull($result->result->data);
                self::assertSame($committed ? 1 : 0, $f->lexical->catalog->count('evidence_usages'));
                $retry = $f->disclose($selected, $request);
                self::assertSame(D\Outcome::Success, $retry->result->outcome);
                self::assertInstanceOf($committed ? D\DisclosureReplay::class : D\EvidenceSet::class, $retry->result->data);
                self::assertSame(1, $f->lexical->catalog->count('evidence_usages'));
                $pdo->exec('RESET ROLE');
            });
        }
    }

    public function testDatabaseClockRejectsSelectionThatExpiresDuringPreload(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->host->now = Fixture::databaseNow($f->db())->plusSeconds(-2);
            $selected = $f->select(ttl: 1);
            // Host clock still considers it live. The SQL boundary must use its own clock.
            $result = $f->disclose($selected);
            self::assertSame(D\Outcome::Denied, $result->result->outcome);
            self::assertSame('selection_expired', $result->result->errorCode);
            self::assertNull($result->result->data);
            self::assertSame(2, $f->objects->reads);
            self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testDatabaseClockRejectsExpiredReceiptAndCurrentLifecycleMarkers(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->host->now = Fixture::databaseNow($f->db())->plusSeconds(-6);
            $selected = $f->select();
            $result = $f->disclose($selected);
            self::assertSame(D\Outcome::Denied, $result->result->outcome);
            self::assertSame('POLICY_CHANGED', $result->result->errorCode);
            self::assertNull($result->result->data);
            $f->host->now = Fixture::databaseNow($f->db());
            $f->db()->execute('UPDATE ' . $f->db()->table('source_version_state') . " SET usability='corrected' WHERE tenant_id=? AND id=?",
                [Fixture::id(1), $f->ref()->revisionRef->sourceRef->sourceVersionId->value]);
            $result = $f->disclose($selected);
            self::assertSame(D\Outcome::Denied, $result->result->outcome);
            self::assertSame('ancestor_unusable', $result->result->errorCode);
            self::assertNull($result->result->data);
        });
    }

    public function testPolicyChangeDuringCanonicalReadBlocksAllText(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $f->objects->beforeOpen = function () use ($f): void {
                $f->db()->execute('UPDATE ' . $f->db()->table('knowledge_bases') . ' SET kb_policy_epoch=kb_policy_epoch+1 WHERE tenant_id=? AND id=?',
                    [Fixture::id(1), Fixture::id(100)]);
            };
            $result = $f->disclose($selected);
            self::assertSame(D\Outcome::Denied, $result->result->outcome);
            self::assertSame('POLICY_CHANGED', $result->result->errorCode);
            self::assertNull($result->result->data);
            self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
        });
    }

    public function testCanonicalPreloadBoundRejectsBeforeReadingAndUsageNeedsSemanticGuards(): void
    {
        Fixture::run(function (Fixture $f): void {
            $reader = new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator());
            foreach ([fn () => $reader->read($f->ref()->revisionRef, 1), fn () => $reader->readChunk($f->ref(), 1)] as $read) {
                try {
                    $read();
                    self::fail('Preload hard bound bypassed');
                } catch (D\DomainFailure $failure) {
                    self::assertSame('canonical_preload_limit', $failure->errorCode);
                }
            }
            self::assertSame(0, $f->objects->reads);
            $scope = $f->host->identity->scope;
            try {
                $f->lexical->catalog->tx->run(function () use ($f, $scope): void {
                    $f->lexical->catalog->tx->lockTenant($scope->tenantId, D\LockMode::Share);
                    $f->lexical->catalog->tx->requireUsageGuards($scope->tenantId, $scope->kbId,
                        [$f->ref()->revisionRef->sourceRef->sourceId->value]);
                });
                self::fail('Shared tenant alone authorized usage');
            } catch (\LogicException $failure) {
                self::assertSame('Usage KB guard required', $failure->getMessage());
            }
        });
    }

    public function testExpiryDuringSqlWorkRollsBackUsageBeforeCommit(): void
    {
        foreach (['selection', 'owner'] as $expiry) {
            Fixture::run(function (Fixture $f) use ($expiry): void {
                $f->host->now = Fixture::databaseNow($f->db())->plusSeconds($expiry === 'owner' ? -4 : 0);
                $selected = $f->select(ttl: $expiry === 'selection' ? 1 : 30);
                $request = $f->request($selected);
                $context = $f->host->context();
                $record = $f->selections->requireBound($context, $selected->token, $f->query(), $f->host->inventory->snapshot, 'run-1');
                $plan = $f->usages->plan($context->scope(), $record, $request);
                $policy = $f->checks()->prepare($context, $plan->resources, D\Operation::Disclose, $plan->binding());
                $buffer = (new CanonicalBuffer($f->catalog, new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()), $f->host))
                    ->preload($record, $request);
                try {
                    $f->usages->commit($context->scope(), $record, $request, $plan,
                        function (D\DisclosurePlan $current, D\UtcInstant $now) use ($f, $policy, $context, $buffer, $record, $request): D\PreparedDisclosure {
                            $policy->requireAllow($context, $current->binding(), $now);
                            $f->db()->execute('SELECT pg_sleep(1.1)');

                            return $buffer->prepare($record, $request, new D\UsageId($f->host->next()->value), $now,
                                PortFixture::validator(), $policy->disclosureDeadline());
                        });
                    self::fail('Expired authorization released response');
                } catch (D\DomainFailure $failure) {
                    self::assertSame(D\Outcome::Denied, $failure->outcome);
                    self::assertSame($expiry === 'selection' ? 'selection_expired' : 'POLICY_CHANGED', $failure->errorCode);
                }
                self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
                self::assertSame(0, $f->lexical->catalog->count('usage_refs'));
            });
        }
    }

    public function testPersistenceRejectsBufferWithoutCheckedReceiptDeadline(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $request = $f->request($selected);
            $context = $f->host->context();
            $record = $f->selections->requireBound($context, $selected->token, $f->query(), $f->host->inventory->snapshot, 'run-1');
            $plan = $f->usages->plan($context->scope(), $record, $request);
            $buffer = (new CanonicalBuffer($f->catalog, new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()), $f->host))
                ->preload($record, $request);
            try {
                $f->usages->commit($context->scope(), $record, $request, $plan,
                    fn (D\DisclosurePlan $current, D\UtcInstant $now): D\PreparedDisclosure =>
                        $buffer->prepare($record, $request, new D\UsageId($f->host->next()->value), $now, PortFixture::validator()));
                self::fail('Unchecked receipt deadline accepted');
            } catch (D\DomainFailure $failure) {
                self::assertSame('decision_receipt_required', $failure->errorCode);
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
            }
            self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
        });
    }
}

/** Real SQL and COMMIT, with a lost acknowledgement injected at the PDO boundary. */
final class CommitFaultPdo extends PDO
{
    public function __construct(private bool $commitBeforeFailure)
    {
        parent::__construct(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',
            getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null);
    }

    public function commit(): bool
    {
        if (!$this->commitBeforeFailure) {
            parent::rollBack();
            throw new PDOException('synthetic acknowledgement loss');
        }
        parent::commit();
        throw new PDOException('synthetic acknowledgement loss');
    }
}
