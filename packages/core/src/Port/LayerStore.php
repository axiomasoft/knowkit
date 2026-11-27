<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Layers\{DirectiveArtifact, LayerNode, ResolvedLayerStack};
use KnowKit\Domain\Ulid;

interface LayerStore
{
    public function saveDirective(DirectiveArtifact $artifact): void;
    public function findDirective(Ulid $tenantId, Ulid $directiveId): ?DirectiveArtifact;
    public function saveLayerNode(Ulid $tenantId, LayerNode $node): void;
    public function saveResolvedStack(ResolvedLayerStack $stack): void;
    public function findResolvedStack(Ulid $tenantId, Ulid $stackId): ?ResolvedLayerStack;
}
