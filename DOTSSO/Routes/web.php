<?php

/**
 * Routes are registered inside a group defined by RouteServiceProvider, which
 * already sets the middleware, namespace and prefix.
 *
 * Do NOT wrap these in another Route::group(['prefix' => ...]). Laravel's
 * RouteGroup::formatPrefix() calls trim() on the parent group's prefix, which
 * is null for a registrar-built group. On PHP 8.4+ that is a deprecation, and
 * FreeScout escalates deprecations to exceptions - producing a 500 on every
 * page of the site, not just this module's.
 *
 * These are guest routes: they must be reachable by someone who is not logged
 * in, which is the entire point. The controller does the gatekeeping.
 */

Route::get('/redirect', 'SSOController@redirect')->name('dotsso.redirect');

// Must match the authorised redirect URI in Google Cloud Console exactly:
// https://support.dotrust.org/sso/callback
Route::get('/callback', 'SSOController@callback')->name('dotsso.callback');
