<?php

declare(strict_types=1);

namespace KnowKit\Tests\Reading\Navigation;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Navigation\PdoNavigationStore;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Application\SourceNavigation;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Navigation as N;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Disclosure\CommitFaultPdo;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/retrieval/disclosure/CommitTest.php';

#[Group('reading:navigation')]
final class ServiceTest extends TestCase
{
    public function testStructureAndReadsAreBoundedAndPersistExactUnitUsageWithoutSearch(): void
    {
        Fixture::run(function (Fixture $f): void {
            $scope = $f->scope();
            $outline = $f->service->listStructure($scope, $f->revision, 1, 1);
            self::assertSame(D\Outcome::Success, $outline->status, $outline->errorCode ?? 'success');
            self::assertCount(1, $outline->nodes);
            self::assertSame('b-p1', $outline->nodes[0]->id->value);
            self::assertSame(Fixture::corpus()['texts']['p1'], $outline->nodes[0]->title);
            self::assertTrue($outline->truncated);
            $before = $f->count('outbox');
            $outcome = $f->service->readUnits($scope, $f->revision, N\UnitRange::pages(3, 4), 8192)->result;
            self::assertSame(D\Outcome::Success, $outcome->outcome, $outcome->errorCode ?? 'success');
            self::assertInstanceOf(N\UnitDisclosure::class, $outcome->data);
            $read = $outcome->data;
            self::assertSame(Fixture::corpus()['texts']['p3'], $read->units[0]->text());
            self::assertSame(3, $read->units[0]->physicalNo);
            self::assertSame('1', $read->units[0]->printedLabel);
            self::assertSame('empty', $read->units[1]->status);
            self::assertSame('', $read->units[1]->text());
            self::assertSame($scope->hostScopeHash, $read->hostScopeHash);
            self::assertSame(1, $f->count('navigation_usages'));
            self::assertSame($before + 1, $f->count('outbox'));
            self::assertSame(0, $f->count('selections'));
            self::assertSame(0, $f->upstream->host->searchCalls);
            $row = $f->upstream->db()->row('SELECT * FROM ' . $f->upstream->db()->table('navigation_usages') . ' WHERE tenant_id=? AND id=?',
                [$scope->tenantId->value, $read->usageRef->value]);
            self::assertSame($scope->hostScopeHash->value, $row['host_scope_hash']);
            $payload = StrictJson::decode($row['reference_json']);
            self::assertEquals($f->revision->toWire(), $payload->get('revisionRef'));
            self::assertSame(hash('sha256', $read->units[0]->text()), $payload->get('unitRefs')[0]->get('fragmentDigest'));
            self::assertStringNotContainsString('中文', $row['reference_json']);
            self::assertStringNotContainsString('Привет', $row['reference_json']);
            $segment = $f->service->readUnits($scope, $f->revision, N\UnitRange::segments(1), 8192)->result;
            self::assertSame(D\Outcome::Success, $segment->outcome, $segment->errorCode ?? 'segment');
            self::assertNull($segment->data->units[0]->physicalNo);
            self::assertSame('segment', $segment->data->units[0]->kind);
            $section = $f->service->readUnits($scope, $f->revision, N\UnitRange::section(new D\LocalId('b-p1')), 8192)->result;
            self::assertSame(D\Outcome::Success, $section->outcome, $section->errorCode ?? 'section');
            self::assertCount(2, $section->data->units);
        });
    }

    public function testInvalidSparsePageRangesAndByteLimitsNeverClampOrReturnPartialText(): void
    {
        Fixture::run(function (Fixture $f): void {
            foreach ([N\UnitRange::pages(900), N\UnitRange::pages(2), N\UnitRange::pages(1, 3), N\UnitRange::segments(99)] as $range) {
                $result = $f->service->readUnits($f->scope(), $f->revision, $range, 8192)->result;
                self::assertSame(D\Outcome::Incompatible, $result->outcome);
                self::assertSame('invalid_locator', $result->errorCode);
                self::assertNull($result->data);
            }
            foreach ([1, 8193] as $bytes) {
                $result = $f->service->readUnits($f->scope(), $f->revision, N\UnitRange::pages(1), $bytes)->result;
                self::assertSame(D\Outcome::Incompatible, $result->outcome);
                self::assertSame('limit_exceeded', $result->errorCode);
                self::assertNull($result->data);
            }
            self::assertSame(0, $f->count('navigation_usages'));
            $small = new SourceNavigation($f->upstream->host, $f->upstream->owner, $f->upstream->checks(), $f->store,
                new PdoCanonicalDocuments($f->upstream->db(), $f->upstream->objects, PortFixture::validator()),
                $f->upstream->host, $f->upstream->host, new N\Limits(1, 1, 2, 256));
            $reads = $f->upstream->objects->reads;
            self::assertSame(D\Outcome::Incompatible, $small->readUnits($f->scope(), $f->revision, N\UnitRange::pages(3, 4), 256)->result->outcome);
            self::assertSame($reads, $f->upstream->objects->reads);
            self::assertSame(D\Outcome::Incompatible, $small->listStructure($f->scope(), $f->revision, 2, 2)->status);
            $location = $small->locateQuote($f->scope(), $f->revision, 'echo', N\MatchMode::Exact);
            self::assertSame('ambiguous', $location->status);
            self::assertCount(2, $location->candidates);
            self::assertTrue($location->truncated);
        });
    }

