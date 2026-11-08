<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Embeddings\RequestReceipt;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Scope;

interface EmbeddingReceiptStore
{
    /** Atomically allocate an attempt and durably record unknown BEFORE sending any bytes. */
    public function begin(Scope $scope, Fingerprint $request, Fingerprint $space): RequestReceipt;

    /** Atomic local acceptance: repeated identical results succeed; changed results conflict. */
    public function complete(Scope $scope, RequestReceipt $receipt): void;

    /** @return list<RequestReceipt> Exact trusted scope; never an authorization cache. */
    public function receipts(Scope $scope, Fingerprint $request): array;
}
