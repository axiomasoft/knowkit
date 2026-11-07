<?php
declare(strict_types=1);

namespace KnowKit\Domain\Access;

use KnowKit\Domain\{DomainFailure, Outcome, Scope};
use KnowKit\Domain\Contract\{Jcs, JsonObject};

/** Trusted host composition value, never a transport DTO or an authorization decision. */
final readonly class PolicyContext
{
    /** @param list<string> $grants @param list<string> $egress */
    public function __construct(public Scope $scope, public string $audience,
        public array $grants = [], public array $egress = [])
    {
        foreach ([$audience, ...$grants, ...$egress] as $name) {
            if (!is_string($name) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9:._-]{0,127}$/D', $name) !== 1) {
                throw new \InvalidArgumentException('Bounded host policy identifier required');
            }
        }
        if (count($grants) > 256 || count($egress) > 256
            || count(array_unique($grants)) !== count($grants) || count(array_unique($egress)) !== count($egress)) {
            throw new \InvalidArgumentException('Bounded unique host eligibility required');
        }
    }

    public function binding(): string
    {
        return Jcs::canonicalize(new JsonObject(['scope'=>$this->scope->toWire(),
            'audience'=>$this->audience, 'grants'=>$this->grants, 'egress'=>$this->egress]));
    }

    public function assertSame(self $current): void
    {
        $identity = fn(self $context): string => Jcs::canonicalize(new JsonObject([
            'scope'=>$context->scope->toWire()->without('policyEpoch'), 'audience'=>$context->audience,
            'grants'=>$context->grants, 'egress'=>$context->egress]));
        if ($identity($this) !== $identity($current)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
        if ($this->scope->policyEpoch->value !== $current->scope->policyEpoch->value) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
    }

    public function __debugInfo(): array { return ['context'=>'host']; }
}
