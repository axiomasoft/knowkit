<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Artifacts\{DerivedArtifactRef, ResolutionManifest};
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Ulid;

interface ResolutionStore
{
    public function saveManifest(ResolutionManifest $manifest): void;

    public function findArtifact(
        Ulid $tenantId,
        Ulid $documentRevisionId,
        ResolutionTier $tier,
    ): ?DerivedArtifactRef;
}
