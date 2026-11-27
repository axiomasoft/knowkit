<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

enum TrustTier: string
{
    case TrustedDirective = 'trusted_directive';
    case CuratedReference = 'curated_reference';
    case UntrustedEvidence = 'untrusted_evidence';
    case DynamicOverlay = 'dynamic_overlay';

    public function allowsDeliveryMode(DeliveryMode $mode): bool
    {
        return match ($this) {
            self::TrustedDirective => $mode === DeliveryMode::PinnedPrefix
                || $mode === DeliveryMode::SessionPinned,
            self::CuratedReference => $mode === DeliveryMode::PinnedPrefix
                || $mode === DeliveryMode::SessionPinned
                || $mode === DeliveryMode::HybridRetrievable,
            self::UntrustedEvidence => $mode === DeliveryMode::HybridRetrievable
                || $mode === DeliveryMode::ToolDiscoverable
                || $mode === DeliveryMode::VolatileSuffix,
            self::DynamicOverlay => $mode === DeliveryMode::VolatileSuffix,
        };
    }
}
