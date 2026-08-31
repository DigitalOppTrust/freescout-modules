<?php

return [
    'name' => 'DOTTheme',

    // Master switch. Turning this off restores FreeScout's default appearance
    // without uninstalling the module.
    'enabled' => env('THEME_ENABLED', true),

    // Brand colour, taken from the DOT logo.
    'brand'       => env('THEME_BRAND', '#0079B2'),
    'brand_dark'  => env('THEME_BRAND_DARK', '#005F8C'),
    'brand_light' => env('THEME_BRAND_LIGHT', '#E6F2F8'),

    // Footer line, replacing FreeScout's default copyright notice. Set to an
    // empty string to restore the original.
    //
    // This is a staff-only desk, so the footer says who it is for - the
    // default line describes the software, which nobody signing in needs.
    'footer_text' => env('THEME_FOOTER_TEXT',
        'DOT Support — this platform is for DOT staff members providing support.'),

    // Serve Montserrat from the module rather than Google Fonts. A support
    // desk should not make a third-party request on every page load, and the
    // customer-facing side is behind Cloudflare where an external font is an
    // extra round trip.
    'self_host_font' => env('THEME_SELF_HOST_FONT', true),
];
