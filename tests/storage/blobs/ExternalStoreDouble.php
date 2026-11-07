<?php
declare(strict_types=1);
namespace KnowKit\Tests\Storage\Blobs;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\{BlobStore,ReadableStream};
use KnowKit\Tests\Contract\Ports\PortFixture;

/** Contract-only provider fault double. No production external adapter/SDK is implemented. */
final class ExternalStoreDouble implements BlobStore
{
    public bool $uploaded=false;
    public function __construct(private readonly BlobFixture $f,private readonly string $mode) {}
    public function putImmutable(D\BlobWriteReservation $reservation,ReadableStream $stream): D\BlobRef
    {
        $state=$this->f->ledger->inspectWriter($reservation);
        if ($state['writer']['state']!=='active') { throw new \LogicException('Reservation must precede provider PUT'); }
        BlobFixture::read($stream); $this->uploaded=true;
        throw new D\DomainFailure(D\Outcome::Unavailable,'provider_timeout');
    }
    public function open(D\BlobRef $ref): ReadableStream { return $this->f->store->open($ref); }
    public function exists(D\BlobRef $ref): bool { return $this->f->store->exists($ref); }
    public function listUnreferenced(D\UtcInstant $before,D\Cursor $cursor): D\ResourcePage { return $this->f->store->listUnreferenced($before,$cursor); }
    public function delete(D\ResourceRef $resource,D\AttemptId $attemptId): D\DeletionReceipt
    {
        $this->f->catalog->scope()->assertTenant($resource->tenantId);
        return new D\DeletionReceipt(new JsonObject(['attemptId'=>$attemptId->value,'taskId'=>BlobFixture::id(350),
            'resourceId'=>$resource->id->value,'inFlight'=>false,'result'=>$this->mode,'providerReceipt'=>null,'knownExpiry'=>null,
            'observedAt'=>$this->f->catalog->clock->now()->value]),PortFixture::validator());
    }
}
