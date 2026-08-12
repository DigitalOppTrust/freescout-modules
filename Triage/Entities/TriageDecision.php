<?php

namespace Modules\Triage\Entities;

use Illuminate\Database\Eloquent\Model;

class TriageDecision extends Model
{
    protected $table = 'triage_decisions';

    protected $fillable = [
        'conversation_id', 'mailbox_id', 'suggested_user_id', 'confidence',
        'reasoning', 'method', 'model', 'tokens_in', 'tokens_out',
        'duration_ms', 'applied', 'overridden_by_user_id',
        'overridden_to_user_id', 'overridden_at', 'error',
    ];

    protected $casts = [
        'applied'    => 'boolean',
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

    /** API calls made today, for the daily budget check. */
    public static function callsToday()
    {
        return self::whereDate('created_at', date('Y-m-d'))
            ->where('method', 'model')
            ->count();
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
