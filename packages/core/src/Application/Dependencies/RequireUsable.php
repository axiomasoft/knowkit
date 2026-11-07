<?php
declare(strict_types=1);
namespace KnowKit\Application\Dependencies;

use KnowKit\Domain\{Decision, DomainFailure, NodeRefs, Outcome};
use KnowKit\Port\DependencyStore;

/** Synchronous lifecycle gate, used under the same locks as the final disclosure. Host authorization is also required. */
final readonly class RequireUsable
{
    public function __construct(private DependencyStore $store) {}
    public function check(NodeRefs $refs): void
    {
        $report=$this->store->checkAncestors($refs);
        if ($report->decision===Decision::Deny) { throw new DomainFailure(Outcome::Denied,'ancestor_unusable'); }
        if ($report->decision===Decision::Unknown) { throw new DomainFailure(Outcome::Unknown,'ancestor_unknown'); }
    }
}
