<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\Migrate;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;

/** Explicit disposable demo setup. It never installs into an existing application schema. */
final class Sandbox
{
    public static function run(callable $work): mixed
    {
        $dsn = getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test';
        if (preg_match('/^pgsql:.*(?:^|;)dbname=[a-zA-Z0-9_]+_test(?:;|$)/D', $dsn) !== 1) {
            throw new \RuntimeException('Disposable *_test database required');
        }
        $pdo = new \PDO($dsn, getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schema = 'knowkit_demo_' . bin2hex(random_bytes(12));
        $role = 'knowkit_demo_' . bin2hex(random_bytes(12));
        $directory = sys_get_temp_dir() . '/knowkit-demo-' . bin2hex(random_bytes(16));
        mkdir($directory, 0700);
        $pdo->exec('CREATE SCHEMA ' . $schema);
        try {
            $pdo->exec('SET search_path TO ' . $schema);
            (new Migrate($pdo, $schema))->apply(dirname(__DIR__, 2) . '/packages/core/migrations/postgres/001_metadata.sql');
            $pdo->exec('CREATE ROLE ' . $role . ' NOLOGIN NOSUPERUSER');
            try {
                $pdo->exec('GRANT USAGE ON SCHEMA ' . $schema . ' TO ' . $role);
                $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA ' . $schema . ' TO ' . $role);
                $pdo->exec('SET ROLE ' . $role);
                $runtime = self::runtime(new Connection($pdo, $schema), $directory);

                return $work($runtime);
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->exec('RESET ROLE');
                $pdo->exec('DROP OWNED BY ' . $role);
                $pdo->exec('DROP ROLE ' . $role);
            }
        } finally {
            $pdo->exec('SET search_path TO public');
            $pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
            self::removeTree($directory);
        }
    }

    private static function runtime(Connection $db, string $directory): Runtime
    {
        $schemas = [];
        foreach (glob(dirname(__DIR__, 2) . '/schemas/*.schema.json') ?: [] as $path) {
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new \RuntimeException('Schema unreadable');
            }
            $wire = StrictJson::decode($bytes, 4194304);
            $schemas[$wire->get('$id')] = $wire;
        }
        $validator = new ContractValidator(new SchemaSet($schemas));
        $scope = new D\Scope(new D\TenantId(Host::id('demo-tenant')), new D\KbId(Host::id('demo-kb')),
            new D\ActorId(Host::id('demo-actor')), 'local.read', new D\Decimal('0'),
            new D\Sha256(hash('sha256', 'local-host-scope-v1')));
        $runtime = new Runtime(new Host($db, $validator, $scope), $directory);
        $runtime->tx->run(function () use ($runtime, $scope): void {
            $runtime->tx->lockTenant($scope->tenantId, D\LockMode::Update);
            $runtime->catalog->insertTenant($scope->tenantId, new D\Tenant($scope->tenantId, 'ready'));
        });

        return $runtime;
    }

    private static function removeTree(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
