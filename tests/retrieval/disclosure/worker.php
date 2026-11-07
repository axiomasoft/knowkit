<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Domain as D;

[$script, $schema, $role, $sourceId, $mode, $parentPid] = $argv;
if (preg_match('/^knowkit_test_[0-9a-f]+$/D', $role) !== 1) {
    throw new RuntimeException('Test role required');
}
$pdo = new PDO(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',
    getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null);
$pdo->exec('SET ROLE "' . $role . '"');
$db = new Connection($pdo, $schema);
$tx = new PdoCatalogTransaction($db);
fwrite(STDOUT, 'READY ' . $db->execute('SELECT pg_backend_pid()')->fetchColumn() . "\n");
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'GO') {
    exit(2);
}
fwrite(STDOUT, "ATTEMPT\n");
fflush(STDOUT);
$tx->run(function () use ($db, $tx, $sourceId, $mode, $parentPid): void {
    $tenant = new D\TenantId('01J' . str_pad('1', 23, '0', STR_PAD_LEFT));
    $tx->lockTenant($tenant, D\LockMode::Update);
    $tx->lockKb(new D\KbId('01J' . str_pad('100', 23, '0', STR_PAD_LEFT)), D\LockMode::Update);
    $tx->lockSources([new D\SourceId($sourceId)], D\LockMode::Update);
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    if ($mode === 'waiter') {
        $waiting = false;
        for ($i = 0; $i < 350; ++$i) {
            if ($db->execute('SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid=? AND NOT granted)', [(int) $parentPid])->fetchColumn()) {
                $waiting = true;
                break;
            }
            usleep(1000);
        }
        if (!$waiting) {
            throw new RuntimeException('Disclosure never waited on revoke');
        }
    }
    if ($mode === 'hold' && trim(fgets(STDIN)) !== 'RELEASE') {
        throw new RuntimeException('Missing release');
    }
    $db->execute('UPDATE ' . $db->table('sources') . " SET lifecycle_state='revoked',lifecycle_epoch=lifecycle_epoch+1 WHERE tenant_id=? AND id=?",
        [$tenant->value, $sourceId]);
});
fwrite(STDOUT, "COMMITTED\n");
fflush(STDOUT);
