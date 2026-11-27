<?php

declare(strict_types=1);

namespace KnowKit\Tests\Training;

require_once __DIR__ . '/TrainingTestSupport.php';

use KnowKit\ContextCompilers\Domain\CitationAliasMap;
use KnowKit\TrainingExport\Domain\TrainingTraceRecord;
use KnowKit\TrainingExport\Rewards\{
    DeterministicRewardSuite,
    GroundingRewardVerifier,
    HostDomainRewardHook,
    InvariantComplianceVerifier,
};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('training:export')]
final class RewardVerifiersTest extends TestCase
{
    public function testGroundingRewardVerifierScoresValidInventedAndMissingAliases(): void
    {
        $trace = TrainingTestSupport::makeTrace();
        $verifier = new GroundingRewardVerifier();

        self::assertSame(1000, $verifier->evaluate($trace->citationAliasMap, 'Claim [#1].', ['#1']));
        self::assertSame(-2000, $verifier->evaluate($trace->citationAliasMap, 'Numeric id.', ['180']));
        self::assertSame(-2000, $verifier->evaluate($trace->citationAliasMap, 'Hallucinated [#99].', ['#99']));
        self::assertSame(-1000, $verifier->evaluate($trace->citationAliasMap, 'No citations at all.', [], true));
        self::assertSame(0, $verifier->evaluate($trace->citationAliasMap, 'Optional.', [], false));

        $emptyMap = CitationAliasMap::fromEvidenceSet(TrainingTestSupport::emptyEvidenceSet($trace->scope->tenantId));
        self::assertSame(-2000, $verifier->evaluate($emptyMap, 'Cited [#1] on empty evidence.', ['#1']));
    }

    public function testInvariantComplianceVerifierScoresPassAndViolation(): void
    {
        $verifier = new InvariantComplianceVerifier();
        $expected = ['answer.must_cite' => true, 'safety.no_harm' => true];

        self::assertSame(500, $verifier->evaluate(null, ['answer.must_cite' => true, 'safety.no_harm' => true], $expected));
        self::assertSame(-3000, $verifier->evaluate(null, ['answer.must_cite' => false, 'safety.no_harm' => true], $expected));
        self::assertSame(-3000, $verifier->evaluate(null, ['answer.must_cite' => true], $expected));
    }

    public function testDeterministicRewardSuiteCombinesVerifiersAndHostHookWithIntegerClamp(): void
    {
        $trace = TrainingTestSupport::makeTrace(
            lockedInvariants: ['answer.must_cite' => true],
        );

        $hook = new class implements HostDomainRewardHook {
            public function id(): string
            {
                return 'sympy_math';
            }

            public function evaluate(TrainingTraceRecord $trace, string $candidateResponse): int
            {
                return str_contains($candidateResponse, 'E=mc^2') ? 9500 : -9500;
            }
        };

        $suite = new DeterministicRewardSuite(hostHook: $hook);

        $maxScore = $suite->evaluate(
            $trace,
            'Formula E=mc^2 [#1]',
            ['#1'],
            ['answer.must_cite' => true],
        );
        // 1000 + 500 + 9500 = 11000 -> clamped to 10000
        self::assertSame(10000, $maxScore->permille);
        self::assertSame(1000, $maxScore->breakdown['grounding']);
        self::assertSame(500, $maxScore->breakdown['invariants']);
        self::assertSame(9500, $maxScore->breakdown['sympy_math']);

        $minScore = $suite->evaluate(
            $trace,
            'Bad [#99]',
            ['#99'],
            ['answer.must_cite' => false],
        );
        // -2000 + -3000 + -9500 = -14500 -> clamped to -10000
        self::assertSame(-10000, $minScore->permille);
    }
}
