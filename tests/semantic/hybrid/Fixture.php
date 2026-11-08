<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Hybrid;

use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Access\ReceiptFactory;
use KnowKit\Application\Publication\Manifest\Builder;
use KnowKit\Application\Retrieval\Fusion\HybridSearch;
use KnowKit\Application\Retrieval\Fusion\ReciprocalRankFusion;
use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Application\Retrieval\Rerank\ConstrainedRerank;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Selection\Fixture as Host;

/** Contract doubles exercise stages; no external reranker adapter is claimed. */
final class Fixture implements P\VectorIndex, P\RerankTransport, P\EgressPolicy
{
    public Host $host;
    public D\SearchCorpus $lexical;
    public D\SearchCorpus $vector;
    public array $sets = [];
    public array $documents = [];
    public array $vectorCandidates = [];
    public int $vectorCalls = 0;
    public int $rerankCalls = 0;
    public int $egressCalls = 0;
    public D\Decision $egressDecision = D\Decision::Allow;
    public ?D\DomainFailure $vectorFailure = null;
    public ?D\DomainFailure $rerankFailure = null;
    public ?\Closure $response = null;
    public ?\Closure $duringRerank = null;
    public array $sent = [];

    public function __construct()
    {
        $this->host = new Host();
        $data = json_decode(file_get_contents(self::root() . '/tests/fixtures/retrieval/lexical/corpus.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['documents'] as $doc) {
            if (in_array($doc['name'], ['allowed', 'tie', 'exact', 'neighbor'], true)) {
                $this->add($doc);
            }
        }
        $this->lexical = $this->corpus();
        $this->vector = $this->corpus(self::space());
        $this->host->candidates = [];
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function space(): D\SpaceRef
    {
        return new D\SpaceRef('test.provider', 'test.model', 'r1', 3, 'l2', 'v1', 'document', 'query');
    }

    private function add(array $doc): void
    {
        $manifest = StrictJson::decode(json_encode($doc['chunkSetManifest'], JSON_THROW_ON_ERROR));
        $r = $manifest->get('revisionRef');
        $ref = new D\SourceVersionRef($this->host->identity->scope->tenantId,
            new D\SourceId($r->get('sourceId')), new D\SourceVersionId($r->get('sourceVersionId')), new D\SourceDigest($r->get('sourceDigest')));
        $revision = new D\DocumentRevisionRef($ref, new D\DocumentRevisionId($r->get('documentRevisionId')),
            new D\IrDigest($r->get('irDigest')), PortFixture::signature($r->get('parserSignature')));
        $chunk = new D\Chunk(new D\ChunkRef($revision, new D\ChunkSetId($manifest->get('chunkSetId')),
            new D\ChunkId(Host::id(6000 + $doc['number'])), new D\Sha256($doc['textDigest'])),
            new D\DocumentId($manifest->get('documentId')), [new D\Span(
                D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), 0, strlen($doc['text'])),
                new D\Sha256($doc['textDigest']),
            )], $doc['text']);
        $this->sets[$doc['name']] = new D\ChunkingResult(new D\ChunkSet($revision, $chunk->ref->chunkSetId,
            PortFixture::signature($manifest->get('chunkerSignature')), [$chunk]), $manifest);
        $this->documents[$revision->irDigest->value] = new D\IrDocument(
            StrictJson::decode(json_encode($doc['ir'], JSON_THROW_ON_ERROR)), PortFixture::validator(),
        );
    }

