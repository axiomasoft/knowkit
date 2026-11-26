<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;

/** Private versioned storage encoding; the public Selection wire contract is unchanged. */
final readonly class SelectionRecordCodec
{
    public function __construct(private ContractValidator $validator) {}

    public function encode(D\SelectionRecord $record): JsonObject
    {
        return new JsonObject([
            'recordVersion' => 'selection-record:1',
            'selection' => $record->selection->toWire(),
            'scope' => $record->context->scope->toWire(),
            'audience' => $record->context->audience,
            'grants' => $record->context->grants,
            'egress' => $record->context->egress,
            'query' => $record->query->toWire(),
            'profile' => $record->query->profile->toWire(),
            'snapshotRef' => $record->snapshot->toWire(),
            'createdAt' => $record->createdAt->value,
        ]);
    }

    public function decode(JsonObject $wire): D\SelectionRecord
    {
        if ($wire->get('recordVersion') !== 'selection-record:1') {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'selection_record_version');
        }
        $s = $wire->get('scope');
        $scope = new D\Scope(new D\TenantId($s->get('tenantId')), new D\KbId($s->get('kbId')),
            new D\ActorId($s->get('actorId')), $s->get('purpose'), new D\Decimal($s->get('policyEpoch')),
            new D\Sha256($s->get('hostScopeHash')));
        $snapshot = $wire->get('snapshotRef');

        return new D\SelectionRecord(
            new D\Selection($wire->get('selection'), $this->validator),
            new PolicyContext($scope, $wire->get('audience'), $wire->get('grants'), $wire->get('egress')),
            $this->query($wire->get('query'), $wire->get('profile')),
            new D\SnapshotRef(new D\TenantId($snapshot->get('tenantId')), new D\KbId($snapshot->get('kbId')),
                new D\SnapshotId($snapshot->get('snapshotId')), new D\Fingerprint($snapshot->get('manifestDigest'))),
            new D\UtcInstant($wire->get('createdAt')),
        );
    }

    private function query(JsonObject $q, JsonObject $profile): D\QueryBinding
    {
        $versions = $this->ids($q->get('sourceVersionIds'), D\SourceVersionId::class);
        $collections = $this->ids($q->get('collectionIds'), D\CollectionId::class);
        $limits = $q->get('limits');
        $token = $limits->get('tokenBudget');
        $identity = $token === null ? null : new D\TokenizerIdentity(new D\AdapterId($token->get('tokenizerId')),
            $token->get('tokenizerVersion'), new D\Sha256($token->get('vocabularyDigest')));
        $binding = new D\QueryBinding(
            new D\Query($q->get('originalQuery'), $versions, $collections),
            new D\Query($q->get('normalizedQuery'), $versions, $collections),
            $q->get('normalizationRevision'),
            $this->profile($profile),
            D\SelectionLimits::profile($limits->get('profile'), $limits->get('maxFragments'), $limits->get('maxBytes'),
                $limits->get('maxBytesPerFragment'), $limits->get('maxAssets'), $token?->get('maxTokens'), $identity),
            $q->get('spaceFingerprint') === null ? null : new D\Fingerprint($q->get('spaceFingerprint')),
        );
        if ($binding->profile->digest()->value !== $q->get('effectiveProfileDigest')) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'selection_binding_mismatch');
        }

        return $binding;
    }

    /**
     * @template T of D\Ulid
     * @param list<string>|null $ids
     * @param class-string<T> $class
     * @return list<T>|null
     */
    private function ids(?array $ids, string $class): ?array
    {
        return $ids === null ? null : array_map(static fn (string $id) => new $class($id), $ids);
    }

    private function profile(JsonObject $wire): D\EffectiveProfile
    {
        $bindings = $config = [];
        /** @var array<string, list<D\Capability>> $capabilities */
        $capabilities = [];
        foreach ($wire->get('adapterBindings') as $port => $binding) {
            $id = (string) $binding->get('adapterId');
            $bindings[$port] = new D\AdapterId($id);
            $config[$id] = $this->configuration($binding->get('config'));
            $capabilities[$id] = array_values(array_map(D\Capability::from(...), $binding->get('capabilities')));
        }

        return new D\EffectiveProfile($wire->get('id'), $wire->get('version'), $bindings, $config, $capabilities);
    }

    /** @return array<string, mixed> */
    private function configuration(JsonObject $wire): array
    {
        $values = [];
        foreach ($wire as $key => $value) {
            if ($value instanceof JsonObject) {
                $value = $value->has('handle') && $value->has('present')
                    ? new D\CredentialRef($value->get('handle'), $value->get('present'))
                    : $this->configuration($value);
            }
            $values[$key] = $value;
        }

        return $values;
    }
}
