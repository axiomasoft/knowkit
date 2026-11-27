<?php
declare(strict_types=1);

namespace KnowKit\Application\Runtime;

use KnowKit\Domain\{ActorId, Decimal, DomainFailure, InstanceId, KbId, Outcome, Scope, Sha256, TenantId};
use KnowKit\Domain\Contract\{HashDomain, JsonObject};

/** Deterministic single-tenant local scope factory for IsolationPreset::SingleLocal. */
final readonly class SingleLocalScopeFactory
{
    public function __construct(
        private InstanceId $instanceId,
        private string $kbName,
        private string $purpose = 'knowkit.local_read',
    ) {
        if (trim($kbName) === '') {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_identifier');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){1,3}$/D', $purpose) !== 1) {
            throw new \InvalidArgumentException('Namespaced business purpose required');
        }
    }

    public function tenantId(): TenantId
    {
        return new TenantId($this->deterministicUlid('tenant'));
    }

    public function kbId(): KbId
    {
        return new KbId($this->deterministicUlid('kb'));
    }

    public function actorId(): ActorId
    {
        return new ActorId($this->deterministicUlid('actor'));
    }

    public function hostScopeHash(): Sha256
    {
        return new Sha256(HashDomain::ScopeBinding->digest(new JsonObject([
            'preset' => 'single_local',
            'kind' => 'host_scope',
            'instanceId' => $this->instanceId->value,
            'kbName' => $this->kbName,
            'tenantId' => $this->tenantId()->value,
            'kbId' => $this->kbId()->value,
            'actorId' => $this->actorId()->value,
            'purpose' => $this->purpose,
        ])));
    }

    public function scope(int $policyEpoch = 1): Scope
    {
        if ($policyEpoch < 0) {
            throw new \InvalidArgumentException('Non-negative policy epoch required');
        }

        return new Scope(
            $this->tenantId(),
            $this->kbId(),
            $this->actorId(),
            $this->purpose,
            new Decimal((string) $policyEpoch),
            $this->hostScopeHash(),
        );
    }

    private function deterministicUlid(string $kind): string
    {
        $hex = substr(HashDomain::ScopeBinding->digest(new JsonObject([
            'preset' => 'single_local',
            'kind' => $kind,
            'instanceId' => $this->instanceId->value,
            'kbName' => $this->kbName,
        ])), 0, 32);
        $bits = '00';
        foreach (str_split($hex) as $digit) {
            $bits .= str_pad(decbin((int) hexdec($digit)), 4, '0', STR_PAD_LEFT);
        }
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $id = '';
        foreach (str_split($bits, 5) as $part) {
            $id .= $alphabet[(int) bindec($part)];
        }

        return $id;
    }
}
