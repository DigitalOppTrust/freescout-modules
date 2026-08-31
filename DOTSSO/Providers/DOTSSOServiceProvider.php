<?php

namespace Modules\DOTSSO\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\DOTSSO\Services\Audit;
use Modules\DOTSSO\Services\Settings;

class DOTSSOServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public $moduleName = 'DOTSSO';
    public $moduleNameLower = 'dotsso';

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerCommands();

        // Same posture as the other DOT modules: FreeScout boots every
        // module's provider on every request, so a fault here must degrade to
        // a log line rather than a site-wide 500.
        //
        // It matters more here than anywhere else: this module sits on the
        // login page, so an uncaught throw would lock every user out of a
        // site they cannot log in to in order to fix it.
        try {
            $this->registerHooks();
        } catch (\Throwable $e) {
            \Log::error('[DOTSSO] boot failed: '.$e->getMessage());
        }
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'dotsso');
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dotsso');
    }

    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\DOTSSO\Console\DisableSSO::class,
                \Modules\DOTSSO\Console\StatusSSO::class,
            ]);
        }
    }

    protected function registerHooks()
    {
        // The settings link registers regardless of the kill switch, so the
        // module can always be configured and diagnosed - including when it
        // is switched off, which is exactly when someone needs to reach it.
        \Eventy::addAction('menu.manage.append', function () {
            if (!auth()->check() || !auth()->user()->isAdmin()) {
                return;
            }

            echo '<li><a href="'.route('dotsso.settings').'">'
                .'<i class="glyphicon glyphicon-log-in"></i> '.__('Single Sign-On').'</a></li>';
        });

        if (!Settings::enabled()) {
            return;
        }

        // The button. Sits outside the login <form> in core's template, so
        // it is a link rather than a nested form.
        \Eventy::addAction('login_form.after', function () {
            echo view('dotsso::button')->render();
        });

        if (!Settings::enforcing()) {
            return;
        }

        // ── Everything below only applies under enforcement. ──

        // Refuse password logins. This fires before the password is checked
        // (LoginController.php:63), which is the right place to refuse an
        // authentication method but would be the wrong place for a second
        // factor - the identity is unproven at this point.
        \Eventy::addFilter('login.custom_check', function ($errors, $request = null) {
            if ($request === null) {
                return $errors;
            }

            $email = (string) $request->input('email');

            // The break-glass account keeps its password, by design.
            if (Settings::isBreakglass($email)) {
                return $errors;
            }

            Audit::passwordBlocked($email);

            $errors['email'] = __('Please sign in with your Google account.');

            return $errors;
        }, 20, 2);

        // Hide the password-reset link: under enforcement a reset grants
        // nothing, so offering it only sends people down a dead end.
        \Eventy::addFilter('auth.password_reset_available', function ($available) {
            return false;
        }, 20, 1);
    }

    public function provides()
    {
        return [];
    }
}
