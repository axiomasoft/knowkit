<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server;

use KnowKit\Domain\InstanceId;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\Scope;

final readonly class AuthenticatedMcpContext
{
    /**
     * @param list<string> $audience
     */
    public function __construct(
        public InstanceId $instanceId,
        public Scope $scope,
        public array $audience,
        public DisclosureMode $disclosureMode = DisclosureMode::VerbatimAllowed,
    ) {
    }
}
