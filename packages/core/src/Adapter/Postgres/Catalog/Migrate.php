<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Catalog;

use PDO;

/** Explicit administrative operation; never called from adapter construction or Composer. */
final readonly class Migrate
{
    /** Ordered core set; the baseline path remains the entry point for existing callers. */
    private const MIGRATIONS=['001_metadata','002_metadata_uint32','003_jobs','004_generation_checks','005_outbox_delivery','006_deletion','007_reconciliation'];
    public function __construct(private PDO $pdo,private string $schema) { Connection::schema($schema); }
    public function apply(string $file): void
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Migration owns transaction'); }
        if (basename($file)!=='001_metadata.sql') { throw new \InvalidArgumentException('Baseline migration required'); }
        $migrations=[];
        foreach (self::MIGRATIONS as $id) {
            $sql=file_get_contents(dirname($file).'/'.$id.'.sql');
            if ($sql===false) { throw new \InvalidArgumentException('Complete core migration set required'); }
            $migrations[$id]=['sql'=>$sql,'checksum'=>hash('sha256',$sql)];
        }
        $this->pdo->beginTransaction();
        try {
            $s=$this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');
            $s->execute(['knowkit:migrations:'.$this->schema]);
            $this->pdo->exec('SET LOCAL search_path TO "'.$this->schema.'",pg_catalog');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations(id text PRIMARY KEY,checksum char(64) NOT NULL,applied_at timestamptz NOT NULL)');
            foreach ($migrations as $id=>$migration) {
                $s=$this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE id=?'); $s->execute([$id]);
                $existing=$s->fetchColumn();
                if ($existing!==false && !hash_equals($existing,$migration['checksum'])) { throw new \RuntimeException('Migration checksum mismatch'); }
                if ($existing===false) {
                    $this->pdo->exec($migration['sql']);
                    $s=$this->pdo->prepare('INSERT INTO schema_migrations VALUES (?,?,CURRENT_TIMESTAMP)'); $s->execute([$id,$migration['checksum']]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->rollbackIfActive(); throw $e; }
    }
    private function rollbackIfActive(): void { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }
}
