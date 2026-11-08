<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Application\Validation\DefaultQualityPolicy;
use KnowKit\Application\Validation\SourceReadiness;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Reading as R;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;
use KnowKit\Tests\Worker\Protocol\Fixture as Protocol;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:e2e')]
final class LifecycleTest extends TestCase
{
    public function testRealPdfWorkerPublishSearchDisclosureAndProtectedPreview(): void
    {
        Harness::run(function (Harness $h): void {
            $a = $h->attempt();
            $start = hrtime(true);
            $measurement = new MeasuredRunner($h->root . '/stages');
            $accepted = $h->extract($a, $measurement);
            $seconds = (hrtime(true) - $start) / 1e9;
            $ir = $accepted->output->ir;
            $oracle = StrictJson::decode(file_get_contents(Protocol::root() . '/tests/fixtures/documents/scans/v1/approved-eng.ir.json'))
                ->with('tenantId', $a->source->ref->tenantId->value)->with('sourceVersionId', $a->source->ref->sourceVersionId->value);
            self::assertEquals($oracle, $ir->toWire());
            self::assertSame(D\Decision::Allow, $accepted->quality->decision);
            self::assertFalse($accepted->jobCompleted);
            self::assertNull($h->pointer());
            self::assertSame('chunk', $h->jobs->row($a->lease->jobId)['stage']);
            $set = $h->pipeline->persist($a->lease, $accepted->revision, $accepted->output);
            self::assertSame(Harness::expected()['freshIrDigest'], $accepted->revision->irDigest->value);
            self::assertSame(Harness::expected()['freshChunkManifestDigest'], hash('sha256', $set->canonicalManifest()));
            $request = $h->pipeline->request($a->lease, $set);
            $h->pipeline->seal($request);
            self::assertNull($h->pointer());
            $receipt = $h->pipeline->publish($request);
            self::assertSame($receipt->toWire()->get('snapshotId'), $h->pointer());
            self::assertSame('succeeded', $h->jobs->row($a->lease->jobId)['state']);
            self::assertSame(3, $h->jobs->catalog->count('job_checkpoints'));
            $reader = new Reader($h->pipeline, $set, $ir, $request, $receipt);
            $selected = $reader->select();
            self::assertSame(D\Outcome::Success, $selected->result->outcome, $selected->result->errorCode ?? 'selection');
            $disclosed = $reader->disclose($selected);
            self::assertSame(D\Outcome::Success, $disclosed->result->outcome, $disclosed->result->errorCode ?? 'disclosure');
            $fragment = $disclosed->result->data->toWire()->get('fragments')[0];
            $citation = new D\Ulid($fragment->get('citation')->get('citationId'));
            self::assertSame($a->source->ref->sourceVersionId->value, $fragment->get('citation')->get('sourceVersionId'));
            self::assertSame($accepted->revision->irDigest->value, $fragment->get('citation')->get('irDigest'));
            $quote = $reader->read($citation, R\ReadTarget::quote());
            self::assertSame(D\Outcome::Success, $quote->outcome, $quote->errorCode ?? 'quote');
            self::assertSame($set->chunkSet->chunks[0]->text(), $quote->data->body->bytes());
            $asset = $ir->assets()[0];
            $preview = $reader->read($citation, R\ReadTarget::asset(new D\LocalId($asset->get('assetId'))));
            self::assertSame(D\Outcome::Success, $preview->outcome, $preview->errorCode ?? 'preview');
            self::assertSame($asset->get('digest'), hash('sha256', $preview->data->body->bytes()));
            self::assertStringStartsWith("\x89PNG", $preview->data->body->bytes());
            self::assertArrayNotHasKey('url', iterator_to_array($preview->data->body->metadata()));
            $this->assertForeignTenantCannotPreview($reader, $citation, $asset->get('assetId'));
            self::assertSame([], glob($h->root . '/stages/*'));
            $this->measurement($seconds, $a, $accepted, count($set->chunkSet->chunks), $measurement);
            $usage = $h->jobs->catalog->db->row('SELECT evidence_digest FROM evidence_usages WHERE id=?', [$disclosed->result->data->usageRef()->value]);
            $h->pipeline->lifecycle()->revoke($a->lease->scope,
                new D\Revocation($a->source->ref->sourceId, new D\Decimal('0')), new D\IdempotencyKey('revoke-after-preview'));
            $denied = $reader->read($citation, R\ReadTarget::asset(new D\LocalId($asset->get('assetId'))));
            self::assertSame(D\Outcome::Denied, $denied->outcome);
            self::assertNull($denied->data);
            self::assertSame($usage, $h->jobs->catalog->db->row('SELECT evidence_digest FROM evidence_usages WHERE id=?', [$disclosed->result->data->usageRef()->value]));
        });
    }

