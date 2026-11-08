<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Vector;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PublicationFixture.php';

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\PgVector\Domain\DocumentVectors;
use KnowKit\Port\Outbox;
use KnowKit\Tests\Publication\Manifest\Fixture as Publication;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:vector')]
final class GenerationTest extends TestCase
{
    public function testAtomicReembedPreservesDocumentCitationIdentityAndPinnedHistory(): void
    {
        Fixture::publication(function (Publication $f): void {
            $first = new PublicationFixture($f, Fixture::space());
            $old = $first->seal(501);
            $receipt = $first->publish($old, '0', 'vector-first');
            $scope = $first->scope($receipt, 501);
            $historical = $first->index->search($scope, new D\Query('question'), 2, $first->space);
            $this->assertPointer($f, $receipt);
            $replacement = new PublicationFixture($f, Fixture::space(['revision' => 'r2']));
            $new = $replacement->seal(502);
            $this->assertPointer($f, $receipt);
            $next = $replacement->publish($new, '1', 'vector-second');
            $this->assertPointer($f, $next);
            self::assertNotSame($old->digest->value, $new->digest->value);
            self::assertSame($old->sets[0]->chunkSet, $new->sets[0]->chunkSet);
            $rebuilt = $replacement->index->search($replacement->scope($next, 502), new D\Query('question'), 2, $replacement->space);
            self::assertEquals($historical->candidates, $rebuilt->candidates);
            self::assertEquals($historical->candidates, $first->index->search($scope, new D\Query('question'), 2, $first->space)->candidates);
            self::assertSame('retired', $f->state(501));
            self::assertSame('active', $f->state(502));
            self::assertEquals($next, $replacement->publish($new, '1', 'vector-second'));
            foreach ($historical->candidates as $i => $candidate) {
                self::assertSame($f->set->chunkSet->chunks[$i]->ref, $candidate->ref);
                self::assertSame($f->set->chunkSet->chunks[$i]->documentId, $new->sets[0]->chunkSet->chunks[$i]->documentId);
                self::assertSame($f->set->chunkSet->chunks[$i]->spans, $new->sets[0]->chunkSet->chunks[$i]->spans);
            }
        });
    }

    public function testMissingVectorCannotSealAndOldPointerRemainsActive(): void
    {
        Fixture::publication(function (Publication $f): void {
            $first = new PublicationFixture($f, Fixture::space());
            $receipt = $first->publish($first->seal(501), '0', 'initial');
            $missing = new PublicationFixture($f, Fixture::space(), documents: new DocumentVectors(Fixture::space(), 'passage', [], []));
            $candidate = $missing->stage(502);
            LexicalFixture::failure(fn () => $missing->index($candidate), 'missing_vector');
            $this->assertPointer($f, $receipt);
            self::assertSame('building', $f->state(502));
            $first->index($candidate);
            $db = $f->jobs->catalog->db;
            $db->execute('DELETE FROM vector_documents WHERE tenant_id=? AND generation_id=? AND chunk_id=?',
                [Fixture::id(1), Fixture::id(502), $f->set->chunkSet->chunks[0]->ref->chunkId->value]);
            LexicalFixture::failure(fn () => $first->staging->seal($first->lease, $candidate), 'vector_generation_unreadable', D\Outcome::Unavailable);
            self::assertSame('failed', $f->state(502));
            $this->assertPointer($f, $receipt);
            self::assertCount(2, $first->index->search($first->scope($receipt, 501), new D\Query('question'), 2, $first->space)->candidates);
        });
    }

    public function testRebuildCrashRollsBackMembershipsAndActivationOutboxAtomically(): void
    {
        Fixture::publication(function (Publication $f): void {
            $first = new PublicationFixture($f, Fixture::space());
            $old = $first->publish($first->seal(501), '0', 'initial');
            $candidate = $first->stage(502);
            $catalog = $f->jobs->catalog;
            try {
                $catalog->tx->run(function () use ($catalog, $first, $f): void {
                    $catalog->tx->lockTenant($f->jobs->scope->tenantId, D\LockMode::Update);
                    $first->index->index(new D\GenerationId(Fixture::id(502)), $f->set->chunkSet, $first->space);
                    throw new \RuntimeException('simulated rebuild crash before commit');
                });
                self::fail('Crash injection did not run');
            } catch (\RuntimeException $failure) {
                self::assertSame('simulated rebuild crash before commit', $failure->getMessage());
            }
            self::assertSame(0, (int) $catalog->db->execute('SELECT count(*) FROM vector_documents WHERE generation_id=?', [Fixture::id(502)])->fetchColumn());
            self::assertSame(0, (int) $catalog->db->execute('SELECT count(*) FROM index_memberships WHERE generation_id=?', [Fixture::id(502)])->fetchColumn());
            $this->assertPointer($f, $old);
            $first->index($candidate);
            $first->staging->seal($first->lease, $candidate);
            $this->assertPointer($f, $old);
            $outbox = new class implements Outbox {
                public function append(D\DomainEvent $event): void
                {
                    throw new D\DomainFailure(D\Outcome::Unavailable, 'activation_outbox_crash');
                }
            };
            $broken = new PublicationFixture($f, Fixture::space(), activationOutbox: $outbox);
            LexicalFixture::failure(fn () => $broken->publish($candidate, '1', 'replacement'), 'activation_outbox_crash', D\Outcome::Unavailable);
            $this->assertPointer($f, $old);
            self::assertSame('active', $f->state(501));
            self::assertSame('sealed', $f->state(502));
            self::assertSame(1, $catalog->count('publication_snapshots'));
            $new = $first->publish($candidate, '1', 'replacement');
            $this->assertPointer($f, $new);
        });
    }

