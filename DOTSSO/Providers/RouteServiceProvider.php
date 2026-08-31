<?php

namespace Modules\DOTSSO\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Extends the plain ServiceProvider rather than Laravel's
 * RouteServiceProvider, matching the other DOT modules: the parent applies a
 * namespace during boot() which, combined with the module's own group, passes
 * null into Laravel's route merge - a trim(null) deprecation that FreeScout
 * escalates to an exception on PHP 8.4+.
 */
class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Prefix is set once per group. Routes files must not wrap their
        // routes in another group - see the note in each file.
        Route::middleware('web')
            ->namespace('Modules\DOTSSO\Http\Controllers')
            ->prefix('sso')
            ->group(__DIR__.'/../Routes/web.php');

        Route::middleware('web')
            ->namespace('Modules\DOTSSO\Http\Controllers')
            ->prefix('sso-settings')
            ->group(__DIR__.'/../Routes/settings.php');
    }

    public function register()
    {
        //
    }
}
