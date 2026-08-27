<?php

namespace Modules\DOTTriage\Services;

use Modules\DOTTriage\Entities\TriageProfile;
use Modules\DOTTriage\Entities\TriageEscalation;

/**
 * The escalation clock: nudge, then transfer, a ticket nobody has answered.
 *
 * One row per conversation in triage_escalations, active while the assignee
 * owes the customer a reply. The clock starts when a ticket is assigned (by
 * triage or by a person) and again whenever the customer writes back; it
 * stops when the assignee replies or the ticket closes. The hourly sweep
 * walks the active rows and acts on the ones past their window:
 *
 *   stage 1  the profile's escalation target is emailed and a note is left
 *   stage 2  after a further grace period, ownership transfers to the target
 *            and a new clock starts for them, one hop deeper in the chain
 *
 * Working time throughout, so nothing escalates over a weekend. Depth and
 * chain bound the hops so a ticket can never ping-pong between two people.
 *
 * Until 2026-08-27 none of this ran: the table, entity and settings existed
 * but nothing ever created a row. Every profile's "escalate to" was a
 * promise the system did not keep.
 */
class Escalator
{
    /**
     * True while the sweep itself is reassigning, so the provider's
     * conversation.user_changed hook can tell an escalation transfer from a
     * human override and not count it against triage accuracy.
     */
    public static $transferring = false;

