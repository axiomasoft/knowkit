<?php

declare(strict_types=1);

namespace KnowKit\Domain\Observability;

/** Closed vocabulary: even syntactically valid upstream error codes can contain secrets. */
enum Reason: string
{
    case None = 'none';
    case ProviderUncertain = 'provider_uncertain';
    case StaleLease = 'stale_lease';
    case AccessDenied = 'access_denied';
    case DependencyUnavailable = 'dependency_unavailable';
    case NotVerified = 'not_verified';
    case Incompatible = 'incompatible';
    case PurgeUnknown = 'purge_unknown';
    case QuotaExceeded = 'quota_exceeded';
    case InternalFailure = 'internal_failure';

    public static function forError(?string $code): self
    {
        return match ($code) {
            null => self::None,
            'embedding_request_unknown', 'provider_timeout', 'answer_dispatch_unknown' => self::ProviderUncertain,
            'stale_lease', 'stale_write' => self::StaleLease,
            'scope_mismatch', 'access_denied', 'POLICY_CHANGED', 'source_revoked', 'unauthenticated' => self::AccessDenied,
            'quota_exceeded', 'quota_exhausted', 'rate_limited', 'budget_exceeded' => self::QuotaExceeded,
            default => self::InternalFailure,
        };
    }
}
