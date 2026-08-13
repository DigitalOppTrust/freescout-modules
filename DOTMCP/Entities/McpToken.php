<?php

namespace Modules\DOTMCP\Entities;

use Illuminate\Database\Eloquent\Model;

class McpToken extends Model
{
    protected $table = 'mcp_tokens';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'client_id', 'scopes', 'revoked', 'access_level',
        'expires_at', 'last_used_at', 'last_used_ip', 'use_count',
    ];

    protected $casts = ['revoked' => 'boolean'];
    protected $dates = ['expires_at', 'last_used_at'];

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }

    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isUsable()
    {
        return !$this->revoked && !$this->isExpired();
    }

    /** Tokens currently valid, for the settings page. */
    public static function active()
    {
        return self::with('user')
            ->where('revoked', false)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('last_used_at', 'desc')
            ->get();
    }

    /** Revoke every token belonging to a user, e.g. when access is withdrawn. */
    public static function revokeForUser($userId)
    {
        return self::where('user_id', (int) $userId)
            ->where('revoked', false)
            ->update(['revoked' => true, 'updated_at' => now()]);
    }
}
