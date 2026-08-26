<?php

namespace Modules\DOTRatings\Entities;

use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    protected $table = 'dot_ratings';

    protected $fillable = [
        'conversation_id', 'mailbox_id', 'customer_id', 'token',
        'close_reason', 'rating', 'comment',
        'email_sent_at', 'rated_at', 'reopened_at', 'expires_at',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    protected $dates = ['email_sent_at', 'rated_at', 'reopened_at', 'expires_at'];

    public function conversation()
    {
        return $this->belongsTo(\App\Conversation::class, 'conversation_id');
    }

    /**
     * Find a usable token.
     *
     * Unknown and expired are deliberately not distinguished - the caller
     * shows the same page for both, so a probe cannot learn whether a token
     * ever existed.
     */
    public static function findUsable($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $rating = self::where('token', $token)->first();

        if (!$rating || !$rating->expires_at || $rating->expires_at->isPast()) {
            return null;
        }

        return $rating;
    }

    /** Has a closure email gone out for this conversation within $days? */
    public static function sentRecently($conversationId, $days)
    {
        return self::where('conversation_id', (int) $conversationId)
            ->whereNotNull('email_sent_at')
            ->where('email_sent_at', '>=', date('Y-m-d H:i:s', strtotime("-{$days} days")))
            ->exists();
    }

    /** Headline numbers for the settings page and the ratings list. */
    public static function summary($days = 30)
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $sent = self::whereNotNull('email_sent_at')
            ->where('email_sent_at', '>=', $since)
            ->count();

        $rated = self::whereNotNull('rated_at')
            ->where('rated_at', '>=', $since)
            ->count();

        // AVG() over an empty set returns NULL, not 0 - so this stays null
        // and the view shows a dash rather than a misleading "0.0 stars".
        $average = self::whereNotNull('rated_at')
            ->where('rated_at', '>=', $since)
            ->avg('rating');

        $reopened = self::whereNotNull('reopened_at')
            ->where('reopened_at', '>=', $since)
            ->count();

        return [
            'sent'     => $sent,
            'rated'    => $rated,
            'average'  => $average === null ? null : round((float) $average, 2),
            'reopened' => $reopened,
            'response_rate' => $sent ? round(($rated / $sent) * 100, 1) : null,
        ];
    }

    /** Distribution of stars, 1-5, always with every key present. */
    public static function distribution($days = 30)
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $counts = self::whereNotNull('rated_at')
            ->where('rated_at', '>=', $since)
            ->selectRaw('rating, COUNT(*) AS total')
            ->groupBy('rating')
            ->pluck('total', 'rating')
            ->all();

        $out = [];
        for ($stars = 5; $stars >= 1; $stars--) {
            $out[$stars] = (int) ($counts[$stars] ?? 0);
        }

        return $out;
    }
}
