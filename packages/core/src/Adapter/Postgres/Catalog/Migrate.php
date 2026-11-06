<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Catalog;

use PDO;

/** Explicit administrative operation; never called from adapter construction or Composer. */
final readonly class Migrate
{
    public function __construct(private PDO $pdo,private string $schema) { Connection::schema($schema); }
    public function apply(string $file): void
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Migration owns transaction'); }
        $sql=file_get_contents($file);
        if ($sql===false || basename($file)!=='001_metadata.sql') { throw new \InvalidArgumentException('Baseline migration required'); }
        $checksum=hash('sha256',$sql); $this->pdo->beginTransaction();
        try {
            $s=$this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');
            $s->execute(['knowkit:migrations:'.$this->schema]);
            $this->pdo->exec('SET LOCAL search_path TO "'.$this->schema.'",pg_catalog');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations(id text PRIMARY KEY,checksum char(64) NOT NULL,applied_at timestamptz NOT NULL)');
            $s=$this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE id=?'); $s->execute(['001_metadata']);
            $existing=$s->fetchColumn();
            if ($existing!==false && !hash_equals($existing,$checksum)) { throw new \RuntimeException('Migration checksum mismatch'); }
            if ($existing===false) {
                $this->pdo->exec($sql);
                $s=$this->pdo->prepare('INSERT INTO schema_migrations VALUES (?,?,CURRENT_TIMESTAMP)'); $s->execute(['001_metadata',$checksum]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->rollbackIfActive(); throw $e; }
    }
    private function rollbackIfActive(): void { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }
}
