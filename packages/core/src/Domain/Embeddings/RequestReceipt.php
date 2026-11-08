<?php

declare(strict_types=1);

namespace KnowKit\Domain\Embeddings;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Contract\Jcs;

/** Safe evidence only: no text, credentials, endpoint URLs or exception messages. */
final readonly class RequestReceipt
{
    public function __construct(
        public Fingerprint $scopeDigest,
        public Fingerprint $requestDigest,
        public Fingerprint $spaceDigest,
        public int $attempt,
        public ReceiptStatus $status = ReceiptStatus::Unknown,
        public ?Fingerprint $resultFingerprint = null,
        public ?string $providerRequestId = null,
        public ?Outcome $failureOutcome = null,
    ) {
        self::assertRequestId($providerRequestId);
        if ($attempt < 1 || ($status === ReceiptStatus::Accepted) !== ($resultFingerprint !== null)
            || ($status === ReceiptStatus::Accepted && $failureOutcome !== null)
            || ($status === ReceiptStatus::Failed && !in_array($failureOutcome, [Outcome::Denied, Outcome::Unavailable, Outcome::Incompatible], true))
            || ($status === ReceiptStatus::Unknown && !in_array($failureOutcome, [null, Outcome::Unknown], true))
            || in_array($failureOutcome, [Outcome::Success, Outcome::Empty], true)) {
            throw new \InvalidArgumentException('Consistent embedding receipt required');
        }
    }

    public static function scopeDigest(Scope $scope): Fingerprint
    {
        return new Fingerprint(hash('sha256', "knowkit:embedding-receipt-scope:v1\0" . Jcs::canonicalize($scope->toWire())));
    }

    public function assertScope(Scope $scope): void
    {
        if (!$this->scopeDigest->equals(self::scopeDigest($scope))) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }

    public function finish(
        ReceiptStatus $status,
        ?Fingerprint $result = null,
        ?string $requestId = null,
        ?Outcome $failure = null,
    ): self {
        return new self($this->scopeDigest, $this->requestDigest, $this->spaceDigest,
            $this->attempt, $status, $result, $requestId, $failure);
    }

    public static function assertRequestId(?string $id): void
    {
        if ($id !== null && preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/D', $id) !== 1) {
            throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
        }
    }
}
