<?php

namespace Modules\DOTMCP\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\DOTMCP\Services\AccessLevel;

class MCPServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public $moduleName = 'DOTMCP';
    public $moduleNameLower = 'dotmcp';

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();

        // A fault here would 500 every page of the site, not just this
        // module's, because Laravel boots every provider on every request.
        try {
            $this->registerHooks();
        } catch (\Throwable $e) {
            \Log::error('[DOTMCP] boot failed: '.$e->getMessage());
        }
    }

    public function register()
    {
        // The module ships its own vendor/ because FreeScout commits its own
        // and adding packages there conflicts on every upgrade. Loading it
        // here keeps league/oauth2-server isolated from FreeScout's tree.
        $autoload = __DIR__.'/../vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'dotmcp');
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dotmcp');
    }

    protected function registerHooks()
    {
        if (!config('dotmcp.enabled')) {
            return;
        }

        // Admins see the entry because this is where MCP access is granted -
        // without that, nobody could ever enable the first user. MCP-enabled
        // users see it to manage their own connections. Everyone else sees no
        // trace of the module, and the controller returns 404 to match.
        \Eventy::addAction('menu.manage.append', function () {
            $user = auth()->user();
            if (!$user) {
                return;
            }

            if ($user->isAdmin() || AccessLevel::checkUser($user)['allowed']) {
                echo '<li><a href="'.route('mcp.settings').'">'
                    .'<i class="glyphicon glyphicon-transfer"></i> MCP</a></li>';
            }
        });
    }

    public function provides()
    {
        return [];
    }
}
