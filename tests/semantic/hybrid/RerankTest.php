<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Hybrid;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\JsonObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:hybrid')]
final class RerankTest extends TestCase
{
    private function profile(bool $allow = false, bool $required = false): D\SearchProfile
    {
        return new D\SearchProfile('hybrid-v1', Fixture::space(), $allow, Fixture::rerankProfile(), $required);
    }

    public function testRerankerOnlyReordersOrRemovesBoundedCanonicalCandidates(): void
    {
        $f = new Fixture();
        $f->host->candidates = [$f->hit('allowed'), $f->hit('exact', 2)];
        $f->response = static fn (array $docs): array => [new JsonObject(['candidateId' => $docs[1]->ref->chunkId->value, 'score' => -999])];
        $result = $f->run($this->profile());
        self::assertSame(D\Outcome::Success, $result->outcome);
        self::assertCount(1, $result->data->candidates);
        self::assertSame($f->hit('exact')->ref, $result->data->candidates[0]->ref);
        self::assertSame(1, $result->data->candidates[0]->rank);
        foreach ($f->sent as $document) {
            self::assertSame($f->lexical->inventory->unit($document->ref)->chunk->text(), $document->text());
        }
        self::assertSame(1, $f->egressCalls);
        self::assertSame(1, $f->rerankCalls);
        $f->response = null;
        $reversed = $f->run($this->profile());
        self::assertSame([$f->hit('exact')->ref, $f->hit('allowed')->ref], array_column($reversed->data->candidates, 'ref'));
        self::assertSame([1, 2], array_column($reversed->data->candidates, 'rank'));
        self::assertSame(2, $f->egressCalls);
        $f->response = static fn (): array => [];
        self::assertSame(D\Outcome::Empty, $f->run($this->profile())->outcome);
    }

    public function testInventedIdExpandedSpanChangedCitationDuplicatesAndMalformedOutputsAreIncompatible(): void
    {
        $f = new Fixture();
        $f->host->candidates = [$f->hit('exact')];
        $id = $f->hit('exact')->ref->chunkId->value;
        $fixture = json_decode(file_get_contents(Fixture::root() . '/tests/fixtures/retrieval/hybrid/corpus.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        $outputs = [[new JsonObject(['candidateId' => 'invented'])],
            [new JsonObject(['candidateId' => $id]), new JsonObject(['candidateId' => $id])],
            [new JsonObject(['candidateId' => $id, 'score' => INF])],
            [new JsonObject(['candidateId' => $id, 'score' => 'high'])],
            ['candidateId' => $id], [['candidateId' => $id]]];
        foreach ($fixture['rerankMutations'] as $mutation) {
            $outputs[] = [new JsonObject(['candidateId' => $id] + $mutation)];
        }
        foreach ($outputs as $output) {
            $f->response = static fn (): array => $output;
            $result = $f->run($this->profile(true));
            self::assertSame(D\Outcome::Incompatible, $result->outcome);
            self::assertSame('adapter_contract_violation', $result->errorCode);
            self::assertNull($result->data);
            self::assertFalse($result->degraded);
        }
    }

    public function testReadPermissionDoesNotConsentToProviderEgress(): void
    {
        foreach ([D\Decision::Deny, D\Decision::Unknown] as $decision) {
            $f = new Fixture();
            $f->host->candidates = [$f->hit('exact')];
            $f->egressDecision = $decision;
            $result = $f->run($this->profile(true));
            self::assertSame($decision === D\Decision::Unknown ? D\Outcome::Unknown : D\Outcome::Denied, $result->outcome);
            self::assertNull($result->data);
            self::assertSame(1, $f->egressCalls);
            self::assertSame(0, $f->rerankCalls);
        }
    }

    public function testProcessingDenialStopsBeforeEgressAndHostChangesStopAfterRerank(): void
    {
        $f = new Fixture();
        $f->host->candidates = [$f->hit('exact')];
        $f->host->decision = D\Decision::Deny;
        self::assertSame(D\Outcome::Denied, $f->run($this->profile())->outcome);
        self::assertSame(0, $f->egressCalls);
        self::assertSame(0, $f->rerankCalls);
        $f->host->decision = D\Decision::Allow;
        $f->duringRerank = function () use ($f): void {
            $f->host->identity = new PolicyContext($f->host->identity->scope, 'changed-audience', []);
        };
        $result = $f->run($this->profile());
        self::assertSame(D\Outcome::Denied, $result->outcome);
        self::assertNull($result->data);
    }

    public function testTimeoutOnlyDegradesWhenExplicitlyAllowedAndStageIsOptional(): void
    {
        foreach ([false, true] as $allow) {
            foreach ([false, true] as $required) {
                $f = new Fixture();
                $f->host->candidates = [$f->hit('exact')];
                $f->rerankFailure = new D\DomainFailure(D\Outcome::Unavailable, 'rerank_timeout');
                $result = $f->run($this->profile($allow, $required));
                self::assertSame($allow && !$required ? D\Outcome::Success : D\Outcome::Unavailable, $result->outcome);
                self::assertSame($allow && !$required, $result->degraded);
                self::assertSame(1, $f->rerankCalls);
                if ($result->degraded) {
                    self::assertSame('lexical-v1', $result->effectiveProfile);
                    self::assertContains('rerank:rerank_timeout', $result->warnings);
                }
            }
        }
    }
}
