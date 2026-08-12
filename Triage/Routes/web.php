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

Route::get('/settings', 'TriageController@settings')->name('triage.settings');
Route::post('/settings/profile', 'TriageController@saveProfile')->name('triage.profile.save');
Route::post('/settings/profile/delete', 'TriageController@deleteProfile')->name('triage.profile.delete');
Route::get('/settings/test-connection', 'TriageController@testConnection')->name('triage.test');
Route::get('/decisions', 'TriageController@decisions')->name('triage.decisions');
