<?php

declare(strict_types=1);

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Tests\Worker\E2e\Attempt;
use KnowKit\Tests\Worker\E2e\Catalog;
use KnowKit\Tests\Worker\E2e\Harness;
use KnowKit\Tests\Worker\E2e\Pipeline;
use KnowKit\Tests\Worker\E2e\OracleRunner;
use KnowKit\Tests\Worker\E2e\ExtractionFixtureCache;
use KnowKit\Domain as D;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;

require_once __DIR__ . '/bootstrap.php';

try {
    [$script, $schema, $role, $directory, $root, $barrier, $mode, $extractionCache] = $argv;
    Connection::schema($schema);
    Connection::schema($role);
    $dsn = getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test';
    if (!preg_match('/dbname=[a-zA-Z0-9_]+_test(?:;|$)/', $dsn)) {
        throw new RuntimeException('Disposable database required');
    }
    $pdo = new PDO($dsn, getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null);
    $pdo->exec('SET ROLE ' . $role);
    $pdo->exec('SET search_path TO ' . $schema);
    // Private trusted test IPC, never worker protocol, logs or persisted evidence.
    $input = unserialize(base64_decode(trim(fgets(STDIN)), true));
    $attempt = $input[0];
    if (!$attempt instanceof Attempt) {
        throw new RuntimeException('Expected test attempt');
    }
    $phase = static function (string $name) use ($barrier): void {
        if ($name === $barrier) {
            fwrite(STDOUT, "BARRIER\n");
            fflush(STDOUT);
            fgets(STDIN);
        }
    };
    $p = new Pipeline(new Catalog(new Connection($pdo, $schema)), $attempt->lease->scope, $directory, $root . '/blobs', $phase);
    if ($mode === 'publish') {
        $p->publish($input[1]);
    } else {
        $runner = $mode === 'oracle' ? new OracleRunner() : Harness::supervisor($root . '/stages');
        if ($extractionCache !== '') {
            $runner = new ExtractionFixtureCache($extractionCache, $runner, $attempt->source, $attempt->identity,
                new D\DocumentRevisionId(Jobs::id(701)));
        }
        $p->extract($attempt->lease, $attempt->identity, $attempt->profile, $attempt->source,
            $runner);
    }
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