    public function corpus(?D\EmbeddingSpace $space = null): D\SearchCorpus
    {
        $scope = $this->host->identity->scope;
        $generation = new D\GenerationId(Host::id($space === null ? 14 : 15));
        $coverage = array_values($this->documents)[0]->toWire()->get('coverage');
        $manifest = (new Builder(PortFixture::validator()))->build($scope, $generation, array_values($this->sets), [], $coverage,
            $space === null ? 'postgres-fts-simple-v1' : 'pgvector-l2-v1', $space);
        $snapshot = new D\SnapshotRef($scope->tenantId, $scope->kbId, new D\SnapshotId(Host::id(110)), new D\Fingerprint(str_repeat('b', 64)));
        $eligible = array_map(static fn (D\ChunkingResult $set): D\SourceVersionId => $set->chunkSet->revisionRef->sourceRef->sourceVersionId, array_values($this->sets));
        $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds' => [],
            'allowedSourceVersionIds' => array_map(static fn (D\SourceVersionId $id): string => $id->value, $eligible),
            'grantEpoch' => '0', 'policyBindingDigest' => HashDomain::PolicyBinding->digest($this->host->binding),
            'expiresAt' => $this->host->now->plusSeconds(30)->value]), PortFixture::validator());

        return new D\SearchCorpus(D\AuthorizedSearchScope::materialize($scope, $grant, $snapshot, $generation,
            $eligible, $this->host->now, $space?->digest()), (new CanonicalUnits())->build($snapshot, $manifest, $this->documents));
    }

    public function hit(string $name, int $rank = 1, ?float $score = 1.0): D\Candidate
    {
        return new D\Candidate($this->sets[$name]->chunkSet->chunks[0]->ref, $rank, $score);
    }

    public static function rerankProfile(): D\RerankProfile
    {
        return new D\RerankProfile('test-rerank-v1', new D\AdapterId('test.rerank'), new D\Fingerprint(str_repeat('a', 64)));
    }

    public function query(D\SearchProfile $profile, string $text = 'DOC-2026/0042'): D\QueryBinding
    {
        return (new Normalizer())->bind(new D\Query($text), new D\EffectiveProfile($profile->id, '1', []),
            D\SelectionLimits::profile('compact'), $profile->space?->digest());
    }

    public function reranker(): ConstrainedRerank
    {
        return new ConstrainedRerank($this, new PolicyChecks($this->host, $this, $this->host),
            new BoundedSelector(), PortFixture::validator());
    }

    public function run(?D\SearchProfile $profile = null, ?HybridSearch $service = null): D\Result
    {
        $profile ??= new D\SearchProfile('hybrid-v1', self::space());
        $service ??= new HybridSearch($this->host, new ReciprocalRankFusion(), $this, $this->reranker());

        return $service->search($this->host->context(), $this->lexical, $this->query($profile), $profile,
            $this->vector, 64, fn (): JsonObject => $this->host->binding);
    }

    public function index(D\GenerationId $generationId, D\ChunkSet $chunkSet, D\EmbeddingSpace $space): D\IndexReceipt
    {
        throw new \LogicException('Contract double has no persistence');
    }

    public function search(D\AuthorizedSearchScope $scope, D\Query $query, int $k, D\EmbeddingSpace $space): D\CandidateList
    {
        ++$this->vectorCalls;
        if ($this->vectorFailure !== null) {
            throw $this->vectorFailure;
        }

        return new D\CandidateList($scope, $this->vectorCandidates);
    }

    public function destination(D\RerankProfile $profile): JsonObject
    {
        return new JsonObject(['providerId' => 'test.provider', 'accountId' => 'test.account', 'region' => 'test', 'modelId' => 'test.rerank']);
    }

    public function rerank(D\Query $query, array $documents, D\RerankProfile $profile): array
    {
        ++$this->rerankCalls;
        $this->sent = $documents;
        if ($this->duringRerank !== null) {
            ($this->duringRerank)();
        }
        if ($this->rerankFailure !== null) {
            throw $this->rerankFailure;
        }

        return $this->response === null ? array_map(static fn (D\RerankDocument $doc): JsonObject =>
            new JsonObject(['candidateId' => $doc->ref->chunkId->value]), array_reverse($documents)) : ($this->response)($documents);
    }

    public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
    {
        ++$this->egressCalls;
        $resources = [new D\ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId)];
        foreach ($request->toWire()->get('items') as $item) {
            foreach ($item->get('sourceVersionRefs') as $ref) {
                $resources[] = new D\ResourceRef($scope->tenantId, 'source_version', new D\SourceVersionId($ref->get('sourceVersionId')));
            }
        }

        return (new ReceiptFactory(PortFixture::validator(), $this->host))->issue($this->egressDecision,
            $scope, $resources, D\Operation::Retrieve, $this->host->binding, 'test-owner:1');
    }
}
