<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum ReleaseReason: string
{
    case CancelledBeforeSend = 'cancelled_before_send';
    case DeniedBeforeSend = 'denied_before_send';
    case FailedBeforeSend = 'failed_before_send';
}
