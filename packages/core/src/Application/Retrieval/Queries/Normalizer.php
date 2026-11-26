<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Queries;

use KnowKit\Domain\EffectiveProfile;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Query;
use KnowKit\Domain\QueryBinding;
use KnowKit\Domain\SelectionLimits;

/** Adapted from AgenTools Query: whitespace only; exact identifier case is preserved. */
final readonly class Normalizer
{
    public function bind(
        Query $query,
        EffectiveProfile $profile,
        SelectionLimits $limits,
        ?Fingerprint $space = null,
        ?string $rewrite = null,
        string $revision = 'whitespace:1',
    ): QueryBinding {
        $replaced = preg_replace_callback(
            '/"[^"]*"|\s+/u',
            static fn (array $match): string => str_starts_with($match[0], '"') ? $match[0] : ' ',
            $query->text(),
        );
        $text = $rewrite ?? trim($replaced ?? $query->text());

        return new QueryBinding(
            $query,
            new Query($text, $query->sourceVersionIds, $query->collectionIds),
            $revision,
            $profile,
            $limits,
            $space,
        );
    }
}
