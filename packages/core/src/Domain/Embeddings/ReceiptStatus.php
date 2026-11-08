<?php

declare(strict_types=1);

namespace KnowKit\Domain\Embeddings;

enum ReceiptStatus: string
{
    case Accepted = 'accepted';
    case Unknown = 'unknown';
    case Failed = 'failed';
}
