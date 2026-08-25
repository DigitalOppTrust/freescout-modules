<?php

namespace Modules\DOTHelp\Providers;

use Illuminate\Support\ServiceProvider;

class DOTHelpServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public $moduleName = 'DOTHelp';
    public $moduleNameLower = 'dothelp';

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();

        // Same posture as the other DOT modules: FreeScout boots every
        // module's provider on every request, so a fault here must degrade to
        // a log line rather than a site-wide 500.
        try {
            $this->registerHooks();
        } catch (\Throwable $e) {
            \Log::error('[DOTHelp] boot failed: '.$e->getMessage());
        }
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'dothelp');
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dothelp');
    }

    protected function registerHooks()
    {
        if (!config('dothelp.enabled')) {
            return;
        }

        // Core's Helper::$menu only knows core routes, so a module route
        // would never highlight as active. This filter registers ours.
        \Eventy::addFilter('menu.selected', function ($menu) {
            $menu['dothelp'] = ['dothelp.index', 'dothelp.topic'];

            return $menu;
        });

        // The handbook exists for people who do not yet know where anything
        // is, so it goes in the main navigation where they will trip over it
        // - not under Manage, which agents cannot open.
        \Eventy::addAction('menu.append', function () {
            if (!auth()->check()) {
                return;
            }

            if (config('dothelp.audience') === 'admin' && !auth()->user()->isAdmin()) {
                return;
            }

            $selected = \App\Misc\Helper::menuSelectedHtml('dothelp');

            echo '<li class="'.$selected.'"><a href="'.route('dothelp.index').'">'
                .__('Help').'</a></li>';
        });

        // Also under Manage, next to the modules it documents.
        \Eventy::addAction('menu.manage.append', function () {
            echo '<li><a href="'.route('dothelp.index').'">'
                .'<i class="glyphicon glyphicon-book"></i> '.__('Help').'</a></li>';
        });
    }

    public function provides()
    {
        return [];
    }
}
