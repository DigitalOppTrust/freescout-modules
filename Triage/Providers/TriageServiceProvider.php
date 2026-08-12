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
        $this->registerViews();

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

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'triage');
    }

    protected function registerHooks()
    {
        // The settings link is registered regardless of the kill switch, so
        // the module can always be configured and diagnosed - including when
        // it is switched off.
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['triage'] = ['title' => 'Triage', 'icon' => 'random', 'order' => 400];
            return $sections;
        });

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            return $settings;
        }, 20, 2);

        // Add a Triage entry to the Manage menu.
        \Eventy::addAction('menu.manage.append', function () {
            echo '<li><a href="'.route('triage.settings').'">'
                .'<i class="glyphicon glyphicon-random"></i> Triage</a></li>';
        });

        // Everything below acts on tickets, so it respects the kill switch.
        if (!config('triage.enabled')) {
            return;
        }

        // Triage and escalation hooks are registered in a later phase.
    }

    public function provides()
    {
        return [];
    }
}
