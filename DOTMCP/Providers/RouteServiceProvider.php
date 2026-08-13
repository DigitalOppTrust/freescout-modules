<?php

namespace Modules\DOTMCP\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Extends the plain ServiceProvider, not Laravel's RouteServiceProvider: the
 * parent applies its own namespace during boot which, combined with a module
 * group, passes null into the route prefix merge. On PHP 8.4+ that is a
 * trim(null) deprecation, and FreeScout escalates deprecations to exceptions -
 * producing a 500 on every page of the site.
 */
class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Module routes, prefixed once here.
        Route::middleware('web')
            ->namespace('Modules\DOTMCP\Http\Controllers')
            ->prefix('mcp')
            ->group(__DIR__.'/../Routes/web.php');

        // RFC 8414 requires discovery at the domain root, not under a prefix.
        Route::middleware('web')
            ->namespace('Modules\DOTMCP\Http\Controllers')
            ->get('/.well-known/oauth-authorization-server', 'OAuthController@metadata')
            ->name('mcp.oauth.metadata');
    }

    public function register()
    {
        //
    }
}
