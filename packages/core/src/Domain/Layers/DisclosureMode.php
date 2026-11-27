<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

enum DisclosureMode: string
{
    case VerbatimAllowed = 'verbatim_allowed';
    case DerivedOnly = 'derived_only';
    case SummaryOnly = 'summary_only';
    case MetadataOnly = 'metadata_only';
    case IndexOnly = 'index_only';

    public function allowsTier(ResolutionTier $tier): bool
    {
        return match ($this) {
            self::VerbatimAllowed => true,
            self::DerivedOnly => $tier !== ResolutionTier::R3Verbatim,
            self::SummaryOnly => $tier === ResolutionTier::R0Outline || $tier === ResolutionTier::R1Digest,
            self::MetadataOnly => $tier === ResolutionTier::R0Outline,
            self::IndexOnly => false,
        };
    }

    public function allowsDirectRead(): bool
    {
        return $this === self::VerbatimAllowed;
    }
}
