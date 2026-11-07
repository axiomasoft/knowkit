<?php
declare(strict_types=1);
namespace KnowKit\Adapter\LocalBlob;

use KnowKit\Domain\{BlobRef,DomainFailure,Outcome};

/** Host-provisioned private root; no request supplies a filesystem path.
 * The root and its descendants must be exclusively writable by the service account.
 */
final readonly class PrivateFilesystem
{
    public string $root;
    private \Closure $sync;
    public function __construct(string $root,?\Closure $sync=null)
    {
        $root=rtrim($root,'/');
        if ($root==='' || $root[0]!=='/' || str_contains($root,"\0")) { throw new \InvalidArgumentException('Absolute private root required'); }
        $path='';
        foreach (explode('/',substr($root,1)) as $part) {
            if ($part==='' || $part==='.' || $part==='..') { throw new \InvalidArgumentException('Canonical root required'); }
            $path.='/'.$part; clearstatcache(true,$path); $stat=@lstat($path);
            if ($stat===false || ($stat['mode']&0170000)!==0040000) { throw new DomainFailure(Outcome::Denied,'unsafe_blob_path'); }
        }
        $this->root=$root; $this->sync=$sync??static fn($handle,string $kind): bool=>@fsync($handle);
        $this->directory('');
    }
    private function parts(string $relative): array
    {
        if ($relative==='') { return []; }
        $parts=explode('/',$relative);
        foreach ($parts as $part) {
            if ($part==='' || $part==='.' || $part==='..' || preg_match('/\A[A-Za-z0-9_.-]+\z/D',$part)!==1) {
                throw new DomainFailure(Outcome::Denied,'unsafe_blob_path');
            }
        }
        return $parts;
    }
    public function path(string $relative): string { $this->parts($relative); return $this->root.($relative===''?'':'/'.$relative); }
    public function directory(string $relative,bool $create=false): string
    {
        $path=$this->root; $owner=@fileowner($this->root);
        foreach (array_merge([''],$this->parts($relative)) as $part) {
            if ($part!=='') {
                $parent=$path; $path.='/'.$part; clearstatcache(true,$path);
                if (@lstat($path)===false && $create) {
                    if (!@mkdir($path,0700) && !is_dir($path)) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
                    $this->syncDirectory($parent);
                }
            }
            clearstatcache(true,$path); $s=@lstat($path);
            if ($s===false && !$create) { throw new DomainFailure(Outcome::Incompatible,'blob_missing'); }
            if ($s===false || ($s['mode']&0170000)!==0040000 || ($s['mode']&0077)!==0 || $s['uid']!==$owner) {
                throw new DomainFailure(Outcome::Denied,'unsafe_blob_path');
            }
        }
        return $path;
    }
    public function syncFile($handle): void
    {
        if (!@fflush($handle) || ($this->sync)($handle,'file')!==true) { throw new DomainFailure(Outcome::Unavailable,'blob_durability_failed'); }
    }
    public function syncDirectory(string $path): void
    {
        $h=@fopen($path,'rb');
        if ($h===false) { throw new DomainFailure(Outcome::Unavailable,'blob_durability_failed'); }
        try { if (($this->sync)($h,'directory')!==true) { throw new DomainFailure(Outcome::Unavailable,'blob_durability_failed'); } }
        finally { fclose($h); }
    }
    private function parent(string $relative,bool $create): string
    { $this->parts($relative); $dir=dirname($relative); return $this->directory($dir==='.'?'':$dir,$create); }
    public function open(string $relative,bool $create=false)
    {
        $this->parent($relative,$create); $path=$this->path($relative); clearstatcache(true,$path); $s=@lstat($path);
        if ($create && $s!==false) { throw new DomainFailure(Outcome::Unknown,'staging_exists'); }
        if (!$create && $s===false) { throw new DomainFailure(Outcome::Incompatible,'blob_missing'); }
        if ($s!==false) { $this->safeFile($s); }
        $mask=umask(0077);
        try { $h=@fopen($path,$create?'x+b':'rb'); } finally { umask($mask); }
        if ($h===false) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
        try {
            if ($create && !@chmod($path,0600)) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
            clearstatcache(true,$path); $after=@lstat($path); $opened=fstat($h);
            if ($after===false || $opened===false || $after['ino']!==$opened['ino'] || $after['dev']!==$opened['dev']) {
                throw new DomainFailure(Outcome::Denied,'unsafe_blob_path');
            }
            $this->safeFile($after);
            return $h;
        } catch (\Throwable $e) { fclose($h); throw $e; }
    }
    private function safeFile(array $s): void
    {
        if (($s['mode']&0170000)!==0100000 || $s['nlink']!==1 || ($s['mode']&0077)!==0 || $s['uid']!==fileowner($this->root)) {
            throw new DomainFailure(Outcome::Denied,'unsafe_blob_path');
        }
    }
    public function lock(string $relative)
    {
        $this->parent($relative,true); $path=$this->path($relative); clearstatcache(true,$path); $s=@lstat($path);
        try { $h=$s===false?$this->open($relative,true):$this->open($relative); }
        catch (DomainFailure $e) {
            clearstatcache(true,$path);
            if (@lstat($path)===false) { throw $e; }
            $h=$this->open($relative);
        }
        $deadline=hrtime(true)+5_000_000_000;
        while (!@flock($h,LOCK_EX|LOCK_NB)) {
            if (hrtime(true)>=$deadline) { fclose($h); throw new DomainFailure(Outcome::Unavailable,'blob_lock_timeout'); }
            usleep(10000);
        }
        return $h;
    }
    public function present(string $relative): bool
    {
        $this->parent($relative,true); clearstatcache(true,$this->path($relative)); $s=@lstat($this->path($relative));
        if ($s===false) { return false; } $this->safeFile($s); return true;
    }
    /** link is atomic no-clobber; unlike rename it cannot replace an immutable target. */
    public function promote(string $staging,string $canonical): void
    {
        $this->parent($canonical,true); $this->parent($staging,false);
        if (!@link($this->path($staging),$this->path($canonical))) { throw new DomainFailure(Outcome::Unavailable,'blob_promotion_failed'); }
        $this->syncDirectory(dirname($this->path($canonical)));
        if (!@unlink($this->path($staging))) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
        $this->syncDirectory(dirname($this->path($staging)));
        if (!@chmod($this->path($canonical),0400)) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
        $h=$this->open($canonical);
        try { $this->syncFile($h); } finally { fclose($h); }
    }
    public function remove(string $relative): bool
    {
        if (!$this->present($relative)) { $this->syncDirectory(dirname($this->path($relative))); return false; }
        if (!@unlink($this->path($relative))) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
        $this->syncDirectory(dirname($this->path($relative))); return true;
    }
    /** Only after the host has proved the old runner stopped. A two-link promotion is recoverable
     * only when both exact private names identify the same inode; arbitrary hardlinks remain forbidden.
     */
    public function recoverPromotion(string $staging,string $canonical,BlobRef $ref,int $maxBytes): void
    {
        $this->parent($staging,true); $this->parent($canonical,true);
        $stagePath=$this->path($staging); $targetPath=$this->path($canonical);
        clearstatcache(); $a=@lstat($stagePath); $b=@lstat($targetPath);
        if ($a!==false && $b!==false && $a['dev']===$b['dev'] && $a['ino']===$b['ino'] && $a['nlink']===2 && $b['nlink']===2) {
            $single=$a; $single['nlink']=1; $this->safeFile($single);
            if (!@unlink($stagePath)) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
            $this->syncDirectory(dirname($stagePath)); $this->syncDirectory(dirname($targetPath));
        } elseif ($b===false) {
            $h=$this->open($staging);
            try { $this->verify($h,$ref,$maxBytes); $this->syncFile($h); } finally { fclose($h); }
            $this->promote($staging,$canonical);
        } elseif ($a!==false) {
            $h=$this->open($canonical);
            try { $this->verify($h,$ref,$maxBytes); } finally { fclose($h); }
            $h=$this->open($staging);
            try { $this->verify($h,$ref,$maxBytes); } finally { fclose($h); }
            $this->remove($staging);
        }
        if (!@chmod($targetPath,0400)) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
        $h=$this->open($canonical);
        try { $this->verify($h,$ref,$maxBytes); $this->syncFile($h); } finally { fclose($h); }
        $this->syncDirectory(dirname($targetPath));
    }
    public function verify($h,BlobRef $ref,int $maxBytes): void
    {
        $s=fstat($h);
        if ($s===false) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
        $this->safeFile($s);
        if ($s['size']>$maxBytes || (string)$s['size']!==$ref->byteLength->value) { throw new DomainFailure(Outcome::Incompatible,'blob_length_mismatch'); }
        $offset=ftell($h); if ($offset===false || fseek($h,0)!==0) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
        $hash=hash_init('sha256'); $length=0;
        while (!feof($h)) {
            $chunk=fread($h,min(65536,$maxBytes-$length+1));
            if ($chunk===false || ($chunk==='' && !feof($h))) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
            $length+=strlen($chunk); if ($length>$maxBytes) { throw new DomainFailure(Outcome::Incompatible,'blob_size_limit'); }
            hash_update($hash,$chunk);
        }
        if ((string)$length!==$ref->byteLength->value || !hash_equals($ref->digest->value,hash_final($hash))) { throw new DomainFailure(Outcome::Incompatible,'blob_digest_mismatch'); }
        if (fseek($h,$offset)!==0) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
    }
    /** Bounded inventory, rejecting links instead of following them. */
    public function inventory(string $tenant): array
    {
        $this->directory($tenant,true); $found=[]; $seen=0;
        $walk=function(string $relative,int $depth) use(&$walk,&$found,&$seen): void {
            if ($depth>5) { throw new DomainFailure(Outcome::Unknown,'inventory_limit'); }
            $dir=$this->directory($relative); $names=@scandir($dir);
            if ($names===false) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
            foreach ($names as $name) {
                if ($name==='.' || $name==='..' || $name==='.locks') { continue; }
                if (++$seen>10000) { throw new DomainFailure(Outcome::Unknown,'inventory_limit'); }
                $key=$relative.'/'.$name; $this->parts($key); $s=@lstat($this->path($key));
                if ($s===false) { continue; }
                if (($s['mode']&0170000)===0040000) { $walk($key,$depth+1); }
                else { $this->safeFile($s); $found[]=$key; }
            }
        };
        $walk($tenant,0); return $found;
    }
}
