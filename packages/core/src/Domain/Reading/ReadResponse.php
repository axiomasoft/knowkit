<?php

declare(strict_types=1);

namespace KnowKit\Domain\Reading;

use KnowKit\Domain as D;

/** One committed disclosure. Hosts must request a new attempt for each new delivery. */
final readonly class ReadResponse
{
    public function __construct(
        public D\CitationRef $citation,
        public ReadBody $body,
        public D\UsageId $usageRef,
        public D\UtcInstant $checkedAt,
        public string $profile,
    ) {}
}
