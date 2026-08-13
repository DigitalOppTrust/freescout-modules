<?php

namespace Modules\DOTTriage\Entities;

use Illuminate\Database\Eloquent\Model;

class TriageDecision extends Model
{
    protected $table = 'triage_decisions';

    protected $fillable = [
        'conversation_id', 'mailbox_id', 'suggested_user_id', 'confidence',
        'reasoning', 'method', 'model', 'tokens_in', 'tokens_out',
        'duration_ms', 'applied', 'overridden_by_user_id',
        'overridden_to_user_id', 'overridden_at', 'error',
        'noise_category', 'closed', 'close_reason', 'reopened_by_user_id', 'reopened_at',
    ];

    protected $casts = [
        'applied'    => 'boolean',
        'closed'     => 'boolean',
        'confidence' => 'float',
    ];

    protected $dates = ['overridden_at'];

    public function conversation()
    {
        return $this->belongsTo(\App\Conversation::class, 'conversation_id');
    }

    public function suggestedUser()
    {
        return $this->belongsTo(\App\User::class, 'suggested_user_id');
    }

    /** Conversations closed as non-support, grouped by category. */
    public static function noiseCounts($days = 30)
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return self::where('closed', true)
            ->where('created_at', '>=', $since)
            ->selectRaw('noise_category, COUNT(*) AS total')
            ->groupBy('noise_category')
            ->pluck('total', 'noise_category')
            ->all();
    }

    /** How many closures a human later reopened - the false-positive rate. */
    public static function noiseReopened($days = 30)
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return self::where('closed', true)
            ->where('created_at', '>=', $since)
            ->whereNotNull('reopened_by_user_id')
            ->count();
    }

    /** API calls made today, for the daily budget check. */
    public static function callsToday()
    {
        return self::whereDate('created_at', date('Y-m-d'))
            ->where('method', 'model')
            ->count();
    }

    /**
     * Per-user triage counts, keyed by user id.
     *
     * Counted from suggested_user_id rather than the conversation's current
     * assignee: this measures what triage *decided*, which is the question.
     * A ticket later reassigned by a human still counts as a triage to the
     * original suggestion, and shows up separately as an override.
     *
     * @return array<int, array{total:int, applied:int, overridden:int, last_at:?string}>
     */
    public static function countsByUser($days = null)
    {
        $q = self::selectRaw('suggested_user_id AS uid')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(applied) AS applied')
            ->selectRaw('SUM(overridden_by_user_id IS NOT NULL) AS overridden')
            ->selectRaw('MAX(created_at) AS last_at')
            ->whereNotNull('suggested_user_id');

        if ($days) {
            $q->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-{$days} days")));
        }

        $out = [];
        foreach ($q->groupBy('suggested_user_id')->get() as $row) {
            $out[(int) $row->uid] = [
                'total'      => (int) $row->total,
                'applied'    => (int) $row->applied,
                'overridden' => (int) $row->overridden,
                'last_at'    => $row->last_at,
            ];
        }

        return $out;
    }

    /** Recent decisions for one user, for the agent detail page. */
    public static function forUser($userId, $limit = 10)
    {
        return self::where('suggested_user_id', (int) $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Routing accuracy over a window: of the decisions that were applied and
     * subsequently reviewed, how many were left alone by a human.
     *
     * Deliberately counts only *applied* decisions - suggestions nobody acted
     * on say nothing about whether the model was right.
     */
    public static function accuracy($days = 30)
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $applied = self::where('applied', true)
            ->where('created_at', '>=', $since)
            ->count();

        if (!$applied) {
            return null;
        }

        $overridden = self::where('applied', true)
            ->where('created_at', '>=', $since)
            ->whereNotNull('overridden_by_user_id')
            ->count();

        return [
            'applied'    => $applied,
            'overridden' => $overridden,
            'accuracy'   => round((($applied - $overridden) / $applied) * 100, 1),
        ];
    }
}
