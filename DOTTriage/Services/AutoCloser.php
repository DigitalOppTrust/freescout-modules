<?php

namespace Modules\DOTTriage\Services;

use Modules\DOTTriage\Entities\TriageDecision;
use Modules\DOTTriage\Services\Settings;

/**
 * Closes conversations that no longer need a human.
 *
 * Three mechanisms, in ascending order of risk:
 *
 *   1. backlog noise  - the existing header detector, applied to conversations
 *                       that arrived before triage was enabled. No judgement.
 *   2. inactivity     - an agent replied, the customer did not come back, the
 *                       window passed. A timestamp comparison.
 *   3. resolved       - a model reads the thread and judges it finished.
 *
 * Only the third can be wrong in a way that matters. Leaving a resolved ticket
 * open costs a slightly noisy queue; closing an unresolved one makes a
 * customer think they were dropped, and nobody notices because the ticket has
 * left the queue. So it is gated hardest: it needs an agent reply, a quiet
 * period, high confidence, and it is off by default.
 *
 * Nothing here emails the customer. If a close is wrong, the customer replies
 * and FreeScout reopens the conversation - the mistake self-corrects.
 */
class AutoCloser
{
    const REASON_NOISE      = 'backlog_noise';
    const REASON_INACTIVITY = 'inactivity';
    const REASON_RESOLVED   = 'resolved';

    protected $dryRun;

    public function __construct($dryRun = false)
    {
        $this->dryRun = (bool) $dryRun;
    }

