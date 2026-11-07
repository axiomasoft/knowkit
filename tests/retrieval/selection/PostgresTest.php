<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Selection;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoSelectionCatalog;
use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Domain\SelectionRecordCodec as RecordCodec;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lexical/bootstrap.php';
require_once __DIR__ . '/DisclosureProbe.php';

#[Group('retrieval:selection')]
final class PostgresTest extends TestCase
{
    public function testActualLexicalExactAndMultilingualEvidenceHasNoPseudoMatches(): void
    {
        LexicalFixture::run(function (LexicalFixture $f): void {
            foreach (LexicalFixture::corpus()['queries'] as $case) {
                $query = (new Normalizer())->bind(new D\Query($case['query']), Fixture::profile(), D\SelectionLimits::profile('compact'));
                $list = $f->index->search($f->scope(), $query->normalized, 20);
                self::assertSame($case['expected'], $f->names($list), $case['id']);
                foreach ($list->candidates as $candidate) {
                    self::assertGreaterThan(0, $candidate->score);
                    self::assertSame(LexicalFixture::id(1), $candidate->ref->revisionRef->sourceRef->tenantId->value);
                }
                self::assertSame($case['query'], $query->original->text());
            }
        });
    }

    public function testPostgresRoundTripPinsExactSnapshotAndStoresOnlyTokenHash(): void
    {
        LexicalFixture::run(function (LexicalFixture $lexical): void {
            $snapshot = $this->completeSnapshot($lexical);
            $f = new Fixture();
            $catalog = $this->catalog($lexical);
            $f->inventory = $catalog->load($f->identity->scope, $snapshot, new D\GenerationId(Fixture::id(14)));
            $f->eligible = [$lexical->sets['exact']->chunkSet->revisionRef->sourceRef->sourceVersionId];
            $store = new PdoSelections($lexical->catalog->db, new RecordCodec(PortFixture::validator()));
            $service = new Selections($f->checks(), $lexical->index, $catalog, $store,
                new BoundedSelector(), $f, $f, PortFixture::validator());
            $query = $f->query(text: 'DOC-2026/0042');
            $result = $f->retrieve($query, $service);
            self::assertSame(D\Outcome::Success, $result->result->outcome, $result->result->errorCode ?? 'success');
            $record = $service->requireBound($f->context(), $result->token, $query, $snapshot, 'run-1');
            self::assertSame('DOC-2026/0042', $record->query->original->text());
            self::assertEquals($snapshot, $record->snapshot);
            self::assertSame($lexical->sets['exact']->chunkSet->chunks[0]->ref->chunkId->value,
                $record->selection->toWire()->get('candidateRefs')[0]->get('chunkId'));
            $row = $lexical->catalog->db->row('SELECT * FROM ' . $lexical->catalog->db->table('selections') . ' WHERE tenant_id=? AND id=?',
                [Fixture::id(1), $record->selection->id()->value]);
            self::assertSame($result->token->hash()->value, $row['token_hash']);
            self::assertSame(str_repeat('a', 64), $row['host_scope_hash']);
            self::assertStringNotContainsString($result->token->reveal(), json_encode($row, JSON_THROW_ON_ERROR));
            $this->assertStorageNegatives($f, $store, $result->token, $record);
            $this->assertForgedRefCannotPersist($f, $store, $record);
            $this->assertRecordSchemaAndLegacyRejection($lexical, $f, $store, $record);
            $this->assertUsageAndRevokeBoundary($lexical, $f, $record);
        });
    }

    public function testCatalogRejectsMembershipDigestAndForeignScope(): void
    {
        LexicalFixture::run(function (LexicalFixture $lexical): void {
            $f = new Fixture();
            $catalog = $this->catalog($lexical);
            $snapshot = new D\SnapshotRef($f->identity->scope->tenantId, $f->identity->scope->kbId,
                new D\SnapshotId(Fixture::id(110)), new D\Fingerprint(str_repeat('b', 64)));
            $this->failure(fn () => $catalog->load($f->identity->scope, $snapshot, new D\GenerationId(Fixture::id(14))),
                D\Outcome::Incompatible, 'snapshot_membership_mismatch');
            $snapshot = $this->completeSnapshot($lexical);
            $wrong = new D\SnapshotRef($snapshot->tenantId, $snapshot->kbId, $snapshot->snapshotId, new D\Fingerprint(str_repeat('c', 64)));
            $this->failure(fn () => $catalog->load($f->identity->scope, $wrong, new D\GenerationId(Fixture::id(14))),
                D\Outcome::Incompatible, 'snapshot_mismatch');
            $foreign = new D\SnapshotRef(new D\TenantId(Fixture::id(2)), $snapshot->kbId, $snapshot->snapshotId, $snapshot->manifestDigest);
            $this->failure(fn () => $catalog->load($f->identity->scope, $foreign, new D\GenerationId(Fixture::id(14))),
                D\Outcome::Denied, 'scope_mismatch');
        });
    }

