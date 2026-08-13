<?php

namespace Modules\DOTTheme\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Applies DO Trust branding to FreeScout.
 *
 * Uses FreeScout's own extension points rather than patching its views:
 *   - layout.header_logo  swaps the logo (a filter FreeScout provides for this)
 *   - layout.head         injects the stylesheet after FreeScout's own CSS
 *
 * That means a FreeScout upgrade cannot undo the branding, and disabling the
 * module restores the default appearance exactly.
 *
 * No routes, no tables, no hooks that touch tickets. Presentation only.
 */
class ThemeServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public $moduleName = 'DOTTheme';
    public $moduleNameLower = 'dottheme';

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();

        // A fault here would 500 every page of the site, not just this
        // module's, because Laravel boots every provider on every request.
        // For a cosmetic module that trade is never worth making.
        try {
            $this->registerBranding();
        } catch (\Throwable $e) {
            \Log::error('[DOTTheme] boot failed: '.$e->getMessage());
        }
    }

    public function register()
    {
        //
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'dottheme');
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dottheme');
    }

    protected function registerBranding()
    {
        if (!config('dottheme.enabled')) {
            return;
        }

        // Swap the header logo. FreeScout exposes this filter precisely so a
        // module can rebrand without touching the layout.
        \Eventy::addFilter('layout.header_logo', function ($default) {
            return asset('modules/dottheme/img/dot-logo.svg');
        });

        // Inject the stylesheet into <head>, after FreeScout's own CSS so
        // these rules win on specificity alone.
        \Eventy::addAction('layout.head', function () {
            $css = asset('modules/dottheme/css/theme.css');

            // Preload the two weights that appear above the fold, so the first
            // paint is not in the fallback font. The other weights load
            // normally - preloading all four would delay the render it is
            // meant to improve.
            $r400 = asset('modules/dottheme/fonts/montserrat-400.woff2');
            $r600 = asset('modules/dottheme/fonts/montserrat-600.woff2');

            echo '<link rel="preload" as="font" type="font/woff2" crossorigin href="'.$r400.'">'."\n";
            echo '<link rel="preload" as="font" type="font/woff2" crossorigin href="'.$r600.'">'."\n";
            echo '<link rel="stylesheet" href="'.$css.'?v='.config('dottheme.version', '1').'">'."\n";

            // Colours the browser chrome uses on mobile.
            echo '<meta name="theme-color" content="'.e(config('dottheme.brand')).'">'."\n";
        });
    }

    public function provides()
    {
        return [];
    }
}
