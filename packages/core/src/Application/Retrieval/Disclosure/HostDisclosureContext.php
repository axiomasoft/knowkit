<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Disclosure;

use KnowKit\Application\Access\HostIdentity;
use KnowKit\Domain\DisclosureBinding;
use KnowKit\Domain\Scope;
use KnowKit\Domain\SelectionId;

/** Authenticated host owns the original query, exact snapshot and opaque token. */
interface HostDisclosureContext extends HostIdentity
{
    public function selection(Scope $scope, SelectionId $selection): DisclosureBinding;
}