    /**
     * Pass 1: apply the noise detector to conversations that were never
     * triaged - typically because they predate the module being enabled.
     */
    public function sweepBacklogNoise($limit = null)
    {
        if (!Settings::get('close_noise_enabled')) {
            return [];
        }

        $limit    = $this->cap($limit);
        $detector = new NoiseDetector();
        $results  = [];

        $conversations = \App\Conversation::where('status', \App\Conversation::STATUS_ACTIVE)
            ->whereNotExists(function ($q) {
                $q->select(\DB::raw(1))
                  ->from('triage_decisions')
                  ->whereRaw('triage_decisions.conversation_id = conversations.id');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($conversations as $c) {
            if ($this->isProtected($c)) {
                continue;
            }

            $thread = $c->threads()
                ->where('type', \App\Thread::TYPE_CUSTOMER)
                ->orderBy('created_at', 'asc')
                ->first();

            if (!$thread) {
                continue;
            }

            $verdict = $detector->classify($thread, $c->mailbox);
            if (!$verdict['noise']) {
                continue;
            }

            $results[] = [
                'number'   => $c->number,
                'subject'  => $c->subject,
                'category' => $verdict['category'],
                'reason'   => $verdict['reason'],
            ];

            if (!$this->dryRun) {
                $this->close($c, self::REASON_NOISE, $verdict['category'], $verdict['reason']);
            }
        }

        return $results;
    }

    /**
     * Pass 2: an agent replied, the customer never came back.
     *
     * Working time, not wall clock - a ticket answered on Friday has not been
     * ignored by Monday morning.
     */
    public function sweepInactive($limit = null)
    {
        if (!Settings::get('close_inactive_enabled')) {
            return [];
        }

        $limit   = $this->cap($limit);
        $minutes = (int) Settings::get('close_after_inactive_minutes');
        $results = [];

        // Pending counts too: the team uses it as "waiting on the customer"
        // (a reply often sets it), which is exactly the state this pass
        // exists to expire. Only the noise pass stays Active-only.
        $conversations = \App\Conversation::whereIn('status', [
                \App\Conversation::STATUS_ACTIVE,
                \App\Conversation::STATUS_PENDING,
            ])
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($conversations as $c) {
            if (count($results) >= $limit) {
                break;
            }

            if ($this->isProtected($c)) {
                continue;
            }

            $lastAgent = $c->threads()
                ->where('type', \App\Thread::TYPE_MESSAGE)
                ->orderBy('created_at', 'desc')
                ->first();

            // No agent has replied - this is not "waiting on the customer",
            // it is unanswered. Closing it would hide a failure rather than
            // tidy the queue, so escalation handles these instead.
            if (!$lastAgent) {
                continue;
            }

            $lastCustomer = $c->threads()
                ->where('type', \App\Thread::TYPE_CUSTOMER)
                ->orderBy('created_at', 'desc')
                ->first();

            // The customer replied after the agent did - the ball is back with
            // the agent, so it must not be closed.
            if ($lastCustomer && $lastCustomer->created_at > $lastAgent->created_at) {
                continue;
            }

            $quiet = BusinessTime::minutesBetween(
                new \DateTimeImmutable($lastAgent->created_at),
                new \DateTimeImmutable()
            );

            if ($quiet < $minutes) {
                continue;
            }

            $results[] = [
                'number'  => $c->number,
                'subject' => $c->subject,
                'quiet'   => BusinessTime::describe($quiet),
            ];

            if (!$this->dryRun) {
                $this->close(
                    $c,
                    self::REASON_INACTIVITY,
                    null,
                    'No customer response for '.BusinessTime::describe($quiet).' after the last reply.'
                );
            }
        }

        return $results;
    }

    /**
     * Pass 3: ask the model whether a conversation is finished.
     *
     * Deliberately narrow. A conversation only reaches the model if:
     *   - an agent has actually replied (there is something to judge)
     *   - the customer has been quiet for a while (not mid-exchange)
     *   - it is not already covered by the inactivity rule
     *
     * And the model must be confident AND say it is resolved. Anything
     * ambiguous is left open, because the cost of a wrong close is much
     * higher than the cost of a stale ticket.
     */
    public function sweepResolved($limit = null)
    {
        if (!Settings::get('close_resolved_enabled')) {
            return ['skipped' => 'Closing resolved tickets is switched off in Manage → Triage.'];
        }

        $limit      = min($this->cap($limit), 25);
        $minQuiet   = (int) Settings::get('resolved_min_quiet_minutes');
        $threshold  = (float) Settings::get('resolved_confidence');
        $client     = new ClaudeClient();
        $results    = [];

        if (!$client->isConfigured()) {
            return ['skipped' => 'No Claude API key configured.'];
        }

        $conversations = \App\Conversation::whereIn('status', [
                \App\Conversation::STATUS_ACTIVE,
                \App\Conversation::STATUS_PENDING,
            ])
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($conversations as $c) {
            if (count($results) >= $limit) {
                break;
            }

            if ($this->isProtected($c)) {
                continue;
            }

            $lastAgent = $c->threads()
                ->where('type', \App\Thread::TYPE_MESSAGE)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$lastAgent) {
                continue;
            }

            $lastCustomer = $c->threads()
                ->where('type', \App\Thread::TYPE_CUSTOMER)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastCustomer && $lastCustomer->created_at > $lastAgent->created_at) {
                continue;
            }

            $quiet = BusinessTime::minutesBetween(
                new \DateTimeImmutable($lastAgent->created_at),
                new \DateTimeImmutable()
            );

            if ($quiet < $minQuiet) {
                continue;
            }

            $verdict = $this->judge($client, $c);

            if (!$verdict['resolved'] || $verdict['confidence'] < $threshold) {
                continue;
            }

            $results[] = [
                'number'     => $c->number,
                'subject'    => $c->subject,
                'confidence' => $verdict['confidence'],
                'reasoning'  => $verdict['reasoning'],
            ];

            if (!$this->dryRun) {
                $this->close(
                    $c,
                    self::REASON_RESOLVED,
                    null,
                    $verdict['reasoning'],
                    $verdict['confidence']
                );
            }
        }

        return $results;
    }

    /** Bound a run to the configured maximum. */
    protected function cap($requested)
    {
        $max = (int) Settings::get('close_max_per_run');

        return $requested === null ? $max : min((int) $requested, $max);
    }

    /**
     * Should this conversation be left alone regardless of the rules?
     * With 'protect assigned' on, a ticket someone owns is theirs to close.
     */
    protected function isProtected($conversation)
    {
        return Settings::get('close_protect_assigned') && $conversation->user_id;
    }

