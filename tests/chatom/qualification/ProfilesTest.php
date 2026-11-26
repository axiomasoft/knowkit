<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use KnowKit\Adapter\Postgres\Catalog\Migrate;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('chatom:qualification')]
final class ProfilesTest extends TestCase
{
    public static function coreProfiles(): array
    {
        return ['pg16' => [16], 'pg17' => [17], 'pg18' => [18]];
    }

    #[DataProvider('coreProfiles')]
    public function testCoreSchemaIsIndependentOfChatomHostReadiness(int $major): void
    {
        $profile = NativeHost::matrix()['postgres'][(string) $major];
        $port = (int) (getenv('CHATOM_TEST_PG' . $major . '_PORT') ?: $profile['port']);
        $pdo = new PDO(
            dsn: "pgsql:host=127.0.0.1;port={$port};dbname=knowkit_chatom_test",
            username: 'knowkit_test',
            options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        self::assertSame($major, intdiv((int) $pdo->query('SHOW server_version_num')->fetchColumn(), 10000));
        self::assertSame($profile['serverVersion'], $pdo->query('SHOW server_version')->fetchColumn());
        $schema = 'knowkit_qa_' . bin2hex(random_bytes(12));
        $pdo->exec('CREATE SCHEMA ' . $schema);
        try {
            $migration = new Migrate(pdo: $pdo, schema: $schema);
            $migration->apply(dirname(__DIR__, 3) . '/packages/core/migrations/postgres/001_metadata.sql');
            $pdo->exec('SET search_path TO ' . $schema);
            self::assertSame(14, (int) $pdo->query('SELECT count(*) FROM schema_migrations')->fetchColumn());
            self::assertSame(0, (int) $pdo->query('SELECT count(*) FROM sources')->fetchColumn());
            self::assertNull($pdo->query("SELECT to_regclass('chatom_messages')")->fetchColumn());
        } finally {
            $pdo->exec('SET search_path TO public');
            $pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
        }
    }

    public static function driverProfiles(): array
    {
        return ['pg17' => [17], 'pg18' => [18]];
    }

    #[DataProvider('driverProfiles')]
    public function testInstalledSchemaDoctorQualifiesPg17AndPg18(int $major): void
    {
        $host = new NativeHost($major);
        try {
            $result = $host->command(name: 'chatom:schema:doctor', parameters: ['--json' => true]);
            self::assertSame(0, $result['exit'], $result['output']);
            self::assertSame([], json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR));
            self::assertSame($major, intdiv((int) $host->db->selectOne('SHOW server_version_num')->server_version_num, 10000));
        } finally {
            $host->close();
        }
    }

    public function testEdufyPg16RemainsExplicitlyUnqualifiedWithoutSilentUpgrade(): void
    {
        $host = new NativeHost(16);
        try {
            $result = $host->command(name: 'chatom:schema:doctor', parameters: ['--json' => true]);
            self::assertSame(1, $result['exit']);
            $findings = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
            self::assertNotEmpty($findings);
            self::assertNotEmpty(array_filter($findings, static fn (array $finding): bool => str_contains($finding['kind'], 'unverified')));
            self::assertSame(16, intdiv((int) $host->db->selectOne('SHOW server_version_num')->server_version_num, 10000));
            self::assertFalse(NativeHost::matrix()['edufyPg16']['expectedReady']);
            self::assertTrue(NativeHost::matrix()['pendingLibraryFiles']);
            self::assertSame([], $host->handler->invocations);
        } finally {
            $host->close();
        }
    }
}