    public function testStrictNineOfTenCannotStageOrPublishAsComplete(): void
    {
        Harness::run(function (Harness $h): void {
            [$a, $accepted, $set] = $this->incomplete($h);
            $ready = new SourceReadiness($accepted->revision, $accepted->output->ir, new D\CoveragePolicy(), new DefaultQualityPolicy());
            self::assertSame([['kind' => 'page', 'id' => 'page-10', 'status' => 'failed', 'physicalNo' => 10]], $ready->missingUnits);
            self::assertSame(D\Decision::Deny, $ready->quality->decision);
            self::assertFalse($ready->eligibleForCompleteQuery());
            $request = $h->pipeline->request($a->lease, $set);
            self::failure(fn () => $h->pipeline->seal($request), D\Outcome::Unavailable, 'coverage_required');
            self::failure(fn () => $h->pipeline->publish($request), D\Outcome::Unavailable);
            self::assertNull($h->pointer());
            self::assertSame(0, $h->jobs->catalog->count('publication_snapshots'));
            self::assertSame('partial', $accepted->output->result->coverage()->toWire()->get('state'));
        });
    }

    public function testExplicitPartialPublishesExactMissingPageAndCompleteQueryExcludesIt(): void
    {
        Harness::run(function (Harness $h): void {
            [$a, $accepted, $set] = $this->incomplete($h);
            $ready = new SourceReadiness($accepted->revision, $accepted->output->ir, new D\CoveragePolicy(true), new DefaultQualityPolicy());
            $ready->requireQualityAllowed();
            $h->jobs->catalog->db->execute('UPDATE knowledge_bases SET coverage_policy=? WHERE tenant_id=? AND id=?',
                ['{"allowPartial":true,"requiredFeatures":[]}', Jobs::id(1), Jobs::id(100)]);
            $request = $h->pipeline->request($a->lease, $set);
            $h->pipeline->seal($request);
            $receipt = $h->pipeline->publish($request);
            self::assertSame($receipt->toWire()->get('snapshotId'), $h->pointer());
            self::assertSame('partial', $request->candidate->manifest->toWire()->get('coverage')->get('state'));
            $stored = $h->jobs->catalog->db->row('SELECT coverage_json FROM document_revisions WHERE tenant_id=? AND id=?',
                [Jobs::id(1), $accepted->revision->documentRevisionId->value]);
            self::assertEquals($accepted->output->ir->toWire()->get('coverage'), StrictJson::decode($stored['coverage_json']));
            $db = $h->jobs->catalog->db;
            self::assertSame('failed', $db->row('SELECT status FROM pages WHERE tenant_id=? AND document_id=? AND physical_no=10',
                [Jobs::id(1), $set->chunkSet->chunks[0]->documentId->value])['status']);
            // Completeness admission is source-level; SQL then searches only the admitted versions.
            $reader = new Reader($h->pipeline, $set, $accepted->output->ir, $request, $receipt);
            self::assertSame(D\Outcome::Success, $reader->select()->result->outcome);
            $reader->host->eligible = $ready->eligibleForCompleteQuery() ? [$a->source->ref->sourceVersionId] : [];
            $result = $reader->select();
            self::assertSame(D\Outcome::Empty, $result->result->outcome, $result->result->errorCode ?? 'complete query');
            self::assertNull($result->result->data);
        });
    }

    public function testBatchSuccessDoesNotUpgradeFailedSourceReadiness(): void
    {
        Harness::run(function (Harness $h): void {
            [$failed, $bad] = $this->incomplete($h);
            $good = $h->attempt(version: 31, source: 20, key: 'batch-good');
            $accepted = $h->extract($good, revision: 711);
            $set = $h->pipeline->persist($good->lease, $accepted->revision, $accepted->output);
            $request = $h->pipeline->request($good->lease, $set);
            $h->pipeline->seal($request);
            $h->pipeline->publish($request);
            $results = [
                $failed->source->ref->sourceVersionId->value => ['coverage' => 'partial', 'decision' => $bad->quality->decision->value, 'published' => false],
                $good->source->ref->sourceVersionId->value => ['coverage' => 'complete', 'decision' => $accepted->quality->decision->value, 'published' => true],
            ];
            self::assertSame(D\Decision::Deny->value, $results[$failed->source->ref->sourceVersionId->value]['decision']);
            self::assertSame(D\Decision::Allow->value, $results[$good->source->ref->sourceVersionId->value]['decision']);
            self::assertSame([$good->source->ref->sourceVersionId->value], $h->jobs->catalog->db->execute(
                'SELECT source_version_id FROM snapshot_memberships ORDER BY source_version_id')->fetchAll(\PDO::FETCH_COLUMN));
            self::assertSame('leased', $h->jobs->row($failed->lease->jobId)['state']);
            self::assertSame('partial', $h->jobs->catalog->db->row('SELECT coverage_state FROM document_revisions WHERE id=?',
                [$bad->revision->documentRevisionId->value])['coverage_state']);
        });
    }

