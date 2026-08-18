<?php

/**
 * Routes are registered inside a group defined by RouteServiceProvider, which
 * already sets the middleware, namespace and prefix.
 *
 * Do NOT wrap these in another Route::group(['prefix' => ...]) - see the
 * matching note in the Triage module's Routes/web.php for why (trim(null)
 * deprecation escalated to an exception on PHP 8.4+).
 */

Route::get('/', 'DOTLogController@index')->name('dotlog.index');
Route::post('/settings', 'DOTLogController@saveSettings')->name('dotlog.settings.save');
