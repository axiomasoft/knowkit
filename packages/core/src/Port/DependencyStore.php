<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{Node, Parents, NodeRefs, UsabilityReport, Usage, LifecycleEvent};

interface DependencyStore
{
    /** Same tenant, active parents, no duplicates/self-reference/cycles; store exact parent-set digest. */
    public function register(Node $node, Parents $parents): void;
    /** Authoritative synchronous ancestor state, including tombstones and corrected exact versions. */
    public function checkAncestors(NodeRefs $refs): UsabilityReport;
    public function recordUsage(Usage $usage): void;
    public function invalidate(LifecycleEvent $event): void;
}
