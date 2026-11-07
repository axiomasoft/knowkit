<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Application\Access\ReceiptFactory;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port as P;

/** Explicit single-user local host integration. This is not a multi-user authentication provider. */
final class Host implements HostIdentity, P\AccessPolicy, P\EgressPolicy, P\Clock, P\IdGenerator
{
    private const SOURCES_SQL = <<<'SQL'
        SELECT s.id,s.lifecycle_epoch,s.source_policy_epoch FROM sources s
        JOIN source_memberships m ON m.tenant_id=s.tenant_id AND m.source_id=s.id
        WHERE s.tenant_id=? AND m.kb_id=? GROUP BY s.id,s.tenant_id ORDER BY s.id
        SQL;

    public ?JsonObject $bindingOverride = null;
    public D\Decision $decision = D\Decision::Allow;

    public function __construct(
        public readonly Connection $db,
        public readonly ContractValidator $validator,
        private readonly D\Scope $identity,
    ) {}

    public static function id(string $seed): string
    {
        $bits = '00';
        foreach (str_split(substr(hash('sha256', $seed), 0, 32)) as $digit) {
            $bits .= str_pad(decbin(hexdec($digit)), 4, '0', STR_PAD_LEFT);
        }
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $id = '';
        foreach (str_split($bits, 5) as $part) {
            $id .= $alphabet[bindec($part)];
        }

        return $id;
    }

    public function next(): D\Ulid
    {
        return new D\Ulid(self::id(bin2hex(random_bytes(32))));
    }

    public function now(): D\UtcInstant
    {
        $now = $this->db->execute('SELECT clock_timestamp()')->fetchColumn();

        return new D\UtcInstant((new \DateTimeImmutable($now))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function scope(): D\Scope
    {
        return $this->current()->scope;
    }

    public function current(): PolicyContext
    {
        $s = $this->identity;
        $row = $this->db->row('SELECT kb_policy_epoch FROM knowledge_bases WHERE tenant_id=? AND id=?',
            [$s->tenantId->value, $s->kbId->value]);
        $scope = new D\Scope($s->tenantId, $s->kbId, $s->actorId, $s->purpose,
            new D\Decimal((string) ($row['kb_policy_epoch'] ?? '0')), $s->hostScopeHash);

        return new PolicyContext($scope, 'local-owner', ['source:read']);
    }

    public function context(): AccessContext
    {
        return AccessContext::fromHost($this);
    }

    /** @param list<string>|null $sourceIds Null selects the current KB source universe. */
    public function binding(?array $sourceIds = null): JsonObject
    {
        $s = $this->scope();
        $tenant = $this->db->row('SELECT tenant_policy_epoch FROM tenants WHERE id=?', [$s->tenantId->value]);
        $rows = $this->db->execute(self::SOURCES_SQL, [$s->tenantId->value, $s->kbId->value])->fetchAll(\PDO::FETCH_ASSOC);
        if ($sourceIds !== null) {
            $rows = [];
            sort($sourceIds, SORT_STRING);
            foreach (array_unique($sourceIds) as $id) {
                $row = $this->db->row('SELECT id,lifecycle_epoch,source_policy_epoch FROM sources WHERE tenant_id=? AND id=?',
                    [$s->tenantId->value, $id]);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }
        $sources = array_map(static fn (array $row): JsonObject => new JsonObject([
            'sourceId' => $row['id'], 'lifecycleEpoch' => (string) $row['lifecycle_epoch'],
            'sourcePolicyEpoch' => (string) $row['source_policy_epoch'],
        ]), $rows);

        return new JsonObject(['tenantPolicyEpoch' => (string) ($tenant['tenant_policy_epoch'] ?? '0'),
            'kbPolicyEpoch' => $s->policyEpoch->value, 'sources' => $sources]);
    }

    /** @return list<D\SourceVersionId> */
    public function eligible(): array
    {
        $s = $this->scope();
        $sql = <<<'SQL'
            SELECT DISTINCT v.id FROM source_versions v
            JOIN sources s ON s.tenant_id=v.tenant_id AND s.id=v.source_id
            JOIN source_memberships m ON m.tenant_id=s.tenant_id AND m.source_id=s.id
            JOIN source_version_state vs ON vs.tenant_id=v.tenant_id AND vs.id=v.id
            WHERE v.tenant_id=? AND m.kb_id=? AND s.lifecycle_state='active' AND vs.usability='usable'
            ORDER BY v.id
            SQL;

        return array_map(static fn (array $row): D\SourceVersionId => new D\SourceVersionId($row['id']),
            $this->db->execute($sql, [$s->tenantId->value, $s->kbId->value])->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function authorizeQuery(D\Scope $scope): D\QueryGrant
    {
        $this->requireScope($scope);
        if ($this->decision !== D\Decision::Allow) {
            throw new D\DomainFailure(D\Outcome::Denied, 'access_denied');
        }

        return new D\QueryGrant(new JsonObject([
            'allowedCollectionIds' => [],
            'allowedSourceVersionIds' => array_map(static fn (D\SourceVersionId $id): string => $id->value, $this->eligible()),
            'grantEpoch' => $scope->policyEpoch->value,
            'policyBindingDigest' => HashDomain::PolicyBinding->digest($this->binding()),
            'expiresAt' => $this->now()->plusSeconds(30)->value,
        ]), $this->validator);
    }

    public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
    {
        $this->requireScope($scope);
        $scope->assertTenant($resource->tenantId);
        $binding = $this->bindingOverride ?? $this->binding($resource->kind === 'source' ? [$resource->id->value] : []);

        return (new ReceiptFactory($this->validator, $this))->issue($this->decision,
            $scope, [$resource], $operation, $binding, 'local-owner:1');
    }

    public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
    {
        throw new D\DomainFailure(D\Outcome::Denied, 'egress_denied');
    }

    private function requireScope(D\Scope $scope): void
    {
        if ($scope != $this->scope() || $this->db->pdo->inTransaction()) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
    }
}
