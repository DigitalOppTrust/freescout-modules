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
 */

Route::get('/', 'DOTHelpController@index')->name('dothelp.index');

// Declared before the catch-all slug route below, which would otherwise
// swallow these and 404 them as unknown topics.
Route::get('/course/{key}', 'DOTHelpController@course')
    ->where('key', '[a-z0-9-]+')
    ->name('dothelp.course');

// Constrained to the slug shape the registry uses. The controller checks the
// registry too; this keeps obvious junk from reaching it at all.
Route::get('/{slug}', 'DOTHelpController@topic')
    ->where('slug', '[a-z0-9-]+')
    ->name('dothelp.topic');
