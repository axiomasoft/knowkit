<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Hybrid;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Application\Retrieval\Fusion\HybridSearch;
use KnowKit\Application\Retrieval\Fusion\ReciprocalRankFusion;
use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Domain as D;
use KnowKit\Tests\Retrieval\Selection\Fixture as Host;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:hybrid')]
final class BoundaryTest extends TestCase
{
    private function rejects(\Closure $call, D\Outcome $expected = D\Outcome::Incompatible): void
    {
        try {
            $call();
            self::fail('Invalid boundary accepted');
        } catch (D\DomainFailure $failure) {
            self::assertSame($expected, $failure->outcome);
        }
    }

    public function testExplicitProfileAndCorpusBindingsRejectInvalidInput(): void
    {
        $this->rejects(static fn () => new D\SearchProfile('typo'));
        $this->rejects(static fn () => new D\SearchProfile('hybrid-v1'));
        $this->rejects(static fn () => new D\SearchProfile(space: Fixture::space()));
        $this->rejects(static fn () => new D\SearchProfile(rerankRequired: true));
        $f = new Fixture();
        $this->rejects(fn () => new D\SearchCorpus($f->vector->scope, $f->lexical->inventory));
        $this->rejects(fn () => $f->lexical->assertProfile('bm25', 'lexical'));
        $scope = $f->host->identity->scope;
        $foreign = new D\Scope(new D\TenantId(Host::id(999)), $scope->kbId, $scope->actorId,
            $scope->purpose, $scope->policyEpoch, $scope->hostScopeHash);
        $this->rejects(fn () => $f->lexical->assertBinding($foreign), D\Outcome::Denied);
        $actor = new D\Scope($scope->tenantId, $scope->kbId, new D\ActorId(Host::id(999)),
            $scope->purpose, $scope->policyEpoch, $scope->hostScopeHash);
        $this->rejects(fn () => $f->lexical->assertBinding($actor), D\Outcome::Denied);
        $this->rejects(fn () => $f->lexical->assertList(new D\CandidateList($f->vector->scope, []), 1), D\Outcome::Denied);
        $this->rejects(fn () => $f->lexical->assertList(new D\CandidateList($f->lexical->scope,
            [$f->hit('allowed'), $f->hit('tie', 2)]), 1));
    }

    public function testInconsistentPairRejectsBeforeBackendAndEligibilityOrderIsIrrelevant(): void
    {
        $f = new Fixture();
        $f->sets = array_reverse($f->sets, true);
        $f->vector = $f->corpus(Fixture::space());
        self::assertSame(D\Outcome::Empty, $f->run()->outcome);
        unset($f->sets['allowed']);
        $f->vector = $f->corpus(Fixture::space());
        $f->host->searchCalls = 0;
        $f->vectorCalls = 0;
        self::assertSame(D\Outcome::Incompatible, $f->run()->outcome);
        self::assertSame(0, $f->host->searchCalls);
        self::assertSame(0, $f->vectorCalls);
    }

    public function testInvalidLimitAndQueryProfileRejectBeforeBackend(): void
    {
        $f = new Fixture();
        $service = new HybridSearch($f->host, new ReciprocalRankFusion());
        $profile = new D\SearchProfile();
        foreach ([0, 513] as $topK) {
            self::assertSame(D\Outcome::Incompatible, $service->search($f->host->context(), $f->lexical,
                $f->query($profile), $profile, topK: $topK)->outcome);
        }
        self::assertSame(D\Outcome::Incompatible, $service->search($f->host->context(), $f->lexical,
            $f->query(new D\SearchProfile('hybrid-v1', Fixture::space())), $profile)->outcome);
        self::assertSame(0, $f->host->searchCalls);
    }

    public function testOversizedCanonicalUnitIsNeverSentAndDocumentDigestCannotChange(): void
    {
        $f = new Fixture();
        $query = (new Normalizer())->bind(new D\Query('DOC-2026/0042'), new D\EffectiveProfile('lexical-v1', '1', []),
            D\SelectionLimits::profile('compact', maxBytes: 1, maxBytesPerFragment: 1));
        $input = new D\CandidateList($f->lexical->scope, [$f->hit('exact')]);
        $result = $f->reranker()->apply($f->host->context(), $f->lexical, $input, $query,
            Fixture::rerankProfile(), fn () => $f->host->binding);
        self::assertSame([], $result->candidates);
        self::assertSame(0, $f->rerankCalls);
        self::assertSame(0, $f->egressCalls);
        $this->rejects(fn () => new D\RerankDocument($f->hit('exact')->ref, 'invented text'));
    }
}
