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
        // Browser-facing routes: the consent screen and settings need the
        // session and CSRF protection that 'web' provides.
        Route::middleware('web')
            ->namespace('Modules\DOTMCP\Http\Controllers')
            ->prefix('mcp')
            ->group(__DIR__.'/../Routes/web.php');

        // Machine-to-machine OAuth endpoints. These are called by Claude, not
        // by a browser, so they carry no session cookie and no CSRF token -
        // 'web' middleware would reject them with a 419. They are protected by
        // PKCE and client credentials instead, which is what the spec expects.
        //
        // No middleware group: FreeScout has its 'api' group commented out in
        // app/Http/Kernel.php, so ->middleware('api') resolves as a class name
        // and throws. Throttling is applied explicitly instead.
        Route::middleware(['throttle:120,1'])
            ->namespace('Modules\DOTMCP\Http\Controllers')
            ->prefix('mcp/oauth')
            ->group(__DIR__.'/../Routes/api.php');

        // RFC 8414 requires discovery at the domain root, not under a prefix.
        // Fully qualified: ->namespace() on a single route does not resolve a
        // bare controller name the way it does inside ->group().
        Route::middleware('web')
            ->get(
                '/.well-known/oauth-authorization-server',
                '\\Modules\\DOTMCP\\Http\\Controllers\\OAuthController@metadata'
            )
            ->name('mcp.oauth.metadata');
    }

    public function register()
    {
        //
    }
}
