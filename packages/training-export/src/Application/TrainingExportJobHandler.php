<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Application;

use KnowKit\Domain\{
    DomainFailure,
    JobType,
    ProfileId,
    Sha256,
    SnapshotRef,
    TenantId,
    Ulid,
    UtcInstant,
};
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\TrainingExport\Domain\{
    ExportShardReceipt,
    TrainingExportManifest,
    TrainingFormat,
    TrainingTraceRecord,
};

final readonly class TrainingExportJobHandler
{
    public function __construct(
        private ExportAdmissionGate $admissionGate,
        private ProfileId $piiRedactorId,
        private ContextDistillationExporter $distillExporter = new ContextDistillationExporter(),
        private GroundedSftExporter $sftExporter = new GroundedSftExporter(),
        private GrpoTaskExporter $grpoExporter = new GrpoTaskExporter(),
    ) {
        if ($piiRedactorId->value !== $admissionGate->redactorId()->value) {
            throw new \InvalidArgumentException('piiRedactorId must be the id of the redactor that admits traces');
        }
    }

    public static function jobType(): JobType
    {
        return new JobType('export_training_traces');
    }

    /**
     * @param list<TrainingTraceRecord> $traces
     * @return array{
     *   manifest: TrainingExportManifest,
     *   shardsContent: array<string, string>,
     *   auditExcluded: list<array{traceId: string, reason: string}>
     * }
     */
    public function handle(
        Ulid $exportId,
        TenantId $tenantId,
        TrainingFormat $format,
        UtcInstant $createdAt,
        array $traces,
        ContractValidator $validator,
        string $shardDir = 'shards',
    ): array {
        $sortedTraces = $traces;
        usort($sortedTraces, static fn (TrainingTraceRecord $a, TrainingTraceRecord $b): int => strcmp($a->traceId->value, $b->traceId->value));

        $lines = [];
        $excludedCounts = [];
        $auditExcluded = [];
        $layerStackDigests = [];
        $snapshotRefs = [];

        foreach ($sortedTraces as $trace) {
            try {
                $admitted = $this->admissionGate->admit($trace);
                foreach ($admitted->snapshotRefs as $snap) {
                    $key = $snap->tenantId->value . ':' . $snap->kbId->value . ':' . $snap->snapshotId->value . ':' . $snap->manifestDigest->value;
                    $snapshotRefs[$key] ??= $snap;
                }
                $layerStackDigests[$admitted->layerStackDigest->value] ??= $admitted->layerStackDigest;
                $lines[] = match ($format) {
                    TrainingFormat::DistillPairsV1 => $this->distillExporter->exportJsonlLine($admitted),
                    TrainingFormat::GroundedSftV1 => $this->sftExporter->exportJsonlLine($admitted),
                    TrainingFormat::GrpoTasksV1 => $this->grpoExporter->exportJsonlLine($admitted),
                };
            } catch (DomainFailure $failure) {
                $code = $failure->errorCode;
                $excludedCounts[$code] = ($excludedCounts[$code] ?? 0) + 1;
                // Audit log records ONLY traceId and machine reason code — never plaintext!
                $auditExcluded[] = [
                    'traceId' => $trace->traceId->value,
                    'reason' => $code,
                ];
            }
        }

        ksort($layerStackDigests, SORT_STRING);
        ksort($snapshotRefs, SORT_STRING);

        $shardPath = rtrim($shardDir, '/') . '/' . $format->value . '-0001.jsonl';
        $jsonlBytes = $lines === [] ? '' : implode("\n", $lines) . "\n";

        $shard = new ExportShardReceipt(
            path: $shardPath,
            recordCount: count($lines),
            sha256: new Sha256(hash('sha256', $jsonlBytes)),
            byteLength: strlen($jsonlBytes),
        );

        $manifest = TrainingExportManifest::create(
            exportId: $exportId,
            tenantId: $tenantId,
            format: $format,
            createdAt: $createdAt,
            layerStackDigests: array_values($layerStackDigests),
            snapshotRefs: array_values($snapshotRefs),
            shards: [$shard],
            includedCount: count($lines),
            excludedCountsByReason: $excludedCounts,
            piiRedactorId: $this->piiRedactorId,
            validator: $validator,
        );

        return [
            'manifest' => $manifest,
            'shardsContent' => [$shardPath => $jsonlBytes],
            'auditExcluded' => $auditExcluded,
        ];
    }
}
