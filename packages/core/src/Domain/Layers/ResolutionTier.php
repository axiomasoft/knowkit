<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

enum ResolutionTier: string
{
    case R0Outline = 'R0';
    case R1Digest = 'R1';
    case R2Propositions = 'R2';
    case R3Verbatim = 'R3';
}
