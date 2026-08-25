<?php

namespace Modules\DOTHelp\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\DOTHelp\Services\Handbook;

class DOTHelpController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');

        // Unlike DOTLog and DOTReports, this module is readable by ordinary
        // agents by default: it contains no customer data, no per-agent
        // performance figures and no credentials. The audience can still be
        // narrowed to administrators with DOTHELP_AUDIENCE=admin.
        $this->middleware(function ($request, $next) {
            if (config('dothelp.audience') === 'admin'
                && (!auth()->user() || !auth()->user()->isAdmin())) {
                abort(403, 'The handbook is restricted to administrators on this installation.');
            }

            return $next($request);
        });
    }

    public function index()
    {
        $isAdmin = auth()->user() && auth()->user()->isAdmin();

        return view('dothelp::index', [
            'courses' => Handbook::courses(),
            'topics'  => Handbook::forAudience($isAdmin),
            'all'     => Handbook::topics(),
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * A time-boxed reading route rendered as one continuous page.
     *
     * The hour is deliberately not eight separate pages: someone who has set
     * an hour aside should scroll through it and be able to see how far they
     * have got, rather than navigating and losing their place.
     */
    public function course($key)
    {
        if (!Handbook::hasCourse($key)) {
            abort(404);
        }

        $course  = Handbook::course($key);
        $isAdmin = auth()->user() && auth()->user()->isAdmin();

        // Courses are built from agent-readable topics only, so there is no
        // audience check to make here - but assert it rather than assume it,
        // so a future edit to courses() cannot quietly leak an admin page.
        $parts = [];
        foreach ($course['parts'] as $slug) {
            $topic = Handbook::get($slug);
            if (!$topic || $topic['audience'] === 'admin') {
                continue;
            }
            $parts[] = $topic;
        }

        return view('dothelp::course', [
            'course'  => $course,
            'parts'   => $parts,
            'minutes' => Handbook::partMinutes(),
            'isAdmin' => $isAdmin,
        ]);
    }

    public function topic($slug)
    {
        // Validated against the registry rather than trusted into a view
        // name - otherwise the slug is a path into the view namespace.
        if (!Handbook::has($slug)) {
            abort(404);
        }

        $isAdmin = auth()->user() && auth()->user()->isAdmin();
        $topic   = Handbook::get($slug);

        if ($topic['audience'] === 'admin' && !$isAdmin) {
            abort(403, 'This page describes an administrator-only screen.');
        }

        return view('dothelp::topic', [
            'topic'      => $topic,
            'topics'     => Handbook::forAudience($isAdmin),
            'neighbours' => Handbook::neighbours($slug, $isAdmin),
            'isAdmin'    => $isAdmin,
        ]);
    }
}
