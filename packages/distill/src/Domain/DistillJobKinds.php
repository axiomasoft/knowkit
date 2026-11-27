<?php

declare(strict_types=1);

namespace KnowKit\Distill\Domain;

use KnowKit\Domain\JobType;

/** Canonical lowercase snake_case JobType identifiers for the four distillation jobs. */
final readonly class DistillJobKinds
{
    public const DISTILL_R0_OUTLINE = 'distill_r0_outline';
    public const DISTILL_R1_DIGEST = 'distill_r1_digest';
    public const DISTILL_R2_PROPOSITIONS = 'distill_r2_propositions';
    public const EXTRACT_CONCEPT_EDGES = 'extract_concept_edges';

    public static function r0Outline(): JobType
    {
        return new JobType(self::DISTILL_R0_OUTLINE);
    }

    public static function r1Digest(): JobType
    {
        return new JobType(self::DISTILL_R1_DIGEST);
    }

    public static function r2Propositions(): JobType
    {
        return new JobType(self::DISTILL_R2_PROPOSITIONS);
    }

    public static function extractConceptEdges(): JobType
    {
        return new JobType(self::EXTRACT_CONCEPT_EDGES);
    }

    /** @return list<JobType> */
    public static function all(): array
    {
        return [
            self::r0Outline(),
            self::r1Digest(),
            self::r2Propositions(),
            self::extractConceptEdges(),
        ];
    }

    public static function isDistillJob(JobType $type): bool
    {
        return in_array($type->value, [
            self::DISTILL_R0_OUTLINE,
            self::DISTILL_R1_DIGEST,
            self::DISTILL_R2_PROPOSITIONS,
            self::EXTRACT_CONCEPT_EDGES,
        ], true);
    }
}
