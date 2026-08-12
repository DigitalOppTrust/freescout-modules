<?php

namespace Modules\DOTTriage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\DOTTriage\Entities\TriageProfile;
use Modules\DOTTriage\Services\TriageEngine;
use Modules\DOTTriage\Services\NoiseDetector;
use Modules\DOTTriage\Entities\TriageDecision;

/**
 * Triage one conversation.
 *
 * Queued deliberately: an API call takes ~1s, and running it inline during
 * the email fetch cycle would slow mail collection and risk timeouts on a
 * mailbox with a backlog. FreeScout already runs a queue worker.
 *
 * Stores the conversation id rather than the model so the job payload stays
 * small and always reads current state when it runs.
 */
class TriageConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** No retries: a repeated API call costs money and a failed triage is
     *  recoverable by hand. The decision row records why it failed. */
    public $tries = 1;

    public $timeout = 120;

    protected $conversationId;

    public function __construct($conversationId)
    {
        $this->conversationId = (int) $conversationId;
    }

    public function handle()
    {
        if (!config('triage.enabled')) {
            return;
        }

        $conversation = \App\Conversation::find($this->conversationId);
        if (!$conversation) {
            return;
        }

        // State can change between queueing and running - someone may have
        // picked the ticket up in the meantime. Re-check rather than assume.
        if ($conversation->user_id) {
            \Log::info('[Triage] conversation '.$conversation->id.' already assigned, skipping');
            return;
        }

        // Belt and braces: even with the dispatch lock, never write a second
        // decision for a conversation that already has one.
        $existing = \Modules\DOTTriage\Entities\TriageDecision::where('conversation_id', $conversation->id)
            ->whereNull('error')
            ->exists();
        if ($existing) {
            \Log::info('[Triage] conversation '.$conversation->id.' already has a decision, skipping');
            return;
        }

        // Non-support mail is identified from headers alone, before any API
        // call - an auto-reply or newsletter should cost nothing to discard.
        if ($this->handleNoise($conversation)) {
            return;
        }

        $decision = (new TriageEngine())->triage($conversation);

        if ($decision->error) {
            \Log::warning('[Triage] conversation '.$conversation->id.' failed: '.$decision->error);
            $this->addNote($conversation, 'Triage failed: '.$decision->error);
            return;
        }

        // The model can also conclude this is not a support request, for mail
        // the header rules could not settle (service notifications, marketing
        // from senders that do not set Precedence).
        if ($decision->noise_category === 'not_support') {
            $decision->closed = true;
            $decision->save();
            $this->closeAsNoise($conversation, 'not_support', $decision->reasoning);
            \Log::info('[Triage] closed conversation '.$conversation->id.' as not_support (model)');
            return;
        }

        if (!$decision->suggested_user_id) {
            $this->addNote(
                $conversation,
                'Triage found no clear match. '.($decision->reasoning ?: '')
            );
            return;
        }

        $profile = $this->resolveAssignee($decision, $conversation);
        if (!$profile) {
            $this->addNote($conversation, 'Triage suggested an agent who is no longer routable.');
            return;
        }

        $threshold  = (float) config('triage.confidence_threshold', 0.75);
        $confident  = $decision->confidence === null || $decision->confidence >= $threshold;
        $autoAssign = (bool) config('triage.auto_assign', false);

        if ($autoAssign && $confident) {
            $this->assign($conversation, $profile, $decision);
        } else {
            $this->suggest($conversation, $profile, $decision, $confident, $threshold);
        }
    }

    /**
     * Close the conversation if it is not a support request.
     *
     * @return bool true when handled and triage should stop
     */
    protected function handleNoise($conversation)
    {
        $thread = $conversation->threads()
            ->where('type', \App\Thread::TYPE_CUSTOMER)
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$thread) {
            return false;
        }

        $result = (new NoiseDetector())->classify($thread, $conversation->mailbox);

        if (!$result['noise']) {
            return false;
        }

        TriageDecision::create([
            'conversation_id' => $conversation->id,
            'mailbox_id'      => $conversation->mailbox_id,
            'method'          => 'headers',
            'noise_category'  => $result['category'],
            'reasoning'       => $result['reason'],
            'confidence'      => 1.0,
            'applied'         => false,
            'closed'          => true,
        ]);

        $this->closeAsNoise($conversation, $result['category'], $result['reason']);

        \Log::info('[Triage] closed conversation '.$conversation->id
            .' as '.$result['category']);

        return true;
    }

    /**
     * Close a conversation and explain why.
     *
     * Closed rather than deleted or marked spam: it stays searchable, a human
     * can reopen it, and calling an out-of-office "spam" would be wrong.
     */
    protected function closeAsNoise($conversation, $category, $reason)
    {
        $this->addNote($conversation, sprintf(
            'Closed automatically — %s. %s',
            NoiseDetector::label($category),
            $reason
        ));

        $conversation->status = \App\Conversation::STATUS_CLOSED;
        $conversation->closed_at = now();
        $conversation->save();
    }

    /**
     * Turn the model's choice into an actual person.
     *
     * The model picks a capability; where several agents share a rotation
     * group, this picks the individual by least-recently-assigned so work is
     * spread evenly. The model has no view of workload, so it cannot do this.
     */
    protected function resolveAssignee($decision, $conversation)
    {
        $chosen = TriageProfile::routable($conversation->mailbox_id)
            ->firstWhere('user_id', $decision->suggested_user_id);

        if (!$chosen) {
            return null;
        }

        if (!$chosen->rotation_group) {
            return $chosen;
        }

        $peers = TriageProfile::routable($conversation->mailbox_id)
            ->where('rotation_group', $chosen->rotation_group);

        return TriageProfile::pickFromChoice($peers) ?: $chosen;
    }

    protected function assign($conversation, $profile, $decision)
    {
        $conversation->user_id = $profile->user_id;
        $conversation->save();

        $profile->markAssigned();

        $decision->suggested_user_id = $profile->user_id;
        $decision->applied = true;
        $decision->save();

        $this->addNote($conversation, sprintf(
            'Assigned to %s by triage (%s%s). %s',
            $profile->userName(),
            $decision->method,
            $decision->confidence !== null
                ? ', confidence '.number_format($decision->confidence, 2)
                : '',
            $decision->reasoning
        ));

        \Log::info('[Triage] assigned conversation '.$conversation->id.' to user '.$profile->user_id);
    }

    protected function suggest($conversation, $profile, $decision, $confident, $threshold)
    {
        $decision->suggested_user_id = $profile->user_id;
        $decision->save();

        $reason = $confident
            ? 'auto-assign is off'
            : sprintf('confidence %.2f is below the %.2f threshold',
                (float) $decision->confidence, $threshold);

        $this->addNote($conversation, sprintf(
            'Triage suggests %s (%s) — not assigned because %s. %s',
            $profile->userName(),
            $decision->method,
            $reason,
            $decision->reasoning
        ));
    }

    /**
     * Post a note on the conversation.
     *
     * Notes are internal - customers never see them - so this is the right
     * place to show reasoning a human can check.
     */
    protected function addNote($conversation, $text)
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
            // A note is informational; failing to write one must not fail
            // the triage that already succeeded.
            \Log::warning('[Triage] could not add note to conversation '
                .$conversation->id.': '.$e->getMessage());
        }
    }

    public function failed(\Throwable $e)
    {
        \Log::error('[Triage] job failed for conversation '
            .$this->conversationId.': '.$e->getMessage());
    }
}