    public function testRevokedMetadataQuoteAndPreviewFailBeforeReturningAnyPayload(): void
    {
        foreach (['structure', 'quote', 'read'] as $operation) {
            Fixture::run(function (Fixture $f) use ($operation): void {
                $f->upstream->objects->beforeOpen = fn () => $f->upstream->revoke();
                $result = $this->call($f, $operation);
                self::assertSame('denied', $result[0]);
                self::assertSame([], $result[1]);
                self::assertSame(0, $f->count('navigation_usages'));
            });
        }
        Fixture::run(function (Fixture $f): void {
            $f->upstream->host->decision = D\Decision::Deny;
            $reads = $f->upstream->objects->reads;
            self::assertSame('denied', $this->call($f, 'structure')[0]);
            self::assertSame($reads, $f->upstream->objects->reads);
        });
    }

    public function testTenantProjectEligibilityAndHostChangesCannotLeakIdenticalCanonicalContent(): void
    {
        Fixture::run(function (Fixture $f): void {
            $scope = $f->scope();
            $stale = new D\Scope($scope->tenantId, $scope->kbId, $scope->actorId, $scope->purpose,
                new D\Decimal('1'), $scope->hostScopeHash);
            $staleResult = $f->service->locateQuote($stale, $f->revision, '中文引用', N\MatchMode::Exact);
            self::assertSame('denied', $staleResult->status);
            self::assertSame('POLICY_CHANGED', $staleResult->errorCode);
            $other = new D\Scope($scope->tenantId, $scope->kbId, $scope->actorId, $scope->purpose,
                $scope->policyEpoch, new D\Sha256(str_repeat('b', 64)));
            self::assertSame('denied', $f->service->locateQuote($other, $f->revision, '中文引用', N\MatchMode::Exact)->status);
            $foreign = new D\DocumentRevisionRef(new D\SourceVersionRef(new D\TenantId(PortFixture::id(99)),
                $f->revision->sourceRef->sourceId, $f->revision->sourceRef->sourceVersionId, $f->revision->sourceRef->sourceDigest),
                $f->revision->documentRevisionId, $f->revision->irDigest, $f->revision->parserSignature);
            self::assertSame('denied', $f->service->locateQuote($scope, $foreign, '中文引用', N\MatchMode::Exact)->status);
            $f->upstream->host->eligible = [];
            $reads = $f->upstream->objects->reads;
            self::assertSame('denied', $this->call($f, 'quote')[0]);
            self::assertSame($reads, $f->upstream->objects->reads);
            $f->upstream->host->eligible = [$f->revision->sourceRef->sourceVersionId];
            $f->upstream->objects->beforeOpen = function () use ($f, $other): void {
                $f->upstream->host->identity = new D\Access\PolicyContext($other, 'teacher', ['source:read']);
            };
            self::assertSame('denied', $this->call($f, 'read')[0]);
            self::assertSame(0, $f->count('navigation_usages'));
        });
    }

