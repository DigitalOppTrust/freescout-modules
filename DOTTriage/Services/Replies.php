<?php

namespace Modules\DOTTriage\Services;

/**
 * Who spoke last - the agent or the customer?
 *
 * FreeScout decides by thread type: a reply written in the app is a
 * TYPE_MESSAGE, anything that arrives by email is a TYPE_CUSTOMER. Staff who
 * answer from their own mail client, with the support address in CC, break
 * that: their reply arrives by email, so it is stored as a customer message.
 * On such a ticket nothing looks answered - it never auto-closes, and the
 * escalation clock transfers it away from an agent who already dealt with it.
 *
 * So an emailed message counts as an agent reply when it came from an active
 * user's address - unless that user is the ticket's own requester. Staff do
 * raise tickets for themselves, and their messages there are the customer's
 * side of the exchange.
 *
 * Drafts are excluded throughout: an unsent reply is a TYPE_MESSAGE row too.
 */
class Replies
{
    /**
     * The most recent agent reply and customer message.
     *
     * @return array [agent thread|null, customer thread|null]
     */
    public static function latest($conversation)
    {
        $agent    = null;
        $customer = null;
        $staff    = self::staffEmails();

        $threads = $conversation->threads()
            ->whereIn('type', [\App\Thread::TYPE_CUSTOMER, \App\Thread::TYPE_MESSAGE])
            ->where('state', \App\Thread::STATE_PUBLISHED)
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($threads as $t) {
            if (self::isAgent($t, $conversation, $staff)) {
                $agent = $agent ?: $t;
            } else {
                $customer = $customer ?: $t;
            }
            if ($agent && $customer) {
                break;
            }
        }

        return [$agent, $customer];
    }

    /** Has an agent replied, in the app or by email, since $since? */
    public static function agentRepliedSince($conversation, $since)
    {
        $staff = self::staffEmails();

        $threads = $conversation->threads()
            ->whereIn('type', [\App\Thread::TYPE_CUSTOMER, \App\Thread::TYPE_MESSAGE])
            ->where('state', \App\Thread::STATE_PUBLISHED)
            ->where('created_at', '>=', $since)
            ->get();

        foreach ($threads as $t) {
            if (self::isAgent($t, $conversation, $staff)) {
                return true;
            }
        }

        return false;
    }

    /** Is this thread an agent speaking to the customer? */
    public static function isAgent($thread, $conversation, $staff = null)
    {
        if ((int) $thread->type === (int) \App\Thread::TYPE_MESSAGE) {
            return true;
        }

        return self::isStaffEmail($thread, $conversation, $staff);
    }

    /** A customer-type thread that is really a staff member replying by email. */
    public static function isStaffEmail($thread, $conversation, $staff = null)
    {
        if ((int) $thread->type !== (int) \App\Thread::TYPE_CUSTOMER) {
            return false;
        }

        // The requester's own messages are the customer side, staff or not.
        if ((int) $thread->created_by_customer_id === (int) $conversation->customer_id) {
            return false;
        }

        $from = strtolower(trim((string) $thread->from));
        if ($from === '') {
            return false;
        }

        $staff = $staff === null ? self::staffEmails() : $staff;

        return isset($staff[$from]);
    }

    /** Active users' addresses, lower-cased, as a lookup set. */
    public static function staffEmails()
    {
        $emails = \App\User::where('status', \App\User::STATUS_ACTIVE)->pluck('email');

        $set = [];
        foreach ($emails as $email) {
            $set[strtolower(trim((string) $email))] = true;
        }

        return $set;
    }
}
