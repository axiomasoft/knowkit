<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum Readiness: string
{
    case Installed = 'installed';
    case Enabled = 'enabled';
    case Configured = 'configured';
    case Verified = 'verified';
    case Ready = 'ready';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
