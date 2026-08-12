<?php

Route::group([
    'middleware' => ['web'],
    'prefix'     => 'triage',
    'namespace'  => 'Modules\Triage\Http\Controllers',
], function () {
    Route::get('/settings', 'TriageController@settings')->name('triage.settings');
    Route::post('/settings/profile', 'TriageController@saveProfile')->name('triage.profile.save');
    Route::post('/settings/profile/delete', 'TriageController@deleteProfile')->name('triage.profile.delete');
    Route::get('/settings/test-connection', 'TriageController@testConnection')->name('triage.test');
    Route::get('/decisions', 'TriageController@decisions')->name('triage.decisions');
});
