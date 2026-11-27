<?php
declare(strict_types=1);

namespace KnowKit\Application\Runtime;

use KnowKit\Domain\{InstanceId, IsolationPreset};
use KnowKit\Registry\{HostProfile, ReadinessReport};

final readonly class KnowledgeInstance
{
    public function __construct(
        public InstanceId $id,
        public HostProfile $profile,
        public IsolationPreset $preset,
        public KnowledgeFacade $facade,
        public ReadinessReport $readiness,
    ) {
    }
}
