<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class UsabilityReport
{
    public function __construct(public Decision $decision, public NodeRefs $blockedAncestors) {}
}
