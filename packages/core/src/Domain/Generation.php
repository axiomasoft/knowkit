<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Generation
{
    public function __construct(public TenantId $tenantId, public KbId $kbId, public GenerationId $generationId,
        public string $state, public ?IndexManifest $manifest)
    {
        if (!in_array($state, ['building','sealed','active','retired','failed'], true)
            || (in_array($state, ['sealed','active','retired'], true) && $manifest === null)) {
            throw new \InvalidArgumentException('Generation state required');
        }
        if ($manifest !== null && ($manifest->toWire()->get('tenantId') !== $tenantId->value
            || $manifest->toWire()->get('kbId') !== $kbId->value || $manifest->toWire()->get('generationId') !== $generationId->value)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }
}
