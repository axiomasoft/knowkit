<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum IsolationPreset: string
{
    case SingleLocal = 'single_local';
    case AudienceScoped = 'audience_scoped';
    case MultiTenantStrict = 'multi_tenant_strict';
    case HierarchicalWithMounts = 'hierarchical_with_mounts';
}
