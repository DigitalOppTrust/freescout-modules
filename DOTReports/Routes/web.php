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

Route::get('/', 'ReportsController@overview')->name('reports.overview');
Route::get('/triage', 'ReportsController@triage')->name('reports.triage');
Route::get('/resolution', 'ReportsController@resolution')->name('reports.resolution');
Route::get('/team', 'ReportsController@team')->name('reports.team');
Route::get('/export/{report}', 'ReportsController@export')->name('reports.export');
