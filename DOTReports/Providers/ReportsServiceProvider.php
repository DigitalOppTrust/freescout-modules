<?php

namespace Modules\DOTReports\Providers;

use Illuminate\Support\ServiceProvider;

class ReportsServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public $moduleName = 'Reports';

    // The Blade namespace, NOT the module alias. The alias in module.json is
    // "dotreports" deliberately: FreeScout matches installed modules against
    // its own directory by alias, and the official paid Reports module owns
    // "reports". Sharing it makes the Modules page offer that module as an
    // "update" to this one. Views stay under reports:: - the namespace is
    // registered here and is independent of the alias.
    public $moduleNameLower = 'reports';

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
            \Log::error('[Reports] boot failed: '.$e->getMessage());
        }
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'reports');
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'reports');
    }

    protected function registerHooks()
    {
        // Core's Helper::$menu only knows core routes, so a module route
        // would never highlight as active. This filter registers ours.
        \Eventy::addFilter('menu.selected', function ($menu) {
            $menu['reports'] = [
                'reports.overview',
                'reports.triage',
                'reports.resolution',
                'reports.team',
            ];

            return $menu;
        });

        // Reports is a daily-use page rather than a settings screen, so it
        // belongs in the main navigation, not buried under Manage.
        \Eventy::addAction('menu.append', function () {
            if (!auth()->check() || !auth()->user()->isAdmin()) {
                return;
            }

            $selected = \App\Misc\Helper::menuSelectedHtml('reports');

            echo '<li class="'.$selected.'"><a href="'.route('reports.overview').'">'
                .__('Reports').'</a></li>';
        });

        // Also in the Manage menu, because that is where an admin looks for
        // anything administrative.
        \Eventy::addAction('menu.manage.append', function () {
            echo '<li><a href="'.route('reports.overview').'">'
                .'<i class="glyphicon glyphicon-stats"></i> '.__('Reports').'</a></li>';
        });
    }

    public function provides()
    {
        return [];
    }
}
