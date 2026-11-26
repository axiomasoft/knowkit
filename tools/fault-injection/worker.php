<?php

declare(strict_types=1);

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain\DomainFailure;
use KnowKit\Tests\Ops\Resilience\Process;
use KnowKit\Tests\Ops\Resilience\Runtime;

require_once dirname(__DIR__, 2) . '/tests/tooling/baseline/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tests/ops/resilience/bootstrap.php';

require_once dirname(__DIR__) . '/LocalIO.php';

// The parent is repository-owned test code. This is not a product deserialization endpoint.
try {
    $frame = \KnowKit\Tooling\LocalIO::line(STDIN);
    if (!str_ends_with($frame, "\n") || strlen($frame) > \KnowKit\Tooling\LocalIO::LINE_LIMIT - 1) {
        throw new \RuntimeException('incomplete or oversized fault worker configuration');
    }
    $config = unserialize(\KnowKit\Tooling\LocalIO::decode(substr($frame, 0, -1)), ['allowed_classes' => true]);
    if (!is_array($config)) {
        throw new \RuntimeException('invalid fault worker configuration');
    }
} catch (Throwable $failure) {
    fwrite(STDERR, "fault worker configuration rejected\n");
    exit(1);
}
Connection::schema($config['schema']);
Connection::schema($config['role']);
$pdo = new PDO(
    getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',
    getenv('TEST_PG_USER') ?: 'knowkit_test',
    getenv('TEST_PG_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec('SET ROLE ' . $config['role']);
$pdo->exec('SET search_path TO ' . $config['schema']);
$db = new Connection(pdo: $pdo, schema: $config['schema']);
$runtime = new Runtime(db: $db, options: $config);
Process::barrier(event: 'READY', data: [
    'pid' => getmypid(),
    'backend' => (int) $db->execute('SELECT pg_backend_pid()')->fetchColumn(),
]);
try {
    $result = $runtime->execute($config['operation']);
    if ($config['postcommit'] ?? false) {
        Process::barrier('COMMITTED');
    }
} catch (DomainFailure $failure) {
    $result = ['status' => 'rejected', 'outcome' => $failure->outcome->value, 'code' => $failure->errorCode];
}
Process::emit(event: 'RESULT', data: ['result' => $result]);
