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

    /**
     * The agents involved in resolving each of the given conversations.
     *
     * "Involved" means: sent a published reply to the customer, or closed the
     * ticket. Notes, drafts and automatic closures (closed_by_user_id NULL)
     * do not count - the first two are invisible to the customer, and the
     * third has nobody to credit.
     *
     * One query for the whole page rather than one per row. Returns
     * [conversation_id => [User, ...]] in order of first involvement, with
     * the closer last if they never replied.
     */
    public static function handlersFor(array $conversationIds)
    {
        $conversationIds = array_values(array_filter(array_map('intval', $conversationIds)));
        if (!$conversationIds) {
            return [];
        }

        $replies = \DB::table('threads')
            ->whereIn('conversation_id', $conversationIds)
            ->where('type', \App\Thread::TYPE_MESSAGE)
            ->where('state', \App\Thread::STATE_PUBLISHED)
            ->whereNotNull('created_by_user_id')
            ->orderBy('created_at')
            ->get(['conversation_id', 'created_by_user_id']);

        $closers = \DB::table('conversations')
            ->whereIn('id', $conversationIds)
            ->whereNotNull('closed_by_user_id')
            ->pluck('closed_by_user_id', 'id');

        $ids = [];
        foreach ($replies as $row) {
            $ids[$row->conversation_id][$row->created_by_user_id] = true;
        }
        foreach ($closers as $conversationId => $userId) {
            $ids[$conversationId][$userId] = true;
        }

        if (!$ids) {
            return [];
        }

        $userIds = [];
        foreach ($ids as $perConversation) {
            $userIds += $perConversation;
        }

        $users = \App\User::whereIn('id', array_keys($userIds))
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($ids as $conversationId => $perConversation) {
            $out[$conversationId] = [];
            foreach (array_keys($perConversation) as $userId) {
                if (isset($users[$userId])) {
                    $out[$conversationId][] = $users[$userId];
                }
            }
        }

        return $out;
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
