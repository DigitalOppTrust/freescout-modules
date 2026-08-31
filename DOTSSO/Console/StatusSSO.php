<?php

namespace Modules\DOTSSO\Console;

use Illuminate\Console\Command;
use Modules\DOTSSO\Services\Settings;

/**
 * What state is SSO actually in?
 *
 * Answers the question you have before you turn enforcement on, and the
 * question you have when logins are failing - without reading four config
 * sources by hand.
 */
class StatusSSO extends Command
{
    protected $signature = 'dotsso:status';

    protected $description = 'Show the current DOTSSO configuration and readiness';

    public function handle()
    {
        $rows = [
            ['Module enabled',    Settings::bool('enabled') ? 'yes' : 'no'],
            ['Credentials set',   Settings::configured() ? 'yes' : 'NO - SSO cannot run'],
            ['Button shown',      Settings::enabled() ? 'yes' : 'no'],
            ['Password login',    Settings::enforcing() ? 'REFUSED (SSO enforced)' : 'allowed'],
            ['Workspace domain',  Settings::domain() ?: '(none - domain check disabled!)'],
            ['Client ID',         Settings::masked('client_id') ?: '(unset)'],
            ['Client secret',     Settings::get('client_secret') ? 'set' : '(unset)'],
            ['Redirect URI',      \Modules\DOTSSO\Services\OAuthFlow::redirectUri()],
            ['Break-glass',       implode(', ', Settings::breakglass()) ?: '(none)'],
        ];

        $this->table(['Setting', 'Value'], $rows);

        if (Settings::domain() === '') {
            $this->error('No Workspace domain is set. Any Google account that matches a '
                .'user row could sign in. Set one before enabling.');
        }

        if (Settings::enforcing() && !Settings::breakglass()) {
            $this->warn('Enforcement is on with no break-glass account. '
                .'If SSO breaks, recovery needs shell access (dotsso:disable).');
        }

        return 0;
    }
}
