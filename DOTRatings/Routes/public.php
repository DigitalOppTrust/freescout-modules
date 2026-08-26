<?php

/**
 * Customer-facing routes. Unauthenticated by design.
 *
 * The GET must never record anything. Corporate mail scanners (Outlook
 * SafeLinks and friends) fetch every link in an incoming email before the
 * recipient sees it, so a rating recorded on GET would be a rating from a
 * robot. Stars in the email link here with ?stars=N, which only preselects.
 *
 * As with the admin routes: no nested groups, the prefix is set once in
 * RouteServiceProvider.
 */

Route::get('/{token}', 'PublicRatingsController@show')->name('dotratings.rate');
Route::post('/{token}', 'PublicRatingsController@submit')->name('dotratings.rate.submit');
Route::post('/{token}/reopen', 'PublicRatingsController@reopen')->name('dotratings.reopen');
