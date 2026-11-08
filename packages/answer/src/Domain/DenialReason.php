<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum DenialReason: string
{
    case AccessDenied = 'access_denied';
    case ScopeMismatch = 'scope_mismatch';
    case Revoked = 'revoked';
    case HistoryRequired = 'history_required';
    case HistoryExpired = 'history_expired';
}
