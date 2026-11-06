<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class SourcePage
{
    /** @param list<Source> $sources stable unique sourceId ascending; filtered by repository before LIMIT */
    public function __construct(public PageCursor $page, public array $sources, public ?PageCursor $next)
    {
        ValueGuard::objects($sources, Source::class, $page->limit); $previous = null;
        foreach ($sources as $source) {
            if (!$page->tenantId->equals($source->tenantId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
            if ($previous !== null && strcmp($previous, $source->sourceId->value) >= 0) {
                throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
            }
            $previous = $source->sourceId->value;
        }
        if ($next !== null) {
            $next->assertBinding($page->tenantId, $page->kbId, $page->filter, $page->securityScopeDigest);
            if ($next->token === null || $next->limit !== $page->limit || $sources === [] || $next->token === $page->token) {
                throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
            }
        }
    }
}
