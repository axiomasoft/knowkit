<?php
declare(strict_types=1);
namespace KnowKit\Port;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Administrative recovery I/O, outside metadata transactions. Never confers disclosure permission.
 * The host freezes writers/traffic and supplies authenticated, complete backup/journal headers.
 * Missing required adapters throw Unavailable; they must never return an empty successful inventory.
 */
interface RecoveryEvidence
{
    /**
     * Contiguous entries strictly after sequence, at most limit, through the plan cutoff.
     *
     * @return list<JsonObject>
     */
    public function journal(D\RecoveryPlan $plan, D\Decimal $after, int $limit): array;

    /**
     * Stable, complete object inventory, ordered by storageId + '/' + storageKey.
     * Cursor is the last such key. Include staging objects.
     *
     * @return list<JsonObject>
     */
    public function objects(D\RecoveryPlan $plan, ?string $after, int $limit): array;
    /** Verify exact storage/key/version/digest/length, or exact absence for deleted resources.
     * Return verified|absent|unknown|unsupported|unavailable|incompatible, never infer a provider receipt.
     */
    public function resource(D\RecoveryPlan $plan, JsonObject $resource): string;
    /** Validate immutable manifests and canonical resources, recreate projections, then read them back.
     * Must be idempotent across kill before checkpoint; only true is proof. */
    public function rebuild(D\RecoveryPlan $plan, JsonObject $generation): bool;
    /** Exercise the real denied-source history/payload boundary; only true is proof. */
    public function deniedCanary(D\RecoveryPlan $plan): bool;
}
