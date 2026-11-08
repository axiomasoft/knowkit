<?php

declare(strict_types=1);

namespace KnowKit\Examples\Laravel;

use Illuminate\Routing\Router;
use KnowKit\Laravel\Http\RouteRegistrar;

/** Minimal host route example; select the optional HTTP adapter for the full versioned API. */
final class SearchRoutes implements RouteRegistrar
{
    public function register(Router $router): void
    {
        $router->post(uri: '/v1/search', action: [SearchController::class, '__invoke'])->name('knowkit.search');
    }
}
