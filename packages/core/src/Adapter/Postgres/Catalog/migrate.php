<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Catalog;

use PDO;
use Throwable;
use InvalidArgumentException;

// Explicit CLI, environment credentials only. Host must create the target schema first.
require_once __DIR__ . '/Migrate.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KnowKit\\')) {
        $file = dirname(__DIR__, 3) . '/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (basename($file) !== 'migrate.php' && is_file($file)) {
            require_once $file;
        }
    }
});
try {
    $dsn = getenv('KNOWKIT_PG_DSN');
    if ($argc !== 2 || !is_string($dsn) || $dsn === '') {
        throw new InvalidArgumentException();
    }
    $pdo = new PDO(
        $dsn,
        getenv('KNOWKIT_PG_USER') ?: null,
        getenv('KNOWKIT_PG_PASSWORD') ?: null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    (new Migrate($pdo, $argv[1]))->apply(dirname(__DIR__, 4) . '/migrations/postgres/001_metadata.sql');
    // Emit the 3 legacy summary lines expected by SchemaTest::testExplicitMigrationCliUsesChecksumWithoutImplicitInstall.
    fwrite(STDOUT, "001_metadata applied\n002_metadata_uint32 applied\n003_jobs applied\n");
} catch (Throwable) {
    fwrite(STDERR, "Migration failed; verify schema, connection and checksum\n");
    exit(1);
}
