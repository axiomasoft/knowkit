<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Domain;

use KnowKit\Domain\{
    ProfileId,
    Sha256,
    SnapshotRef,
    TenantId,
    Ulid,
    UtcInstant,
    ValueGuard,
};
use KnowKit\Domain\Contract\{ContractValidator, ContractViolation, HashDomain, JsonObject};

final readonly class TrainingExportManifest
{
    public const SCHEMA_ID = 'urn:knowkit:schema:training-export:1.0';
    public const SCHEMA_VERSION = 'training-export:1';

    /**
     * @param list<Sha256> $layerStackDigests
     * @param list<SnapshotRef> $snapshotRefs
     * @param list<ExportShardReceipt> $shards
     * @param array<string, int> $excludedCountsByReason
     */
    private function __construct(
        public Ulid $exportId,
        public TenantId $tenantId,
        public TrainingFormat $format,
        public UtcInstant $createdAt,
        public array $layerStackDigests,
        public array $snapshotRefs,
        public array $shards,
        public int $includedCount,
        public array $excludedCountsByReason,
        public ProfileId $piiRedactorId,
        public Sha256 $manifestDigest,
    ) {
    }

    /**
     * @param list<Sha256> $layerStackDigests
     * @param list<SnapshotRef> $snapshotRefs
     * @param list<ExportShardReceipt> $shards
     * @param array<string, int> $excludedCountsByReason
     */
    public static function create(
        Ulid $exportId,
        TenantId $tenantId,
        TrainingFormat $format,
        UtcInstant $createdAt,
        array $layerStackDigests,
        array $snapshotRefs,
        array $shards,
        int $includedCount,
        array $excludedCountsByReason,
        ProfileId $piiRedactorId,
        ContractValidator $validator,
    ): self {
        ValueGuard::objects($layerStackDigests, Sha256::class, 256);
        ValueGuard::objects($snapshotRefs, SnapshotRef::class, 256);
        ValueGuard::objects($shards, ExportShardReceipt::class, 1024);

        $sortedShards = $shards;
        usort($sortedShards, static fn (ExportShardReceipt $a, ExportShardReceipt $b): int => strcmp($a->path, $b->path));

        $sortedReasons = $excludedCountsByReason;
        ksort($sortedReasons, SORT_STRING);

        $withoutDigest = self::buildWire(
            $exportId,
            $tenantId,
            $format,
            $createdAt,
            $layerStackDigests,
            $snapshotRefs,
            $sortedShards,
            $includedCount,
            $sortedReasons,
            $piiRedactorId,
        );
        $digest = new Sha256(HashDomain::TrainingExportManifest->digest($withoutDigest));
        $wire = $withoutDigest->with('manifestDigest', $digest->value);

        $errors = $validator->validateValue($wire, self::SCHEMA_ID);
        if ($errors !== []) {
            throw new ContractViolation($errors[0]['code'], $errors[0]['path']);
        }

        return new self(
            exportId: $exportId,
            tenantId: $tenantId,
            format: $format,
            createdAt: $createdAt,
            layerStackDigests: $layerStackDigests,
            snapshotRefs: $snapshotRefs,
            shards: $sortedShards,
            includedCount: $includedCount,
            excludedCountsByReason: $sortedReasons,
            piiRedactorId: $piiRedactorId,
            manifestDigest: $digest,
        );
    }

    public function digest(): Sha256
    {
        return $this->manifestDigest;
    }

    public function toWire(): JsonObject
    {
        return self::buildWire(
            $this->exportId,
            $this->tenantId,
            $this->format,
            $this->createdAt,
            $this->layerStackDigests,
            $this->snapshotRefs,
            $this->shards,
            $this->includedCount,
            $this->excludedCountsByReason,
            $this->piiRedactorId,
        )->with('manifestDigest', $this->manifestDigest->value);
    }

    /**
     * @param list<Sha256> $layerStackDigests
     * @param list<SnapshotRef> $snapshotRefs
     * @param list<ExportShardReceipt> $shards
     * @param array<string, int> $excludedCountsByReason
     */
    private static function buildWire(
        Ulid $exportId,
        TenantId $tenantId,
        TrainingFormat $format,
        UtcInstant $createdAt,
        array $layerStackDigests,
        array $snapshotRefs,
        array $shards,
        int $includedCount,
        array $excludedCountsByReason,
        ProfileId $piiRedactorId,
    ): JsonObject {
        return new JsonObject([
            'schemaVersion' => self::SCHEMA_VERSION,
            'exportId' => $exportId->value,
            'tenantId' => $tenantId->value,
            'format' => $format->value,
            'createdAt' => $createdAt->value,
            'layerStackDigests' => array_map(static fn (Sha256 $d): string => $d->value, $layerStackDigests),
            'snapshotRefs' => array_map(static fn (SnapshotRef $ref): JsonObject => $ref->toWire(), $snapshotRefs),
            'shards' => array_map(static fn (ExportShardReceipt $shard): JsonObject => $shard->toWire(), $shards),
            'includedCount' => $includedCount,
            'excludedCountsByReason' => new JsonObject($excludedCountsByReason),
            'piiRedactorId' => $piiRedactorId->value,
        ]);
    }
}
