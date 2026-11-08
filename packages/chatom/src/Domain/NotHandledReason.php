<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

enum NotHandledReason: string
{
    case MissingConfiguration = 'missing_configuration';
    case RouteMismatch = 'route_mismatch';
    case ChangedBinding = 'changed_binding';
    case BindingUnavailable = 'binding_unavailable';
}
