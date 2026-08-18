<?php

return [
    /*
     * Kill switch for event capture. Pruning and the log viewer stay
     * available when this is off, so history can still be read and the
     * retention sweep keeps honouring its promise.
     */
    'enabled' => env('DOTLOG_ENABLED', true),
];
