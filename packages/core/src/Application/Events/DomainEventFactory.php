<?php
declare(strict_types=1);

namespace KnowKit\Application\Events;

use KnowKit\Domain\{Decimal,DomainEvent,TenantId,Ulid};
use KnowKit\Domain\Contract\{ContractValidator,HashDomain,JsonObject};
use KnowKit\Port\{Clock,IdGenerator};

final readonly class DomainEventFactory
{
    public function __construct(private Clock $clock,private IdGenerator $ids,private ContractValidator $validator) {}
    public function create(TenantId $tenant,string $aggregateType,Ulid $aggregateId,Decimal $aggregateVersion,
        string $type,JsonObject $payload,?Ulid $causationId=null,?Ulid $correlationId=null): DomainEvent
    {
        return new DomainEvent(new JsonObject(['eventId'=>$this->ids->next()->value,'schemaVersion'=>'event-1.0',
            'tenantId'=>$tenant->value,'aggregateType'=>$aggregateType,'aggregateId'=>$aggregateId->value,
            'aggregateVersion'=>$aggregateVersion->value,'type'=>$type,'occurredAt'=>$this->clock->now()->value,
            'causationId'=>$causationId?->value,'correlationId'=>$correlationId?->value,'payload'=>$payload,
            'payloadDigest'=>HashDomain::DomainEventPayload->digest($payload)]),$this->validator);
    }
}
