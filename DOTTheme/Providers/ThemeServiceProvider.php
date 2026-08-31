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

        // The header logo above only renders for signed-in users. The login
        // page draws its own banner (resources/views/auth/banner.blade.php)
        // through a separate filter, so without this the sign-in page - the
        // first thing anyone sees - still showed FreeScout's mark.
        \Eventy::addFilter('login.banner', function ($default) {
            return asset('modules/dottheme/img/dot-logo.svg');
        });

        // Replace the footer. Returning a non-empty string from this filter
        // makes core use it instead of its own copyright line, so this says
        // what the desk is and who it is for rather than what it runs on.
        //
        // FreeScout remains credited in the About page and in the source; this
        // is a staff-facing sign-in page, not an attribution notice.
        \Eventy::addFilter('footer.text', function ($default) {
            $text = trim((string) config('dottheme.footer_text'));

            if ($text === '') {
                return $default;
            }

            // The filter's output is echoed unescaped by core, so escape here.
            return e($text);
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
            // Cache-bust on the file's own mtime. This previously read a
            // 'dottheme.version' config key that does not exist, so it always
            // emitted ?v=1 and every CSS change stayed behind whatever the
            // browser had cached - the same trap DOTHelp hit.
            $path = public_path('modules/dottheme/css/theme.css');
            if (file_exists($path)) {
                $css .= '?v='.filemtime($path);
            }

            echo '<link rel="stylesheet" href="'.$css.'">'."\n";

            // Colours the browser chrome uses on mobile.
            echo '<meta name="theme-color" content="'.e(config('dottheme.brand')).'">'."\n";
        });
    }

    public function provides()
    {
        return [];
    }
}
