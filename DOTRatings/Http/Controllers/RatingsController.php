<?php

namespace Modules\DOTRatings\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DOTRatings\Entities\Rating;
use Modules\DOTRatings\Services\Settings;

class RatingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Whether the help desk emails customers when their tickets close is
        // a policy decision, so this is admin-only rather than available to
        // every agent.
        $this->middleware(function ($request, $next) {
            if (!auth()->user() || !auth()->user()->isAdmin()) {
                abort(403, 'Ratings settings are restricted to administrators.');
            }

            return $next($request);
        });
    }

    /** Settings screen, with the headline numbers above the form. */
    public function settings()
    {
        return view('dotratings::settings', [
            'sending'      => Settings::group('sending'),
            'link'         => Settings::group('link'),
            'summary'      => Rating::summary(30),
            'distribution' => Rating::distribution(30),
            'enabled'      => (bool) config('dotratings.enabled'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        foreach (Settings::schema() as $key => $spec) {
            if ($spec['type'] === 'bool') {
                // An unchecked checkbox is absent from the request, which is
                // how "off" arrives - so booleans are always written, never
                // conditionally.
                Settings::set($key, $request->has($key) ? '1' : '0');
                continue;
            }

            $value = $request->input($key);
            if ($value !== null && $value !== '') {
                Settings::set($key, $value);
            }
        }

        // The queue worker is long-running and holds its own copy of config
        // and options. Without this it keeps using the previous settings
        // until it happens to restart.
        try {
            \Artisan::call('queue:restart');
        } catch (\Throwable $e) {
            \Log::warning('[Ratings] could not restart the queue worker: '.$e->getMessage());
        }

        \Session::flash('flash_success_floating', 'Ratings settings saved.');

        return redirect()->route('dotratings.settings');
    }

    /** Recent ratings, newest first. */
    public function index()
    {
        $ratings = Rating::with('conversation')
            ->whereNotNull('rated_at')
            ->orderBy('rated_at', 'desc')
            ->paginate(50);

        return view('dotratings::list', [
            'ratings' => $ratings,
            'summary' => Rating::summary(30),
        ]);
    }
}
