<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Historical attempt metadata only. Never contains, or authorizes replay of, plaintext. */
final readonly class DisclosureReplay
{
    public function __construct(
        public UsageId $usageRef,
        public Ulid $disclosureAttemptId,
        public UtcInstant $checkedAt,
        public Sha256 $evidenceDigest,
    ) {}
}
