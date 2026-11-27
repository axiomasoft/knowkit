<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

enum DeliveryMode: string
{
    case PinnedPrefix = 'pinned_prefix';
    case SessionPinned = 'session_pinned';
    case HybridRetrievable = 'hybrid_retrievable';
    case ToolDiscoverable = 'tool_discoverable';
    case VolatileSuffix = 'volatile_suffix';
}
