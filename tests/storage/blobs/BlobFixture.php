<?php
declare(strict_types=1);
namespace KnowKit\Tests\Storage\Blobs;

use KnowKit\Adapter\LocalBlob\LocalBlobStore;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Application\Resources\ReadAuthority;
use KnowKit\Domain as D;
use KnowKit\Port\ReadableStream;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;

final class Bytes implements ReadableStream
{
    private int $position=0;
    public bool $closed=false;
    public function __construct(private readonly string $bytes,private readonly ?\Closure $onRead=null) {}
    public function read(int $maxBytes): string
    {
        if ($this->closed || $maxBytes<1) { throw new \LogicException('Invalid bounded read'); }
        if ($this->onRead!==null) { ($this->onRead)($maxBytes); }
        $chunk=substr($this->bytes,$this->position,$maxBytes); $this->position+=strlen($chunk); return $chunk;
    }
    public function eof(): bool { return $this->position>=strlen($this->bytes); }
    public function close(): void { $this->closed=true; }
}
final class BlobFixture
{
    public readonly PdoResourceLedger $ledger;
    public readonly LocalBlobStore $store;
    public readonly string $root;
    public function __construct(public readonly CatalogFixture $catalog,?\Closure $sync=null,?string $root=null)
    {
        $this->root=$root??sys_get_temp_dir().'/knowkit-blob-'.bin2hex(random_bytes(12));
        if (!is_dir($this->root)) { mkdir($this->root,0700); }
        $this->ledger=new PdoResourceLedger($catalog->db,$catalog->tx,$catalog->scope()->tenantId,'local-v1',$catalog->clock);
        $this->store=$this->makeStore($sync);
    }
    public function makeStore(?\Closure $sync=null,int $limit=1048576,?\Closure $delete=null): LocalBlobStore
    {
        $l=$this->ledger; $callbacks=[];
        foreach (['inspectWriter','finalizeIdentity','closeWriter','markUncertain','requireAccepted','listUnreferenced','quarantine','recoveryRef','reconcileWriter'] as $name) { $callbacks[$name]=$l->$name(...); }
        $authority=new ReadAuthority($this->catalog->scope(),$this->catalog->owner,$this->catalog->clock,$l->readBinding(...));
        return new LocalBlobStore($this->catalog->scope()->tenantId,$this->root,'local-v1',$limit,$callbacks,$authority->requireAllow(...),
            $delete??$l->deletionBinding(...),$this->catalog->ids,$this->catalog->clock,PortFixture::validator(),$sync);
    }
    public static function run(callable $test,?\Closure $sync=null): mixed
    {
        return CatalogFixture::run(function($c) use($test,$sync) {
            $c->tenant(); $c->kb(); $c->source();
            $c->db->execute('INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,\'{}\')',
                [self::id(1),self::id(400),self::id(2),self::id(100)]);
            $f=new self($c,$sync);
            try { return $test($f); } finally { self::removeTree($f->root); }
        });
    }
    public static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) { if ($name!=='.' && $name!=='..') { self::removeTree($path.'/'.$name); } }
            rmdir($path);
        } else { unlink($path); }
    }
    public static function id(int $id): string { return CatalogFixture::id($id); }
    public function reservation(int $resource=300,int $writer=301,bool $known=false): D\BlobWriteReservation
    {
        return new D\BlobWriteReservation(new D\ResourceRef($this->catalog->scope()->tenantId,'resource',new D\ResourceId(self::id($resource))),
            new D\SourceId(self::id(2)),new D\WriterId(self::id($writer)),new D\Decimal('0'),new D\Decimal('0'),
            $known?new D\Sha256(hash('sha256','abc')):null,$known?new D\Uint64('3'):null,new D\Ulid(self::id($resource+1000)));
    }
    public function put(int $resource=300,int $writer=301,string $bytes='abc'): D\BlobRef
    { return $this->store->putImmutable($this->ledger->reserveWriter($this->reservation($resource,$writer)),new Bytes($bytes)); }
    public function path(D\BlobRef $ref): string
    { return $this->root.'/'.$ref->tenantId->value.'/'.$ref->sourceId->value.'/sha256/'.$ref->digest->value.'/'.$ref->objectVersion; }
    public function row(int $resource=300): array
    { return $this->catalog->db->row('SELECT * FROM resource_ledger WHERE tenant_id=? AND id=?',[self::id(1),self::id($resource)]); }
    public static function read(ReadableStream $stream): string
    {
        $bytes=''; try { while (!$stream->eof()) { $bytes.=$stream->read(2); } return $bytes; } finally { $stream->close(); }
    }
}
