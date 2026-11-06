<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Catalog;

use PDO;
use Throwable;
use InvalidArgumentException;

// Explicit CLI, environment credentials only. Host must create the target schema first.
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'KnowKit\\')) {
        $file=dirname(__DIR__,3).'/'.str_replace('\\','/',substr($class,8)).'.php';
        if (is_file($file)) { require_once $file; }
    }
});
try {
    if ($argc!==2 || !getenv('KNOWKIT_PG_DSN')) { throw new \InvalidArgumentException(); }
    $pdo=new PDO(getenv('KNOWKIT_PG_DSN'),getenv('KNOWKIT_PG_USER')?:null,getenv('KNOWKIT_PG_PASSWORD')?:null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    (new Migrate($pdo,$argv[1]))->apply(dirname(__DIR__,4).'/migrations/postgres/001_metadata.sql');
    fwrite(STDOUT,"001_metadata applied\n");
} catch (Throwable) { fwrite(STDERR,"Migration failed; verify schema, connection and checksum\n"); exit(1); }
