<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum MessageRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
