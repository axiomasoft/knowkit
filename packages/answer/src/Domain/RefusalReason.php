<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum RefusalReason: string
{
    case InvalidCitation = 'invalid_citation';
    case ProviderRefused = 'provider_refused';
    case InvalidOutput = 'invalid_output';
}
