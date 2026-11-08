<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\CitationRef;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;

/** Ordered immutable dependencies. No historical receipt grants current access. */
final readonly class DependencyManifest
{
    /** @param list<CitationRef> $evidence
     * @param list<HistoryDependency> $history
     */
    public function __construct(
        public Sha256 $questionDigest,
        public array $evidence = [],
        public array $history = [],
    ) {
        foreach (['evidence' => CitationRef::class, 'history' => HistoryDependency::class] as $name => $type) {
            Guard::objects($this->{$name}, $type, 1024);
        }
    }

    public function requireScope(Scope $scope): void
    {
        foreach ($this->evidence as $citation) {
            $scope->assertTenant(new \KnowKit\Domain\TenantId($citation->toWire()->get('tenantId')));
        }
        foreach ($this->history as $history) {
            $scope->assertTenant($history->tenantId);
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'questionDigest' => $this->questionDigest->value,
            'evidence' => array_map(static fn (CitationRef $ref) => $ref->toWire(), $this->evidence),
            'history' => array_map(static fn (HistoryDependency $ref) => $ref->toWire(), $this->history),
        ]);
    }
}
