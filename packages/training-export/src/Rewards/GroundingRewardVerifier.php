<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Rewards;

use KnowKit\ContextCompilers\Domain\CitationAliasMap;

final readonly class GroundingRewardVerifier
{
    public const PASS_PERMILLE = 1000;
    public const HALLUCINATION_PENALTY_PERMILLE = -2000;
    public const MISSING_CITATION_PENALTY_PERMILLE = -1000;

    /**
     * @param list<string> $citations
     */
    public function evaluate(
        CitationAliasMap $aliasMap,
        string $responseText,
        array $citations = [],
        bool $requireGrounding = true,
    ): int {
        $aliases = $this->collectAliases($responseText, $citations);

        if ($aliases === []) {
            return $requireGrounding ? self::MISSING_CITATION_PENALTY_PERMILLE : 0;
        }

        if ($aliasMap->all() === []) {
            return self::HALLUCINATION_PENALTY_PERMILLE;
        }

        foreach ($aliases as $alias) {
            if ($aliasMap->resolve($alias) === null) {
                return self::HALLUCINATION_PENALTY_PERMILLE;
            }
        }

        return self::PASS_PERMILLE;
    }

    /**
     * @param list<string> $citations
     * @return list<string>
     */
    private function collectAliases(string $responseText, array $citations): array
    {
        $seen = [];
        foreach ($citations as $citation) {
            // A numeric string key is stored as an int. Keep the original
            // string as the value so strict resolve() does not receive an int.
            $seen[$citation] = $citation;
        }

        if (preg_match_all('/\[(#\d+)\]/', $responseText, $matches) > 0) {
            foreach ($matches[1] as $alias) {
                $seen[$alias] = $alias;
            }
        }

        return array_values($seen);
    }
}
