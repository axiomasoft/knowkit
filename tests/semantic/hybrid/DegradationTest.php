<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Hybrid;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Application\Retrieval\Fusion\HybridSearch;
use KnowKit\Application\Retrieval\Fusion\ReciprocalRankFusion;
use KnowKit\Domain as D;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:hybrid')]
final class DegradationTest extends TestCase
{
    public function testVectorTimeoutRequiresExplicitDegradationAndRetainsEmptyMetadata(): void
    {
        foreach ([false, true] as $allow) {
            foreach ([false, true] as $empty) {
                $f = new Fixture();
                $f->host->candidates = $empty ? [] : [$f->hit('exact')];
                $f->vectorFailure = new D\DomainFailure(D\Outcome::Unavailable, 'vector_timeout');
                $result = $f->run(new D\SearchProfile('hybrid-v1', Fixture::space(), $allow));
                self::assertSame(!$allow ? D\Outcome::Unavailable : ($empty ? D\Outcome::Empty : D\Outcome::Success), $result->outcome);
                self::assertSame($allow, $result->degraded);
                self::assertSame(1, $f->vectorCalls);
                if ($allow) {
                    self::assertSame('lexical-v1', $result->effectiveProfile);
                    self::assertContains('vector:vector_timeout', $result->warnings);
                }
            }
        }
    }

    public function testDeniedUnknownAndIncompatibleNeverBecomeLexicalSuccess(): void
    {
        foreach ([D\Outcome::Denied, D\Outcome::Unknown, D\Outcome::Incompatible] as $outcome) {
            $f = new Fixture();
            $f->host->candidates = [$f->hit('exact')];
            $f->vectorFailure = new D\DomainFailure($outcome, 'stage_failed');
            $result = $f->run(new D\SearchProfile('hybrid-v1', Fixture::space(), true));
            self::assertSame($outcome, $result->outcome);
            self::assertNull($result->data);
            self::assertFalse($result->degraded);
        }
    }

    public function testPinnedSpaceMismatchIsRejectedBeforeLexicalEvenWithDegradation(): void
    {
        $payload = Fixture::space()->toWire();
        $mutations = ['provider' => 'other', 'model' => 'other', 'revision' => 'r2', 'normalization' => 'none',
            'indexVersion' => 'v2', 'documentRole' => 'other', 'queryRole' => 'other', 'dimensions' => 4];
        foreach ($mutations as $field => $value) {
            $f = new Fixture();
            $f->host->candidates = [$f->hit('exact')];
            $space = new D\SpaceRef(...iterator_to_array($payload->with($field, $value)));
            $result = $f->run(new D\SearchProfile('hybrid-v1', $space, true));
            self::assertSame(D\Outcome::Incompatible, $result->outcome, $field);
            self::assertSame('space_mismatch', $result->errorCode);
            self::assertSame(0, $f->host->searchCalls);
            self::assertSame(0, $f->vectorCalls);
        }
    }

    public function testMissingPinnedCorpusAndRequiredRerankerNeverFallback(): void
    {
        $f = new Fixture();
        $f->host->candidates = [$f->hit('exact')];
        $service = new HybridSearch($f->host, new ReciprocalRankFusion());
        $profile = new D\SearchProfile('hybrid-v1', Fixture::space(), true);
        $result = $service->search($f->host->context(), $f->lexical, $f->query($profile), $profile);
        self::assertSame(D\Outcome::Incompatible, $result->outcome);
        self::assertSame(0, $f->host->searchCalls);
        $required = new D\SearchProfile('hybrid-v1', Fixture::space(), true, Fixture::rerankProfile(), true);
        self::assertSame(D\Outcome::Unavailable, $f->run($required, $service)->outcome);
        $service = new HybridSearch($f->host, new ReciprocalRankFusion(), $f);
        self::assertSame(D\Outcome::Unavailable, $f->run($required, $service)->outcome);
    }

    public function testMandatoryLexicalFailureCannotDegradeToVector(): void
    {
        $f = new Fixture();
        $f->host->duringSearch = static function (): void {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'lexical_timeout');
        };
        $result = $f->run(new D\SearchProfile('hybrid-v1', Fixture::space(), true));
        self::assertSame(D\Outcome::Unavailable, $result->outcome);
        self::assertSame('lexical_timeout', $result->errorCode);
        self::assertSame(0, $f->vectorCalls);
    }
}
