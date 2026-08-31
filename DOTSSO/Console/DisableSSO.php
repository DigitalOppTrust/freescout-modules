<?php

namespace Modules\DOTSSO\Console;

use Illuminate\Console\Command;
use Modules\DOTSSO\Services\Settings;

/**
 * Break-glass.
 *
 * If SSO is misconfigured, every user is locked out - including all six
 * administrators - and the fix is no longer reachable through the web UI.
 * This command restores password login from a shell, with no deploy and no
 * git push.
 *
 *   sudo -u www-data php artisan dotsso:disable
 *
 * Clears the caches it needs to clear itself, because someone running this is
 * by definition having a bad day and should not also have to remember that
 * settings changes need a config clear.
 */
class DisableSSO extends Command
{
    protected $signature = 'dotsso:disable {--enforce-only : Only lift enforcement, leave the button in place}';

    protected $description = 'Break-glass: restore password login by switching SSO enforcement off';

    public function handle()
    {
        $enforceOnly = (bool) $this->option('enforce-only');

        Settings::set('enforce', '0');

        if (!$enforceOnly) {
            Settings::set('enabled', '0');
        }

        $this->info($enforceOnly
            ? 'SSO enforcement is OFF. Password login works again; the Google button remains.'
            : 'SSO is OFF. Password login works again and the Google button is hidden.');

        // Settings live in the options table, but config caching and the
        // long-running queue worker both hold their own copies.
        try {
            $this->call('config:clear');
            $this->call('cache:clear');
        } catch (\Throwable $e) {
            $this->warn('Could not clear caches automatically: '.$e->getMessage());
            $this->warn('Run: php artisan config:clear && php artisan cache:clear');
        }

        $this->line('');
        $this->warn('If DOTSSO_ENABLED or DOTSSO_ENFORCE are set to true in .env, '
            .'they will override this. Check .env before assuming the site is recovered.');

        return 0;
    }
}
