<?php

return [
    'name' => 'Ratings',

    // Master kill switch. Set DOTRATINGS_ENABLED=false in the FreeScout .env
    // to disable all module behaviour without removing the module.
    //
    // Note this is not the same thing as the "send closure emails" setting on
    // the settings page. This switch exists for emergencies - it stops the
    // hooks from being registered at all. The settings page controls intended
    // behaviour, and is the one an administrator should normally use.
    'enabled' => env('DOTRATINGS_ENABLED', true),
];
