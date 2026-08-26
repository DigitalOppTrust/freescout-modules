<?php

/**
 * Admin routes. Registered inside a group defined by RouteServiceProvider,
 * which already sets the middleware, namespace and prefix.
 *
 * Do NOT wrap these in another Route::group(['prefix' => ...]) - see the note
 * in RouteServiceProvider.
 */

Route::get('/settings', 'RatingsController@settings')->name('dotratings.settings');
Route::post('/settings', 'RatingsController@saveSettings')->name('dotratings.settings.save');
Route::get('/list', 'RatingsController@index')->name('dotratings.list');
