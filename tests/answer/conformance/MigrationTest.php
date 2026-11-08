<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use KnowKit\Answer\Adapter\PostgresAnswerMigrations;
use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:conformance')]
final class MigrationTest extends TestCase
{
    public function testInstalled001UpgradesIdempotentlyAndKeepsObservationAndEgressBytesImmutable(): void
    {
        BlobFixture::run(function (BlobFixture $blobs): void {
            $c = $blobs->catalog;
            $role = $c->db->pdo->query('SELECT current_user')->fetchColumn();
            $pdo = $c->db->pdo;
            $pdo->exec('RESET ROLE');
            $sql = (string) file_get_contents(Fixture::root() . '/packages/answer/migrations/postgres/001_answer_invocations.sql');
            $pdo->exec($sql);
            $pdo->prepare("INSERT INTO schema_migrations VALUES ('answer:001',?,clock_timestamp())")
                ->execute([hash('sha256', $sql)]);
            $migrations = new PostgresAnswerMigrations($pdo, $c->db->schema);
            $migrations->apply();
            $migrations->apply();
            self::assertSame(hash('sha256', $sql), $pdo->query("SELECT checksum FROM schema_migrations WHERE id='answer:001'")->fetchColumn());
            self::assertSame(1, (int) $pdo->query("SELECT count(*) FROM schema_migrations WHERE id='answer:002'")->fetchColumn());
            $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA ' . $c->db->schema . ' TO ' . $role);
            $pdo->exec('SET ROLE ' . $role);
            $c->source(3);
            $f = new ReplayFixture($c->db, $blobs->root, $c->scope());
            $f->seedAuthority();
            $f->completed();
            self::assertSame(A\OutcomeCode::Answered, $f->replay()->code);
            foreach (['observation', 'egress-check'] as $kind) {
                $ref = $f->payloads->put($f->scope, $kind, '{"synthetic":true}',
                    new A\DependencyManifest(new D\Sha256(str_repeat('1', 64))),
                    new \DateTimeImmutable($f->clock->now()->plusSeconds(60)->value));
                self::assertSame('{"synthetic":true}', $f->payloads->read($f->scope, $ref));
                CatalogFixture::sqlReject($pdo, 'UPDATE knowkit_answer_payloads SET kind=? WHERE tenant_id=? AND resource_id=?',
                    ['request', $f->scope->tenantId->value, $ref->resourceId->value]);
            }
            $this->expectException(D\DomainFailure::class);
            $this->expectExceptionMessage('storage_conflict');
            $f->payloads->put($f->scope, 'invented-kind', '{}', new A\DependencyManifest(new D\Sha256(str_repeat('1', 64))),
                new \DateTimeImmutable($f->clock->now()->plusSeconds(60)->value));
        });
    }
}
