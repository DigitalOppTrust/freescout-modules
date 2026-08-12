<?php

namespace Modules\Triage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Triage\Entities\TriageProfile;
use Modules\Triage\Entities\TriageDecision;
use Modules\Triage\Services\ClaudeClient;

class TriageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Routing rules are effectively workload allocation, so this is
        // admin-only rather than available to every agent.
        $this->middleware(function ($request, $next) {
            if (!auth()->user() || !auth()->user()->isAdmin()) {
                abort(403, 'Triage settings are restricted to administrators.');
            }
            return $next($request);
        });
    }

    /** Settings screen: connection status, global options, agent profiles. */
    public function settings(Request $request)
    {
        $mailboxId = $request->get('mailbox_id');
        $mailboxes = \App\Mailbox::orderBy('name')->get();

        // Default to the only mailbox when there is just one.
        if (!$mailboxId && $mailboxes->count() === 1) {
            $mailboxId = $mailboxes->first()->id;
        }

        $profiles = TriageProfile::with(['user', 'escalateTo'])
            ->when($mailboxId, function ($q) use ($mailboxId) {
                return $q->where(function ($sub) use ($mailboxId) {
                    $sub->where('mailbox_id', $mailboxId)->orWhereNull('mailbox_id');
                });
            })
            ->get()
            ->keyBy('user_id');

        $users = \App\User::where('status', \App\User::STATUS_ACTIVE)
            ->orderBy('first_name')
            ->get();

        return view('triage::settings', [
            'mailboxes' => $mailboxes,
            'mailboxId' => $mailboxId,
            'profiles'  => $profiles,
            'users'     => $users,
            'accuracy'  => TriageDecision::accuracy(30),
            'callsToday'=> TriageDecision::callsToday(),
        ]);
    }

    /** AJAX: live Claude API check for the status panel. */
    public function testConnection()
    {
        $client = new ClaudeClient();
        return response()->json($client->testConnection());
    }

    /** Save one agent's profile. */
    public function saveProfile(Request $request)
    {
        $userId    = (int) $request->input('user_id');
        $mailboxId = $request->input('mailbox_id') ?: null;

        if (!$userId) {
            return redirect()->back()->with('error', 'No user specified.');
        }

        $escalateTo = $request->input('escalate_to_user_id') ?: null;

        // Reject self-escalation and loops at save time rather than
        // discovering them when a ticket is already stuck mid-chain.
        if ($escalateTo && (int) $escalateTo === $userId) {
            return redirect()->back()->with('error', 'A user cannot escalate to themselves.');
        }

        if ($escalateTo) {
            $loop = TriageProfile::detectLoop($userId, $escalateTo, $mailboxId);
            if (!empty($loop)) {
                $names = array_map(function ($id) {
                    $u = \App\User::find($id);
                    return $u ? $u->getFullName() : ('User '.$id);
                }, $loop);

                return redirect()->back()->with(
                    'error',
                    'That escalation target creates a loop: '.implode(' → ', $names)
                );
            }
        }

        TriageProfile::updateOrCreate(
            ['user_id' => $userId, 'mailbox_id' => $mailboxId],
            [
                'description'            => $request->input('description'),
                'keywords'               => $request->input('keywords'),
                'rotation_group'         => $request->input('rotation_group') ?: null,
                'escalate_to_user_id'    => $escalateTo,
                'escalate_after_minutes' => $request->input('escalate_after_minutes') ?: null,
                'available'              => (bool) $request->input('available'),
                'max_open'               => (int) $request->input('max_open'),
            ]
        );

        return redirect()->back()->with('success', 'Profile saved.');
    }

    /** Remove an agent from triage routing entirely. */
    public function deleteProfile(Request $request)
    {
        TriageProfile::where('user_id', (int) $request->input('user_id'))
            ->where('mailbox_id', $request->input('mailbox_id') ?: null)
            ->delete();

        return redirect()->back()->with('success', 'Profile removed.');
    }

    /** Recent routing decisions, for reviewing accuracy. */
    public function decisions(Request $request)
    {
        $decisions = TriageDecision::with(['suggestedUser', 'conversation'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return view('triage::decisions', [
            'decisions' => $decisions,
            'accuracy'  => TriageDecision::accuracy(30),
        ]);
    }
}
