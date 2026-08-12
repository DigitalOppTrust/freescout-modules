<?php

namespace Modules\Triage\Entities;

use Illuminate\Database\Eloquent\Model;

class TriageProfile extends Model
{
    protected $table = 'triage_profiles';

    protected $fillable = [
        'user_id',
        'mailbox_id',
        'description',
        'keywords',
        'rotation_group',
        'last_assigned_at',
        'escalate_to_user_id',
        'escalate_after_minutes',
        'available',
        'max_open',
    ];

    protected $casts = [
        'available' => 'boolean',
    ];

    protected $dates = ['last_assigned_at'];

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }

    public function escalateTo()
    {
        return $this->belongsTo(\App\User::class, 'escalate_to_user_id');
    }

    public function mailbox()
    {
        return $this->belongsTo(\App\Mailbox::class, 'mailbox_id');
    }

    public function userName()
    {
        return $this->user ? $this->user->getFullName() : ('User '.$this->user_id);
    }

    /**
     * Profiles eligible to receive routed tickets for a mailbox.
     *
     * A null mailbox_id means "all mailboxes", so those are always included.
     */
    public static function routable($mailboxId = null)
    {
        return self::where('available', true)
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->when($mailboxId, function ($q) use ($mailboxId) {
                return $q->where(function ($sub) use ($mailboxId) {
                    $sub->where('mailbox_id', $mailboxId)
                        ->orWhereNull('mailbox_id');
                });
            })
            ->get()
            ->filter(function ($p) {
                return !$p->isAtCapacity();
            })
            ->values();
    }

    /**
     * Collapse profiles into the choices offered to the model.
     *
     * Agents sharing a rotation group appear once, so the model chooses a
     * capability rather than a person. Rotation then picks the individual.
     *
     * @return array<string, \Illuminate\Support\Collection> keyed by choice id
     */
    public static function routingChoices($mailboxId = null)
    {
        $choices = [];

        foreach (self::routable($mailboxId) as $profile) {
            $key = $profile->rotation_group
                ? 'group:'.$profile->rotation_group
                : 'user:'.$profile->user_id;

            if (!isset($choices[$key])) {
                $choices[$key] = collect();
            }
            $choices[$key]->push($profile);
        }

        return $choices;
    }

    /**
     * Pick the agent who should take the next ticket for a choice.
     *
     * Least-recently-assigned wins; a profile never assigned takes priority.
     * Ties break on the lowest user id so the outcome is deterministic.
     */
    public static function pickFromChoice($profiles)
    {
        return $profiles
            ->sortBy(function ($p) {
                return [
                    $p->last_assigned_at ? $p->last_assigned_at->timestamp : 0,
                    $p->user_id,
                ];
            })
            ->first();
    }

    /** Stamp the rotation clock after this agent is assigned a ticket. */
    public function markAssigned()
    {
        $this->last_assigned_at = now();
        $this->save();
    }

    public function isAtCapacity()
    {
        if (!$this->max_open) {
            return false;
        }

        $open = \App\Conversation::where('user_id', $this->user_id)
            ->where('status', \App\Conversation::STATUS_ACTIVE)
            ->count();

        return $open >= $this->max_open;
    }

    public function keywordList()
    {
        if (!$this->keywords) {
            return [];
        }

        return array_values(array_filter(array_map(
            function ($k) { return trim(mb_strtolower($k)); },
            explode(',', $this->keywords)
        )));
    }

    public function escalateAfter()
    {
        return $this->escalate_after_minutes
            ?: (int) config('triage.escalate_after_minutes', 240);
    }

    /**
     * Walk the escalation chain to detect a loop before it is saved.
     * Returns the user ids forming the loop, or an empty array if clean.
     */
    public static function detectLoop($userId, $escalateToId, $mailboxId = null)
    {
        $seen = [(int) $userId];
        $next = (int) $escalateToId;
        $max  = (int) config('triage.max_escalation_depth', 3) + 2;

        for ($i = 0; $i < $max && $next; $i++) {
            if (in_array($next, $seen, true)) {
                $seen[] = $next;
                return $seen;
            }
            $seen[] = $next;

            $profile = self::where('user_id', $next)
                ->when($mailboxId, function ($q) use ($mailboxId) {
                    return $q->where(function ($sub) use ($mailboxId) {
                        $sub->where('mailbox_id', $mailboxId)->orWhereNull('mailbox_id');
                    });
                })
                ->first();

            $next = $profile ? (int) $profile->escalate_to_user_id : 0;
        }

        return [];
    }
}
