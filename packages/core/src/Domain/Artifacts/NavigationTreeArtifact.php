<?php
declare(strict_types=1);

namespace KnowKit\Domain\Artifacts;

use KnowKit\Domain\{DocumentRevisionRef, Sha256, ValueGuard};
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Domain\Layers\ResolutionTier;

final readonly class NavigationTreeArtifact
{
    public ResolutionTier $tier;

    /**
     * @param list<NavigationTreeNode> $nodes
     */
    public function __construct(
        public DocumentRevisionRef $documentRevisionRef,
        public array $nodes,
        public int $sourceByteLength,
    ) {
        $this->tier = ResolutionTier::R0Outline;
        ValueGuard::objects($nodes, NavigationTreeNode::class, 4096);
        if ($sourceByteLength < 0) {
            throw new \InvalidArgumentException('sourceByteLength must be non-negative');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'schemaVersion' => 'navigation-tree:1',
            'tier' => $this->tier->value,
            'documentRevisionRef' => $this->documentRevisionRef->toWire(),
            'sourceByteLength' => $this->sourceByteLength,
            'nodes' => array_map(
                static fn(NavigationTreeNode $node): JsonObject => $node->toWire(),
                $this->nodes,
            ),
        ]);
    }

    public function toCanonicalJson(): string
    {
        return Jcs::canonicalize($this->toWire());
    }

    public function byteLength(): int
    {
        return strlen($this->toCanonicalJson());
    }

    public function digest(): Sha256
    {
        return new Sha256(hash('sha256', $this->toCanonicalJson()));
    }
}