    private function catalog(LexicalFixture $f): PdoSelectionCatalog
    {
        $documents = [];
        foreach ($f->data as $data) {
            $ir = new D\IrDocument(StrictJson::decode(json_encode($data['ir'], JSON_THROW_ON_ERROR)), PortFixture::validator());
            $documents[hash('sha256', $ir->canonical())] = $ir;
        }

        return new PdoSelectionCatalog($f->catalog->db, new CanonicalChunkSets(array_values($f->sets)), PortFixture::validator(),
            $documents, new CanonicalUnits());
    }

    /** P2.8 intentionally omitted one membership; publish a separate complete immutable snapshot. */
    private function completeSnapshot(LexicalFixture $f): D\SnapshotRef
    {
        $db = $f->catalog->db;
        $db->execute('INSERT INTO ' . $db->table('publication_snapshots') . ' VALUES (?,?,?,?,?,false)',
            [Fixture::id(1), Fixture::id(112), Fixture::id(100), str_repeat('b', 64), '2026-11-08T00:00:00Z']);
        $db->execute('INSERT INTO ' . $db->table('snapshot_generations') . ' VALUES (?,?,?,?,?)',
            [Fixture::id(1), Fixture::id(112), Fixture::id(100), Fixture::id(14), 'postgres-fts-simple-v1']);
        foreach ($f->sets as $result) {
            $set = $result->chunkSet;
            $source = $set->revisionRef->sourceRef;
            if ($source->tenantId->value !== Fixture::id(1)) {
                continue;
            }
            $db->execute('INSERT INTO ' . $db->table('snapshot_memberships')
                . ' (tenant_id,id,snapshot_id,kb_id,source_id,source_version_id,revision_id,chunk_set_id,generation_id,profile_id)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?)', [$source->tenantId->value, $f->catalog->ids->next()->value, Fixture::id(112), Fixture::id(100),
                $source->sourceId->value, $source->sourceVersionId->value, $set->revisionRef->documentRevisionId->value,
                $set->chunkSetId->value, Fixture::id(14), 'postgres-fts-simple-v1']);
        }
        $db->execute('UPDATE ' . $db->table('publication_snapshots') . ' SET sealed=true WHERE tenant_id=? AND id=?',
            [Fixture::id(1), Fixture::id(112)]);

        return new D\SnapshotRef(new D\TenantId(Fixture::id(1)), new D\KbId(Fixture::id(100)),
            new D\SnapshotId(Fixture::id(112)), new D\Fingerprint(str_repeat('b', 64)));
    }

    private function assertStorageNegatives(Fixture $f, PdoSelections $store, D\SelectionToken $token, D\SelectionRecord $record): void
    {
        $this->failure(fn () => $store->put($f->identity->scope, $token->hash(), $record), D\Outcome::Incompatible, 'storage_conflict');
        $s = $f->identity->scope;
        $foreign = new D\Scope(new D\TenantId(Fixture::id(2)), $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch, $s->hostScopeHash);
        self::assertNull($store->get($foreign, $token->hash()));
        $this->failure(fn () => $store->put($foreign, D\SelectionToken::issue()->hash(), $record), D\Outcome::Denied, 'scope_mismatch');
    }