    /**
     * Start the clock for the current assignee.
     *
     * Idempotent per conversation: a second call replaces the row, which is
     * what a reassignment or a fresh customer reply needs. Returns null when
     * there is nothing to time - no assignee, ticket not open, or the
     * assignee's profile names nobody to escalate to.
     */
    public static function start($conversation, $depth = 0, $chain = null)
    {
        if (!$conversation || !$conversation->user_id) {
            return null;
        }

        if (!self::isOpen($conversation)) {
            self::stop($conversation->id);
            return null;
        }

        $profile    = self::profileFor($conversation->user_id, $conversation->mailbox_id);
        $escalateTo = $profile ? (int) $profile->escalate_to_user_id : 0;
        $after      = $profile ? $profile->escalateAfter()
                               : (int) config('triage.escalate_after_minutes', 1440);

        // Nobody to escalate to: the clock would tick towards nothing.
        if (!$escalateTo || $escalateTo === (int) $conversation->user_id) {
            self::stop($conversation->id);
            return null;
        }

        // Never escalate back to someone already in this chain.
        $chainIds = $chain ? array_map('intval', array_filter(explode(',', $chain))) : [];
        if (in_array($escalateTo, $chainIds, true)) {
            self::stop($conversation->id);
            return null;
        }

        return TriageEscalation::updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'assigned_user_id'       => (int) $conversation->user_id,
                'clock_started_at'       => now(),
                'escalate_after_minutes' => $after,
                'escalate_to_user_id'    => $escalateTo,
                'notified_at'            => null,
                'reassigned_at'          => null,
                'depth'                  => (int) $depth,
                'chain'                  => $chain,
                'active'                 => true,
            ]
        );
    }

    /**
     * A customer wrote back on an assigned ticket. If no clock is running
     * the assignee now owes a reply, so one starts. If one is already
     * running it is left alone: a customer chasing must not reset the SLA.
     */
    public static function rearm($conversation)
    {
        if (!$conversation || !$conversation->user_id) {
            return null;
        }

        $active = TriageEscalation::where('conversation_id', $conversation->id)
            ->where('active', true)
            ->exists();

        return $active ? null : self::start($conversation);
    }

    /** Stop the clock - the assignee replied, or the ticket closed. */
    public static function stop($conversationId)
    {
        TriageEscalation::where('conversation_id', (int) $conversationId)
            ->where('active', true)
            ->update(['active' => false]);
    }

    /**
     * Act on every clock that has run out.
     *
     * @return array rows describing what was (or would be) done
     */
    public function sweep($dryRun = false, $limit = 100)
    {
        $results = [];

        $rows = TriageEscalation::where('active', true)
            ->orderBy('clock_started_at')
            ->limit(500)
            ->get();

        foreach ($rows as $esc) {
            if (count($results) >= $limit) {
                break;
            }

            $c = \App\Conversation::find($esc->conversation_id);

            // Anything that makes the clock meaningless deactivates it: the
            // ticket closed, was deleted, or now belongs to someone else.
            if (!$c || !self::isOpen($c) || (int) $c->user_id !== (int) $esc->assigned_user_id) {
                if (!$dryRun) {
                    $esc->resolve();
                }
                continue;
            }

            // Belt and braces for a missed user_replied hook: an agent reply
            // since the clock started means the customer has been answered.
            $replied = $c->threads()
                ->where('type', \App\Thread::TYPE_MESSAGE)
                ->where('state', \App\Thread::STATE_PUBLISHED)
                ->where('created_at', '>=', $esc->clock_started_at)
                ->exists();

            if ($replied) {
                if (!$dryRun) {
                    $esc->resolve();
                }
                continue;
            }

            if ($esc->isDueForReassign()) {
                $results[] = $this->describe($esc, $c, 'transfer');
                if (!$dryRun) {
                    $this->transfer($esc, $c);
                }
                continue;
            }

            if ($esc->isDueForNotify()) {
                $results[] = $this->describe($esc, $c, 'notify');
                if (!$dryRun) {
                    $this->notify($esc, $c);
                }
            }
        }

        return $results;
    }

    /** Stage 1: tell the escalation target, leave the ticket where it is. */
    protected function notify(TriageEscalation $esc, $conversation)
    {
        $target   = \App\User::find($esc->escalate_to_user_id);
        $assignee = \App\User::find($esc->assigned_user_id);

        if (!$target) {
            $esc->resolve();
            return;
        }

        $elapsed = BusinessTime::describe($esc->minutesElapsed());
        $grace   = BusinessTime::describe((int) config('triage.reassign_after_minutes', 120));

        $this->note($conversation, sprintf(
            'Escalation — no reply to the customer for %s (window %s). %s notified; '
            .'the ticket transfers to them in %s if still unanswered.',
            $elapsed,
            BusinessTime::describe($esc->escalate_after_minutes),
            $target->getFullName(),
            $grace
        ));

        $this->email($target, $conversation, sprintf(
            "%s has not replied to this ticket for %s (their window is %s).\n\n"
            ."If it is still unanswered in %s it will be transferred to you.\n\n"
            ."#%s  %s\nFrom: %s\n\n%s",
            $assignee ? $assignee->getFullName() : 'The assignee',
            $elapsed,
            BusinessTime::describe($esc->escalate_after_minutes),
            $grace,
            $conversation->number,
            $conversation->subject,
            $conversation->customer_email,
            $this->link($conversation)
        ), 'Escalation: #'.$conversation->number.' '.$conversation->subject);

        $esc->notified_at = now();
        $esc->save();

        $this->dotlog('triage.escalated', 'Escalation notice sent to '.$target->getFullName()
            .' after '.$elapsed.' without a reply', $conversation, ['user_id' => $target->id]);

        \Log::info('[Triage] escalation notify: conversation '.$conversation->id
            .' -> user '.$target->id);
    }

    /** Stage 2: hand the ticket to the target and start their clock. */
    protected function transfer(TriageEscalation $esc, $conversation)
    {
        $target   = \App\User::find($esc->escalate_to_user_id);
        $assignee = \App\User::find($esc->assigned_user_id);
        $maxDepth = (int) config('triage.max_escalation_depth', 3);

        if (!$target) {
            $esc->resolve();
            return;
        }

        if ($esc->depth + 1 > $maxDepth) {
            $this->note($conversation, sprintf(
                'Escalation — chain limit (%d hops) reached; the ticket stays with %s. '
                .'Someone needs to pick this up by hand.',
                $maxDepth,
                $assignee ? $assignee->getFullName() : 'the current assignee'
            ));
            $esc->resolve();
            return;
        }

        $esc->addToChain($esc->assigned_user_id);
        $esc->addToChain($target->id);

        // Direct write, as triage's own assign() does: changeUser() would
        // need an acting user and would file the line item under them.
        self::$transferring = true;
        try {
            $conversation->user_id = $target->id;
            $conversation->updateFolder();
            $conversation->save();
            $conversation->mailbox->updateFoldersCounters();
        } finally {
            self::$transferring = false;
        }

        $this->note($conversation, sprintf(
            'Escalation — transferred from %s to %s: no reply to the customer for %s.',
            $assignee ? $assignee->getFullName() : 'the previous assignee',
            $target->getFullName(),
            BusinessTime::describe($esc->minutesElapsed())
        ));

        // Same reasoning as TriageConversation::assign(): a direct user_id
        // write skips the event core uses to email the new assignee.
        try {
            self::registerAssigned($conversation);
        } catch (\Throwable $e) {
            \Log::warning('[Triage] could not register escalation assignment notification for '
                .$conversation->id.': '.$e->getMessage());
        }

        $this->email($target, $conversation, sprintf(
            "This ticket has been transferred to you: %s did not reply to the customer for %s.\n\n"
            ."#%s  %s\nFrom: %s\n\n%s",
            $assignee ? $assignee->getFullName() : 'the previous assignee',
            BusinessTime::describe($esc->minutesElapsed()),
            $conversation->number,
            $conversation->subject,
            $conversation->customer_email,
            $this->link($conversation)
        ), 'Transferred to you: #'.$conversation->number.' '.$conversation->subject);

        $esc->reassigned_at = now();
        $esc->active        = false;
        $esc->save();

        $this->dotlog('triage.escalated', 'Escalation transferred the ticket from '
            .($assignee ? $assignee->getFullName() : '?').' to '.$target->getFullName(),
            $conversation, ['user_id' => $target->id]);

        \Log::info('[Triage] escalation transfer: conversation '.$conversation->id
            .' user '.$esc->assigned_user_id.' -> '.$target->id);

        // The target now owes the reply. Their own profile decides the next hop.
        self::start($conversation, $esc->depth + 1, $esc->chain);
    }

    /**
     * Register the "assigned" notification so core emails the new owner.
     *
     * registerEvent() gained an $extra_data parameter in later FreeScout
     * versions, moving $process_now from 4th to 5th position. Passing true
     * in the wrong slot does not error - it just never sends - so the
     * signature is checked rather than assumed. process_now matters: this
     * runs from cron, where nothing else flushes the event queue.
     */
    public static function registerAssigned($conversation)
    {
        $ref = new \ReflectionMethod(\App\Subscription::class, 'registerEvent');

        if ($ref->getNumberOfParameters() >= 5) {
            \App\Subscription::registerEvent(
                \App\Subscription::EVENT_TYPE_ASSIGNED, $conversation, null, [], true);
        } else {
            \App\Subscription::registerEvent(
                \App\Subscription::EVENT_TYPE_ASSIGNED, $conversation, null, true);
        }
    }

    /**
     * Start clocks for tickets that were already assigned and unanswered
     * before escalation existed. Nothing else would ever start one for
     * them: the hooks only fire on new assignments and new replies.
     *
     * The clock is backdated to the customer's last message, because that
     * is when the reply became owed - starting it now would grant every
     * stalled ticket a fresh window it has not earned.
     */
    public function seed($dryRun = false)
    {
        $results = [];

        $conversations = \App\Conversation::whereNotNull('user_id')
            ->whereIn('status', [\App\Conversation::STATUS_ACTIVE, \App\Conversation::STATUS_PENDING])
            ->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->whereNotExists(function ($q) {
                $q->select(\DB::raw(1))->from('triage_escalations')
                  ->whereRaw('triage_escalations.conversation_id = conversations.id')
                  ->where('active', 1);
            })
            ->orderBy('id')
            ->get();

        foreach ($conversations as $c) {
            $lastCustomer = $c->threads()->where('type', \App\Thread::TYPE_CUSTOMER)
                ->orderBy('created_at', 'desc')->first();
            $lastAgent = $c->threads()->where('type', \App\Thread::TYPE_MESSAGE)
                ->where('state', \App\Thread::STATE_PUBLISHED)
                ->orderBy('created_at', 'desc')->first();

            if (!$lastCustomer || ($lastAgent && $lastAgent->created_at >= $lastCustomer->created_at)) {
                continue;   // answered, or nothing to answer
            }

            $profile = self::profileFor($c->user_id, $c->mailbox_id);
            if (!$profile || !$profile->escalate_to_user_id) {
                continue;
            }

            $results[] = [
                'number'   => $c->number,
                'subject'  => $c->subject,
                'assignee' => optional(\App\User::find($c->user_id))->getFullName(),
                'since'    => (string) $lastCustomer->created_at,
            ];

            if (!$dryRun) {
                $row = self::start($c);
                if ($row) {
                    $row->clock_started_at = $lastCustomer->created_at;
                    $row->save();
                }
            }
        }

        return $results;
    }

    protected function describe(TriageEscalation $esc, $conversation, $action)
    {
        $target = \App\User::find($esc->escalate_to_user_id);

        return [
            'action'   => $action,
            'number'   => $conversation->number,
            'subject'  => $conversation->subject,
            'assignee' => optional(\App\User::find($esc->assigned_user_id))->getFullName(),
            'target'   => $target ? $target->getFullName() : '?',
            'elapsed'  => BusinessTime::describe($esc->minutesElapsed()),
            'window'   => BusinessTime::describe($esc->escalate_after_minutes),
            'depth'    => $esc->depth,
        ];
    }

    /**
     * Email one person directly. Not the subscription pipeline: that sends
     * only what each user subscribed to, and "someone else's ticket is
     * stuck" is not one of its events. Plain text, system mail settings.
     */
    protected function email($user, $conversation, $body, $subject)
    {
        if (!config('triage.escalation_email', true) || !$user || !$user->email) {
            return;
        }

        try {
            \MailHelper::setSystemMailDriver();

            \Mail::raw($body, function ($message) use ($user, $subject) {
                $message->to($user->email)->subject($subject);
            });
        } catch (\Throwable $e) {
            \Log::warning('[Triage] escalation email to '.$user->email.' failed: '.$e->getMessage());
            $this->dotlog('triage.escalated', 'Escalation email to '.$user->email
                .' FAILED: '.$e->getMessage(), $conversation, ['level' => 'error']);
        }
    }

    protected function link($conversation)
    {
        try {
            return route('conversations.view', ['id' => $conversation->id]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected static function isOpen($conversation)
    {
        return in_array((int) $conversation->status, [
            \App\Conversation::STATUS_ACTIVE,
            \App\Conversation::STATUS_PENDING,
        ], true) && (int) $conversation->state !== \App\Conversation::STATE_DELETED;
    }

    /** The profile that applies to a user in a mailbox: specific first, then global. */
    protected static function profileFor($userId, $mailboxId)
    {
        return TriageProfile::where('user_id', (int) $userId)
            ->where(function ($q) use ($mailboxId) {
                $q->where('mailbox_id', $mailboxId)->orWhereNull('mailbox_id');
            })
            ->orderByRaw('mailbox_id IS NULL')
            ->first();
    }

    protected function note($conversation, $text)
    {
        try {
            $thread = new \App\Thread();
            $thread->conversation_id = $conversation->id;
            $thread->user_id         = null;
            $thread->type            = \App\Thread::TYPE_NOTE;
            $thread->status          = \App\Thread::STATUS_NOCHANGE;
            $thread->state           = \App\Thread::STATE_PUBLISHED;
            $thread->body            = '<strong>Triage</strong><br>'.e($text);
            $thread->source_via      = \App\Thread::PERSON_USER;
            $thread->source_type     = \App\Thread::SOURCE_TYPE_WEB;
            $thread->customer_id     = $conversation->customer_id;
            $thread->created_by_user_id = null;
            $thread->save();
        } catch (\Throwable $e) {
            \Log::warning('[Triage] could not add escalation note to conversation '
                .$conversation->id.': '.$e->getMessage());
        }
    }

    protected function dotlog($event, $message, $conversation, array $extra = [])
    {
        if (!class_exists(\Modules\DOTLog\Services\DotLog::class)) {
            return;
        }

        \Modules\DOTLog\Services\DotLog::write($event, $message,
            $extra + ['conversation' => $conversation]);
    }
}
