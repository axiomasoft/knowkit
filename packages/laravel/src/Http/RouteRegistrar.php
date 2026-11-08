<?php

declare(strict_types=1);

namespace KnowKit\Laravel\Http;

use Illuminate\Routing\Router;

/** Host-selected HTTP delivery adapter. The provider wraps every route in the context guard. */
interface RouteRegistrar
{
    public function register(Router $router): void;
}