    public function testNewParserCannotReuseOldCheckpointOrRedirectHistoricalCitation(): void
    {
        Harness::run(function (Harness $h): void {
            $old = $h->attempt();
            $accepted = $h->extract($old);
            $set = $h->pipeline->persist($old->lease, $accepted->revision, $accepted->output);
            $request = $h->pipeline->request($old->lease, $set);
            $h->pipeline->seal($request);
            $receipt = $h->pipeline->publish($request);
            $reader = new Reader($h->pipeline, $set, $accepted->output->ir, $request, $receipt);
            $selected = $reader->select();
            $evidence = $reader->disclose($selected);
            self::assertSame(D\Outcome::Success, $evidence->result->outcome, $evidence->result->errorCode ?? 'citation');
            $wire = $evidence->result->data->toWire()->get('fragments')[0]->get('citation');
            $citation = new D\Ulid($wire->get('citationId'));
            $before = $reader->read($citation, R\ReadTarget::quote());
            self::assertSame(D\Outcome::Success, $before->outcome, $before->errorCode ?? 'old quote');
            $next = $h->attempt('docling-pdf-text-v1', key: 'new-parser');
            self::assertNotEquals($old->identity->digest(), $next->identity->digest());
            self::failure(fn () => $h->pipeline->queue->resume($next->lease, $old->identity),
                D\Outcome::Incompatible, 'processing_identity_mismatch');
            self::assertNull($h->pipeline->queue->resume($next->lease, $next->identity));
            $new = $h->extract($next, revision: 721);
            self::assertNotEquals($accepted->revision->irDigest, $new->revision->irDigest);
            $newSet = $h->pipeline->persist($next->lease, $new->revision, $new->output);
            self::assertNotEquals($set->chunkSet->chunkSetId, $newSet->chunkSet->chunkSetId);
            $after = $reader->read($citation, R\ReadTarget::quote());
            self::assertSame(D\Outcome::Success, $after->outcome, $after->errorCode ?? 'historical quote');
            self::assertSame($before->data->body->bytes(), $after->data->body->bytes());
            self::assertSame($wire->get('irDigest'), $after->data->citation->toWire()->get('irDigest'));
            self::assertSame($wire->get('documentRevisionId'), $after->data->citation->toWire()->get('documentRevisionId'));
            $h->pipeline->lifecycle()->correct($old->lease->scope, new D\Correction($old->source->ref, null, new D\Decimal('0')),
                new D\IdempotencyKey('correct-old-version'));
            self::assertSame(D\Outcome::Denied, $reader->read($citation, R\ReadTarget::quote())->outcome);
        });
    }

    private function incomplete(Harness $h): array
    {
        $a = $h->attempt('docling-pdf-text-v1', true);
        $accepted = $h->extract($a, new OracleRunner());
        $set = $h->pipeline->persist($a->lease, $accepted->revision, $accepted->output);

        return [$a, $accepted, $set];
    }

    public static function failure(\Closure $run, D\Outcome $outcome, ?string $code = null): void
    {
        try {
            $run();
            self::fail('Forbidden transition succeeded');
        } catch (D\DomainFailure $failure) {
            self::assertSame($outcome, $failure->outcome);
            if ($code !== null) {
                self::assertSame($code, $failure->errorCode);
            }
        }
    }

    private function measurement(float $seconds, Attempt $attempt, D\WorkerAcceptance $accepted, int $chunks, MeasuredRunner $runner): void
    {
        $directory = Protocol::root() . '/python/knowkit-worker/.local-checks/e2e';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $usage = getrusage(1);
        file_put_contents($directory . '/resources.json', json_encode([
            'schemaVersion' => 'worker-e2e-measurement-1.0', 'profile' => $attempt->profile->id,
            'wallSeconds' => round($seconds, 3), 'childMaxRssKiB' => $usage['ru_maxrss'],
            'childUserSeconds' => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6,
            'childSystemSeconds' => $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6,
            'inputBytes' => $attempt->source->byteLength(), 'irBytes' => strlen($accepted->output->ir->canonical()),
            'sampledCgroupMemoryPeakBytes' => $runner->sampledMemoryPeakBytes,
            'sampledCgroupCpuUsec' => $runner->sampledCpuUsec, 'peakStagedBytes' => $runner->peakStagedBytes,
            'assetBytes' => array_sum(array_map('strlen', $accepted->output->assets)),
            'pages' => count($accepted->output->ir->pages()), 'assets' => count($accepted->output->assets), 'chunks' => $chunks,
            'scope' => 'One real sandbox extraction including BOM validation and negotiation. Cgroup and stage samples taken during supervisor polls; final tail may be missed. Child rusage is cumulative for this PHPUnit process, not worker cgroup memory. Staging excludes private sandbox /tmp and filesystem overhead.',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    private function assertForeignTenantCannotPreview(Reader $reader, D\Ulid $citation, string $asset): void
    {
        $identity = $reader->host->identity;
        $scope = $identity->scope;
        $reader->host->identity = new \KnowKit\Domain\Access\PolicyContext(new D\Scope(new D\TenantId(Jobs::id(999)),
            $scope->kbId, $scope->actorId, $scope->purpose, $scope->policyEpoch, $scope->hostScopeHash), 'teacher', ['source:read']);
        try {
            $foreign = $reader->read($citation, R\ReadTarget::asset(new D\LocalId($asset)));
            self::assertSame(D\Outcome::Unknown, $foreign->outcome);
            self::assertSame('citation_unknown', $foreign->errorCode);
            self::assertNull($foreign->data);
        } finally {
            $reader->host->identity = $identity;
        }
    }
}
