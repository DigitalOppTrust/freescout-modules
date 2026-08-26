<?php

namespace Modules\DOTRatings\Services;

use Modules\DOTRatings\Entities\Rating;
use Modules\DOTRatings\Mail\ClosureNotification;

/**
 * Decides whether a closed conversation earns a closure email, and sends it.
 *
 * Every reason for not sending is a named guard that logs why. When somebody
 * asks "why didn't this customer get an email", the answer should be one grep
 * away rather than a re-reading of this file.
 *
 * The order of the guards matters: cheap checks that need no database work
 * come first, and the resend guard comes last because it is the only one that
 * queries.
 */
class ClosureNotifier
{
    /** Reasons a conversation reached us. Noise never does - see the provider. */
    const REASON_MANUAL     = 'manual';
    const REASON_INACTIVITY = 'inactivity';
    const REASON_RESOLVED   = 'resolved';

    /**
     * Send the closure email for a conversation, or explain why not.
     *
     * @return bool whether an email was sent
     */
    public function send($conversationId, $reason = self::REASON_MANUAL)
    {
        $conversation = \App\Conversation::find($conversationId);

        if (!$conversation) {
            return $this->skip($conversationId, 'conversation no longer exists');
        }

        // ── Settings guards ──────────────────────────────────────────
        if (!Settings::get('send_enabled')) {
            return $this->skip($conversationId, 'closure emails are switched off');
        }

        if ($reason === self::REASON_MANUAL && !Settings::get('send_on_manual')) {
            return $this->skip($conversationId, 'emails on agent closes are switched off');
        }

        if ($reason !== self::REASON_MANUAL && !Settings::get('send_on_auto')) {
            return $this->skip($conversationId, 'emails on automatic closes are switched off');
        }

        // ── State guards ─────────────────────────────────────────────
        // The job is queued, so the world may have moved on since the close:
        // an agent can reopen a ticket in the seconds before the worker picks
        // this up, and emailing "your ticket is closed" then would be wrong.
        if ((int) $conversation->status !== (int) \App\Conversation::STATUS_CLOSED) {
            return $this->skip($conversationId, 'conversation is no longer closed');
        }

        if ((int) $conversation->state !== (int) \App\Conversation::STATE_PUBLISHED) {
            return $this->skip($conversationId, 'conversation is a draft or deleted');
        }

        // ── Recipient guards ─────────────────────────────────────────
        $email = $conversation->customer_email;

        if (!$email) {
            return $this->skip($conversationId, 'no customer email address');
        }

        if ($this->isUnmailable($email)) {
            return $this->skip($conversationId, 'address does not accept mail ('.$email.')');
        }

        $customer = $conversation->customer;
        if (!$customer) {
            return $this->skip($conversationId, 'conversation has no customer record');
        }

        // ── Content guards ───────────────────────────────────────────
        // The anchor thread is what the email threads onto, so it must exist
        // regardless of the setting below.
        $anchor = $this->anchorThread($conversation);
        if (!$anchor) {
            return $this->skip($conversationId, 'no message to thread the email onto');
        }

        if (Settings::get('require_agent_reply') && !$this->hasAgentReply($conversation)) {
            return $this->skip($conversationId, 'no agent ever replied');
        }

        // ── Loop guard ───────────────────────────────────────────────
        $guardDays = (int) Settings::get('resend_guard_days');
        if (Rating::sentRecently($conversation->id, $guardDays)) {
            return $this->skip($conversationId,
                'a closure email already went out within the last '.$guardDays.' days');
        }

        return $this->dispatchEmail($conversation, $customer, $anchor, $email, $reason);
    }

