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
            'topics'  => Handbook::forAudience($isAdmin),
            'all'     => Handbook::topics(),
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
