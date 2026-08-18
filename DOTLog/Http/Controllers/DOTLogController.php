<?php

namespace Modules\DOTLog\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DOTLog\Entities\LogEntry;
use Modules\DOTLog\Services\Settings;

class DOTLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // The log names customers and recipients, so it is admin-only rather
        // than available to every agent.
        $this->middleware(function ($request, $next) {
            if (!auth()->user() || !auth()->user()->isAdmin()) {
                abort(403, 'DOTLog is restricted to administrators.');
            }
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $query = LogEntry::orderBy('id', 'desc');

        // Accepts either the conversation id (as in /conversation/{id} URLs)
        // or the ticket number shown in the UI - they can differ, so both are
        // matched.
        $conversation = trim((string) $request->get('conversation'));
        if ($conversation !== '') {
            $n = (int) ltrim($conversation, '#');
            $ids = [$n];
            $byNumber = \App\Conversation::where('number', $n)->first();
            if ($byNumber) {
                $ids[] = (int) $byNumber->id;
            }
            $query->whereIn('conversation_id', array_unique($ids));
        }

        $event = trim((string) $request->get('event'));
        if ($event !== '') {
            // A bare group like 'triage' matches all its events.
            $query->where(function ($q) use ($event) {
                $q->where('event', $event)
                  ->orWhere('event', 'like', $event.'.%');
            });
        }

        $level = trim((string) $request->get('level'));
        if (in_array($level, ['info', 'warning', 'error'])) {
            $query->where('level', $level);
        }

        $entries = $query->paginate(50)->appends($request->query());

        return view('dotlog::index', [
            'entries'   => $entries,
            'events'    => LogEntry::eventKeys(),
            'retention' => Settings::group('retention'),
            'capturing' => (bool) config('dotlog.enabled'),
            'filters'   => [
                'conversation' => $conversation,
                'event'        => $event,
                'level'        => $level,
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        foreach (Settings::schema() as $key => $spec) {
            if ($spec['type'] === 'bool') {
                Settings::set($key, $request->has($key) ? '1' : '0');
            } elseif ($request->filled($key)) {
                Settings::set($key, $request->input($key));
            }
        }

        return redirect()->route('dotlog.index')->with('success', 'DOTLog settings saved.');
    }
}
