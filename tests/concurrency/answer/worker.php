<?php

declare(strict_types=1);

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Answer\Application\InvocationRecovery;
use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Answer\Conformance\ReplayFixture;

require_once dirname(__DIR__, 2) . '/answer/conformance/bootstrap.php';

[$script, $schema, $role, $root, $scopeJson, $mode, $worker] = $argv;
Connection::schema($schema);
Connection::schema($role);
$pdo = new PDO(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',
    getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET ROLE ' . $role);
$pdo->exec('SET search_path TO ' . $schema);
$wire = StrictJson::decode($scopeJson);
$scope = new D\Scope(new D\TenantId($wire->get('tenantId')), new D\KbId($wire->get('kbId')),
    new D\ActorId($wire->get('actorId')), $wire->get('purpose'), new D\Decimal($wire->get('policyEpoch')),
    new D\Sha256($wire->get('hostScopeHash')));
// Crash windows must expire on the database clock. A two-second lease dies while the next process starts.
$leaseSeconds = str_starts_with($mode, 'crash-') ? 2 : 120;
$f = new ReplayFixture(new Connection($pdo, $schema), $root, $scope, $worker, $leaseSeconds);

function barrier(string $name): void
{
    fwrite(STDOUT, $name . "\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Interrupted fixture barrier');
    }
}

function report(ReplayFixture $f, ?A\AnswerOutcome $outcome = null, ?string $error = null): void
{
    fwrite(STDOUT, Jcs::canonicalize(new JsonObject(['pid' => getmypid(), 'backend' => $f->db->pdo->query('SELECT pg_backend_pid()')->fetchColumn(),
        'sends' => $f->transport->sendCount(), 'bytes' => $f->transport->outboundBytes(),
        'resolutions' => $f->resolutions, 'state' => $f->row()['state'], 'outcome' => $outcome?->toWire(),
        'bindingDigest' => $f->ledger->find($f->request()->invocationRef())?->pins->binding->bindingDigest->value,
        'resolvedBindingDigest' => $f->binding->bindingDigest->value,
        'error' => $error])) . "\n");
    fflush(STDOUT);
}

barrier('READY');
try {
    if (str_starts_with($mode, 'deny:')) {
        $f->deny(substr($mode, 5));
        fwrite(STDOUT, "MUTATED\n");
        exit(0);
    }
    if (in_array($mode, ['correct', 'revoke', 'tombstone'], true)) {
        $tx = new \KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction($f->db);
        $tx->run(function () use ($tx, $f, $mode): void {
            $tx->lockTenant($f->scope->tenantId, D\LockMode::Update);
            $tx->lockKb($f->scope->kbId, D\LockMode::Update);
            $tx->lockSources([new D\SourceId(ReplayFixture::id(3))], D\LockMode::Update);
            if ($mode === 'correct') {
                $f->db->execute("UPDATE source_version_state SET usability='corrected' WHERE tenant_id=? AND id=?",
                    [$f->scope->tenantId->value, $f->citation()->toWire()->get('sourceVersionId')]);

                return;
            }
            if ($mode === 'revoke') {
                $f->db->execute("UPDATE sources SET lifecycle_state='revoked',lifecycle_epoch=lifecycle_epoch+1 WHERE tenant_id=? AND id=?",
                    [$f->scope->tenantId->value, ReplayFixture::id(3)]);

                return;
            }
            $f->db->execute(<<<'SQL'
                INSERT INTO tombstones(tenant_id,id,source_id,reason,event_id,lifecycle_epoch,applies_to)
                VALUES (?,?,?,'source_revoked',?,1,'future_versions')
                SQL, [$f->scope->tenantId->value, ReplayFixture::id(800), ReplayFixture::id(3), ReplayFixture::id(801)]);
        });
        fwrite(STDOUT, "MUTATED\n");
        exit(0);
    }
    if ($mode === 'replay') {
        $f->failResolution = true;
        $outcome = $f->replay();
        report($f, $outcome);
        exit(0);
    }
    if ($mode === 'recover') {
        $recovered = (new InvocationRecovery($f->ledger, $f))->resume($f->request(), $f->scope);
        report($f, $recovered instanceof A\AnswerOutcome ? $recovered : $f->replay());
        exit(0);
    }
    if ($mode === 'race') {
        if ($worker === 'race:b') {
            $f->binding = A\ResolvedModelBinding::fromWire($f->binding->toWire()->with('bindingDigest', str_repeat('b', 64)));
        }
        // Both independent processes have observed no key before either INSERT-CAS.
        $f->onResolve = static function (): void {
            barrier('RESOLVED');
        };
    }
    $record = $mode === 'recover-send'
        ? (new InvocationRecovery($f->ledger, $f))->resume($f->request(), $f->scope)
        : $f->admit();
    if ($mode === 'race') {
        barrier('ADMITTED');
    }
    if ($record->state === A\InvocationState::Preparing) {
        $record = $f->reserve($record);
    }
    if ($mode === 'crash-reserved') {
        barrier('RESERVED ' . $f->transport->sendCount());
    }
    if ($mode === 'crash-sending') {
        $f->ledger->markSending($record->claim, $f->outbound($record)->identity);
        barrier('SENDING ' . $f->transport->sendCount());
    }
    if ($mode === 'revoke-before-send') {
        $f->beforeSendCheck = static function (): void {
            barrier('MARKED');
        };
    }
    $result = $f->dispatch($record);
    if ($result instanceof A\AnswerOutcome) {
        report($f, $result);
        exit(0);
    }
    if ($mode === 'crash-reply') {
        // The spy proves the reply was observed; no durable response has been committed.
        fwrite(STDOUT, 'REPLY ' . $f->transport->sendCount() . "\n");
        fflush(STDOUT);
        barrier('AFTER_REPLY');
    }
    $f->finish($f->ledger->find($record->invocationRef), $result);
    if ($mode === 'lost-ack') {
        barrier('COMPLETED ' . $f->transport->sendCount());
    }
    report($f, $f->replay());
} catch (D\DomainFailure $failure) {
    report($f, error: $failure->errorCode);
}
