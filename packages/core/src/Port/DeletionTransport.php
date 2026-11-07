<?php
declare(strict_types=1);
namespace KnowKit\Port;

use KnowKit\Domain as D;

interface DeletionTransport
{
    /** Explicit adapter assertion for this exact immutable object/staging version, not a current-name delete. */
    public function provesExactVersion(D\DeletionTarget $target): bool;
    /** Runs outside SQL. A request acknowledgement alone must return unknown. */
    public function delete(D\DeletionTarget $target, D\AttemptId $attempt): D\DeletionReceipt;
}
