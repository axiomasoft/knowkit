<?php
declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\{DomainFailure, Outcome, TokenizerIdentity};
use KnowKit\Port\Tokenizer;

/** Local budget guard, not disclosure authorization. Byte limits always apply. */
final class TokenBudget
{
    public static function require(string $utf8, int $maxBytes, ?int $maxTokens = null,
        ?Tokenizer $tokenizer = null, ?TokenizerIdentity $identity = null): void
    {
        if ($maxBytes < 0 || ($maxTokens !== null && $maxTokens < 0) || preg_match('//u', $utf8) !== 1) {
            throw new \InvalidArgumentException('Valid UTF-8 and nonnegative budgets required');
        }
        if (strlen($utf8) > $maxBytes) { throw new DomainFailure(Outcome::Incompatible, 'byte_budget_exceeded'); }
        if ($maxTokens === null) { return; }
        if ($tokenizer === null || $identity === null) { throw new DomainFailure(Outcome::Incompatible, 'tokenizer_required'); }
        $tokenizer->capabilities()->requireExact($identity);
        $count = $tokenizer->count($utf8, $identity);
        if ($count < 0) { throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation'); }
        if ($count > $maxTokens) { throw new DomainFailure(Outcome::Incompatible, 'token_budget_exceeded'); }
    }
}
