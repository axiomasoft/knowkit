<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class DeliveryLease
{
    public function __construct(public TenantId $tenantId, public EventId $eventId, public Decimal $fence,
        #[\SensitiveParameter] private string $token, public UtcInstant $expiresAt)
    {
        if (strlen($token) < 32) { throw new \InvalidArgumentException('Delivery token required'); }
    }
    public function tokenHash(): Sha256 { return new Sha256(hash('sha256', $this->token)); }
    public function __debugInfo(): array { return ['tenantId'=>$this->tenantId,'eventId'=>$this->eventId,'fence'=>$this->fence]; }
}