    private function assertForgedRefCannotPersist(Fixture $f, PdoSelections $store, D\SelectionRecord $record): void
    {
        $s = $record->selection->toWire();
        $ref = $s->get('candidateRefs')[0]->with('chunkDigest', str_repeat('f', 64));
        $selection = new D\Selection($s->with('selectionId', Fixture::id(9999))->with('candidateRefs', [$ref]), PortFixture::validator());
        $forged = new D\SelectionRecord($selection, $record->context, $record->query, $record->snapshot, $record->createdAt);
        $this->failure(fn () => $store->put($f->identity->scope, D\SelectionToken::issue()->hash(), $forged),
            D\Outcome::Incompatible, 'candidate_not_in_manifest');
    }

    private function assertRecordSchemaAndLegacyRejection(LexicalFixture $lexical, Fixture $f, PdoSelections $store, D\SelectionRecord $record): void
    {
        $db = $lexical->catalog->db;
        $table = $db->table('selections');
        $sql = 'INSERT INTO ' . $table . ' SELECT (jsonb_populate_record(NULL::' . $table
            . ',to_jsonb(s)||jsonb_build_object(\'id\',CAST(? AS text),\'token_hash\',CAST(? AS text),\'record_json\',?::jsonb))).*'
            . ' FROM ' . $table . ' s WHERE tenant_id=? AND id=?';
        $token = D\SelectionToken::issue();
        $this->failure(fn () => $db->execute($sql, [Fixture::id(9998), $token->hash()->value, '[]',
            Fixture::id(1), $record->selection->id()->value]), D\Outcome::Incompatible, 'storage_conflict');
        $db->execute($sql, [Fixture::id(9997), $token->hash()->value, 'null', Fixture::id(1), $record->selection->id()->value]);
        $this->failure(fn () => $store->get($f->identity->scope, $token->hash()), D\Outcome::Incompatible, 'selection_record_version');
        $token = D\SelectionToken::issue();
        $db->execute($sql, [Fixture::id(9996), $token->hash()->value, '{"recordVersion":"selection-record:1"}',
            Fixture::id(1), $record->selection->id()->value]);
        $this->failure(fn () => $store->get($f->identity->scope, $token->hash()), D\Outcome::Incompatible, 'selection_record_invalid');
    }

    private function assertUsageAndRevokeBoundary(LexicalFixture $lexical, Fixture $f, D\SelectionRecord $record): void
    {
        $probe = new DisclosureProbe($lexical->catalog, $f, $record);
        $first = $probe->disclose();
        $second = $probe->disclose();
        self::assertNotSame($first->toWire()->get('usageId'), $second->toWire()->get('usageId'));
        self::assertSame($lexical->data['exact']['text'], $first->toWire()->get('fragments')[0]->get('text'));
        self::assertSame(2, $lexical->catalog->count('evidence_usages'));
        self::assertSame(2, $lexical->catalog->count('usage_refs'));
        $rows = $lexical->catalog->db->execute('SELECT u.host_scope_hash,u.prepared_at,r.exact_version_ref FROM '
            . $lexical->catalog->db->table('evidence_usages') . ' u JOIN ' . $lexical->catalog->db->table('usage_refs')
            . ' r ON r.tenant_id=u.tenant_id AND r.usage_id=u.id')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            self::assertSame(str_repeat('a', 64), $row['host_scope_hash']);
            self::assertEquals(new \DateTimeImmutable($f->now->value), new \DateTimeImmutable($row['prepared_at']));
            self::assertEquals($record->selection->candidateRefs()[0]->toWire(),
                StrictJson::decode($row['exact_version_ref'])->get('fragmentRefs')[0]->get('chunkRef'));
        }
        $source = $record->selection->candidateRefs()[0]->revisionRef->sourceRef;
        $lexical->catalog->db->execute('UPDATE ' . $lexical->catalog->db->table('sources')
            . " SET lifecycle_state='revoked',lifecycle_epoch=lifecycle_epoch+1 WHERE tenant_id=? AND id=?",
            [$source->tenantId->value, $source->sourceId->value]);
        $this->failure(fn () => $probe->disclose(), D\Outcome::Denied, 'ancestor_unusable');
        self::assertSame(2, $lexical->catalog->count('evidence_usages'));
    }

    private function failure(\Closure $action, D\Outcome $outcome, string $code): void
    {
        try {
            $action();
            self::fail('Forbidden input accepted');
        } catch (D\DomainFailure $error) {
            self::assertSame($outcome, $error->outcome);
            self::assertSame($code, $error->errorCode);
        }
    }
}