    public function testIndexReplayTenantGuardAndSealedSqlImmutability(): void
    {
        Fixture::publication(function (Publication $f): void {
            $v = new PublicationFixture($f, Fixture::space());
            $candidate = $v->stage(501);
            $receipt = $v->index($candidate);
            self::assertEquals($receipt, $v->index($candidate));
            self::assertSame(Fixture::space()->digest()->value, $receipt->embeddingSpaceDigest->value);
            self::assertSame($f->set->manifestDigest->value, $receipt->manifestDigest->value);
            $catalog = $f->jobs->catalog;
            $catalog->tx->run(function () use ($catalog, $v, $f): void {
                $catalog->tx->lockTenant(new D\TenantId(Fixture::id(2)), D\LockMode::Update);
                LexicalFixture::failure(fn () => $v->index->index(new D\GenerationId(Fixture::id(501)), $f->set->chunkSet, $v->space),
                    'scope_mismatch', D\Outcome::Denied);
            });
            CatalogFixture::sqlReject($catalog->db->pdo, "UPDATE vector_generations SET space_json='{}'");
            CatalogFixture::sqlReject($catalog->db->pdo, "UPDATE vector_documents SET embedding=CAST('[0,0,0]' AS public.vector)");
            $v->staging->seal($v->lease, $candidate);
            LexicalFixture::failure(fn () => $v->index($candidate), 'generation_sealed');
            CatalogFixture::sqlReject($catalog->db->pdo, 'DELETE FROM vector_documents');
            CatalogFixture::sqlReject($catalog->db->pdo, 'INSERT INTO vector_documents SELECT * FROM vector_documents');
        });
    }

    public function testChangedOriginalUnderExistingVersionIsRejected(): void
    {
        Fixture::publication(function (Publication $f): void {
            CatalogFixture::sqlReject($f->jobs->catalog->db->pdo,
                'UPDATE source_versions SET source_digest=? WHERE tenant_id=? AND id=?', [str_repeat('f', 64), Fixture::id(1), Fixture::id(3)]);
            $base = $f->jobs->processing;
            $changed = new D\ProcessingIdentity($base->sourceVersionId, new D\SourceDigest(str_repeat('f', 64)),
                $base->parserSignature, $base->ocrProfileSignature, $base->chunkerSignature,
                $base->irSchema, $base->indexProfileDigest, $base->embeddingSpaceDigest);
            LexicalFixture::failure(fn () => $base->changedAxes($changed), 'immutable_conflict');
            $v = new PublicationFixture($f, Fixture::space());
            $candidate = $v->seal(501);
            self::assertSame(hash('sha256', 'source'), $candidate->sets[0]->chunkSet->revisionRef->sourceRef->sourceDigest->value);
        });
    }

    public function testFullSpaceReceiptAndProcessingFenceRejectTampering(): void
    {
        Fixture::publication(function (Publication $f): void {
            $v = new PublicationFixture($f, Fixture::space());
            $candidate = $v->stage(501);
            LexicalFixture::failure(fn () => $v->staging->stage($f->lease, $candidate), 'processing_incompatible');
            $v->index($candidate);
            $db = $f->jobs->catalog->db;
            $role = $db->execute('SELECT current_user')->fetchColumn();
            $db->pdo->exec('RESET ROLE');
            try {
                $db->pdo->exec('ALTER TABLE vector_generations DISABLE TRIGGER vector_generation_guard');
                $db->execute('UPDATE vector_generations SET space_json=? WHERE tenant_id=? AND generation_id=?',
                    [Jcs::canonicalize(Fixture::space(['revision' => 'r2'])->toWire()), Fixture::id(1), Fixture::id(501)]);
            } finally {
                $db->pdo->exec('ALTER TABLE vector_generations ENABLE TRIGGER vector_generation_guard');
                $db->pdo->exec('SET ROLE ' . $role);
            }
            LexicalFixture::failure(fn () => $v->staging->seal($v->lease, $candidate), 'space_mismatch');
            self::assertSame('failed', $f->state(501));
            self::assertNull($f->pointer());
        });
    }

