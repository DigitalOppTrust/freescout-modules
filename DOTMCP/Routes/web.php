<?php

/**
 * Prefix and namespace are applied by RouteServiceProvider. Do NOT wrap these
 * in another Route::group(['prefix' => ...]) - nested groups pass null into
 * Laravel's prefix merge, which throws on PHP 8.4+ and 500s the entire site.
 */

// Discovery is registered at the domain root by the service provider.
Route::get('/oauth/authorize', 'OAuthController@authorize')->name('mcp.oauth.authorize');
Route::post('/oauth/approve', 'OAuthController@approve')->name('mcp.oauth.approve');

Route::get('/settings', 'SettingsController@index')->name('mcp.settings');
Route::post('/settings/user', 'SettingsController@saveUser')->name('mcp.settings.user');
Route::post('/settings/revoke', 'SettingsController@revokeToken')->name('mcp.settings.revoke');
Route::post('/settings/keys', 'SettingsController@generateKeys')->name('mcp.settings.keys');
