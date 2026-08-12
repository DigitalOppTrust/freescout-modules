<?php

namespace Modules\DOTTriage\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\DOTTriage\Services\BusinessTime;

class TriageEscalation extends Model
{
    protected $table = 'triage_escalations';

    protected $fillable = [
        'conversation_id', 'assigned_user_id', 'clock_started_at',
        'escalate_after_minutes', 'escalate_to_user_id', 'notified_at',
        'reassigned_at', 'depth', 'chain', 'active',
    ];

    protected $casts = ['active' => 'boolean'];

    protected $dates = ['clock_started_at', 'notified_at', 'reassigned_at'];

    public function conversation()
    {
        return $this->belongsTo(\App\Conversation::class, 'conversation_id');
    }

    /** Users already escalated through, so a chain never revisits someone. */
    public function chainIds()
    {
        if (!$this->chain) {
            return [];
        }

        return array_map('intval', array_filter(explode(',', $this->chain)));
    }

    public function addToChain($userId)
    {
        $ids = $this->chainIds();
        if (!in_array((int) $userId, $ids, true)) {
            $ids[] = (int) $userId;
        }
        $this->chain = implode(',', $ids);
    }

    /**
     * Working minutes elapsed since the clock started.
     *
     * Counts business time, not wall-clock: a ticket that sits over a weekend
     * has not consumed its SLA, because nobody was going to answer it.
     */
    public function minutesElapsed()
    {
        if (!$this->clock_started_at) {
            return 0;
        }

        return BusinessTime::minutesBetween($this->clock_started_at, new \DateTimeImmutable());
    }

    /** When this ticket will escalate, for display. */
    public function escalatesAt()
    {
        if (!$this->clock_started_at) {
            return null;
        }

        return BusinessTime::addMinutes($this->clock_started_at, $this->escalate_after_minutes);
    }

    /** Stage 1: notify the escalation target. */
    public function isDueForNotify()
    {
        return $this->active
            && !$this->notified_at
            && $this->escalate_to_user_id
            && $this->minutesElapsed() >= $this->escalate_after_minutes;
    }

    /**
     * Stage 2: transfer ownership, but only after a second window has passed
     * since the notification - the target deserves a chance to act first.
     */
    public function isDueForReassign()
    {
        if (!$this->active || !$this->notified_at || $this->reassigned_at) {
            return false;
        }

        $grace = (int) config('triage.reassign_after_minutes', 120);

        return BusinessTime::minutesBetween($this->notified_at, new \DateTimeImmutable()) >= $grace;
    }

    /**
     * Stop the clock. Called when the assignee replies to the customer.
     */
    public function resolve()
    {
        $this->active = false;
        $this->save();
    }
}
