<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

use RuntimeException;

/** Metadata only; owner diagnostics and protected bytes never cross this boundary. */
final class ConversationDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('chatom_current_authority_required');
    }
}