    public function testFloat32PersistenceReplayAndChangedVectorConflict(): void
    {
        Fixture::publication(function (Publication $f): void {
            $vectors = [];
            $references = [];
            foreach ($f->set->chunkSet->chunks as $i => $chunk) {
                $id = $chunk->ref->chunkId->value;
                $vectors[$id] = new D\Vector([$i + 0.6, 0.8, -0.0], Fixture::space());
                $references[$id] = $chunk->ref;
            }
            $documents = new DocumentVectors(Fixture::space(), 'passage', $vectors, $references);
            $v = new PublicationFixture($f, Fixture::space(), documents: $documents);
            $candidate = $v->stage(501);
            $receipt = $v->index($candidate);
            self::assertEquals($receipt, $v->index($candidate));
            self::assertCount(2, $v->backend->reload($candidate->manifest)->chunks);
            $firstId = $f->set->chunkSet->chunks[0]->ref->chunkId->value;
            $vectors[$firstId] = new D\Vector([0.7, 0.8, 0.0], Fixture::space());
            $changed = new PublicationFixture($f, Fixture::space(),
                documents: new DocumentVectors(Fixture::space(), 'passage', $vectors, $references));
            LexicalFixture::failure(fn () => $changed->index($candidate), 'immutable_conflict');
            self::assertCount(2, $v->backend->reload($candidate->manifest)->chunks);
        });
    }

    public function testProcessingAxesRetainHistoricalSourceRevisionChunksetAndIndexRefs(): void
    {
        Fixture::publication(function (Publication $f): void {
            $base = new PublicationFixture($f, Fixture::space());
            $original = $base->seal(501);
            $receipt = $base->publish($original, '0', 'original');
            [$ocr, $rechunk] = PublicationFixture::reprocess($f);
            $ocrAdapter = new PublicationFixture($f, Fixture::space(), [$ocr]);
            $ocrCandidate = $ocrAdapter->seal(503, $ocr);
            $chunkAdapter = new PublicationFixture($f, Fixture::space(), [$rechunk]);
            $chunkCandidate = $chunkAdapter->seal(504, $rechunk);
            $embedAdapter = new PublicationFixture($f, Fixture::space(['revision' => 'r2']), [$rechunk]);
            $embedCandidate = $embedAdapter->seal(505, $rechunk);
            self::assertEquals($f->set->chunkSet->revisionRef->sourceRef, $ocr->chunkSet->revisionRef->sourceRef);
            self::assertNotSame($f->set->chunkSet->revisionRef, $ocr->chunkSet->revisionRef);
            self::assertEquals($ocr->chunkSet->revisionRef, $rechunk->chunkSet->revisionRef);
            self::assertNotSame($ocr->chunkSet->chunkSetId->value, $rechunk->chunkSet->chunkSetId->value);
            self::assertSame($chunkCandidate->sets, $embedCandidate->sets);
            $this->assertProcessingAxes($f, $ocr, $rechunk);
            foreach ([[$base, $original], [$ocrAdapter, $ocrCandidate], [$chunkAdapter, $chunkCandidate], [$embedAdapter, $embedCandidate]] as [$adapter, $candidate]) {
                self::assertCount(2, $adapter->backend->reload($candidate->manifest)->chunks);
            }
            self::assertCount(2, $base->index->search($base->scope($receipt, 501), new D\Query('question'), 2, $base->space)->candidates);
            $this->assertPointer($f, $receipt);
        });
    }

    private function assertPointer(Publication $fixture, D\PublishReceipt $receipt): void
    {
        self::assertSame($receipt->toWire()->get('snapshotId'), $fixture->pointer());
    }

    private function assertProcessingAxes(Publication $f, D\ChunkingResult $ocr, D\ChunkingResult $rechunk): void
    {
        $source = $f->set->chunkSet->revisionRef->sourceRef;
        $old = $f->jobs->processing;
        $processing = new D\ProcessingIdentity($source->sourceVersionId, $source->sourceDigest,
            $f->set->chunkSet->revisionRef->parserSignature, $old->ocrProfileSignature,
            $f->set->chunkSet->chunkerSignature, 'ir-1.0', $old->indexProfileDigest, Fixture::space()->digest());
        $nextOcr = new D\ProcessingIdentity($source->sourceVersionId, $source->sourceDigest,
            $ocr->chunkSet->revisionRef->parserSignature, $old->ocrProfileSignature,
            $ocr->chunkSet->chunkerSignature, 'ir-1.0', $old->indexProfileDigest, Fixture::space()->digest());
        $nextChunk = new D\ProcessingIdentity($source->sourceVersionId, $source->sourceDigest,
            $ocr->chunkSet->revisionRef->parserSignature, $old->ocrProfileSignature,
            $rechunk->chunkSet->chunkerSignature, 'ir-1.0', $old->indexProfileDigest, Fixture::space()->digest());
        $nextEmbed = new D\ProcessingIdentity($source->sourceVersionId, $source->sourceDigest,
            $ocr->chunkSet->revisionRef->parserSignature, $old->ocrProfileSignature,
            $rechunk->chunkSet->chunkerSignature, 'ir-1.0', $old->indexProfileDigest, Fixture::space(['revision' => 'r2'])->digest());
        $expected = Fixture::corpus()['expected']['reprocessingAxes'];
        self::assertSame($expected['reocr'], $processing->changedAxes($nextOcr));
        self::assertSame($expected['rechunk'], $nextOcr->changedAxes($nextChunk));
        self::assertSame($expected['reembed'], $nextChunk->changedAxes($nextEmbed));
    }
}
