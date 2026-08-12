<?php

namespace Modules\Triage\Providers;

use Illuminate\Support\ServiceProvider;

class TriageServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public $moduleName = 'Triage';
    public $moduleNameLower = 'triage';

    public function boot()
    {
        $this->registerConfig();

        // Hooks are registered inside a try/catch so that a fault in module
        // code cannot take down the whole application. FreeScout boots every
        // module's provider on every request - an uncaught error here would
        // produce a 500 on every page, including the admin UI needed to
        // disable this module.
        try {
            $this->registerHooks();
        } catch (\Throwable $e) {
            \Log::error('[Triage] boot failed: '.$e->getMessage());
        }
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'triage');
    }

    protected function registerHooks()
    {
        // Master kill switch - checked before anything else is wired up.
        if (!config('triage.enabled')) {
            return;
        }

        // Placeholder: triage logic is added in a later phase.
        // \Eventy::addAction('thread.created', function ($thread) { ... });
    }

    public function provides()
    {
        return [];
    }
}