    /** Ask the model whether the exchange is finished. */
    protected function judge(ClaudeClient $client, $conversation)
    {
        $lines = [];
        foreach ($conversation->threads()->orderBy('created_at', 'asc')->limit(20)->get() as $t) {
            if (!in_array((int) $t->type, [1, 2], true)) {
                continue;   // skip notes and line items
            }
            $who  = (int) $t->type === 1 ? 'CUSTOMER' : 'AGENT';
            $body = trim(preg_replace('/\s+/u', ' ',
                html_entity_decode(strip_tags((string) $t->body), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $lines[] = $who.': '.mb_substr($body, 0, 800);
        }

        if (empty($lines)) {
            return ['resolved' => false, 'confidence' => 0.0, 'reasoning' => 'No messages to judge.'];
        }

        $system = "You decide whether a support conversation is finished.\n\n"
            ."Reply with ONLY a JSON object:\n"
            .'{"resolved": <true|false>, "confidence": <0.0-1.0>, "reasoning": "<one short sentence>"}'."\n\n"
            ."Answer true ONLY when the customer's request has clearly been dealt with:\n"
            ."the agent answered and the customer confirmed, or thanked them, or the\n"
            ."agent completed the task and nothing further was asked.\n\n"
            ."Answer false when:\n"
            ."- the agent asked a question the customer has not answered\n"
            ."- the agent promised to follow up and there is no sign they did\n"
            ."- the customer raised something the agent did not address\n"
            ."- you are unsure\n\n"
            ."Closing an unresolved conversation makes a customer think they were\n"
            ."ignored. When in doubt, answer false.";

        $result = $client->complete($system, implode("\n\n", $lines), 300);

        if (!$result['ok']) {
            return ['resolved' => false, 'confidence' => 0.0, 'reasoning' => 'API error: '.$result['error']];
        }

        if (!preg_match('/\{.*\}/s', $result['text'], $m)) {
            return ['resolved' => false, 'confidence' => 0.0, 'reasoning' => 'Unparseable model response.'];
        }

        $json = json_decode($m[0], true);
        if (!is_array($json)) {
            return ['resolved' => false, 'confidence' => 0.0, 'reasoning' => 'Unparseable model response.'];
        }

        return [
            'resolved'   => !empty($json['resolved']),
            'confidence' => isset($json['confidence']) ? (float) $json['confidence'] : 0.0,
            'reasoning'  => isset($json['reasoning']) ? mb_substr((string) $json['reasoning'], 0, 300) : '',
        ];
    }

    /** Close, record why, and leave a note. No customer email. */
    protected function close($conversation, $reason, $noiseCategory, $explanation, $confidence = null)
    {
        TriageDecision::create([
            'conversation_id' => $conversation->id,
            'mailbox_id'      => $conversation->mailbox_id,
            'method'          => $reason === self::REASON_RESOLVED ? 'model' : 'headers',
            'noise_category'  => $noiseCategory,
            'close_reason'    => $reason,
            'reasoning'       => $explanation,
            'confidence'      => $confidence,
            'applied'         => false,
            'closed'          => true,
        ]);

        $labels = [
            self::REASON_NOISE      => 'Not a support request',
            self::REASON_INACTIVITY => 'No response from the customer',
            self::REASON_RESOLVED   => 'Appears resolved',
        ];

        $this->note($conversation, sprintf(
            'Closed automatically — %s. %s%s',
            $labels[$reason] ?? $reason,
            $explanation,
            $confidence !== null ? ' (confidence '.number_format($confidence, 2).')' : ''
        ));

        $conversation->status    = \App\Conversation::STATUS_CLOSED;
        $conversation->closed_at = now();
        // Assigning status directly skips setStatus(), so the folder must be
        // re-derived by hand - without this the conversation keeps the
        // folder_id it had while open and never shows up under Closed.
        $conversation->updateFolder();
        $conversation->save();

        $conversation->mailbox->updateFoldersCounters();

        \Log::info('[Triage] auto-closed conversation '.$conversation->id.' ('.$reason.')');
    }

    protected function note($conversation, $text)
    {
        try {
            $thread = new \App\Thread();
            $thread->conversation_id = $conversation->id;
            $thread->type            = \App\Thread::TYPE_NOTE;
            $thread->status          = \App\Thread::STATUS_NOCHANGE;
            $thread->state           = \App\Thread::STATE_PUBLISHED;
            $thread->body            = '<strong>Triage</strong><br>'.e($text)
                                      .'<br><em>The customer was not emailed. If they reply, '
                                      .'this conversation reopens automatically.</em>';
            $thread->source_via      = \App\Thread::PERSON_USER;
            $thread->source_type     = \App\Thread::SOURCE_TYPE_WEB;
            $thread->customer_id     = $conversation->customer_id;
            $thread->save();
        } catch (\Throwable $e) {
            \Log::warning('[Triage] could not note auto-close on '.$conversation->id.': '.$e->getMessage());
        }
    }
}
