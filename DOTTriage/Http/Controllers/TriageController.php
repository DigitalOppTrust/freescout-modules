<?php

namespace Modules\DOTTriage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DOTTriage\Entities\TriageProfile;
use Modules\DOTTriage\Entities\TriageDecision;
use Modules\DOTTriage\Services\ClaudeClient;
use Modules\DOTTriage\Services\Settings;
use Modules\DOTTriage\Services\AutoCloser;
use Modules\DOTTriage\Services\RetentionSweeper;

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
            'counts'    => TriageDecision::countsByUser(),
            'noise'     => TriageDecision::noiseCounts(30),
            'noiseReopened' => TriageDecision::noiseReopened(30),
            'escalation'=> Settings::group('escalation'),
            'closing'   => Settings::group('closing'),
            'closeStats'=> TriageDecision::closeCounts(30),
            'retention' => Settings::group('retention'),
            'retentionEligible' => RetentionSweeper::eligibleCount(),
            'escalations' => \Modules\DOTTriage\Entities\TriageEscalation::where('active', true)
                ->orderBy('clock_started_at')->get(),
            'escalationStats' => [
                'notified'   => \Modules\DOTTriage\Entities\TriageEscalation::where('notified_at', '>=', now()->subDays(30))->count(),
                'reassigned' => \Modules\DOTTriage\Entities\TriageEscalation::where('reassigned_at', '>=', now()->subDays(30))->count(),
            ],
            'userNames' => \App\User::pluck('first_name', 'id')->all(),
        ]);
    }

    /** Edit one agent's routing and escalation setup. */
    public function agent(Request $request, $id)
    {
        $user = \App\User::findOrFail((int) $id);

        $mailboxId = $request->get('mailbox_id') ?: null;
        if (!$mailboxId && \App\Mailbox::count() === 1) {
            $mailboxId = \App\Mailbox::first()->id;
        }

        $profile = TriageProfile::where('user_id', $user->id)
            ->where(function ($q) use ($mailboxId) {
                $q->where('mailbox_id', $mailboxId)->orWhereNull('mailbox_id');
            })
            ->first();

        $users = \App\User::where('status', \App\User::STATUS_ACTIVE)
            ->orderBy('first_name')
            ->get();

        // Existing groups, offered as autocomplete so a typo does not
        // silently create a one-person "group".
        $rotationGroups = TriageProfile::whereNotNull('rotation_group')
            ->distinct()
            ->pluck('rotation_group')
            ->filter()
            ->values()
            ->all();

        // Who else is already in this agent's group, so the effect of the
        // setting is visible rather than implied.
        $groupPeers = [];
        if ($profile && $profile->rotation_group) {
            $groupPeers = TriageProfile::with('user')
                ->where('rotation_group', $profile->rotation_group)
                ->where('user_id', '!=', $user->id)
                ->get()
                ->map(function ($p) { return $p->userName(); })
                ->all();
        }

        $openCount = \App\Conversation::where('user_id', $user->id)
            ->where('status', \App\Conversation::STATUS_ACTIVE)
            ->count();

        $counts = TriageDecision::countsByUser();

        return view('triage::agent', [
            'user'           => $user,
            'counts'         => $counts[$user->id] ?? null,
            'recent'         => TriageDecision::forUser($user->id, 10),
            'users'          => $users,
            'profile'        => $profile,
            'mailboxId'      => $mailboxId,
            'rotationGroups' => $rotationGroups,
            'groupPeers'     => $groupPeers,
            'openCount'      => $openCount,
        ]);
    }

    /**
     * Save the escalation settings.
     *
     * Changing the window does not retime clocks that are already running -
     * each escalation stored its own window when it started, so a ticket
     * mid-clock keeps the rule it began under. New clocks pick up the new
     * value. That is deliberate: silently moving a deadline a ticket is
     * already being measured against would make the audit trail dishonest.
     */
    public function saveEscalation(Request $request)
    {
        foreach (Settings::schema() as $key => $spec) {
            if ($spec['group'] !== 'escalation') {
                continue;
            }

            if ($spec['type'] === 'bool') {
                // An unchecked box submits nothing, so absence means false.
                Settings::set($key, $request->input($key) ? '1' : '0');
            } elseif ($request->has($key)) {
                Settings::set($key, $request->input($key));
            }
        }

        return redirect()->route('triage.settings')
            ->with('success', 'Escalation settings saved. Clocks already running keep the window they started with.');
    }

    /** Save the automatic-closing settings. */
    public function saveClosing(Request $request)
    {
        foreach (Settings::schema() as $key => $spec) {
            if ($spec['group'] !== 'closing') {
                continue;
            }

            if ($spec['type'] === 'bool') {
                // An unchecked box submits nothing, so absence means false.
                Settings::set($key, $request->input($key) ? '1' : '0');
            } elseif ($request->has($key)) {
                Settings::set($key, $request->input($key));
            }
        }

        return redirect()->route('triage.settings')
            ->with('success', 'Closing settings saved.');
    }

    /** Show what a sweep would close, without closing anything. */
    public function previewClosing()
    {
        $closer = new AutoCloser(true);

        return view('triage::closing_preview', [
            'noise'    => $closer->sweepBacklogNoise(),
            'inactive' => $closer->sweepInactive(),
            'resolved' => $closer->sweepResolved(),
        ]);
    }

    /** Save the data-retention settings. */
    public function saveRetention(Request $request)
    {
        foreach (Settings::schema() as $key => $spec) {
            if ($spec['group'] !== 'retention') {
                continue;
            }

            if ($spec['type'] === 'bool') {
                // An unchecked box submits nothing, so absence means false.
                Settings::set($key, $request->input($key) ? '1' : '0');
            } elseif ($request->has($key)) {
                Settings::set($key, $request->input($key));
            }
        }

        return redirect()->route('triage.settings')
            ->with('success', 'Retention settings saved.');
    }

    /**
     * Show what retention would delete, without deleting anything. Works
     * even while retention is switched off, so the blast radius can be
     * inspected before enabling it.
     */
    public function previewRetention()
    {
        return view('triage::retention_preview', [
            'rows'    => (new RetentionSweeper(true))->collect(),
            'total'   => RetentionSweeper::eligibleCount(),
            'cutoff'  => substr(RetentionSweeper::cutoff(), 0, 10),
            'months'  => Settings::get('retention_months'),
            'enabled' => Settings::get('retention_enabled'),
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
            return redirect()->route('triage.settings')->with('error', 'No user specified.');
        }

        $escalateTo = $request->input('escalate_to_user_id') ?: null;

        // Reject self-escalation and loops at save time rather than
        // discovering them when a ticket is already stuck mid-chain.
        if ($escalateTo && (int) $escalateTo === $userId) {
            return redirect()->back()->withInput()
                ->with('error', 'A user cannot escalate to themselves.');
        }

        if ($escalateTo) {
            $loop = TriageProfile::detectLoop($userId, $escalateTo, $mailboxId);
            if (!empty($loop)) {
                $names = array_map(function ($id) {
                    $u = \App\User::find($id);
                    return $u ? $u->getFullName() : ('User '.$id);
                }, $loop);

                return redirect()->back()->withInput()->with(
                    'error',
                    'That escalation target creates a loop: '.implode(' → ', $names)
                );
            }
        }

        TriageProfile::updateOrCreate(
            ['user_id' => $userId, 'mailbox_id' => $mailboxId],
            [
                'description'            => $request->input('description'),
                // Keywords are no longer saved or used: routing is the model
                // reasoning over the description. See TriageEngine::triage().
                'rotation_group'         => $request->input('rotation_group') ?: null,
                'escalate_to_user_id'    => $escalateTo,
                'escalate_after_minutes' => $request->input('escalate_after_minutes') ?: null,
                'available'              => (bool) $request->input('available'),
                'max_open'               => (int) $request->input('max_open'),
            ]
        );

        return redirect()
            ->route('triage.settings', ['mailbox_id' => $mailboxId])
            ->with('success', 'Saved '.(\App\User::find($userId)->getFullName() ?? 'profile').'.');
    }

    /** Remove an agent from triage routing entirely. */
    public function deleteProfile(Request $request)
    {
        TriageProfile::where('user_id', (int) $request->input('user_id'))
            ->where('mailbox_id', $request->input('mailbox_id') ?: null)
            ->delete();

        return redirect()
            ->route('triage.settings', ['mailbox_id' => $request->input('mailbox_id') ?: null])
            ->with('success', 'Profile removed.');
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
