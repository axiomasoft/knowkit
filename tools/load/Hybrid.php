<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Application\Retrieval\Fusion\HybridSearch;
use KnowKit\Application\Retrieval\Fusion\ReciprocalRankFusion;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Semantic\Hybrid\Fixture as Host;
use KnowKit\Tests\Semantic\Vector\Fixture as Vector;
use KnowKit\Tooling\Qa;

require_once Report::root() . '/tests/semantic/vector/bootstrap.php';
require_once Report::root() . '/tests/semantic/hybrid/bootstrap.php';

final class Hybrid
{
    public static function run(): array
    {
        $report = [];
        Vector::run(function (Vector $f) use (&$report): void {
            $host = new Host();
            $db = $f->lexical->catalog->db;
            self::prepareSnapshot($f);
            $documents = self::documents($f);
            $snapshot = new D\SnapshotRef($host->host->identity->scope->tenantId, $host->host->identity->scope->kbId,
                new D\SnapshotId(Vector::id(912)), new D\Fingerprint(str_repeat('b', 64)));
            $lexical = self::corpus(host: $host, f: $f, snapshot: $snapshot, documents: $documents, vector: false);
            $vector = self::corpus(host: $host, f: $f, snapshot: $snapshot, documents: $documents, vector: true);
            $service = new HybridSearch(lexical: $f->lexical->index, fusion: new ReciprocalRankFusion(), vector: $f->index());
            $profile = new D\SearchProfile(id: 'hybrid-v1', space: Vector::space());
            $query = $host->query(profile: $profile, text: 'gravity');
            $samples = [];
            $cpu = Metrics::cpuMs();
            for ($i = 0; $i < 20; ++$i) {
                $result = Metrics::timed(operation: fn () => $service->search(context: $host->host->context(), lexical: $lexical,
                    query: $query, profile: $profile, vector: $vector), samples: $samples);
                self::assertResult(result: $result, vector: $vector);
            }
            $report = Report::save('hybrid', ['latencyMs' => Metrics::percentiles($samples), 'cpuMs' => Metrics::cpuMs() - $cpu,
                'providerCalls' => 0, 'embedding' => 'Pinned synthetic document/query vectors in real pgvector; no remote model latency claim.',
                'sources' => 3, 'allowedSources' => 2, 'deniedReturned' => 0,
                'postgres' => $db->execute('SHOW server_version')->fetchColumn(),
                'vectorVersion' => $db->execute("SELECT extversion FROM pg_extension WHERE extname='vector'")->fetchColumn(),
                'referenceScaleQualified' => false]);
        });

        return $report;
    }

    private static function assertResult(D\Result $result, D\SearchCorpus $vector): void
    {
        if ($result->outcome !== D\Outcome::Success || !$result->data instanceof D\CandidateList) {
            throw new \RuntimeException('Real hybrid backend failed');
        }
        Qa::ensure(count($result->data->candidates) === 2, 'real hybrid backend failed');
        foreach ($result->data->candidates as $candidate) {
            Qa::ensure(in_array($candidate->ref->revisionRef->sourceRef->sourceVersionId->value,
                array_map(static fn ($id): string => $id->value, $vector->scope->allowedSourceVersionIds), true), 'hybrid permission leak');
            Qa::ensure($candidate->score > 0 && $candidate->score <= 2 / 61, 'RRF rank score invalid');
        }
    }

    private static function documents(Vector $f): array
    {
        $documents = [];
        foreach ($f->lexical->data as $doc) {
            $ir = new D\IrDocument(StrictJson::decode(json_encode($doc['ir'], JSON_THROW_ON_ERROR)), PortFixture::validator());
            $documents[hash('sha256', $ir->canonical())] = $ir;
        }

        return $documents;
    }

    private static function prepareSnapshot(Vector $f): void
    {
        $db = $f->lexical->catalog->db;
        $f->lexical->seal();
        $db->execute('INSERT INTO publication_snapshots VALUES (?,?,?,?,clock_timestamp(),false)',
            [Vector::id(1), Vector::id(912), Vector::id(100), str_repeat('b', 64)]);
        foreach ([14 => 'postgres-fts-simple-v1', 501 => 'pgvector-l2-v1'] as $id => $profileId) {
            $db->execute('INSERT INTO snapshot_generations VALUES (?,?,?,?,?)',
                [Vector::id(1), Vector::id(912), Vector::id(100), Vector::id($id), $profileId]);
        }
        foreach (['allowed', 'tie'] as $name) {
            $r = $f->lexical->sets[$name]->chunkSet->revisionRef;
            foreach ([14 => 'postgres-fts-simple-v1', 501 => 'pgvector-l2-v1'] as $id => $profileId) {
                $db->execute('INSERT INTO snapshot_memberships VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [Vector::id(1), $f->lexical->catalog->ids->next()->value, Vector::id(912), Vector::id(100),
                        $r->sourceRef->sourceId->value, $r->sourceRef->sourceVersionId->value, $r->documentRevisionId->value,
                        $f->lexical->sets[$name]->chunkSet->chunkSetId->value, Vector::id($id), $profileId]);
            }
        }
        $db->execute('UPDATE publication_snapshots SET sealed=true WHERE tenant_id=? AND id=?', [Vector::id(1), Vector::id(912)]);
    }

    private static function corpus(Host $host, Vector $f, D\SnapshotRef $snapshot, array $documents, bool $vector): D\SearchCorpus
    {
        $versions = array_map(fn (string $name): D\SourceVersionId => $f->lexical->sets[$name]->chunkSet->revisionRef->sourceRef->sourceVersionId,
            ['allowed', 'tie']);
        $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds' => [],
            'allowedSourceVersionIds' => array_map(static fn ($id): string => $id->value, $versions),
            'grantEpoch' => '0', 'policyBindingDigest' => HashDomain::PolicyBinding->digest($host->host->binding),
            'expiresAt' => $host->host->now->plusSeconds(30)->value]), PortFixture::validator());
        $generation = new D\GenerationId(Vector::id($vector ? 501 : 14));
        $scope = D\AuthorizedSearchScope::materialize($host->host->identity->scope, $grant, $snapshot, $generation,
            $versions, $host->host->now, $vector ? Vector::space()->digest() : null);
        $ownSets = array_values(array_filter($f->lexical->sets,
            static fn (D\ChunkingResult $set): bool => $set->chunkSet->revisionRef->sourceRef->tenantId->value === Vector::id(1)));
        $manifest = $vector ? $f->candidates['1:501'] : new D\ManifestCandidate($f->lexical->manifests[1], $ownSets, []);

        return new D\SearchCorpus($scope, (new CanonicalUnits())->build($snapshot, $manifest, $documents));
    }
}
