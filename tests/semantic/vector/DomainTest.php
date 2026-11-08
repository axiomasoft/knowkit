<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Vector;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Domain as D;
use KnowKit\PgVector\Domain\DocumentVectors;
use KnowKit\PgVector\Domain\SearchPolicy;
use KnowKit\PgVector\Domain\VectorPayload;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:vector')]
final class DomainTest extends TestCase
{
    public function testNonfiniteDimensionsFloat32AndNormalizationAreRejected(): void
    {
        $space = Fixture::space();
        foreach ([[NAN, 0, 0], [INF, 0, 0], [-INF, 0, 0], ['1', 0, 0], [1, 0], ['x' => 1, 0, 0]] as $values) {
            try {
                new VectorPayload(new D\Vector($values, $space));
                self::fail('Malformed vector accepted');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
            }
        }
        LexicalFixture::failure(static fn () => new VectorPayload(new D\Vector([1.0e100, 0, 0], $space)), 'vector_float32_overflow');
        LexicalFixture::failure(static fn () => new D\Vector([0, 0, 0], Fixture::space(['normalization' => 'l2'])), 'space_mismatch');
        LexicalFixture::failure(static fn () => VectorPayload::assertSpace(Fixture::space(['dimensions' => 2001])), 'pgvector_dimensions_unsupported');
        foreach (Fixture::corpus()['vectors'] as $name => $values) {
            $payload = new VectorPayload(new D\Vector($values, $space));
            self::assertSame(Fixture::corpus()['vectorDigests'][$name], $payload->digest);
            self::assertSame($payload->digest, VectorPayload::decode($payload->literal, $space)->digest);
        }
        $unit = new VectorPayload(new D\Vector([0.6, 0.8, 0], Fixture::space(['normalization' => 'l2'])));
        self::assertStringContainsString('0.6000000238418579', $unit->literal);
        $zero = new VectorPayload(new D\Vector([-0.0, 0, 0], $space));
        self::assertSame(Fixture::corpus()['vectorDigests']['denied'], $zero->digest);
        self::assertSame($zero->digest, VectorPayload::decode($zero->literal, $space)->digest);
    }

    public function testFallbackPolicyHasNegativeExamplesAndCannotSilentlyDegrade(): void
    {
        $policy = new SearchPolicy('authorized-hnsw');
        self::assertSame(0.5, $policy->recall(['a', 'b'], ['a']));
        self::assertTrue($policy->requiresFallback(0.5));
        self::assertFalse($policy->requiresFallback(1.0));
        self::assertSame(1.0, $policy->recall([], []));
        LexicalFixture::failure(static fn () => (new SearchPolicy('authorized-hnsw', 1, 'reject'))->requiresFallback(0.5),
            'ann_recall_insufficient', D\Outcome::Unavailable);
        LexicalFixture::failure(static fn () => new SearchPolicy(filterCapability: 'postfilter-only'), 'prefilter_unsupported');
        LexicalFixture::failure(static fn () => new DocumentVectors(Fixture::space(), 'question', [], []), 'embedding_role_mismatch');
    }
}
