<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Domain as D;
use KnowKit\Tests\Retrieval\Selection\Fixture;
use KnowKit\Tooling\Qa;

final class RewriteProbe
{
    public static function run(): array
    {
        $query = new D\Query('Инструкция foo_bar.v2 ревизия 7 固定', [new D\SourceVersionId(Fixture::id(3))], [new D\CollectionId(Fixture::id(9))]);
        $normalizer = new Normalizer();
        $binding = $normalizer->bind($query, Fixture::profile(), D\SelectionLimits::profile('compact'), rewrite: 'Manual foo_bar.v2 revision 7 固定');
        Qa::ensure($binding->original === $query && $binding->normalized->sourceVersionIds === $query->sourceVersionIds
            && $binding->normalized->collectionIds === $query->collectionIds, 'rewrite lost original query or filters');
        $rejected = 0;
        foreach (['Manual foo_bar.v20 revision 7 固定', 'Manual foo_bar.v2 revision 8 固定'] as $rewrite) {
            try {
                $normalizer->bind($query, Fixture::profile(), D\SelectionLimits::profile('compact'), rewrite: $rewrite);
            } catch (D\DomainFailure $failure) {
                Qa::ensure($failure->errorCode === 'rewrite_identifier_mismatch', 'unexpected rewrite error');
                ++$rejected;
            }
        }
        try {
            new D\QueryBinding($query, new D\Query($query->text(), [new D\SourceVersionId(Fixture::id(4))]),
                'unsafe-rewrite', Fixture::profile(), D\SelectionLimits::profile('compact'));
        } catch (D\DomainFailure $failure) {
            Qa::ensure($failure->errorCode === 'rewrite_filter_mismatch', 'unexpected filter error');
            ++$rejected;
        }
        Qa::ensure($rejected === 3, 'unsafe rewrite accepted');

        return ['id' => 'R24-A03', 'status' => 'passed', 'unsafeRewritesRejected' => $rejected,
            'bindingFingerprint' => $binding->digest()->value];
    }
}
