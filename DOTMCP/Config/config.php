<?php

return [
    'name' => 'DOTMCP',

    // Master kill switch. Disables the endpoint and hides the module without
    // uninstalling it.
    'enabled' => env('MCP_ENABLED', false),

    // Where the OAuth signing keypair lives. Outside the web root and outside
    // the module directory, so neither nginx nor a module reinstall can
    // expose or destroy it.
    'key_path' => env('MCP_KEY_PATH', storage_path('app/mcp-keys')),

    // Per-token rate limit. Not primarily anti-abuse: with 8 PHP-FPM workers,
    // an agent retrying in a loop could exhaust the pool and take the whole
    // helpdesk down.
    'rate_limit_per_minute' => env('MCP_RATE_LIMIT', 60),

    // Hard cap on rows any tool may return, regardless of what is requested.
    'max_page_size' => env('MCP_MAX_PAGE', 100),

    'server_name'    => 'DO Trust Support',
    'server_version' => '0.1.0',
];
