<?php

return [
    /*
     * Kill switch. When false the module registers nothing: no menu entry,
     * no routes reachable through the UI. The handbook is documentation, so
     * it can never affect a ticket - but the switch exists for consistency
     * with the other DOT modules and so a broken page can be taken out of
     * the navigation without uninstalling anything.
     */
    'enabled' => env('DOTHELP_ENABLED', true),

    /*
     * Who may read the handbook.
     *
     * 'all'   - every logged-in user, including agents. The default: an
     *           onboarding guide nobody can open has failed at its one job.
     * 'admin' - administrators only.
     *
     * The handbook deliberately contains no customer data, no credentials
     * and no server addresses, which is what makes 'all' safe.
     */
    'audience' => env('DOTHELP_AUDIENCE', 'all'),
];
