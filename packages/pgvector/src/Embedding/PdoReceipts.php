<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Embedding;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Embeddings\ReceiptStatus;
use KnowKit\Domain\Embeddings\RequestReceipt;
use KnowKit\Port\EmbeddingReceiptStore;

/** Durable pre-send intent and atomic, tenant-bound local acceptance. Never stores text. */
final readonly class PdoReceipts implements EmbeddingReceiptStore
{
    private const BEGIN_SQL = <<<'SQL'
        INSERT INTO %s AS current (tenant_id, kb_id, request_digest, scope_digest, space_digest, next_attempt)
        VALUES (?, ?, ?, ?, ?, 1)
        ON CONFLICT (tenant_id, kb_id, request_digest) DO UPDATE
        SET next_attempt = current.next_attempt + 1
        WHERE current.scope_digest = EXCLUDED.scope_digest AND current.space_digest = EXCLUDED.space_digest
        RETURNING next_attempt
        SQL;

    private const INSERT_SQL = <<<'SQL'
        INSERT INTO %s (tenant_id, kb_id, request_digest, attempt, status)
        VALUES (?, ?, ?, ?, 'unknown')
        SQL;

    private const HEAD_SQL = <<<'SQL'
        SELECT scope_digest, space_digest, accepted_result_digest FROM %s
        WHERE tenant_id = ? AND kb_id = ? AND request_digest = ?
        SQL;

    private const ATTEMPT_SQL = <<<'SQL'
        SELECT attempt, status, result_fingerprint, provider_request_id, failure_outcome FROM %s
        WHERE tenant_id = ? AND kb_id = ? AND request_digest = ?
        SQL;

    private const UPDATE_SQL = <<<'SQL'
        UPDATE %s SET status = ?, result_fingerprint = ?, provider_request_id = ?, failure_outcome = ?
        WHERE tenant_id = ? AND kb_id = ? AND request_digest = ? AND attempt = ?
        SQL;

    private const ACCEPT_SQL = <<<'SQL'
        UPDATE %s SET accepted_result_digest = ?
        WHERE tenant_id = ? AND kb_id = ? AND request_digest = ?
        SQL;

    public function __construct(private Connection $connection) {}

    public function begin(Scope $scope, Fingerprint $request, Fingerprint $space): RequestReceipt
    {
        return $this->transaction(function () use ($scope, $request, $space): RequestReceipt {
            $scopeDigest = RequestReceipt::scopeDigest($scope);
            $head = $this->connection->row($this->sql(self::BEGIN_SQL, 'embedding_requests'),
                [...$this->key($scope, $request), $scopeDigest->value, $space->value]);
            if ($head === null) {
                $this->head($scope, $request, true);
                throw new DomainFailure(Outcome::Incompatible, 'space_mismatch');
            }
            $attempt = (int) $head['next_attempt'];
            $this->connection->execute($this->sql(self::INSERT_SQL, 'embedding_receipts'),
                [...$this->key($scope, $request), $attempt]);

            return new RequestReceipt($scopeDigest, $request, $space, $attempt);
        });
    }

    public function complete(Scope $scope, RequestReceipt $receipt): void
    {
        $receipt->assertScope($scope);
        $this->transaction(function () use ($scope, $receipt): void {
            $head = $this->head($scope, $receipt->requestDigest, true);
            if ($head === null || $head['space_digest'] !== $receipt->spaceDigest->value) {
                throw new DomainFailure(Outcome::Incompatible, 'embedding_receipt_mismatch');
            }
            $row = $this->connection->row($this->sql(self::ATTEMPT_SQL, 'embedding_receipts') . ' AND attempt = ?',
                [...$this->key($scope, $receipt->requestDigest), $receipt->attempt]);
            if ($row === null) {
                throw new DomainFailure(Outcome::Incompatible, 'embedding_receipt_missing');
            }
            $current = $this->hydrate($row, $receipt->scopeDigest, $receipt->requestDigest, $receipt->spaceDigest);
            if ($current == $receipt) {
                return;
            }
            if ($current->status !== ReceiptStatus::Unknown || $current->failureOutcome !== null) {
                throw new DomainFailure(Outcome::Incompatible, 'embedding_receipt_conflict');
            }
            $this->accept($scope, $receipt, $head['accepted_result_digest']);
            $this->connection->execute($this->sql(self::UPDATE_SQL, 'embedding_receipts'), [
                $receipt->status->value, $receipt->resultFingerprint?->value, $receipt->providerRequestId,
                $receipt->failureOutcome?->value, ...$this->key($scope, $receipt->requestDigest), $receipt->attempt,
            ]);
        });
    }

    public function receipts(Scope $scope, Fingerprint $request): array
    {
        $head = $this->head($scope, $request);
        if ($head === null) {
            return [];
        }
        $rows = $this->connection->execute($this->sql(self::ATTEMPT_SQL, 'embedding_receipts') . ' ORDER BY attempt',
            $this->key($scope, $request))->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn(array $row): RequestReceipt => $this->hydrate($row,
            RequestReceipt::scopeDigest($scope), $request, new Fingerprint($head['space_digest'])), $rows);
    }

    private function accept(Scope $scope, RequestReceipt $receipt, ?string $accepted): void
    {
        if ($receipt->status !== ReceiptStatus::Accepted) {
            return;
        }
        if ($accepted !== null && $accepted !== $receipt->resultFingerprint->value) {
            throw new DomainFailure(Outcome::Incompatible, 'embedding_result_conflict');
        }
        $this->connection->execute($this->sql(self::ACCEPT_SQL, 'embedding_requests'),
            [$receipt->resultFingerprint->value, ...$this->key($scope, $receipt->requestDigest)]);
    }

    private function head(Scope $scope, Fingerprint $request, bool $lock = false): ?array
    {
        $sql = $this->sql(self::HEAD_SQL, 'embedding_requests') . ($lock ? ' FOR UPDATE' : '');
        $head = $this->connection->row($sql, $this->key($scope, $request));
        if ($head !== null && $head['scope_digest'] !== RequestReceipt::scopeDigest($scope)->value) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }

        return $head;
    }

    private function hydrate(array $row, Fingerprint $scope, Fingerprint $request, Fingerprint $space): RequestReceipt
    {
        return new RequestReceipt($scope, $request, $space, (int) $row['attempt'], ReceiptStatus::from($row['status']),
            $row['result_fingerprint'] === null ? null : new Fingerprint($row['result_fingerprint']),
            $row['provider_request_id'], $row['failure_outcome'] === null ? null : Outcome::from($row['failure_outcome']));
    }

    private function key(Scope $scope, Fingerprint $request): array
    {
        return [$scope->tenantId->value, $scope->kbId->value, $request->value];
    }

    private function sql(string $template, string $table): string
    {
        return sprintf($template, $this->connection->table($table));
    }

    private function transaction(\Closure $work): mixed
    {
        $pdo = $this->connection->pdo;
        if ($pdo->inTransaction()) {
            throw new DomainFailure(Outcome::Incompatible, 'embedding_receipt_transaction_owned');
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $failure) {
            $this->rollbackIfActive();
            throw $failure;
        }
    }

    private function rollbackIfActive(): void
    {
        if ($this->connection->pdo->inTransaction()) {
            $this->connection->pdo->rollBack();
        }
    }
}