    public function testHistoricalRevisionNeverReanchorsAndTypedFailuresStaySeparate(): void
    {
        Fixture::run(function (Fixture $f): void {
            $original = $f->revision;
            // The upstream publication has different bytes/revision; navigation still uses this exact historical IR.
            self::assertNotEquals($f->upstream->ref()->revisionRef, $original);
            $located = $f->service->locateQuote($f->scope(), $original, 'Привет мир 😀', N\MatchMode::Exact);
            self::assertSame('unique_exact', $located->status);
            self::assertSame($original, $located->revision);
            $before = Jcs::canonicalize($located->candidates[0]->span->toWire());
            $unknown = new D\DocumentRevisionRef($original->sourceRef, new D\DocumentRevisionId(PortFixture::id(39999)), $original->irDigest, $original->parserSignature);
            self::assertSame('unknown', $f->service->locateQuote($f->scope(), $unknown, 'Привет', N\MatchMode::Exact)->status);
            $wrong = new D\DocumentRevisionRef($original->sourceRef, $original->documentRevisionId, new D\IrDigest(str_repeat('f', 64)), $original->parserSignature);
            self::assertSame('incompatible', $f->service->locateQuote($f->scope(), $wrong, 'Привет', N\MatchMode::Exact)->status);
            $f->upstream->host->decision = D\Decision::Unknown;
            self::assertSame('unknown', $this->call($f, 'read')[0]);
            $f->upstream->host->decision = D\Decision::Allow;
            unset($f->upstream->objects->bodies[$original->irDigest->value]);
            self::assertSame('unavailable', $this->call($f, 'read')[0]);
            self::assertSame($before, Jcs::canonicalize($located->candidates[0]->span->toWire()));
        });
    }

    public function testPolicyEpochChangeAndUnitPurgeDuringPreloadPreventDisclosure(): void
    {
        foreach (['policy', 'purge'] as $change) {
            Fixture::run(function (Fixture $f) use ($change): void {
                $f->upstream->objects->beforeOpen = function () use ($f, $change): void {
                    $tx = $f->upstream->lexical->catalog->tx;
                    $tx->run(function () use ($f, $tx, $change): void {
                        $tx->lockTenant($f->scope()->tenantId, D\LockMode::Update);
                        if ($change === 'policy') {
                            $f->upstream->db()->execute('UPDATE ' . $f->upstream->db()->table('knowledge_bases') . ' SET kb_policy_epoch=kb_policy_epoch+1 WHERE tenant_id=? AND id=?',
                                [$f->scope()->tenantId->value, $f->scope()->kbId->value]);

                            return;
                        }
                        $f->upstream->db()->execute('UPDATE ' . $f->upstream->db()->table('resource_ledger')
                            . " SET state='deleting' WHERE tenant_id=? AND id IN (SELECT text_resource_id FROM " . $f->upstream->db()->table('pages') . ' WHERE tenant_id=? AND document_id=?)',
                            [$f->scope()->tenantId->value, $f->scope()->tenantId->value, $f->documentId->value]);
                    });
                };
                self::assertSame($change === 'policy' ? 'denied' : 'unavailable', $this->call($f, 'read')[0]);
                self::assertSame(0, $f->count('navigation_usages'));
            });
        }
    }

    public function testCommitAcknowledgementLossNeverReleasesBuffer(): void
    {
        foreach ([true, false] as $committed) {
            Fixture::run(function (Fixture $f) use ($committed): void {
                $up = $f->upstream;
                $pdo = new CommitFaultPdo($committed);
                $role = $up->db()->execute('SELECT current_user')->fetchColumn();
                $pdo->exec('SET ROLE "' . $role . '"');
                try {
                    $db = new Connection($pdo, $up->db()->schema);
                    $tx = new PdoCatalogTransaction($db);
                    $dependencies = new PdoDependencyStore($db, $tx, $f->scope()->tenantId, $up->host);
                    $store = new PdoNavigationStore($db, $tx, new PdoUsages($db, $tx, $dependencies), PortFixture::validator(), $up->host);
                    $service = new SourceNavigation($up->host, $up->owner, $up->checks(), $store,
                        new PdoCanonicalDocuments($db, $up->objects, PortFixture::validator()), $up->host, $up->host);
                    $result = $service->readUnits($f->scope(), $f->revision, N\UnitRange::pages(1), 8192)->result;
                    self::assertSame(D\Outcome::Unknown, $result->outcome, $result->errorCode ?? 'unknown');
                    self::assertSame('commit_unknown', $result->errorCode);
                    self::assertNull($result->data);
                    self::assertSame($committed ? 1 : 0, $f->count('navigation_usages'));
                } finally {
                    $pdo->exec('RESET ROLE');
                }
            });
        }
    }

    /** @return array{string,array} */
    private function call(Fixture $f, string $operation): array
    {
        if ($operation === 'structure') {
            $result = $f->service->listStructure($f->scope(), $f->revision, 2, 24);

            return [$result->status->value, $result->nodes];
        }
        if ($operation === 'quote') {
            $result = $f->service->locateQuote($f->scope(), $f->revision, 'Привет', N\MatchMode::Exact);

            return [$result->status, $result->candidates];
        }
        $result = $f->service->readUnits($f->scope(), $f->revision, N\UnitRange::pages(1), 8192)->result;

        return [$result->outcome->value, $result->data?->units ?? []];
    }
}
