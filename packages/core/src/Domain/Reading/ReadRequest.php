<?php

declare(strict_types=1);

namespace KnowKit\Domain\Reading;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;

/** Transport carries no host identity, storage path, credentials or permission claim. */
final readonly class ReadRequest
{
    public function __construct(
        public D\Ulid $citationId,
        public D\Ulid $attemptId,
        public ReadTarget $target,
        public D\SelectionLimits $limits,
        public int $maxBytes,
        public D\UtcInstant $expiresAt,
        public ?D\Sha256 $etag = null,
        public ?string $runRef = null,
    ) {
        if ($maxBytes < 1 || $maxBytes > 8388608) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        if ($runRef !== null) {
            D\ValueGuard::text($runRef, 256);
        }
    }

    public function requireFresh(D\UtcInstant $now): void
    {
        if ($this->expiresAt->compare($now) <= 0) {
            throw new D\DomainFailure(D\Outcome::Denied, 'read_expired');
        }
        if ($this->expiresAt->compare($now->plusSeconds(300)) > 0) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'read_ttl_exceeded');
        }
    }

    public function digest(): D\Sha256
    {
        return new D\Sha256(hash('sha256', Jcs::canonicalize(new JsonObject([
            'citationId' => $this->citationId->value, 'target' => $this->target->toWire(),
            'limits' => $this->limits->toWire(), 'maxBytes' => $this->maxBytes,
            'expiresAt' => $this->expiresAt->value, 'etag' => $this->etag?->value, 'runRef' => $this->runRef,
        ]))));
    }
}
