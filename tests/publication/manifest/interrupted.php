<?php
declare(strict_types=1);
// Crash actor: accepted canonical objects and staging inventory already exist.
// Commit only one lexical projection row, then stop before any seal/publication.
require dirname(__DIR__,3).'/vendor/autoload.php';
require_once __DIR__.'/Fixture.php';
use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogTransaction};
use KnowKit\Adapter\Lexical\{CanonicalChunkSets,PdoLexicalIndex};
use KnowKit\Domain as D;
use KnowKit\Tests\Publication\Manifest\Fixture;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\TestIds;
Connection::schema($argv[1]); Connection::schema($argv[2]);
$pdo = new PDO(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',getenv('TEST_PG_USER') ?: 'knowkit_test',getenv('TEST_PG_PASSWORD') ?: null);
$pdo->exec('SET ROLE '.$argv[2]); $pdo->exec('SET search_path TO '.$argv[1]);
$db = new Connection($pdo,$argv[1]); $tx = new PdoCatalogTransaction($db);
$index = new PdoLexicalIndex($db,$tx,PortFixture::validator(),new CanonicalChunkSets([Fixture::set()]),new TestIds(70000000));
$tx->run(function() use($tx,$index,$db): void {
    $tx->lockTenant(new D\TenantId(Fixture::id(1)),D\LockMode::Update); $index->index(new D\GenerationId(Fixture::id(501)),Fixture::set()->chunkSet);
    $db->execute('DELETE FROM lexical_documents WHERE generation_id=? AND chunk_id=?',[Fixture::id(501),Fixture::id(6002)]);
    $db->execute('DELETE FROM index_memberships WHERE generation_id=? AND chunk_id=?',[Fixture::id(501),Fixture::id(6002)]);
    $db->execute('UPDATE index_generations SET chunk_count=1 WHERE tenant_id=? AND id=?',[Fixture::id(1),Fixture::id(501)]);
});
echo "PARTIAL\n"; flush(); fgets(STDIN);
