<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Client;

use KnowKit\Domain\Layers\DeliveryMode;
use KnowKit\Domain\Layers\TrustTier;

final readonly class UntrustedToolResult
{
    public TrustTier $trustTier;

    public function __construct(
        public mixed $content,
        public string $contentDigest,
        public string $serverId,
        public string $toolName,
        TrustTier $trustTier = TrustTier::UntrustedEvidence,
    ) {
        if ($trustTier !== TrustTier::UntrustedEvidence) {
            throw new \InvalidArgumentException('External MCP tool results must always have TrustTier::UntrustedEvidence');
        }
        $this->trustTier = $trustTier;
    }

    public function deliveryMode(): DeliveryMode
    {
        return DeliveryMode::VolatileSuffix;
    }
}
