<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Application;

use KnowKit\Domain\{DomainFailure, Outcome, SourceVersionRef};
use KnowKit\Domain\Contract\Jcs;
use KnowKit\TrainingExport\Domain\TrainingTraceRecord;

final readonly class ContextDistillationExporter
{
    /** @return array<string, mixed> */
    public function exportRecord(TrainingTraceRecord $trace): array
    {
        if ($trace->teacherMessages === [] || $trace->studentMessages === [] || $trace->targetResponse === null || $trace->targetResponse === '') {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_distill_pair');
        }

        $sourceDigests = array_map(
            static fn (SourceVersionRef $ref): string => $ref->sourceDigest->value,
            $trace->sourceRefs,
        );
        sort($sourceDigests, SORT_STRING);

        return [
            'layerStackDigest' => $trace->layerStackDigest->value,
            'sourceDigests' => $sourceDigests,
            'studentMessages' => $trace->studentMessages,
            'targetResponse' => $trace->targetResponse,
            'teacherMessages' => $trace->teacherMessages,
            'traceId' => $trace->traceId->value,
        ];
    }

    public function exportJsonlLine(TrainingTraceRecord $trace): string
    {
        return Jcs::canonicalize($this->exportRecord($trace));
    }
}
