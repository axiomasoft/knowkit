<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\Memory\InMemoryReferenceCache;
use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Cache\Fixture as Cache;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as Disclosure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/retrieval/cache/Fixture.php';
require_once dirname(__DIR__, 2) . '/retrieval/disclosure/bootstrap.php';

#[Group('ops:resilience')]
final class CacheTest extends TestCase
{
    public function testCacheHitRacesIndependentRevokeWithoutFreshBytesOrHistoricalCitationRewriting(): void
    {
        Disclosure::run(function (Disclosure $f): void {
            $cache = $this->wire($f);
            $first = $f->select();
            $request = $f->request($first);
            $original = $f->disclose(selected: $first, request: $request);
            self::assertSame(D\Outcome::Success, $original->result->outcome);
            $history = $original->result->data->canonical();
            $citations = $f->db()->execute('SELECT to_jsonb(c)::text FROM citations c ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
            $revoker = new Process(db: $f->db(), operation: 'revoke', options: [
                'scope' => $f->host->identity->scope,
                'sourceId' => $f->ref()->revisionRef->sourceRef->sourceId,
                'precommit' => true,
            ]);
            $cache->onGet = function ($scope, $key, $entry) use ($f, $cache, $revoker) {
                self::assertNotNull($entry);
                self::assertFalse($f->db()->pdo->inTransaction());
                $revoker->go();
                $revoker->receive('PRECOMMIT');
                $revoker->go();
                self::assertSame('committed', $revoker->result()['status']);
                $cache->onGet = null;

                return $entry;
            };
            // No invalidation is delivered: the stale entry must actually be hit.
            $hit = $f->select();
            self::assertSame(1, $cache->hits);
            $reads = $f->objects->reads;
            $denied = $f->disclose($hit);
            self::assertSame(D\Outcome::Denied, $denied->result->outcome);
            self::assertNull($denied->result->data);
            self::assertSame($reads, $f->objects->reads);
            $replay = $f->disclose(selected: $first, request: $request);
            self::assertInstanceOf(D\DisclosureReplay::class, $replay->result->data);
            self::assertStringNotContainsString($f->lexical->data['exact']['text'], json_encode($replay->result->data, JSON_THROW_ON_ERROR));
            self::assertSame($history, $original->result->data->canonical());
            self::assertSame($citations, $f->db()->execute('SELECT to_jsonb(c)::text FROM citations c ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
            Fixture::expect(case: 'CACHE-HIT-REVOKE', actual: [
                'hits' => $cache->hits,
                'disclosure' => $denied->result->outcome->value,
                'newUsages' => (int) Fixture::reconnect($f->db())->execute('SELECT count(*) FROM evidence_usages')->fetchColumn() - 1,
                'historicalEvidence' => 'unchanged',
                'freshReads' => $f->objects->reads - $reads,
            ]);
        });
    }

    private function wire(Disclosure $f): Cache
    {
        $cache = new Cache();
        $cache->host = $f->host;
        $cache->memory = new InMemoryReferenceCache($f->host);
        $f->selections = new Selections(
            policy: $f->checks(),
            index: $f->lexical->index,
            catalog: $f->catalog,
            store: new PdoSelections(db: $f->db(), codec: new D\SelectionRecordCodec(PortFixture::validator())),
            selector: new BoundedSelector(),
            clock: $f->host,
            ids: $f->host,
            validator: PortFixture::validator(),
            cache: $cache,
        );
        $canonical = new CanonicalBuffer(
            catalog: $f->catalog,
            documents: new PdoCanonicalDocuments(db: $f->db(), blobs: $f->objects, validator: PortFixture::validator()),
            ids: $f->host,
        );
        $f->service = new Disclosures(
            selections: $f->selections,
            policy: $f->checks(),
            canonical: $canonical,
            store: $f->usages,
            ids: $f->host,
            validator: PortFixture::validator(),
        );

        return $cache;
    }
}
