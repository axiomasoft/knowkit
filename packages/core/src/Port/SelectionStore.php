<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Scope;
use KnowKit\Domain\SelectionRecord;
use KnowKit\Domain\Sha256;

/** Tenant-bound immutable records; unknown and foreign tokens both return null. No text or raw tokens. */
interface SelectionStore
{
    public function put(Scope $scope, Sha256 $tokenHash, SelectionRecord $record): void;

    public function get(Scope $scope, Sha256 $tokenHash): ?SelectionRecord;
}
