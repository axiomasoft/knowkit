<?php
declare(strict_types=1);
namespace KnowKit\Adapter\LocalBlob;
use KnowKit\Domain\{BlobRef,DomainFailure,Outcome};
use KnowKit\Port\ReadableStream;

/** Private buffers only. A disclosure workflow still owns Usage commit and exact-version checks. */
final class VerifiedStream implements ReadableStream
{
    private bool $closed=false;
    public function __construct(private $handle,private readonly PrivateFilesystem $fs,private readonly BlobRef $ref,
        private readonly int $maxBytes,private readonly \Closure $authorize)
    { $this->fs->verify($this->handle,$ref,$maxBytes); }
    public function read(int $maxBytes): string
    {
        if ($this->closed || $maxBytes<1) { throw new \InvalidArgumentException('Open bounded stream required'); }
        // Verify before exposing any buffer, including after the object changed since open.
        $this->fs->verify($this->handle,$this->ref,$this->maxBytes);
        ($this->authorize)($this->ref);
        $chunk=fread($this->handle,min(65536,$maxBytes));
        if ($chunk===false) { throw new DomainFailure(Outcome::Unavailable,'blob_io_failed'); }
        return $chunk;
    }
    public function eof(): bool { return $this->closed || feof($this->handle); }
    public function close(): void { if (!$this->closed) { fclose($this->handle); $this->closed=true; } }
    public function __destruct() { $this->close(); }
}
