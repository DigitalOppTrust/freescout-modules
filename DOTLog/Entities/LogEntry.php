<?php

namespace Modules\DOTLog\Entities;

use Illuminate\Database\Eloquent\Model;

class LogEntry extends Model
{
    protected $table = 'dotlog_entries';

    /** Log rows are immutable; created_at is set explicitly on write. */
    public $timestamps = false;

    protected $fillable = [
        'event', 'level', 'conversation_id', 'mailbox_id', 'thread_id',
        'user_id', 'message', 'context', 'created_at',
    ];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    /** Distinct event keys, for the filter dropdown. */
    public static function eventKeys()
    {
        return self::select('event')->distinct()->orderBy('event')->pluck('event');
    }
}
