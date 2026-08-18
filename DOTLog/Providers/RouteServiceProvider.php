<?php

namespace Modules\DOTLog\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Extends the plain ServiceProvider rather than Laravel's
 * RouteServiceProvider, for the same reason as the Triage module: the parent
 * applies a namespace during boot() which, combined with the module's own
 * group, passes null into Laravel's route merge - a trim(null) deprecation
 * that FreeScout escalates to an exception on PHP 8.4+.
 */
class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Prefix is set here, once. Routes/web.php must not wrap its routes
        // in another group - see the note in that file.
        Route::middleware('web')
            ->namespace('Modules\DOTLog\Http\Controllers')
            ->prefix('dotlog')
            ->group(__DIR__.'/../Routes/web.php');
    }

    public function register()
    {
        //
    }
}