    /**
     * Create the rating record, send the mail, and note it on the ticket.
     */
    protected function dispatchEmail($conversation, $customer, $anchor, $email, $reason)
    {
        $rating = Rating::create([
            'conversation_id' => $conversation->id,
            'mailbox_id'      => $conversation->mailbox_id,
            'customer_id'     => $customer->id,
            'token'           => bin2hex(random_bytes(32)),
            'close_reason'    => $reason,
            'expires_at'      => now()->addDays((int) Settings::get('token_valid_days')),
        ]);

        $mailbox = $conversation->mailbox;

        try {
            // Configure the mail driver from the mailbox's own SMTP settings,
            // exactly as core's SendAutoReply does. Without this the mail goes
            // out via the global driver and the From address is wrong.
            \App\Misc\Mail::setMailDriver($mailbox, null, $conversation);

            \Mail::to([['name' => $customer->getFullName(), 'email' => $email]])
                ->send(new ClosureNotification($conversation, $mailbox, $customer, $rating, $anchor, $reason));
        } catch (\Throwable $e) {
            // email_sent_at stays null, so the resend guard will not count
            // this attempt and a later close can try again.
            \Log::error('[Ratings] could not email closure for conversation '
                .$conversation->id.': '.$e->getMessage());

            return false;
        }

        $rating->email_sent_at = now();
        $rating->save();

        $this->note($conversation, 'Closure email sent to '.$email
            .' with a rating link. Replying to it reopens this ticket.');

        \Log::info('[Ratings] closure email sent for conversation '
            .$conversation->id.' ('.$reason.')');

        return true;
    }

    /**
     * The thread the closure email hangs off.
     *
     * Newest real message, agent or customer: line items and notes are
     * internal and have no Message-ID a customer's mail client would know.
     *
     * The state filter is essential. An agent who starts a reply and never
     * sends it leaves a draft thread of TYPE_MESSAGE behind, and a draft is
     * usually the newest thread on the conversation - so without this the
     * draft wins. Drafts have no message_id, which would silently drop the
     * In-Reply-To header and stop the email threading in the customer's
     * inbox: the one thing this whole scheme exists to get right.
     */
    protected function anchorThread($conversation)
    {
        return $conversation->threads()
            ->whereIn('type', [\App\Thread::TYPE_MESSAGE, \App\Thread::TYPE_CUSTOMER])
            ->where('state', \App\Thread::STATE_PUBLISHED)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Has an agent actually sent the customer something?
     *
     * Published only - an unsent draft is precisely the case this guard
     * exists to catch. Counting it would mean asking someone to rate support
     * they never received.
     */
    protected function hasAgentReply($conversation)
    {
        return $conversation->threads()
            ->where('type', \App\Thread::TYPE_MESSAGE)
            ->where('state', \App\Thread::STATE_PUBLISHED)
            ->exists();
    }

    /**
     * Addresses that should never be written to.
     *
     * Mailing a no-reply or a bounce handler at best goes nowhere and at
     * worst starts a mail loop between two robots.
     */
    protected function isUnmailable($email)
    {
        return (bool) preg_match(
            '/(^|[._-])(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounce[sd]?)([._-]|@)/i',
            $email
        );
    }

    protected function skip($conversationId, $why)
    {
        \Log::info('[Ratings] no closure email for conversation '.$conversationId.': '.$why);

        return false;
    }

    /**
     * Leave an internal note so agents can see what the customer was told.
     * Modelled on the Triage module's note(): a failure here must never
     * prevent the email that has already gone out from being recorded.
     */
    protected function note($conversation, $text)
    {
        try {
            $thread = new \App\Thread();
            $thread->conversation_id = $conversation->id;
            $thread->type            = \App\Thread::TYPE_NOTE;
            $thread->status          = \App\Thread::STATUS_NOCHANGE;
            $thread->state           = \App\Thread::STATE_PUBLISHED;
            $thread->body            = '<strong>Ratings</strong><br>'.e($text);
            $thread->source_via      = \App\Thread::PERSON_USER;
            $thread->source_type     = \App\Thread::SOURCE_TYPE_WEB;
            $thread->customer_id     = $conversation->customer_id;
            $thread->save();
        } catch (\Throwable $e) {
            \Log::warning('[Ratings] could not note closure email on '
                .$conversation->id.': '.$e->getMessage());
        }
    }
}
