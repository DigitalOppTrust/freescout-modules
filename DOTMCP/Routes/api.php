<?php

/**
 * Machine-to-machine OAuth endpoints, registered under the 'api' middleware
 * group so they are not subject to CSRF. Claude calls these directly with no
 * browser session; PKCE and client validation are the protection here.
 *
 * The authorize and approve steps deliberately stay in Routes/web.php - those
 * ARE browser requests and do need the session.
 */

Route::post('/register', 'OAuthController@register')->name('mcp.oauth.register');
Route::post('/token', 'OAuthController@token')->name('mcp.oauth.token');
Route::post('/revoke', 'OAuthController@revoke')->name('mcp.oauth.revoke');
