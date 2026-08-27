<?php

namespace Modules\DOTTriage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\DOTTriage\Services\Settings;
use Modules\DOTTriage\Services\Escalator;
use Modules\DOTTriage\Services\ClaudeClient;
use Modules\DOTTriage\Entities\TriageDecision;

/**
 * A customer replied to a closed ticket. Does it need to be open?
 *
 * FreeScout reopens unconditionally, which is right for "it still doesn't
 * work" and wrong for "thanks, all sorted", an out-of-office, or a reply to
 * a closure email that says nothing. Those sat in the queue as live work
 * until someone closed them again by hand - or, worse, were routed and
 * answered.
 *
 * The ticket is reopened first and judged second, on purpose. If the model
 * is unavailable or unsure the ticket stays open and a person decides;
 * the only thing the model can do on its own is put a ticket back to closed,
 * with its reasoning on the record. A wrong "keep closed" is one lost reply;
 * a wrong "keep open" is one extra glance from an agent.
 */
class JudgeReopen implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 3;
    public $timeout = 120;

    protected $conversationId;
    protected $threadId;

    public function __construct($conversationId, $threadId)
    {
        $this->conversationId = (int) $conversationId;
        $this->threadId       = (int) $threadId;
    }

    public function handle()
    {
        if (!config('triage.enabled') || !Settings::get('reopen_judge_enabled')) {
            return;
        }

        $conversation = \App\Conversation::find($this->conversationId);
        $thread       = \App\Thread::find($this->threadId);

        if (!$conversation || !$thread) {
            return;
        }

        // Only judge a ticket that is still sitting open because of this
        // reply. If someone has already closed it, replied, or the status
        // moved on, a person is on it and the model has nothing to add.
        if ((int) $conversation->status !== \App\Conversation::STATUS_ACTIVE) {
            return;
        }

        $touched = $conversation->threads()
            ->whereIn('type', [\App\Thread::TYPE_MESSAGE, \App\Thread::TYPE_LINEITEM])
            ->where('created_at', '>', $thread->created_at)
            ->exists();

        if ($touched) {
            return;
        }

        $client = new ClaudeClient();

        if (!$client->isConfigured()) {
            $this->addNote($conversation, 'Customer replied after closing. Left open — no API key configured to judge it.');
            return;
        }

        $limit = (int) config('triage.daily_call_limit', 500);
        if ($limit > 0 && TriageDecision::callsToday() >= $limit) {
            $this->addNote($conversation, 'Customer replied after closing. Left open — daily API call limit reached.');
            return;
        }

        $started = microtime(true);
        $result  = $client->complete($this->systemPrompt(), $this->transcript($conversation, $thread), 300);
        $ms      = (int) round((microtime(true) - $started) * 1000);

        if (!$result['ok']) {
            if (!empty($result['transient']) && $this->attempts() < $this->tries) {
                $delay = [60, 300, 900][min($this->attempts() - 1, 2)];
                \Log::info('[Triage] reopen judgement for '.$conversation->id
                    .' hit a transient API error, retrying in '.$delay.'s: '.$result['error']);
                $this->release($delay);
                return;
            }

            $this->addNote($conversation, 'Customer replied after closing. Left open — could not judge it: '.$result['error']);
            $this->dotlog('triage.failed', 'Reopen judgement failed: '.$result['error'],
                $conversation, ['level' => 'error']);
            return;
        }

        $verdict   = $this->parse($result['text']);
        $threshold = (float) config('triage.reopen_confidence', 0.75);

        // Keeping a ticket closed is the only irreversible-feeling outcome,
        // so it needs both the verdict and the confidence. Anything else
        // stays open.
        $keepClosed = !$verdict['reopen'] && $verdict['confidence'] >= $threshold;

        TriageDecision::create([
            'conversation_id' => $conversation->id,
            'mailbox_id'      => $conversation->mailbox_id,
            'method'          => 'model',
            'model'           => config('triage.model'),
            'confidence'      => $verdict['confidence'],
            'reasoning'       => 'Reply after closing: '.$verdict['reasoning'],
            'tokens_in'       => $result['tokens_in'],
            'tokens_out'      => $result['tokens_out'],
            'duration_ms'     => $ms,
            'applied'         => false,
            'closed'          => $keepClosed,
            'close_reason'    => $keepClosed ? 'not_reopened' : null,
        ]);

        if ($keepClosed) {
            $this->keepClosed($conversation, $verdict);
            return;
        }

        $this->addNote($conversation, sprintf(
            'Reopened — the customer\'s reply needs attention (confidence %.2f). %s',
            $verdict['confidence'], $verdict['reasoning']
        ));

        $this->dotlog('triage.reopened', 'Reopened after a customer reply: '.$verdict['reasoning'],
            $conversation);

        if ($conversation->user_id) {
            // The assignee owes a reply; put them on the clock.
            Escalator::start($conversation);
        } else {
            // Nobody owns it - route it like new mail, judged on the reply
            // that reopened it rather than the message that started it.
            TriageConversation::dispatch($conversation->id, true);
        }
    }

    protected function keepClosed($conversation, $verdict)
    {
        // Status only. closed_at and closed_by stay as they were: nothing
        // new was resolved, the original close still stands.
        $conversation->status = \App\Conversation::STATUS_CLOSED;
        $conversation->updateFolder();
        $conversation->save();
        $conversation->mailbox->updateFoldersCounters();

        $this->addNote($conversation, sprintf(
            'Kept closed — the customer\'s reply needs no action (confidence %.2f). %s '
            .'Reopen it yourself if that is wrong.',
            $verdict['confidence'], $verdict['reasoning']
        ));

        $this->dotlog('triage.kept_closed', 'Customer reply judged to need no action: '
            .$verdict['reasoning'], $conversation);

        \Log::info('[Triage] kept conversation '.$conversation->id.' closed after customer reply');
    }

    protected function systemPrompt()
    {
        return "A customer has replied to a support ticket that was already closed.\n"
            ."Decide whether the ticket needs to be reopened for a person to act on it.\n\n"
            ."Reply with ONLY a JSON object:\n"
            .'{"reopen": <true|false>, "confidence": <0.0-1.0>, "reasoning": "<one short sentence>"}'."\n\n"
            ."Answer reopen=true when the new message:\n"
            ."- reports the problem is not fixed, or has come back\n"
            ."- asks a question or makes a new request, even a small one\n"
            ."- provides information an agent had asked for\n"
            ."- expresses dissatisfaction that needs a response\n\n"
            ."Answer reopen=false when the new message:\n"
            ."- only says thank you, acknowledges, or confirms it is resolved\n"
            ."- is an automatic reply, out-of-office, or delivery notification\n"
            ."- is a rating or feedback with nothing that needs an answer\n"
            ."- is unrelated bulk or marketing mail\n\n"
            ."A ticket left closed by mistake means a customer is ignored. When unsure,\n"
            ."answer true.";
    }

    /** Recent history, then the reply that reopened it, clearly marked. */
    protected function transcript($conversation, $newThread)
    {
        $lines = [];

        $closeNote = $this->closeReason($conversation);
        if ($closeNote) {
            $lines[] = 'TICKET WAS CLOSED BECAUSE: '.$closeNote;
        }

        $history = $conversation->threads()
            ->whereIn('type', [\App\Thread::TYPE_CUSTOMER, \App\Thread::TYPE_MESSAGE])
            ->where('id', '!=', $newThread->id)
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get()
            ->reverse();

        foreach ($history as $t) {
            $who = (int) $t->type === \App\Thread::TYPE_CUSTOMER ? 'CUSTOMER' : 'AGENT';
            $lines[] = $who.': '.$this->text($t->body, 600);
        }

        $lines[] = 'NEW MESSAGE FROM CUSTOMER (after the ticket was closed): '
            .$this->text($newThread->body, (int) config('triage.max_body_chars', 4000));

        return implode("\n\n", $lines);
    }

    /** Why it was closed, from triage's record or the closing agent. */
    protected function closeReason($conversation)
    {
        $decision = TriageDecision::where('conversation_id', $conversation->id)
            ->where('closed', true)
            ->orderBy('id', 'desc')
            ->first();

        if ($decision && $decision->reasoning
            && (!$conversation->closed_by_user_id || $decision->created_at >= $conversation->closed_at)) {
            return $decision->reasoning;
        }

        if ($conversation->closed_by_user_id) {
            $user = \App\User::find($conversation->closed_by_user_id);
            return 'closed by agent '.($user ? $user->getFullName() : '').' on '
                .substr((string) $conversation->closed_at, 0, 10);
        }

        return '';
    }

    protected function text($html, $max)
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).' [truncated]' : $text;
    }

    protected function parse($text)
    {
        // Unparseable means "reopen": the fail-safe direction.
        $default = ['reopen' => true, 'confidence' => 0.0, 'reasoning' => 'Could not parse model response.'];

        if (!preg_match('/\{.*\}/s', (string) $text, $m)) {
            return $default;
        }

        $json = json_decode($m[0], true);
        if (!is_array($json) || !array_key_exists('reopen', $json)) {
            return $default;
        }

        return [
            'reopen'     => (bool) $json['reopen'],
            'confidence' => isset($json['confidence']) ? (float) $json['confidence'] : 0.0,
            'reasoning'  => isset($json['reasoning']) ? mb_substr((string) $json['reasoning'], 0, 300) : '',
        ];
    }

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
            \Log::warning('[Triage] could not add note to conversation '
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

    public function failed(\Throwable $e)
    {
        \Log::error('[Triage] reopen judgement job failed for conversation '
            .$this->conversationId.': '.$e->getMessage());
    }
}
