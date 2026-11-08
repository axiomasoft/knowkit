<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

use AgenToolsPlatform\Core\Security\ExecutionContext;

interface EvidenceContextContributor
{
    public function pin(): ContributionPin;

    public function collect(
        ExecutionContext $context,
        IncomingInput $input,
        EvidenceContextRequest $request,
    ): EvidenceContribution;
}
