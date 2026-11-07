<?php
declare(strict_types=1);
namespace KnowKit\Application\Events;

use KnowKit\Domain\{DomainEvent,DomainFailure,Outcome,Sha256,TenantId};
use KnowKit\Domain\Contract\{ContractValidator,ContractViolation};
use KnowKit\Port\{EventAuthority,EventInbox};

/** Invalidation consumer: an event can invalidate, never authorize or reactivate. */
final readonly class Consumer
{
    public function __construct(private TenantId $tenant, private ContractValidator $validator,
        private EventInbox $inbox, private EventAuthority $authority) {}
    /** @phpstan-impure */
    public function consume(#[\SensitiveParameter] string $bytes): string
    {
        try { $event = new DomainEvent($this->validator->decodeValid($bytes,'urn:knowkit:schema:domain-event:1.0'),$this->validator); }
        catch (ContractViolation) { $this->inbox->quarantine(new Sha256(hash('sha256',$bytes))); return 'quarantined'; }
        if ($event->toWire()->get('tenantId') !== $this->tenant->value) { throw new DomainFailure(Outcome::Denied,'scope_mismatch'); }
        $result = $this->inbox->apply($event);
        if ($result === 'gap') {
            try { $version = $this->authority->version($event); }
            catch (DomainFailure $error) {
                if ($error->outcome !== Outcome::Unavailable && $error->outcome !== Outcome::Unknown) { throw $error; }
                return 'gap';
            }
            if ($version !== null) { $this->inbox->reconcile($event,$version); }
        }
        return $result;
    }
}
