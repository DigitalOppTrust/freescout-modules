<?php

namespace Modules\DOTReports\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Deliberately extends the plain ServiceProvider rather than Laravel's
 * RouteServiceProvider: the parent applies a namespace during boot() which,
 * combined with the module's own group, passes null into Laravel's route
 * merge. On PHP 8.4+ that is a trim(null) deprecation, and FreeScout
 * escalates deprecations to exceptions - producing a 500 on every page.
 *
 * This is not a preference. The Triage module paid for this in production.
 */
class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Prefix is set here, once. Routes/web.php must not wrap its routes
        // in another group - see the note in that file.
        Route::middleware('web')
            ->namespace('Modules\DOTReports\Http\Controllers')
            ->prefix('reports')
            ->group(__DIR__.'/../Routes/web.php');
    }

    public function register()
    {
        //
    }
}
