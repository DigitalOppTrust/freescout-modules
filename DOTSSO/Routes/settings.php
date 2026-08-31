<?php

/**
 * Admin settings, under their own prefix so the OAuth routes above stay at
 * /sso/* where Google's registered redirect URI expects them.
 */

Route::get('/', 'SettingsController@index')->name('dotsso.settings');
Route::post('/', 'SettingsController@save')->name('dotsso.settings.save');
