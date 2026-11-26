<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Selection;

use KnowKit\Domain as D;
use KnowKit\Port\Tokenizer;

/** Adapts AgenTools Selector's overlap/budgets with whole canonical units and stable ID ties. */
final readonly class BoundedSelector
{
    public function __construct(private ?Tokenizer $tokenizer = null) {}

    /** @param list<D\Candidate> $candidates */
    public function choose(
        D\AuthorizedSearchScope $scope,
        array $candidates,
        D\SelectionInventory $inventory,
        D\SelectionLimits $limits,
    ): D\SelectedReferences {
        D\ValueGuard::objects($candidates, D\Candidate::class, 512);
        $this->requireTokenizer($limits);
        usort($candidates, static fn (D\Candidate $a, D\Candidate $b): int =>
            ($a->rank <=> $b->rank) ?: strcmp($a->ref->chunkId->value, $b->ref->chunkId->value));
        // Verify every ref before dedupe/budgeting, including candidates that would be omitted.
        $pool = [];
        foreach ($candidates as $candidate) {
            $scope->assertCandidate($candidate->ref);
            $id = $candidate->ref->chunkId->value;
            // unit() already checks the manifest; duplicates must still be checked.
            if (!isset($pool[$id])) {
                $pool[$id] = $inventory->unit($candidate->ref);
            } else {
                $inventory->manifest->assertCandidate($candidate->ref);
            }
        }

        return $this->bound(array_values($pool), $limits);
    }

    private function requireTokenizer(D\SelectionLimits $limits): void
    {
        if ($limits->maxTokens === null) {
            return;
        }
        if ($this->tokenizer === null || $limits->tokenizer === null) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'tokenizer_required');
        }
        $this->tokenizer->capabilities()->requireExact($limits->tokenizer);
    }

    /** @param list<D\SelectionUnit> $pool */
    private function bound(array $pool, D\SelectionLimits $limits): D\SelectedReferences
    {
        $selected = $refs = $reads = $warnings = [];
        $assets = [];
        $bytes = $tokens = 0;
        foreach ($pool as $unit) {
            if ($this->overlaps($unit, $selected)) {
                $warnings[] = 'overlap_omitted';
                continue;
            }
            $text = $unit->budgetText();
            $nextAssets = $assets;
            foreach ($unit->assetKeys as $key) {
                $nextAssets[$key] = true;
            }
            $count = $this->tokens($text, $limits);
            if (count($refs) >= $limits->maxFragments || strlen($text) > $limits->maxBytesPerFragment
                || $bytes + strlen($text) > $limits->maxBytes || count($nextAssets) > $limits->maxAssets
                || ($limits->maxTokens !== null && $tokens + $count > $limits->maxTokens)) {
                $warnings[] = 'budget_exhausted';
                if ($unit->table) {
                    $reads[] = $unit->chunk->ref;
                    $warnings[] = 'table_read_required';
                }
                continue;
            }
            $selected[] = $unit;
            $refs[] = $unit->chunk->ref;
            $bytes += strlen($text);
            $tokens += $count;
            $assets = $nextAssets;
        }

        return new D\SelectedReferences($refs, $reads, array_values(array_unique($warnings)));
    }

    /** @param list<D\SelectionUnit> $selected */
    private function overlaps(D\SelectionUnit $unit, array $selected): bool
    {
        foreach ($selected as $prior) {
            if ($unit->overlaps($prior)) {
                return true;
            }
        }

        return false;
    }

    private function tokens(string $text, D\SelectionLimits $limits): int
    {
        if ($limits->maxTokens === null || $this->tokenizer === null || $limits->tokenizer === null) {
            return 0;
        }
        $tokens = $this->tokenizer->count($text, $limits->tokenizer);
        if ($tokens < 0) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'adapter_contract_violation');
        }

        return $tokens;
    }
}
