<?php

namespace Modules\DOTRatings\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Deliberately extends the plain ServiceProvider rather than Laravel's
 * RouteServiceProvider: the parent applies a namespace during boot() which,
 * combined with the module's own group, passes null into Laravel's route
 * merge. On PHP 8.4+ that is a trim(null) deprecation, and FreeScout
 * escalates deprecations to exceptions - producing a 500 on every page.
 *
 * Two sibling groups, never nested. Each sets its prefix exactly once.
 */
class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $namespace = 'Modules\DOTRatings\Http\Controllers';

        // Admin side: settings and the ratings list. Authentication is
        // applied in the controller constructor, matching the Triage module.
        Route::middleware('web')
            ->namespace($namespace)
            ->prefix('ratings')
            ->group(__DIR__.'/../Routes/web.php');

        // Customer side: no authentication, by design - the recipient of a
        // closure email has no account. The token is the credential, and the
        // throttle bounds how fast anyone can guess at them.
        Route::middleware(['web', 'throttle:30,1'])
            ->namespace($namespace)
            ->prefix('ratings/r')
            ->group(__DIR__.'/../Routes/public.php');
    }

    public function register()
    {
        //
    }
}
