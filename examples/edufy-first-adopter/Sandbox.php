<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\Migrate;
use KnowKit\Answer\Adapter\PostgresAnswerMigrations;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKitExample\Host;
use KnowKitExample\Runtime;
use PDO;

/** Explicit disposable local database setup. No Edufy connection, writes or deployment. */
final class Sandbox
{
    /** @param callable(FixtureHost):mixed $work */
    public static function run(callable $work, string $profile = 'schoolA', Audience $audience = Audience::StudentSafe): mixed
    {
        $dsn = getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test';
        if (!preg_match('/^pgsql:.*(?:^|;)dbname=[a-zA-Z0-9_]+_test(?:;|$)/D', $dsn)) {
            throw new \RuntimeException('Disposable *_test database required');
        }
        $pdo = new PDO($dsn, getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'knowkit_adoption_' . bin2hex(random_bytes(12));
        $role = 'knowkit_adoption_' . bin2hex(random_bytes(12));
        $root = sys_get_temp_dir() . '/knowkit-adoption-' . bin2hex(random_bytes(12));
        mkdir($root, 0700);
        $pdo->exec('CREATE SCHEMA ' . $schema);
        try {
            $pdo->exec('SET search_path TO ' . $schema);
            (new Migrate($pdo, $schema))->apply(dirname(__DIR__, 2) . '/packages/core/migrations/postgres/001_metadata.sql');
            (new PostgresAnswerMigrations($pdo, $schema))->apply();
            $pdo->exec('CREATE ROLE ' . $role . ' NOLOGIN NOSUPERUSER');
            try {
                $pdo->exec('GRANT USAGE ON SCHEMA ' . $schema . ' TO ' . $role);
                $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA ' . $schema . ' TO ' . $role);
                $pdo->exec('SET ROLE ' . $role);

                return $work(self::host(new Connection($pdo, $schema), $root, $profile, $audience));
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
            self::remove($root);
        }
    }

    public static function inputs(string $profile, Audience $audience = Audience::StudentSafe): HostScope
    {
        $profileJson = file_get_contents(dirname(__DIR__, 2) . '/tests/fixtures/adoption/edufy/v1/profiles.json');
        if (!is_string($profileJson)) {
            throw new \RuntimeException('Fixture profiles unreadable');
        }
        $profiles = json_decode($profileJson, true, 32, JSON_THROW_ON_ERROR);
        $row = $profiles['profiles'][$profile] ?? throw new \InvalidArgumentException('Unknown fixture profile');

        return new HostScope($profile, $row['tenant'], 'actor:1', null, $row['subject'], $row['learner'],
            $row['class'], $row['lesson'], $row['topic'], $audience, [$row['subject']],
            $row['consent'], 'session:1', $row['dataScopes'], new PolicyRevisions(), $row['purpose']);
    }

    private static function host(Connection $db, string $root, string $profile, Audience $audience): FixtureHost
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
        $inputs = self::inputs($profile, $audience);
        $scope = new D\Scope(new D\TenantId(Host::id($inputs->tenant)), new D\KbId(Host::id('demo-kb')),
            new D\ActorId(Host::id($inputs->actor)), $inputs->purpose, new D\Decimal('0'), $inputs->hash());
        $runtime = new Runtime(new Host($db, new ContractValidator(new SchemaSet($schemas)), $scope), $root);
        $runtime->tx->run(function () use ($runtime, $scope): void {
            $runtime->tx->lockTenant($scope->tenantId, D\LockMode::Update);
            $runtime->catalog->insertTenant($scope->tenantId, new D\Tenant($scope->tenantId, 'ready'));
        });
        FixtureHost::seed($runtime, $audience);

        return new FixtureHost($runtime, $inputs);
    }

    private static function remove(string $root): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
                continue;
            }
            unlink($file->getPathname());
        }
        rmdir($root);
    }
}
