<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Legacy;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Result;
use KnowKit\Domain\Scope;
use KnowKit\Domain\ValueGuard;

/**
 * Adapted from AgenTools PostgresGraph exact identity/conflict checks.
 * Copyright 2026 Dmitry Vostrikov. Private local adaptation; no SQL storage copied.
 * Pin and rationale: tests/bridge/contracts/evidence/P8.1-reuse.json.
 */
final readonly class IdentityMap
{
    /** @var array<string, Mapping> */
    private array $entries;

    /** @param list<Mapping> $entries */
    public function __construct(array $entries)
    {
        ValueGuard::objects(values: $entries, type: Mapping::class);
        $indexed = [];
        foreach ($entries as $entry) {
            $key = $entry->release->key();
            if (isset($indexed[$key])) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'immutable_conflict');
            }
            $indexed[$key] = $entry;
        }
        $this->entries = $indexed;
    }

    /** @return Result<Mapping> */
    public function resolve(Scope $scope, ReleaseIdentity $release): Result
    {
        if (!$scope->tenantId->equals($release->tenantId)) {
            return Result::failure(new DomainFailure(outcome: Outcome::Denied, errorCode: 'scope_mismatch'));
        }
        $entry = $this->entries[$release->key()] ?? null;
        if ($entry === null) {
            return Result::failure(new DomainFailure(outcome: Outcome::Unavailable, errorCode: 'legacy_mapping_missing'));
        }
        if ($entry->target !== null && !$scope->kbId->equals($entry->target->snapshot->kbId)) {
            return Result::failure(new DomainFailure(outcome: Outcome::Denied, errorCode: 'scope_mismatch'));
        }

        return Result::success($entry);
    }
}
