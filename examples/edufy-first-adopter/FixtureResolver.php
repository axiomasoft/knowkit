<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

final readonly class FixtureResolver implements KbResolverPort
{
    /** Ordered fixture rules, from general to specific. @param list<KbRule> $rules */
    public function __construct(
        private array $rules,
    ) {
    }

    public function resolve(string $topicId, KbContext $context): KbResolution
    {
        $entries = [];
        $excluded = [];
        foreach ($this->rules as $rule) {
            if (!$rule->matches($topicId, $context)) {
                continue;
            }
            if ($rule->mode === BindingMode::Override) {
                $entries = [];
            }
            foreach ($rule->entries as $entry) {
                $entries[$entry->kb . ':' . $entry->entry] = $entry;
            }
            $excluded = array_values(array_unique([...$excluded, ...$rule->exclude]));
        }
        $eligible = array_filter($entries, static fn (KbEntry $entry): bool => !in_array($entry->kb, $excluded, true));

        return new KbResolution(array_values($eligible), $excluded);
    }
}
