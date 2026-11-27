<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Domain;

use KnowKit\Domain\Layers\TrustTier;
use KnowKit\Domain\Sha256;

final readonly class CompiledBlock
{
    private const ROLES = ['system', 'user', 'assistant'];

    public function __construct(
        public string $role,
        public TrustTier $trustTier,
        public string $content,
        public ?string $cacheControlHint,
        public int $tokenEstimate,
        public Sha256 $contentDigest,
    ) {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Invalid CompiledBlock role: ' . $role);
        }
        if ($tokenEstimate < 0) {
            throw new \InvalidArgumentException('tokenEstimate must be >= 0');
        }
    }
}
