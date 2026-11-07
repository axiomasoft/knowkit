<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Reading;

use KnowKit\Adapter\LocalBlob\Preview\BoundedObjects;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Reading\PdoReadingStore;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Application\Reading\CanonicalReads;
use KnowKit\Application\Reading\Readings;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Reading as R;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Disclosure\CommitFaultPdo;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as DisclosureFixture;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/disclosure/CommitTest.php';
require_once dirname(__DIR__, 2) . '/storage/blobs/bootstrap.php';

#[Group('retrieval:reading')]
final class BoundaryTest extends TestCase
{
    public function testAmbiguousCommitReturnsNoBodyAndRetryIsMetadataOnly(): void
    {
        foreach ([true, false] as $committed) {
            Fixture::run(function (Fixture $f) use ($committed): void {
                $upstream = $f->upstream;
                $pdo = new CommitFaultPdo($committed);
                $role = $upstream->db()->execute('SELECT current_user')->fetchColumn();
                $pdo->exec('SET ROLE "' . $role . '"');
                try {
                    $db = new Connection($pdo, $upstream->db()->schema);
                    $tx = new PdoCatalogTransaction($db);
                    $dependencies = new PdoDependencyStore($db, $tx, $f->scope()->tenantId, $upstream->host);
                    $usages = new PdoUsages($db, $tx, $dependencies);
                    $store = new PdoReadingStore($db, $tx, $usages, $dependencies, PortFixture::validator(), $upstream->host);
                    $canonical = new CanonicalReads(new PdoCanonicalDocuments($upstream->db(), $upstream->objects, PortFixture::validator()),
                        $store, new BoundedObjects($upstream->objects));
                    $service = new Readings($store, $upstream->checks(), $canonical, $upstream->host);
                    $request = $f->request();
                    $result = $service->read($upstream->host->context(), $request);
                    self::assertSame(D\Outcome::Unknown, $result->outcome);
                    self::assertSame('commit_unknown', $result->errorCode);
                    self::assertNull($result->data);
                    $retry = $f->read($request);
                    self::assertSame(D\Outcome::Success, $retry->outcome, $retry->errorCode ?? 'success');
                    self::assertInstanceOf($committed ? D\DisclosureReplay::class : R\ReadResponse::class, $retry->data);
                } finally {
                    $pdo->exec('RESET ROLE');
                }
            });
        }
    }

    public function testOldCitationSurvivesExpiredSelectionWithoutExtendingItsAuthorization(): void
    {
        Fixture::run(function (Fixture $f): void {
            $upstream = $f->upstream;
            $upstream->host->now = DisclosureFixture::databaseNow($upstream->db());
            $selected = $upstream->select(ttl: 1);
            $issued = $upstream->disclose($selected)->result;
            self::assertSame(D\Outcome::Success, $issued->outcome, $issued->errorCode ?? 'success');
            $citation = new D\Ulid($issued->data->toWire()->get('fragments')[0]->get('citation')->get('citationId'));
            $upstream->db()->execute('SELECT pg_sleep(1.1)');
            $request = $f->request();
            $request = new R\ReadRequest($citation, $request->attemptId, R\ReadTarget::quote(), $request->limits,
                $request->maxBytes, $request->expiresAt);
            $result = $f->read($request);
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            self::assertSame($citation->value, $result->data->citation->id()->value);
            $upstream->host->decision = D\Decision::Deny;
            $next = new R\ReadRequest($citation, $upstream->host->next(), $request->target, $request->limits,
                $request->maxBytes, $request->expiresAt);
            self::assertSame(D\Outcome::Denied, $f->read($next)->outcome);
        });
    }

    public function testExpiryDuringSqlWorkRollsBackUsageOutboxAndReadReceipt(): void
    {
        Fixture::run(function (Fixture $f): void {
            $request = $f->request(expires: DisclosureFixture::databaseNow($f->upstream->db())->plusSeconds(1));
            $context = $f->upstream->host->context();
            $record = $f->record();
            $plan = $f->store->plan($f->scope(), $record);
            $decision = $f->upstream->checks()->prepare($context, $plan->resources, D\Operation::Disclose, $plan->binding());
            $body = new R\ReadBody(Fixture::corpus()['page'], 'text/plain', new JsonObject());
            $before = [];
            foreach (['evidence_usages', 'outbox', 'idempotency_receipts'] as $table) {
                $before[$table] = $f->upstream->lexical->catalog->count($table);
            }
            try {
                $f->store->commit($f->scope(), $record, $request, $plan, $body, null,
                    function (D\DisclosurePlan $current, D\UtcInstant $now) use ($f, $context, $decision): D\DisclosureDeadline {
                        $decision->requireAllow($context, $current->binding(), $now);
                        $f->upstream->db()->execute('SELECT pg_sleep(1.1)');

                        return $decision->disclosureDeadline();
                    });
                self::fail('Expired request committed');
            } catch (D\DomainFailure $error) {
                self::assertSame('read_expired', $error->errorCode);
            }
            foreach ($before as $table => $count) {
                self::assertSame($count, $f->upstream->lexical->catalog->count($table));
            }
        });
    }

    public function testRealLocalBlobPreviewIsBoundedVerifiedAndReauthorizes(): void
    {
        BlobFixture::run(function (BlobFixture $f): void {
            $blob = $f->put(bytes: Fixture::corpus()['asset']);
            $object = new R\BlobObject(new D\ResourceId(BlobFixture::id(300)), $blob, 'image/png');
            $reader = new BoundedObjects($f->store);
            self::assertSame(Fixture::corpus()['asset'], $reader->read($object, 1024));
            self::assertFileExists($f->path($blob));
            try {
                $reader->read($object, 1);
                self::fail('Bound bypassed');
            } catch (D\DomainFailure $error) {
                self::assertSame('bounded_read_required', $error->errorCode);
            }
            $f->catalog->owner->decision = D\Decision::Deny;
            try {
                $reader->read($object, 1024);
                self::fail('Revoked preview read');
            } catch (D\DomainFailure $error) {
                self::assertSame(D\Outcome::Denied, $error->outcome);
            }
        });
    }
}
