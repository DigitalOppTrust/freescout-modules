<?php

namespace Modules\Triage\Entities;

use Illuminate\Database\Eloquent\Model;

class TriageProfile extends Model
{
    protected $table = 'triage_profiles';

    protected $fillable = [
        'user_id',
        'description',
        'keywords',
        'escalate_to_user_id',
        'escalate_after_minutes',
        'available',
        'max_open',
    ];

    protected $casts = [
        'available' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }

    public function escalateTo()
    {
        return $this->belongsTo(\App\User::class, 'escalate_to_user_id');
    }

    /**
     * Profiles eligible to receive routed tickets: available, with a
     * description to reason over, and under their open-ticket cap.
     */
    public static function routable()
    {
        return self::where('available', true)
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->get()
            ->filter(function ($p) {
                return !$p->isAtCapacity();
            });
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
}
